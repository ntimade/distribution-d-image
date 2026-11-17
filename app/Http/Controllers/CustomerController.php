<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TariffGroup;
use App\Services\BillingService;
use App\Support\PhoneNumber;
use App\Support\SortedTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function __construct(private BillingService $billing)
    {
    }

    public function index(Request $request): View
    {
        $status = in_array($request->query('statut'), [Customer::ACTIVE, Customer::SUSPENDED, Customer::TERMINATED], true) ? $request->query('statut') : null;
        $groupId = (int) $request->query('groupe');
        $neighbourhood = (string) $request->query('quartier');

        $unpaid = Invoice::selectRaw('COALESCE(SUM(amount - paid_amount), 0)')->whereColumn('invoices.customer_id', 'customers.id');
        $overdue = Invoice::overdue()->selectRaw('COUNT(*)')->whereColumn('invoices.customer_id', 'customers.id');

        $query = Customer::query()
            ->search($request->query('q'))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($groupId, fn ($q) => $q->where('tariff_group_id', $groupId))
            ->when($neighbourhood !== '', fn ($q) => $q->where('neighbourhood', $neighbourhood));

        return view('customers.index', [
            'status' => $status,
            'groupId' => $groupId,
            'neighbourhood' => $neighbourhood,
            'counts' => Customer::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'groups' => TariffGroup::orderBy('name')->get(),
            'neighbourhoods' => self::neighbourhoods(),
            'customers' => SortedTable::query($query->with('tariffGroup')
                ->addSelect(['customers.*', 'unpaid' => $unpaid, 'overdue_months' => $overdue])
                ->orderBy('name'), 'clients', [
                    'number' => 'number',
                    'name' => 'name',
                    'neighbourhood' => 'neighbourhood',
                    'fee' => \Illuminate\Support\Facades\DB::raw('COALESCE(customers.monthly_fee, (SELECT tariff_groups.monthly_fee FROM tariff_groups WHERE tariff_groups.id = customers.tariff_group_id))'),
                    'unpaid' => 'unpaid',
                ]),
        ]);
    }

    public function create(Request $request): View
    {
        $organisation = $request->user()->organisation;

        return view('customers.form', [
            'customer' => new Customer([
                'due_day' => $organisation->default_due_day,
                'start_date' => today(),
                'tv_count' => 1,
                'tariff_group_id' => TariffGroup::count() === 1 ? TariffGroup::value('id') : null,
            ]),
            'groups' => TariffGroup::orderBy('name')->get(),
            'neighbourhoods' => self::neighbourhoods(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $this->billing->subscribe($this->validated($request, null));

        return redirect()->route('customers.show', $customer)->with('success', __('Client abonné. Ses factures sont créées chaque mois.'));
    }

    public function show(Customer $customer): View
    {
        $customer->load('tariffGroup');
        $justPaid = session('notify_payment') ? Payment::with(['customer', 'allocations.invoice'])->find(session('notify_payment')) : null;
        $justEvent = session('notify_event') ? CustomerEvent::find(session('notify_event')) : null;

        return view('customers.show', [
            'customer' => $customer,
            'justPaid' => $justPaid,
            'justEvent' => $justEvent,
            'unpaid' => $customer->unpaidTotal(),
            'events' => $customer->events()->with('user')->latest('happened_on')->latest('id')->get(),
            'invoices' => SortedTable::query(Invoice::where('customer_id', $customer->id)->orderByDesc('period'), 'factures', [
                'period' => 'period', 'due' => 'due_date', 'amount' => 'amount', 'paid' => 'paid_amount',
            ]),
            'payments' => SortedTable::query(Payment::where('customer_id', $customer->id)->with('allocations.invoice')->latest('paid_at')->latest('id'), 'paiements', [
                'date' => 'paid_at', 'method' => 'method', 'reference' => 'reference', 'amount' => 'amount',
            ]),
            'methods' => config('payment_methods'),
            'reconnectionFee' => (float) auth()->user()->organisation->reconnection_fee,
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('customers.form', [
            'customer' => $customer,
            'groups' => TariffGroup::orderBy('name')->get(),
            'neighbourhoods' => self::neighbourhoods(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->billing->updateCustomer($customer, $this->validated($request, $customer));

        return redirect()->route('customers.show', $customer)->with('success', __('Client modifié.'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        if ($customer->invoices()->exists() || $customer->payments()->withTrashed()->exists()) {
            return back()->with('error', __('Ce client a déjà des factures ou des paiements : résiliez son abonnement au lieu de le supprimer.'));
        }

        $customer->delete();

        return redirect()->route('customers.index')->with('success', __('Client supprimé.'));
    }

    public function suspend(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'suspended_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['suspended_on' => __('date de coupure')]);

        $this->billing->suspend($customer, $data['suspended_on'], $data['note'] ?? null);

        return redirect()->route('customers.show', $customer)->with('success', __('Client coupé : plus aucune facture jusqu\'au réabonnement.'))
            ->with('notify_event', $customer->lastSuspension()?->id);
    }

    public function terminate(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'terminated_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['terminated_on' => __('date de résiliation')]);

        $this->billing->terminate($customer, $data['terminated_on'], $data['note'] ?? null);

        return redirect()->route('customers.show', $customer)->with('success', __('Abonnement résilié. Ce qui reste dû reste à encaisser.'));
    }

    public function reconnect(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'reconnected_on' => ['required', 'date', 'before_or_equal:today'],
            'reconnection_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['reconnected_on' => __('date du réabonnement'), 'reconnection_fee' => __('frais de réabonnement')]);

        $this->billing->reconnect($customer, $data['reconnected_on'], (float) ($data['reconnection_fee'] ?? 0), $data['note'] ?? null);

        return redirect()->route('customers.show', $customer)->with('success', __('Client réabonné : la facturation reprend.'))
            ->with('notify_event', $customer->events()->where('type', CustomerEvent::RECONNECTED)->latest('id')->value('id'));
    }

    private function validated(Request $request, ?Customer $customer): array
    {
        $locked = $customer && $customer->invoices()->exists();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', 'in:M,F'],
            'phone' => ['nullable', 'string', 'max:50', function ($attribute, $value, $fail) {
                if (! PhoneNumber::isValid($value)) {
                    $fail(__('Saisissez un seul numéro de téléphone, avec des chiffres uniquement.'));
                }
            }],
            'email' => ['nullable', 'email', 'max:255'],
            'neighbourhood' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'tv_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'tariff_group_id' => ['nullable', 'integer', Rule::exists('tariff_groups', 'id')->where('organisation_id', $request->user()->organisation_id)],
            'monthly_fee' => ['nullable', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3', 'required_without:tariff_group_id'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'start_date' => ['nullable', 'date', 'before_or_equal:'.today()->addMonth()->toDateString()],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'monthly_fee.required_without' => __('Choisissez un groupe tarifaire ou saisissez un tarif personnel.'),
        ], [
            'name' => __('nom'), 'monthly_fee' => __('tarif personnel'), 'tariff_group_id' => __('groupe tarifaire'),
            'due_day' => __('jour d\'échéance'), 'start_date' => __('début de l\'abonnement'), 'tv_count' => __('nombre de téléviseurs'),
        ]);

        $data['phone'] = PhoneNumber::forStorage($data['phone'] ?? null, $request->user()->organisation->phone_country);
        $data['tariff_group_id'] = $data['tariff_group_id'] ?? null;
        $data['monthly_fee'] = isset($data['monthly_fee']) && $data['monthly_fee'] !== '' ? $data['monthly_fee'] : null;

        // Champs laissés vides : la valeur actuelle, ou celle par défaut pour un nouveau client.
        $data['tv_count'] = $data['tv_count'] ?? $customer?->tv_count ?? 1;
        $data['due_day'] = $data['due_day'] ?? $customer?->due_day ?? $request->user()->organisation->default_due_day;
        $data['start_date'] = $data['start_date'] ?? $customer?->start_date?->toDateString() ?? today()->toDateString();

        if ($locked) {
            unset($data['start_date']);
        }

        return $data;
    }

    // Les quartiers déjà saisis, proposés pendant la frappe.
    public static function neighbourhoods(): array
    {
        return Customer::whereNotNull('neighbourhood')->where('neighbourhood', '<>', '')->distinct()->orderBy('neighbourhood')->pluck('neighbourhood')->all();
    }
}
