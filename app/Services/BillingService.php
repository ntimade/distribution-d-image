<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\TariffGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La facturation mensuelle des abonnés : une facture par mois tant que le client est actif,
 * des paiements répartis du mois le plus ancien au plus récent, et les avances sur plusieurs mois.
 */
class BillingService
{
    // Limite de sécurité : une faute de frappe ne doit pas créer des dizaines d'années de factures.
    public const MAX_MONTHS_AHEAD = 36;

    /**
     * Abonne un nouveau client. Ses factures sont créées tout de suite jusqu'au mois en cours.
     */
    public function subscribe(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $start = Carbon::parse($data['start_date']);
            $first = $data['first_period'] ?? $start->format('Y-m');

            $customer = Customer::create($data + [
                'first_period' => $first,
                'billing_from' => $first,
                'status' => Customer::ACTIVE,
                'status_since' => $start->toDateString(),
            ]);

            $customer->recordEvent(CustomerEvent::SUBSCRIBED, $start, $this->tariffNote($customer), $customer->effectiveFee());
            $this->syncCustomer($customer);

            return $customer;
        });
    }

    /**
     * Le nouveau tarif et le nouveau jour d'échéance valent pour les prochaines factures : celles
     * qui existent gardent leur montant et leur date, sauf correction une par une.
     */
    public function updateCustomer(Customer $customer, array $data): void
    {
        DB::transaction(function () use ($customer, $data) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail()->load('tariffGroup');
            $previousFee = $locked->effectiveFee();
            $previousTariff = $locked->tariffLabel();

            // Le début de l'abonnement ne se corrige que tant qu'aucun mois n'est facturé.
            if ($locked->invoices()->exists()) {
                unset($data['start_date'], $data['first_period']);
            } elseif (isset($data['start_date'])) {
                $data['first_period'] = $data['first_period'] ?? Carbon::parse($data['start_date'])->format('Y-m');
                $data['billing_from'] = $data['first_period'];
            }

            $locked->update($data);
            $locked->load('tariffGroup');

            if ($locked->effectiveFee() !== $previousFee || $locked->tariffLabel() !== $previousTariff) {
                $locked->recordEvent(CustomerEvent::TARIFF_CHANGED, today(), __(':v1 (:v2) → :v3 (:v4)', [
                    'v1' => $previousTariff, 'v2' => money($previousFee), 'v3' => $locked->tariffLabel(), 'v4' => money($locked->effectiveFee()),
                ]), $locked->effectiveFee());
            }

            $this->syncCustomer($locked);
        });
    }

    /**
     * Coupe le signal d'un client. Le mois de la coupure reste dû ; les mois suivants pas encore
     * payés disparaissent, et plus rien n'est facturé jusqu'au réabonnement.
     */
    public function suspend(Customer $customer, string $date, ?string $note): void
    {
        DB::transaction(function () use ($customer, $date, $note) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                $this->fail('suspended_on', __('Ce client n\'est pas actif : il ne peut pas être coupé.'));
            }

            $this->assertAfterStart($locked, $date, 'suspended_on');
            $locked->invoices()->where('period', '>', substr($date, 0, 7))->where('paid_amount', 0)->delete();
            $locked->forceFill(['status' => Customer::SUSPENDED, 'status_since' => $date])->save();
            $locked->recordEvent(CustomerEvent::SUSPENDED, $date, $note, $locked->unpaidTotal());
        });
    }

    // Résiliation : le client quitte le réseau. Ce qui reste dû reste dû.
    public function terminate(Customer $customer, string $date, ?string $note): void
    {
        DB::transaction(function () use ($customer, $date, $note) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($locked->isTerminated()) {
                $this->fail('terminated_on', __('Ce client est déjà résilié.'));
            }

            $this->assertAfterStart($locked, $date, 'terminated_on');
            $locked->invoices()->where('period', '>', substr($date, 0, 7))->where('paid_amount', 0)->delete();
            $locked->forceFill(['status' => Customer::TERMINATED, 'status_since' => $date])->save();
            $locked->recordEvent(CustomerEvent::TERMINATED, $date, $note, $locked->unpaidTotal());
        });
    }

    /**
     * Réabonne un client coupé ou résilié. La facturation reprend au mois du réabonnement, avec
     * les frais de réabonnement sur ce mois, à régler le jour même.
     */
    public function reconnect(Customer $customer, string $date, float $fee, ?string $note): void
    {
        DB::transaction(function () use ($customer, $date, $fee, $note) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($locked->isActive()) {
                $this->fail('reconnected_on', __('Ce client est déjà actif.'));
            }

            if ($date < $locked->status_since?->toDateString()) {
                $this->fail('reconnected_on', __('Le réabonnement ne peut pas précéder la coupure (:v1).', ['v1' => $locked->status_since->format('d/m/Y')]));
            }

            $this->assertCurrencyDecimals($locked, $fee, 'reconnection_fee');

            $period = substr($date, 0, 7);
            $locked->forceFill(['status' => Customer::ACTIVE, 'status_since' => $date, 'billing_from' => max($period, $locked->billing_from)])->save();

            $invoice = $locked->invoices()->where('period', $period)->first()
                ?? $this->createInvoice($locked, $period, $locked->dueDateFor($period)->max(Carbon::parse($date)));

            if ($fee > 0) {
                // Mois déjà réglé (coupé puis réabonné dans le même mois) : les frais sont dus le jour du
                // réabonnement, pas à l'échéance passée du mois, qui ferait paraître le client en retard.
                $due = $invoice->balance() <= 0 && $invoice->due_date->lt(Carbon::parse($date))
                    ? $date
                    : $invoice->due_date->toDateString();

                $invoice->update([
                    'amount' => round((float) $invoice->amount + $fee, 3),
                    'reconnection_fee' => round((float) $invoice->reconnection_fee + $fee, 3),
                    'due_date' => $due,
                ]);
            }

            $locked->recordEvent(CustomerEvent::RECONNECTED, $date, $note, $fee);
            $this->reallocate($locked);
            $this->syncCustomer($locked);
        });
    }

    // Crée les factures qui manquent pour tous les clients actifs du compte.
    public function syncOrganisation(Organisation $organisation): void
    {
        Customer::withoutGlobalScope('organisation')->where('organisation_id', $organisation->id)
            ->active()->orderBy('id')->get()
            ->each(fn (Customer $customer) => $this->syncCustomer($customer));

        $organisation->forceFill(['invoices_synced_on' => today()->toDateString()])->save();
    }

    /**
     * Crée les factures dues d'un client actif : jusqu'au mois en cours, et le mois suivant dès
     * que son échéance entre dans la période de rappel. Après des mois payés d'avance, la
     * facturation reprend au premier mois qui n'est pas encore facturé.
     */
    public function syncCustomer(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            $locked = Customer::withoutGlobalScope('organisation')->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive() || $this->feeOf($locked) <= 0) {
                return;
            }

            $currentPeriod = now()->format('Y-m');
            $horizon = $this->horizon($locked);
            $period = $this->nextPeriod($locked);

            while ($period <= $currentPeriod || $locked->dueDateFor($period)->lte($horizon)) {
                $this->createInvoice($locked, $period);
                $period = Customer::followingPeriod($period);
            }
        });
    }

    public function recordPayment(Customer $customer, float $amount, string $paidAt, ?string $method, ?string $reference): Payment
    {
        return DB::transaction(function () use ($customer, $amount, $paidAt, $method, $reference) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrencyDecimals($locked, $amount);

            $payment = Payment::create([
                'organisation_id' => $locked->organisation_id,
                'customer_id' => $locked->id,
                'user_id' => Auth::id(),
                'amount' => $amount,
                'paid_at' => $paidAt,
                'method' => $method,
                'reference' => $reference,
            ]);

            $this->reallocate($locked);

            return $payment->fresh(['allocations.invoice']);
        });
    }

    public function updatePayment(Payment $payment, float $amount, string $paidAt, ?string $method, ?string $reference): void
    {
        DB::transaction(function () use ($payment, $amount, $paidAt, $method, $reference) {
            $locked = Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
            $this->assertCurrencyDecimals($locked, $amount);

            $payment->update(['amount' => $amount, 'paid_at' => $paidAt, 'method' => $method, 'reference' => $reference]);

            $this->reallocate($locked);
        });
    }

    public function cancelPayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $locked = Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();

            $payment->delete();

            $this->reallocate($locked);
        });
    }

    // Corrige une facture. Si elle baisse sous ce qui est payé, le surplus passe au mois suivant.
    public function correctInvoice(Invoice $invoice, array $data): void
    {
        DB::transaction(function () use ($invoice, $data) {
            $locked = Customer::whereKey($invoice->customer_id)->lockForUpdate()->firstOrFail();
            $this->assertCurrencyDecimals($locked, (float) $data['amount'], 'amount');

            if ((float) $invoice->reconnection_fee > (float) $data['amount']) {
                $data['reconnection_fee'] = $data['amount'];
            }

            $invoice->update($data);

            $this->reallocate($locked);
        });
    }

    /**
     * Répartit tous les paiements du client du mois le plus ancien au plus récent, et crée les
     * mois à venir que couvre une avance. Tout recalculer à chaque changement évite qu'annuler
     * un ancien paiement laisse un mois impayé au milieu de mois réglés.
     */
    private function reallocate(Customer $customer): void
    {
        $invoices = $customer->invoices()->orderBy('period')->lockForUpdate()->get()->values();
        $payments = $customer->payments()->orderBy('paid_at')->orderBy('id')->lockForUpdate()->get();

        PaymentAllocation::whereIn('payment_id', Payment::withTrashed()->where('customer_id', $customer->id)->select('id'))->delete();

        $paid = [];
        $index = 0;

        foreach ($payments as $payment) {
            $rest = round((float) $payment->amount, 3);

            while ($rest > 0) {
                if ($index >= $invoices->count()) {
                    $invoices->push($this->createAdvanceInvoice($customer, $rest));
                }

                $invoice = $invoices[$index];
                $room = round((float) $invoice->amount - ($paid[$invoice->id] ?? 0), 3);

                if ($room <= 0) {
                    $index++;

                    continue;
                }

                $share = min($rest, $room);
                PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $share]);
                $paid[$invoice->id] = round(($paid[$invoice->id] ?? 0) + $share, 3);
                $rest = round($rest - $share, 3);
            }
        }

        $currentPeriod = now()->format('Y-m');
        $horizon = $this->horizon($customer);

        foreach ($invoices as $invoice) {
            $amount = $paid[$invoice->id] ?? 0;

            // Un mois à venir qui n'est plus payé d'avance disparaît : il reviendra le moment venu.
            if ($amount <= 0 && $invoice->period > $currentPeriod && $invoice->due_date->gt($horizon) && (float) $invoice->reconnection_fee <= 0) {
                $invoice->delete();

                continue;
            }

            if ((float) $invoice->paid_amount !== (float) $amount) {
                $invoice->update(['paid_amount' => $amount]);
            }
        }
    }

    private function createAdvanceInvoice(Customer $customer, float $rest): Invoice
    {
        $customer = $customer->fresh();

        if (! $customer->isActive()) {
            $this->fail('amount', __('Ce client est :v1 : :v2 dépassent ce qu\'il doit. Réabonnez-le avant d\'encaisser une avance.', [
                'v1' => mb_strtolower($customer->statusLabel()), 'v2' => money($rest),
            ]));
        }

        $period = $this->nextPeriod($customer);

        if ($period > now()->addMonthsNoOverflow(self::MAX_MONTHS_AHEAD)->format('Y-m')) {
            $this->fail('amount', __('Une avance ne peut pas couvrir plus de :v1 mois.', ['v1' => self::MAX_MONTHS_AHEAD]));
        }

        if ($this->feeOf($customer) <= 0) {
            $this->fail('amount', __('Ce client n\'a pas de tarif : aucune avance n\'est possible.'));
        }

        return $this->createInvoice($customer, $period);
    }

    private function createInvoice(Customer $customer, string $period, ?Carbon $due = null): Invoice
    {
        return Invoice::withoutGlobalScope('organisation')->create([
            'organisation_id' => $customer->organisation_id,
            'customer_id' => $customer->id,
            'period' => $period,
            'due_date' => ($due ?? $customer->dueDateFor($period))->toDateString(),
            'amount' => $this->feeOf($customer),
        ]);
    }

    // Le tarif lu sans le filtre du compte connecté : la création des factures tourne aussi hors connexion.
    private function feeOf(Customer $customer): float
    {
        if ($customer->monthly_fee !== null) {
            return (float) $customer->monthly_fee;
        }

        return (float) TariffGroup::withoutGlobalScope('organisation')->whereKey($customer->tariff_group_id)->value('monthly_fee');
    }

    private function tariffNote(Customer $customer): string
    {
        return $customer->tariffLabel().' — '.money($customer->effectiveFee());
    }

    private function nextPeriod(Customer $customer): string
    {
        $last = Invoice::withoutGlobalScope('organisation')->where('customer_id', $customer->id)->max('period');

        return $last ? max(Customer::followingPeriod($last), $customer->billing_from) : $customer->billing_from;
    }

    // Les factures du mois suivant existent dès que leur rappel doit partir.
    private function horizon(Customer $customer): Carbon
    {
        $days = (int) Organisation::whereKey($customer->organisation_id)->value('reminder_days');

        return today()->addDays($days);
    }

    private function assertAfterStart(Customer $customer, string $date, string $field): void
    {
        if ($date < $customer->start_date->toDateString()) {
            $this->fail($field, __('La date ne peut pas précéder le début de l\'abonnement (:v1).', ['v1' => $customer->start_date->format('d/m/Y')]));
        }

        if ($customer->status_since && $date < $customer->status_since->toDateString()) {
            $this->fail($field, __('La date ne peut pas précéder le dernier changement d\'état (:v1).', ['v1' => $customer->status_since->format('d/m/Y')]));
        }
    }

    private function assertCurrencyDecimals(Customer $customer, float $amount, string $field = 'amount'): void
    {
        $decimals = Organisation::whereKey($customer->organisation_id)->firstOrFail()->currencyDecimals();

        if (round($amount, $decimals) !== round($amount, 3)) {
            $this->fail($field, $decimals === 0
                ? __('Cette devise n\'accepte pas de décimales.')
                : __('Cette devise accepte :v1 décimales au maximum.', ['v1' => $decimals]));
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
