<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerHistoryController;
use App\Http\Controllers\CutoffController;
use App\Http\Controllers\InvoiceMessageController;
use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\Payment;
use App\Models\TariffGroup;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Scénarios de la vie courante d'un distributeur, de bout en bout.
class CableScenarioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');

        $organisation = Organisation::create(['name' => 'Cable Beta', 'code' => Organisation::generateCode('Cable Beta'), 'currency' => 'XAF', 'phone' => '+237690000000', 'phone_country' => 'CM']);
        $organisation->forceFill(['reconnection_fee' => 2000, 'cutoff_after_months' => 2, 'default_due_day' => 5])->save();
        $this->user = $organisation->users()->create(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1']);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function billing(): BillingService
    {
        return app(BillingService::class);
    }

    private function subscribe(array $data = []): Customer
    {
        return $this->billing()->subscribe($data + [
            'name' => 'Client Test', 'phone' => '+237699000000', 'monthly_fee' => 5000,
            'start_date' => '2026-11-01', 'due_day' => 5, 'tv_count' => 1,
        ]);
    }

    private function periods(Customer $customer): array
    {
        return $customer->invoices()->orderBy('period')->pluck('period')->all();
    }

    private function sync(string $now): void
    {
        Carbon::setTestNow($now);
        $this->billing()->syncOrganisation($this->user->organisation->fresh());
    }

    public function test_partial_payments_add_up_month_by_month(): void
    {
        $customer = $this->subscribe(['start_date' => '2026-10-01']);

        $this->post(route('payments.store', $customer), ['amount' => 3000, 'paid_at' => '2026-11-10', 'method' => 'cash'])->assertSessionHasNoErrors();
        $october = $customer->invoices()->where('period', '2026-10')->first();
        $this->assertSame('late', $october->statusKey());
        $this->assertSame(2000.0, $october->balance());

        $this->post(route('payments.store', $customer), ['amount' => 4000, 'paid_at' => '2026-11-10', 'method' => 'orange_money'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $october->fresh()->statusKey());
        $this->assertSame('late', $customer->invoices()->where('period', '2026-11')->first()->statusKey());
        $this->assertSame(3000.0, $customer->unpaidTotal());
    }

    public function test_correcting_a_payment_recomputes_the_months(): void
    {
        $customer = $this->subscribe();
        $payment = $this->billing()->recordPayment($customer, 15000, '2026-11-10', 'cash', null);
        $this->assertSame(['2026-11', '2026-12', '2027-01'], $this->periods($customer));

        $this->put(route('payments.update', $payment), ['amount' => 5000, 'paid_at' => '2026-11-10', 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->assertSame(['2026-11'], $this->periods($customer));
        $this->assertSame(0.0, $customer->unpaidTotal());
    }

    public function test_lowering_an_invoice_below_what_was_paid_moves_the_surplus_to_the_next_month(): void
    {
        $customer = $this->subscribe();
        $this->billing()->recordPayment($customer, 5000, '2026-11-10', 'cash', null);
        $november = $customer->invoices()->first();

        $this->put(route('invoices.update', $november), ['amount' => 3000, 'due_date' => '2026-11-05'])->assertSessionHasNoErrors();

        $december = $customer->invoices()->where('period', '2026-12')->firstOrFail();
        $this->assertSame(2000.0, (float) $december->paid_amount);
        $this->assertSame(3000.0, $december->balance());
    }

    public function test_due_day_31_falls_on_the_last_day_of_short_months(): void
    {
        $customer = $this->subscribe(['start_date' => '2027-01-15', 'due_day' => 31]);
        $this->sync('2027-03-10 08:00:00');

        $this->assertSame(
            ['2027-01-31', '2027-02-28', '2027-03-31'],
            $customer->invoices()->orderBy('period')->get()->map(fn ($invoice) => $invoice->due_date->toDateString())->all(),
        );
    }

    public function test_first_month_is_never_due_before_the_subscription_starts(): void
    {
        $customer = $this->subscribe(['start_date' => '2026-11-20', 'due_day' => 5]);

        $this->assertSame('2026-11-20', $customer->invoices()->first()->due_date->toDateString());
        $this->assertSame(0, $customer->overdueMonths());
    }

    public function test_changing_tariff_and_due_day_only_affects_future_invoices(): void
    {
        $group = TariffGroup::create(['name' => 'Premium', 'monthly_fee' => 8000]);
        $customer = $this->subscribe();

        $this->put(route('customers.update', $customer), [
            'name' => 'Client Test', 'phone' => '+237699000000', 'tariff_group_id' => $group->id, 'monthly_fee' => '', 'due_day' => 15, 'tv_count' => 2,
        ])->assertSessionHasNoErrors();

        $this->assertSame(5000.0, (float) $customer->invoices()->where('period', '2026-11')->value('amount'));
        $this->sync('2026-12-13 08:00:00');
        $december = $customer->invoices()->where('period', '2026-12')->firstOrFail();
        $this->assertSame(8000.0, (float) $december->amount);
        $this->assertSame('2026-12-15', $december->due_date->toDateString());
        $this->assertSame(1, $customer->events()->where('type', CustomerEvent::TARIFF_CHANGED)->count());
    }

    public function test_start_date_is_locked_once_months_are_invoiced(): void
    {
        $customer = $this->subscribe();

        $this->put(route('customers.update', $customer), ['name' => 'Client Test', 'monthly_fee' => 5000, 'start_date' => '2026-01-01'])->assertSessionHasNoErrors();

        $this->assertSame('2026-11-01', $customer->fresh()->start_date->toDateString());
        $this->assertSame(['2026-11'], $this->periods($customer));
    }

    public function test_cut_off_and_reconnection_in_the_same_month_adds_the_fee_once(): void
    {
        $customer = $this->subscribe();
        $this->billing()->suspend($customer, '2026-11-08', null);
        $this->billing()->reconnect($customer->fresh(), '2026-11-10', 2000, null);

        $this->assertSame(['2026-11'], $this->periods($customer));
        $november = $customer->invoices()->first();
        $this->assertSame(7000.0, (float) $november->amount);
        $this->assertSame(2000.0, (float) $november->reconnection_fee);
        $this->assertSame(5000.0, $november->subscriptionAmount());
    }

    public function test_fee_on_a_month_already_paid_is_due_on_the_reconnection_day(): void
    {
        $customer = $this->subscribe(['start_date' => '2026-10-01']);
        $this->billing()->suspend($customer, '2026-11-10', null);
        $this->billing()->recordPayment($customer->fresh(), 10000, '2026-11-10', 'cash', null);
        $this->billing()->reconnect($customer->fresh(), '2026-11-10', 2000, null);

        $november = $customer->invoices()->where('period', '2026-11')->first();
        $this->assertSame('2026-11-10', $november->due_date->toDateString());

        // Réabonné : l'avis de coupure n'est plus proposé, seule la confirmation du réabonnement reste.
        $events = $this->get(route('messages.index', ['etat' => 'coupures']))->original->getData()['events'];
        $this->assertSame([CustomerEvent::RECONNECTED], collect($events->items())->pluck('type')->all());
        $this->post(route('messages.event', [$customer->lastSuspension(), 'whatsapp']))->assertSessionHas('error');
        $this->assertSame(0, $customer->fresh()->overdueMonths());
        $this->assertSame(2000.0, $customer->unpaidTotal());
    }

    public function test_reconnection_without_fee_and_after_termination(): void
    {
        $customer = $this->subscribe(['start_date' => '2026-09-01']);
        $this->billing()->recordPayment($customer, 15000, '2026-11-01', 'cash', null);
        $this->billing()->terminate($customer->fresh(), '2026-11-05', 'Départ');
        $this->sync('2027-02-03 08:00:00');
        $this->assertSame(['2026-09', '2026-10', '2026-11'], $this->periods($customer));

        $this->post(route('customers.reconnect', $customer), ['reconnected_on' => '2027-02-03', 'reconnection_fee' => 0])->assertSessionHasNoErrors();

        $this->assertSame(['2026-09', '2026-10', '2026-11', '2027-02'], $this->periods($customer));
        $this->assertSame(5000.0, (float) $customer->invoices()->where('period', '2027-02')->value('amount'));
        $this->assertSame('2027-02-05', $customer->invoices()->where('period', '2027-02')->first()->due_date->toDateString());
    }

    public function test_months_paid_ahead_are_kept_through_a_cut_off(): void
    {
        $customer = $this->subscribe();
        $this->billing()->recordPayment($customer, 15000, '2026-11-10', 'cash', null);
        $this->billing()->suspend($customer->fresh(), '2026-11-10', 'À la demande du client');

        $this->assertSame(['2026-11', '2026-12', '2027-01'], $this->periods($customer));

        $this->sync('2027-03-10 08:00:00');
        $this->billing()->reconnect($customer->fresh(), '2027-03-10', 0, null);
        $this->assertSame(['2026-11', '2026-12', '2027-01', '2027-03'], $this->periods($customer));
        $this->assertSame(5000.0, $customer->unpaidTotal());
    }

    public function test_advance_is_limited_and_decimals_are_refused_in_xaf(): void
    {
        $customer = $this->subscribe();

        $this->post(route('payments.store', $customer), ['amount' => 5000 * 40, 'paid_at' => '2026-11-10'])->assertSessionHasErrors('amount');
        $this->post(route('payments.store', $customer), ['amount' => 5000.5, 'paid_at' => '2026-11-10'])->assertSessionHasErrors('amount');
        $this->post(route('payments.store', $customer), ['amount' => 5000, 'paid_at' => '2026-12-01'])->assertSessionHasErrors('paid_at');
        $this->assertSame(0, Payment::count());
        $this->assertSame(['2026-11'], $this->periods($customer));
    }

    public function test_cancelled_payment_stays_out_of_totals(): void
    {
        $customer = $this->subscribe();
        $payment = $this->billing()->recordPayment($customer, 5000, '2026-11-10', 'cash', null);

        $this->delete(route('payments.destroy', $payment))->assertSessionHas('success');

        $this->assertSame(5000.0, $customer->unpaidTotal());
        $this->assertSame(0.0, (float) Payment::sum('amount'));
        $this->get(route('payments.index'))->assertOk()->assertSee(__('Aucun paiement.'));
    }

    public function test_customer_without_phone_is_billed_but_listed_as_unreachable(): void
    {
        $customer = $this->subscribe(['phone' => null, 'start_date' => '2026-11-12', 'due_day' => 12]);

        $this->assertSame(1, $customer->invoices()->count());
        $this->assertSame(0, InvoiceMessageController::remindersToSend()->count());
        $this->get(route('messages.index'))->assertOk()->assertSee(__('Rappels impossibles : numéro absent ou à corriger'))->assertSee('Client Test');
        $this->post(route('messages.open', [$customer->invoices()->first(), 'whatsapp']))->assertSessionHas('error');
    }

    public function test_paid_month_cannot_be_sent_as_an_invoice_or_reminder(): void
    {
        $customer = $this->subscribe();
        $this->billing()->recordPayment($customer, 5000, '2026-11-10', 'cash', null);
        $invoice = $customer->invoices()->first();

        $this->post(route('messages.open', [$invoice, 'sms']))->assertSessionHas('error');
        $this->post(route('messages.reminder', [$invoice, 'whatsapp']))->assertSessionHas('error');
        $this->post(route('messages.late', [$customer, 'whatsapp']))->assertSessionHas('error');
        $this->assertNull($invoice->fresh()->sms_opened_at);
    }

    public function test_cut_off_list_follows_the_threshold_from_the_settings(): void
    {
        $customer = $this->subscribe(['start_date' => '2026-10-01']);
        $this->assertSame(1, CutoffController::toCut()->count());

        $this->put(route('settings.update'), [
            'name' => 'Cable Beta', 'currency' => 'XAF', 'reminder_days' => 3, 'phone_country' => 'CM',
            'default_due_day' => 5, 'cutoff_after_months' => 3, 'reconnection_fee' => 1500,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, CutoffController::toCut()->count());
        $this->get(route('customers.show', $customer))->assertOk()->assertDontSee(__('Préavis WhatsApp'));
        $this->get(route('customers.show', $customer->fresh()))->assertOk();
        $this->assertSame(1500.0, (float) $this->user->organisation->fresh()->reconnection_fee);
    }

    public function test_manager_handles_payments_and_cut_offs_but_not_settings(): void
    {
        $customer = $this->subscribe(['start_date' => '2026-10-01']);
        $manager = $this->user->organisation->users()->create(['name' => 'Agent', 'email' => 'agent@t.test', 'password' => 'secret-pass-1']);
        $manager->forceFill(['role' => User::MANAGER])->save();
        $this->actingAs($manager);

        $this->post(route('payments.store', $customer), ['amount' => 5000, 'paid_at' => '2026-11-10', 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->post(route('customers.suspend', $customer), ['suspended_on' => '2026-11-10'])->assertSessionHasNoErrors();
        $this->post(route('groups.store'), ['name' => 'Nouveau', 'monthly_fee' => 3000])->assertSessionHasNoErrors();
        $this->put(route('settings.update'), ['name' => 'X', 'currency' => 'XAF', 'reminder_days' => 3])->assertForbidden();

        $this->assertSame($manager->id, Payment::first()->user_id);
        $this->assertSame($manager->id, $customer->lastSuspension()->user_id);
    }

    public function test_history_shows_an_advance_as_a_negative_balance(): void
    {
        $customer = $this->subscribe();
        $this->billing()->recordPayment($customer, 12000, '2026-11-10', 'cash', null);

        $rows = CustomerHistoryController::rows($customer);
        $this->assertSame(['event', 'invoice', 'payment', 'invoice', 'invoice'], $rows->pluck('kind')->all());
        $this->assertSame(3000.0, $rows->last()->balance);

        // Avant la création des mois payés d'avance, le paiement fait passer le solde en avance.
        $this->assertSame(-7000.0, $rows[2]->balance);
    }

    public function test_english_messages_and_pages(): void
    {
        $customer = $this->subscribe(['start_date' => '2026-10-01']);
        app()->setLocale('en');

        $warning = \App\Support\InvoiceMessage::cutoffWarning($customer, $customer->invoices()->overdue()->get());
        $this->assertStringContainsString('CUT-OFF WARNING', $warning);
        $this->assertStringContainsString('If you have already paid, contact us on +237690000000', $warning);

        $this->withUnencryptedCookie('locale', 'en')->get(route('customers.show', $customer))->assertOk()
            ->assertSee('Signal and subscription')->assertDontSee('Signal et abonnement');
        $this->withUnencryptedCookie('locale', 'en')->get(route('cutoffs.index'))->assertOk()->assertSee('To cut off');
    }

    public function test_filters_on_lists(): void
    {
        $group = TariffGroup::create(['name' => 'Essentiel', 'monthly_fee' => 3000]);
        $this->subscribe(['name' => 'Akwa Un', 'neighbourhood' => 'Akwa', 'tariff_group_id' => $group->id, 'monthly_fee' => null]);
        $this->subscribe(['name' => 'Deido Deux', 'neighbourhood' => 'Deïdo', 'start_date' => '2026-10-01']);

        $this->get(route('customers.index', ['quartier' => 'Akwa']))->assertSee('Akwa Un')->assertDontSee('Deido Deux');
        $this->get(route('customers.index', ['groupe' => $group->id]))->assertSee('Akwa Un')->assertDontSee('Deido Deux');
        $this->get(route('customers.index', ['q' => '0002']))->assertSee('Deido Deux')->assertDontSee('Akwa Un');
        $this->get(route('unpaid.index', ['quartier' => 'Deïdo']))->assertSee('Deido Deux')->assertDontSee('Akwa Un');
        $this->get(route('map.index', ['quartier' => 'Akwa']))->assertOk();
        $this->get(route('payments.index', ['method' => 'cash']))->assertOk();
    }

    public function test_group_with_members_cannot_be_deleted_but_personal_price_members_are_detached(): void
    {
        $group = TariffGroup::create(['name' => 'Confort', 'monthly_fee' => 5000]);
        $member = $this->subscribe(['tariff_group_id' => $group->id, 'monthly_fee' => null]);

        $this->delete(route('groups.destroy', $group))->assertSessionHas('error');

        $member->update(['monthly_fee' => 4500]);
        $this->delete(route('groups.destroy', $group))->assertSessionHas('success');
        $this->assertNull($member->fresh()->tariff_group_id);
        $this->assertSame(4500.0, $member->fresh()->effectiveFee());
    }

    public function test_next_months_keep_coming_over_a_year(): void
    {
        $customer = $this->subscribe();

        foreach (range(1, 12) as $month) {
            $this->sync(Carbon::parse('2026-11-10')->addMonthsNoOverflow($month)->toDateTimeString());
            $this->billing()->recordPayment($customer->fresh(), 5000, today()->toDateString(), 'mtn_momo', null);
        }

        $this->assertCount(13, $this->periods($customer));
        $this->assertSame(5000.0, $customer->unpaidTotal());
        // Chaque paiement règle le mois le plus ancien : il reste un seul mois dû, le dernier.
        $this->assertSame(['2027-11'], $customer->invoices()->unpaid()->pluck('period')->all());
    }
}
