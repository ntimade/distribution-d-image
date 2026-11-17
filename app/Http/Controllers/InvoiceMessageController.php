<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\InvoiceMessage;
use App\Support\PhoneNumber;
use App\Support\SortedTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class InvoiceMessageController extends Controller
{
    // Un paiement plus ancien n'attend plus de confirmation.
    public const PAYMENT_DAYS = 30;

    // Un avis de coupure ou de réabonnement plus ancien n'attend plus d'envoi.
    public const EVENT_DAYS = 30;

    private const STATES = ['rappels', 'factures', 'paiements', 'relances', 'coupures'];

    public function index(Request $request): View
    {
        $state = in_array($request->query('etat'), self::STATES, true) ? $request->query('etat') : self::STATES[0];
        $lateRows = self::lateRows();
        $warningRows = self::warningRows();

        return view('messages.index', [
            'state' => $state,
            'reminderDays' => self::reminderDays(),
            'counts' => [
                'rappels' => self::remindersToSend()->count(),
                'factures' => self::invoicesToSend()->count(),
                'paiements' => self::paymentsToConfirm()->count(),
                'relances' => $lateRows->filter(fn ($row) => $row->waiting)->count(),
                'coupures' => self::eventsToSend()->count() + $warningRows->filter(fn ($row) => $row->waiting)->count(),
            ],
            'cutoffThreshold' => CutoffController::threshold(),
            'warnings' => $state === 'coupures'
                ? SortedTable::collection($warningRows, 'preavis', [
                    'customer' => fn ($row) => mb_strtolower($row->customer->name),
                    'months' => fn ($row) => $row->invoices->count(),
                    'total' => fn ($row) => $row->total,
                ])
                : null,
            'reminders' => $state === 'rappels'
                ? SortedTable::query(self::remindersToSend()->with('customer')->orderBy('due_date')->orderBy('id'), 'rappels', ['amount' => 'amount', 'due' => 'due_date'])
                : null,
            'unreachable' => $state === 'rappels'
                ? Invoice::with('customer')->dueWithin(self::reminderDays())->unreachable()->orderBy('due_date')->get()
                : collect(),
            'invoices' => $state === 'factures'
                ? SortedTable::query(self::invoicesToSend()->with('customer')->orderBy('due_date')->orderBy('id'), 'envois', ['amount' => 'amount', 'due' => 'due_date'])
                : null,
            'payments' => $state === 'paiements'
                ? SortedTable::query(self::paymentsToConfirm()->with(['customer', 'allocations.invoice'])->latest('paid_at')->latest('id'), 'confirmation', ['date' => 'paid_at', 'amount' => 'amount'])
                : null,
            'late' => $state === 'relances'
                ? SortedTable::collection($lateRows, 'relances', [
                    'customer' => fn ($row) => mb_strtolower($row->customer->name),
                    'total' => fn ($row) => $row->total,
                    'due' => fn ($row) => $row->oldest_due,
                ])
                : null,
            'events' => $state === 'coupures'
                ? SortedTable::query(self::eventsToSend()->with('customer')->latest('happened_on')->latest('id'), 'avis', ['date' => 'happened_on'])
                : null,
        ]);
    }

    /**
     * Les mois dont l'échéance arrive sous quelques jours et dont le rappel n'est pas encore
     * parti. Toute la période compte, pas seulement le 3e jour : un jour sans connexion ne fait
     * pas perdre un rappel. Un mois payé d'avance n'y figure jamais.
     */
    public static function remindersToSend(): Builder
    {
        return Invoice::dueWithin(self::reminderDays())->reachable()->whereNull('reminder_opened_at');
    }

    private static function invoicesToSend(): Builder
    {
        return Invoice::unpaid()->reachable()
            ->where('due_date', '>=', today()->toDateString())
            ->whereNull('whatsapp_opened_at')->whereNull('sms_opened_at');
    }

    private static function paymentsToConfirm(): Builder
    {
        return Payment::query()
            ->whereNull('whatsapp_opened_at')->whereNull('sms_opened_at')
            ->where('created_at', '>=', now()->subDays(self::PAYMENT_DAYS))
            ->whereHas('customer', fn (Builder $customer) => $customer->where('phone', 'like', '+%'));
    }

    // Les coupures et réabonnements récents dont l'avis n'a pas encore été ouvert.
    private static function eventsToSend(): Builder
    {
        // Un avis de coupure n'a plus de sens une fois le client réabonné ou résilié.
        return CustomerEvent::query()
            ->where(fn (Builder $event) => $event->where('type', CustomerEvent::RECONNECTED)
                ->orWhere(fn (Builder $cut) => $cut->where('type', CustomerEvent::SUSPENDED)
                    ->whereHas('customer', fn (Builder $customer) => $customer->where('status', Customer::SUSPENDED))))
            ->whereNull('whatsapp_opened_at')->whereNull('sms_opened_at')
            ->where('happened_on', '>=', today()->subDays(self::EVENT_DAYS)->toDateString())
            ->whereHas('customer', fn (Builder $customer) => $customer->where('phone', 'like', '+%'));
    }

    // Une ligne par client actif en retard. Une relance attend tant qu'un mois échu n'a pas été relancé.
    private static function lateRows(): Collection
    {
        return Invoice::with('customer')->overdue()
            ->whereHas('customer', fn (Builder $customer) => $customer->active())
            ->orderBy('due_date')->get()
            ->groupBy('customer_id')
            ->map(fn (Collection $invoices) => (object) [
                'customer' => $invoices->first()->customer,
                'invoices' => $invoices,
                'total' => $invoices->sum(fn (Invoice $invoice) => $invoice->balance()),
                'oldest_due' => $invoices->first()->due_date->toDateString(),
                'waiting' => PhoneNumber::isInternational($invoices->first()->customer->phone)
                    && $invoices->contains(fn (Invoice $invoice) => ! $invoice->late_notice_opened_at),
                'opened_at' => $invoices->pluck('late_notice_opened_at')->filter()->max(),
            ])
            ->sortByDesc('total')->values();
    }

    /**
     * Une ligne par client actif qui a atteint le seuil de coupure. Le préavis attend tant qu'il
     * n'a pas été ouvert depuis que le dernier mois est passé en retard.
     */
    public static function warningRows(): Collection
    {
        return CutoffController::toCut()->orderBy('name')->get()
            ->map(function (Customer $customer) {
                $invoices = Invoice::where('customer_id', $customer->id)->overdue()->orderBy('period')->get();
                $latestDue = $invoices->max('due_date');

                return (object) [
                    'customer' => $customer,
                    'invoices' => $invoices,
                    'total' => $invoices->sum(fn (Invoice $invoice) => $invoice->balance()),
                    'opened_at' => $customer->cutoff_warning_opened_at,
                    'reachable' => PhoneNumber::isInternational($customer->phone),
                    'waiting' => PhoneNumber::isInternational($customer->phone)
                        && (! $customer->cutoff_warning_opened_at || $customer->cutoff_warning_opened_at->lt($latestDue->copy()->addDay())),
                ];
            })->values();
    }

    // Le nombre affiché dans le menu : ce qui attend un envoi aujourd'hui.
    public static function waitingCount(): int
    {
        return self::remindersToSend()->count()
            + self::paymentsToConfirm()->count()
            + self::lateRows()->filter(fn ($row) => $row->waiting)->count()
            + self::eventsToSend()->count()
            + self::warningRows()->filter(fn ($row) => $row->waiting)->count();
    }

    public function openCutoffWarning(Customer $customer, string $channel): Response|RedirectResponse
    {
        $invoices = Invoice::where('customer_id', $customer->id)->overdue()->orderBy('period')->get();

        if ($invoices->isEmpty()) {
            return back()->with('error', __('Ce client n\'a plus de mois en retard.'));
        }

        if (! PhoneNumber::isInternational($customer->phone)) {
            return back()->with('error', __('Le numéro de ce client est absent ou à corriger.'));
        }

        $customer->forceFill(['cutoff_warning_opened_at' => now()])->save();

        return InvoiceMessage::open(InvoiceMessage::cutoffWarningLink($customer, $invoices, $channel));
    }

    public function openReminder(Invoice $invoice, string $channel): Response
    {
        $invoice->load('customer');

        if (! $invoice->canBeSent()) {
            return back()->with('error', __('Ce mois est déjà réglé.'));
        }

        if (! PhoneNumber::isInternational($invoice->customer->phone)) {
            return back()->with('error', __('Le numéro de ce client est absent ou à corriger.'));
        }

        $invoice->forceFill(['reminder_opened_at' => now()])->save();

        return InvoiceMessage::open(InvoiceMessage::reminderLink($invoice, $channel));
    }

    public function openPayment(Payment $payment, string $channel): Response
    {
        $payment->load(['customer', 'allocations.invoice']);

        if (! PhoneNumber::isInternational($payment->customer->phone)) {
            return back()->with('error', __('Le numéro de ce client est absent ou à corriger.'));
        }

        $payment->forceFill([$channel.'_opened_at' => now()])->save();

        return InvoiceMessage::open(InvoiceMessage::paymentLink($payment, $channel));
    }

    public function openLateNotice(Customer $customer, string $channel): Response|RedirectResponse
    {
        $invoices = Invoice::where('customer_id', $customer->id)->overdue()->orderBy('period')->get();

        if ($invoices->isEmpty()) {
            return back()->with('error', __('Ce client n\'a plus de mois en retard.'));
        }

        if (! PhoneNumber::isInternational($customer->phone)) {
            return back()->with('error', __('Le numéro de ce client est absent ou à corriger.'));
        }

        Invoice::whereKey($invoices->modelKeys())->update(['late_notice_opened_at' => now()]);

        return InvoiceMessage::open(InvoiceMessage::lateNoticeLink($customer, $invoices, $channel));
    }

    // L'avis de coupure ou la confirmation de réabonnement.
    public function openEvent(CustomerEvent $event, string $channel): Response|RedirectResponse
    {
        abort_unless(in_array($event->type, [CustomerEvent::SUSPENDED, CustomerEvent::RECONNECTED], true), 404);
        $customer = $event->customer;

        if ($event->type === CustomerEvent::SUSPENDED && ! $customer->isSuspended()) {
            return back()->with('error', __('Ce client n\'est plus coupé : l\'avis de coupure n\'a plus lieu d\'être.'));
        }

        if (! PhoneNumber::isInternational($customer->phone)) {
            return back()->with('error', __('Le numéro de ce client est absent ou à corriger.'));
        }

        $event->forceFill([$channel.'_opened_at' => now()])->save();

        return InvoiceMessage::open(InvoiceMessage::eventLink($customer, $event, $channel));
    }

    // On note l'ouverture de l'application : on ne peut pas savoir si le message est parti.
    public function open(Invoice $invoice, string $channel): Response
    {
        $invoice->load('customer');

        if (! $invoice->canBeSent()) {
            return back()->with('error', __('Ce mois est déjà réglé.'));
        }

        if (! PhoneNumber::isInternational($invoice->customer->phone)) {
            return back()->with('error', __('Le numéro de ce client est absent ou à corriger.'));
        }

        $invoice->forceFill([$channel.'_opened_at' => now()])->save();

        return InvoiceMessage::open(InvoiceMessage::invoiceLink($invoice, $channel));
    }

    private static function reminderDays(): int
    {
        return (int) auth()->user()->organisation->reminder_days;
    }
}
