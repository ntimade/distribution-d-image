<?php

namespace Tests\Feature;

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

class CableBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');

        $organisation = Organisation::create(['name' => 'Cable Alpha', 'code' => Organisation::generateCode('Cable Alpha'), 'currency' => 'XAF', 'phone' => '+237690000000', 'phone_country' => 'CM']);
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
            'name' => 'Jean Abonné', 'phone' => '+237699000000', 'monthly_fee' => 5000,
            'start_date' => '2026-09-01', 'due_day' => 5, 'tv_count' => 1,
        ]);
    }

    public function test_a_new_subscriber_is_billed_every_month_until_now(): void
    {
        $customer = $this->subscribe();

        $this->assertSame(['2026-09', '2026-10', '2026-11'], $customer->invoices()->orderBy('period')->pluck('period')->all());
        $this->assertSame(15000.0, $customer->unpaidTotal());
        $this->assertSame(3, $customer->overdueMonths());
        $this->assertSame(1, $customer->number);
        $this->assertSame(CustomerEvent::SUBSCRIBED, $customer->events()->value('type'));
    }

    public function test_next_month_appears_when_its_reminder_is_due(): void
    {
        $customer = $this->subscribe();
        Carbon::setTestNow('2026-12-02 08:00:00');
        $this->billing()->syncOrganisation($this->user->organisation);

        // Échéance du 5 décembre, rappel 3 jours avant : la facture existe déjà le 2.
        $this->assertTrue($customer->invoices()->where('period', '2026-12')->exists());
        $this->assertFalse($customer->invoices()->where('period', '2027-01')->exists());
    }

    public function test_personal_price_takes_precedence_over_the_group(): void
    {
        $group = TariffGroup::create(['name' => 'Standard', 'monthly_fee' => 4000]);
        $grouped = $this->subscribe(['name' => 'Groupe', 'monthly_fee' => null, 'tariff_group_id' => $group->id, 'start_date' => '2026-11-01']);
        $personal = $this->subscribe(['name' => 'Perso', 'monthly_fee' => 3000, 'tariff_group_id' => $group->id, 'start_date' => '2026-11-01']);

        $this->assertSame(4000.0, (float) $grouped->invoices()->value('amount'));
        $this->assertSame(3000.0, (float) $personal->invoices()->value('amount'));

        // Le nouveau tarif du groupe vaut pour les factures suivantes, pas pour celles qui existent.
        $this->put(route('groups.update', $group), ['name' => 'Standard', 'monthly_fee' => 4500])->assertSessionHasNoErrors();
        $this->assertSame(4000.0, (float) $grouped->invoices()->value('amount'));
        $this->assertSame(4500.0, $grouped->fresh()->effectiveFee());
        $this->assertSame(3000.0, $personal->fresh()->effectiveFee());
        $this->assertTrue($grouped->events()->where('type', CustomerEvent::TARIFF_CHANGED)->exists());
        $this->assertFalse($personal->events()->where('type', CustomerEvent::TARIFF_CHANGED)->exists());
    }

    public function test_customer_needs_a_group_or_a_personal_price(): void
    {
        $this->post(route('customers.store'), ['name' => 'Sans tarif'])->assertSessionHasErrors('monthly_fee');

        $group = TariffGroup::create(['name' => 'Standard', 'monthly_fee' => 4000]);
        $this->post(route('customers.store'), ['name' => 'Avec groupe', 'tariff_group_id' => $group->id, 'neighbourhood' => 'Bépanda', 'latitude' => 4.06, 'longitude' => 9.71])
            ->assertSessionHasNoErrors();

        $customer = Customer::where('name', 'Avec groupe')->firstOrFail();
        $this->assertSame(5, $customer->due_day);
        $this->assertTrue($customer->hasPosition());
        $this->assertSame(4000.0, (float) $customer->invoices()->value('amount'));
    }

    public function test_a_group_from_another_account_is_refused(): void
    {
        $other = Organisation::create(['name' => 'Autre', 'code' => Organisation::generateCode('Autre'), 'currency' => 'XAF']);
        $foreign = new TariffGroup(['name' => 'Étranger', 'monthly_fee' => 100]);
        $foreign->organisation_id = $other->id;
        $foreign->save();

        $this->post(route('customers.store'), ['name' => 'X', 'tariff_group_id' => $foreign->id])->assertSessionHasErrors('tariff_group_id');
    }

    public function test_payment_settles_oldest_months_first_then_pays_ahead(): void
    {
        $customer = $this->subscribe();

        $payment = $this->billing()->recordPayment($customer, 30000, '2026-11-10', 'orange_money', 'OM123');

        $this->assertSame(0.0, $customer->unpaidTotal());
        $this->assertSame(['2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02'], $customer->invoices()->orderBy('period')->pluck('period')->all());
        $this->assertSame('2027-03-04', $customer->paidThrough()->toDateString());
        $this->assertSame('Orange Money', $payment->methodLabel());

        // Annuler le paiement fait disparaître les mois à venir qui n'étaient payés que d'avance.
        $this->billing()->cancelPayment($payment);
        $this->assertSame(['2026-09', '2026-10', '2026-11'], $customer->invoices()->orderBy('period')->pluck('period')->all());
        $this->assertSame(15000.0, $customer->unpaidTotal());
    }

    public function test_cut_off_stops_billing_and_reconnection_resumes_with_a_fee(): void
    {
        $customer = $this->subscribe();
        $this->assertSame(1, \App\Http\Controllers\CutoffController::toCut()->count());

        $this->post(route('customers.suspend', $customer), ['suspended_on' => '2026-11-10', 'note' => '2 mois impayés'])
            ->assertRedirect(route('customers.show', $customer))->assertSessionHas('notify_event');
        $customer->refresh();
        $this->assertTrue($customer->isSuspended());
        $this->assertSame(0, \App\Http\Controllers\CutoffController::toCut()->count());

        // Pendant la coupure, aucun mois n'est facturé.
        Carbon::setTestNow('2027-01-20 09:00:00');
        $this->billing()->syncOrganisation($this->user->organisation->fresh());
        $this->assertSame(['2026-09', '2026-10', '2026-11'], $customer->invoices()->orderBy('period')->pluck('period')->all());
        $this->assertSame('2026-09-05', $customer->nextDueDate()->toDateString());

        // Un paiement qui dépasse la dette d'un client coupé est refusé : il faut d'abord le réabonner.
        $this->post(route('payments.store', $customer), ['amount' => 20000, 'paid_at' => '2027-01-20', 'method' => 'cash'])->assertSessionHasErrors('amount');

        $this->post(route('customers.reconnect', $customer), ['reconnected_on' => '2027-01-20', 'reconnection_fee' => 2000])->assertSessionHasNoErrors();
        $customer->refresh();
        $this->assertTrue($customer->isActive());

        $january = $customer->invoices()->where('period', '2027-01')->firstOrFail();
        $this->assertSame(7000.0, (float) $january->amount);
        $this->assertSame(2000.0, (float) $january->reconnection_fee);
        $this->assertSame('2027-01-20', $january->due_date->toDateString());
        $this->assertFalse($customer->invoices()->where('period', '2026-12')->exists());
        $this->assertSame(22000.0, $customer->unpaidTotal());
        $this->assertSame(
            [CustomerEvent::SUBSCRIBED, CustomerEvent::SUSPENDED, CustomerEvent::RECONNECTED],
            $customer->events()->orderBy('id')->pluck('type')->all(),
        );
    }

    public function test_termination_keeps_the_debt_and_removes_unpaid_future_months(): void
    {
        $customer = $this->subscribe(['start_date' => '2026-11-01']);
        Carbon::setTestNow('2026-12-03 09:00:00');
        $this->billing()->syncOrganisation($this->user->organisation->fresh());
        $this->assertTrue($customer->invoices()->where('period', '2026-12')->exists());

        $this->post(route('customers.terminate', $customer), ['terminated_on' => '2026-11-30'])->assertSessionHasNoErrors();

        $this->assertTrue($customer->fresh()->isTerminated());
        $this->assertSame(['2026-11'], $customer->invoices()->pluck('period')->all());
        $this->assertSame(5000.0, $customer->unpaidTotal());
    }

    public function test_dates_before_the_subscription_are_refused(): void
    {
        $customer = $this->subscribe();

        $this->post(route('customers.suspend', $customer), ['suspended_on' => '2026-08-01'])->assertSessionHasErrors('suspended_on');
        $this->post(route('customers.reconnect', $customer), ['reconnected_on' => '2026-11-10'])->assertSessionHasErrors('reconnected_on');
    }

    public function test_messages_are_ready_to_send_without_emoji_and_sms_fit_in_one_message(): void
    {
        $customer = $this->subscribe(['name' => 'Émilie Ngo Bassong', 'gender' => 'F']);
        $invoice = $customer->invoices()->where('period', '2026-11')->firstOrFail();

        $whatsapp = \App\Support\InvoiceMessage::whatsapp($invoice);
        $this->assertStringContainsString('━', $whatsapp);
        $this->assertStringContainsString('Madame *Émilie Ngo Bassong*', $whatsapp);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $whatsapp);
        $this->assertStringContainsString('contactez-nous au +237690000000 pour mettre votre compte à jour', \App\Support\InvoiceMessage::reminder($invoice));

        $payment = $this->billing()->recordPayment($customer, 5000, '2026-11-10', 'mtn_momo', 'MP-77');
        $customer->refresh();
        $event = $customer->recordEvent(CustomerEvent::SUSPENDED, '2026-11-10', 'Impayés');

        foreach ([
            \App\Support\InvoiceMessage::sms($invoice),
            \App\Support\InvoiceMessage::reminderSms($invoice),
            \App\Support\InvoiceMessage::paymentSms($payment->load('customer')),
            \App\Support\InvoiceMessage::lateNoticeSms($customer, $customer->invoices()->overdue()->get()),
            \App\Support\InvoiceMessage::cutoffSms($customer, $event),
            \App\Support\InvoiceMessage::reconnectionSms($customer, $event),
        ] as $sms) {
            $this->assertLessThanOrEqual(160, strlen($sms), $sms);
            $this->assertSame($sms, \Illuminate\Support\Str::ascii($sms));
        }
    }

    public function test_whatsapp_and_sms_buttons_open_the_prepared_message(): void
    {
        $customer = $this->subscribe();
        $invoice = $customer->invoices()->where('period', '2026-11')->firstOrFail();

        $response = $this->post(route('messages.open', [$invoice, 'whatsapp']));
        $this->assertStringStartsWith('https://wa.me/237699000000?text=', $response->headers->get('Location'));
        $this->assertNotNull($invoice->fresh()->whatsapp_opened_at);

        $late = $this->post(route('messages.late', [$customer, 'sms']));
        $this->assertStringStartsWith('sms:+237699000000?&body=', $late->headers->get('Location'));
        $this->assertSame(3, Invoice::whereNotNull('late_notice_opened_at')->count());

        $this->post(route('customers.suspend', $customer), ['suspended_on' => '2026-11-10']);
        $event = $customer->lastSuspension();
        $this->get(route('messages.index', ['etat' => 'coupures']))->assertOk()->assertSee($customer->name);
        $cut = $this->post(route('messages.event', [$event, 'whatsapp']));
        $this->assertStringContainsString(rawurlencode('AVIS DE COUPURE'), $cut->headers->get('Location'));
        $this->get(route('messages.index', ['etat' => 'coupures']))->assertOk()->assertDontSee($customer->name);
    }

    public function test_customer_past_the_cut_off_threshold_gets_a_warning_to_send(): void
    {
        $customer = $this->subscribe();
        $this->post(route('messages.late', [$customer, 'whatsapp']));

        // La relance est partie, mais le préavis de coupure attend toujours à l'étape 5.
        $page = $this->get(route('messages.index', ['etat' => 'coupures']))->assertOk()->assertSee('Jean Abonné');
        $this->assertSame(1, $page->original->getData()['counts']['coupures']);

        $sms = \App\Support\InvoiceMessage::cutoffWarningSms($customer, $customer->invoices()->overdue()->get());
        $this->assertLessThanOrEqual(160, strlen($sms), $sms);

        $response = $this->post(route('messages.cutoff_warning', [$customer, 'whatsapp']));
        $this->assertStringContainsString(rawurlencode('PRÉAVIS DE COUPURE'), $response->headers->get('Location'));
        $this->assertNotNull($customer->fresh()->cutoff_warning_opened_at);
        $this->assertSame(0, $this->get(route('messages.index', ['etat' => 'coupures']))->original->getData()['counts']['coupures']);

        // Un nouveau mois en retard remet le préavis en attente.
        Carbon::setTestNow('2026-12-06 09:00:00');
        $this->billing()->syncOrganisation($this->user->organisation->fresh());
        $this->assertSame(1, $this->get(route('messages.index', ['etat' => 'coupures']))->original->getData()['counts']['coupures']);

        // Au-dessous du seuil, pas de préavis.
        $this->billing()->recordPayment($customer, 15000, '2026-12-06', 'cash', null);
        $this->assertSame(0, $this->get(route('messages.index', ['etat' => 'coupures']))->original->getData()['counts']['coupures']);
    }

    public function test_reminders_list_the_months_due_soon_but_not_those_paid_ahead(): void
    {
        $paid = $this->subscribe(['name' => 'Payé', 'start_date' => '2026-11-01', 'due_day' => 12]);
        $due = $this->subscribe(['name' => 'Dû', 'start_date' => '2026-11-01', 'due_day' => 12]);
        $this->billing()->recordPayment($paid, 5000, '2026-11-09', 'cash', null);

        $this->get(route('messages.index'))->assertOk()->assertSee('Dû')->assertDontSee('>Payé<', false);
        $this->assertSame(1, \App\Http\Controllers\InvoiceMessageController::remindersToSend()->count());
    }

    public function test_history_lists_every_step_with_the_running_balance_and_exports_a_pdf(): void
    {
        $customer = $this->subscribe();
        $this->billing()->recordPayment($customer, 7000, '2026-10-02', 'cash', null);

        $rows = \App\Http\Controllers\CustomerHistoryController::rows($customer);
        $this->assertSame(8000.0, $rows->last()->balance);
        $this->assertSame('event', $rows->first()->kind);

        $this->get(route('customers.history', $customer))->assertOk()->assertSee(__('Abonnement'))->assertSee('8 000 XAF');
        $this->get(route('customers.history.pdf', $customer))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('invoices.pdf', $customer->invoices()->first()))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pages_open_and_show_the_customer(): void
    {
        $group = TariffGroup::create(['name' => 'Premium', 'monthly_fee' => 8000]);
        $customer = $this->subscribe(['tariff_group_id' => $group->id, 'neighbourhood' => 'Akwa', 'latitude' => 4.05, 'longitude' => 9.7]);

        foreach (['dashboard', 'customers.index', 'groups.index', 'map.index', 'cutoffs.index', 'unpaid.index', 'invoices.index', 'payments.index', 'messages.index', 'customers.create', 'groups.create'] as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->get(route('customers.show', $customer))->assertOk()->assertSee('Jean Abonné')->assertSee(__('Tarif personnel (groupe :v1 ignoré)', ['v1' => 'Premium']));
        $this->get(route('customers.edit', $customer))->assertOk();
        $this->get(route('map.index'))->assertSee('Jean Abonn');
        $this->get(route('cutoffs.index'))->assertSee('Jean Abonné');
        $this->get(route('invoices.month', ['period' => '2026-11']))->assertOk()->assertSee(__('Facture câble TV'));
    }

    public function test_another_account_cannot_see_or_touch_a_customer(): void
    {
        $customer = $this->subscribe();
        $other = Organisation::create(['name' => 'Autre', 'code' => Organisation::generateCode('Autre'), 'currency' => 'XAF']);
        $this->actingAs($other->users()->create(['name' => 'B', 'email' => 'b@t.test', 'password' => 'secret-pass-1']));

        $this->get(route('customers.show', $customer))->assertNotFound();
        $this->post(route('payments.store', $customer), ['amount' => 5000, 'paid_at' => '2026-11-10'])->assertNotFound();
        $this->post(route('customers.suspend', $customer), ['suspended_on' => '2026-11-10'])->assertNotFound();
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_customer_with_invoices_cannot_be_deleted(): void
    {
        $customer = $this->subscribe();
        $this->delete(route('customers.destroy', $customer))->assertSessionHas('error');
        $this->assertNotNull($customer->fresh());

        $group = TariffGroup::create(['name' => 'Vide', 'monthly_fee' => 1000]);
        $this->delete(route('groups.destroy', $group))->assertSessionHas('success');
    }
}
