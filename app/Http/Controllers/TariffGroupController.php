<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\TariffGroup;
use App\Services\BillingService;
use App\Support\SortedTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TariffGroupController extends Controller
{
    public function index(): View
    {
        return view('groups.index', [
            'groups' => SortedTable::query(TariffGroup::query()
                ->withCount([
                    'customers',
                    'customers as active_count' => fn ($customer) => $customer->active(),
                    'customers as own_fee_count' => fn ($customer) => $customer->whereNotNull('monthly_fee'),
                ])
                ->orderBy('name'), 'groupes', [
                    'name' => 'name',
                    'fee' => 'monthly_fee',
                    'customers' => 'customers_count',
                ]),
            'withoutGroup' => Customer::whereNull('tariff_group_id')->count(),
        ]);
    }

    public function create(): View
    {
        return view('groups.form', ['group' => new TariffGroup]);
    }

    public function store(Request $request): RedirectResponse
    {
        TariffGroup::create($this->validated($request, null));

        return redirect()->route('groups.index')->with('success', __('Groupe tarifaire ajouté.'));
    }

    public function show(TariffGroup $group): RedirectResponse
    {
        return redirect()->route('customers.index', ['groupe' => $group->id]);
    }

    public function edit(TariffGroup $group): View
    {
        return view('groups.form', ['group' => $group->loadCount('customers')]);
    }

    // Le nouveau tarif vaut pour les prochaines factures des membres qui n'ont pas de tarif personnel.
    public function update(Request $request, TariffGroup $group, BillingService $billing): RedirectResponse
    {
        $previous = (float) $group->monthly_fee;
        $group->update($this->validated($request, $group));

        if ((float) $group->monthly_fee !== $previous) {
            foreach ($group->customers()->whereNull('monthly_fee')->get() as $customer) {
                $customer->recordEvent(\App\Models\CustomerEvent::TARIFF_CHANGED, today(), __('Tarif du groupe :v1 : :v2 → :v3', [
                    'v1' => $group->name, 'v2' => money($previous), 'v3' => money($group->monthly_fee),
                ]), (float) $group->monthly_fee);
            }
        }

        return redirect()->route('groups.index')->with('success', __('Groupe tarifaire modifié. Le nouveau tarif vaut pour les prochaines factures.'));
    }

    public function destroy(TariffGroup $group): RedirectResponse
    {
        if ($group->customers()->whereNull('monthly_fee')->exists()) {
            return back()->with('error', __('Des clients sans tarif personnel appartiennent à ce groupe : changez-les de groupe avant de le supprimer.'));
        }

        $group->customers()->update(['tariff_group_id' => null]);
        $group->delete();

        return redirect()->route('groups.index')->with('success', __('Groupe tarifaire supprimé.'));
    }

    private function validated(Request $request, ?TariffGroup $group): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('tariff_groups')->where('organisation_id', $request->user()->organisation_id)->ignore($group?->id)],
            'monthly_fee' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => __('nom'), 'monthly_fee' => __('tarif mensuel')]);
    }
}
