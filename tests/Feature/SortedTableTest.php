<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organisation;
use App\Models\TariffGroup;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SortedTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->actingAs($this->account('a@t.test', 'Alpha'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $email, string $name): User
    {
        $organisation = Organisation::create(['name' => $name, 'code' => Organisation::generateCode($name), 'currency' => 'XAF']);

        return $organisation->users()->create(['name' => 'A', 'email' => $email, 'password' => 'secret-pass-1']);
    }

    private function names($table): array
    {
        return collect($table->items())->pluck('name')->all();
    }

    private function subscriber(): Customer
    {
        return app(BillingService::class)->subscribe(['name' => 'Jean', 'monthly_fee' => 1000, 'start_date' => '2026-09-01', 'due_day' => 1, 'tv_count' => 1]);
    }

    public function test_tables_show_ten_lines_and_keep_the_rest_on_other_pages(): void
    {
        foreach (range(1, 13) as $index) {
            Customer::create(['name' => sprintf('Client %02d', $index), 'monthly_fee' => 5000]);
        }

        $first = $this->get(route('customers.index'))->assertOk()->assertSee('1 / 2')->original->getData()['customers'];
        $this->assertCount(10, $first->items());
        $this->assertSame('Client 01', $first->items()[0]->name);

        $second = $this->get(route('customers.index', ['clients_page' => 2]))->assertOk()->original->getData()['customers'];
        $this->assertSame(['Client 11', 'Client 12', 'Client 13'], $this->names($second));
    }

    public function test_sorting_applies_to_every_line_not_only_the_page(): void
    {
        foreach (range(1, 13) as $index) {
            Customer::create(['name' => sprintf('Client %02d', $index), 'monthly_fee' => 5000]);
        }

        $table = $this->get(route('customers.index', ['clients_tri' => 'name', 'clients_ordre' => 'desc']))->assertOk()->original->getData()['customers'];

        $this->assertSame('Client 13', $table->items()[0]->name);
    }

    public function test_every_declared_column_can_be_sorted_in_both_directions(): void
    {
        $customer = $this->subscriber();
        app(BillingService::class)->recordPayment($customer, 500, '2026-11-02', 'cash', null);
        TariffGroup::create(['name' => 'Standard', 'monthly_fee' => 5000]);

        foreach ([
            [route('customers.index'), 'clients', ['number', 'name', 'neighbourhood', 'fee', 'unpaid']],
            [route('customers.show', $customer), 'factures', ['period', 'amount', 'paid', 'due']],
            [route('customers.show', $customer), 'paiements', ['date', 'method', 'reference', 'amount']],
            [route('groups.index'), 'groupes', ['name', 'fee', 'customers']],
            [route('invoices.index', ['period' => '2026-11']), 'factures', ['customer', 'amount', 'paid', 'due']],
            [route('unpaid.index'), 'impayes', ['name', 'neighbourhood', 'months', 'overdue', 'unpaid', 'due']],
            [route('cutoffs.index'), 'coupures', ['name', 'neighbourhood', 'months', 'unpaid', 'since']],
            [route('payments.index'), 'paiements', ['date', 'customer', 'method', 'reference', 'amount']],
            [route('messages.index', ['etat' => 'relances']), 'relances', ['customer', 'total', 'due']],
        ] as [$url, $table, $columns]) {
            foreach ($columns as $column) {
                foreach (['asc', 'desc'] as $direction) {
                    $separator = str_contains($url, '?') ? '&' : '?';
                    $this->get($url.$separator."{$table}_tri={$column}&{$table}_ordre={$direction}")->assertOk();
                }
            }
        }
    }

    public function test_unknown_sort_is_ignored(): void
    {
        Customer::create(['name' => 'Bravo', 'monthly_fee' => 5000]);
        Customer::create(['name' => 'Alpha', 'monthly_fee' => 5000]);

        foreach ([
            ['clients_tri' => 'name; DROP TABLE customers', 'clients_ordre' => 'desc'],
            ['clients_tri' => 'name', 'clients_ordre' => 'sideways'],
        ] as $query) {
            $table = $this->get(route('customers.index', $query))->assertOk()->original->getData()['customers'];
            $this->assertSame(['Alpha', 'Bravo'], $this->names($table));
        }
    }

    public function test_tables_of_a_page_keep_their_own_page_and_sort(): void
    {
        $customer = $this->subscriber();
        foreach (range(1, 3) as $index) {
            app(BillingService::class)->recordPayment($customer, 100 * $index, '2026-11-01', null, null);
        }

        $data = $this->get(route('customers.show', ['customer' => $customer, 'paiements_tri' => 'amount', 'paiements_ordre' => 'desc']))->assertOk()->original->getData();

        $this->assertSame(300.0, (float) $data['payments']->items()[0]->amount);
        $this->assertSame(1, $data['invoices']->currentPage());
    }

    public function test_sort_links_show_the_active_column(): void
    {
        Customer::create(['name' => 'Alpha', 'monthly_fee' => 5000]);

        $this->get(route('customers.index'))->assertSee('clients_tri=name&amp;clients_ordre=asc', false)->assertDontSee('aria-sort="ascending"', false);

        $this->get(route('customers.index', ['clients_tri' => 'name', 'clients_ordre' => 'asc']))
            ->assertSee('aria-sort="ascending"', false)->assertSee('href="'.e(route('customers.index', ['clients_tri' => 'name', 'clients_ordre' => 'desc'])).'#t-clients"', false);
    }
}
