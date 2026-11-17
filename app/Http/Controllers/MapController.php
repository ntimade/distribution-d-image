<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MapController extends Controller
{
    public function index(Request $request): View
    {
        $neighbourhood = (string) $request->query('quartier');

        $customers = Customer::query()
            ->with('tariffGroup')
            ->when($neighbourhood !== '', fn ($q) => $q->where('neighbourhood', $neighbourhood))
            ->addSelect([
                'customers.*',
                'unpaid_total' => Invoice::unpaid()->selectRaw('COALESCE(SUM(amount - paid_amount), 0)')->whereColumn('invoices.customer_id', 'customers.id'),
                'overdue_months' => Invoice::overdue()->selectRaw('COUNT(*)')->whereColumn('invoices.customer_id', 'customers.id'),
            ])
            ->orderBy('name')->get();

        $placed = $customers->filter(fn (Customer $customer) => $customer->hasPosition());

        return view('map.index', [
            'neighbourhood' => $neighbourhood,
            'neighbourhoods' => CustomerController::neighbourhoods(),
            'withoutPosition' => $customers->count() - $placed->count(),
            'points' => $placed->values()->map(fn (Customer $customer) => [
                'lat' => (float) $customer->latitude,
                'lng' => (float) $customer->longitude,
                'name' => $customer->numberLabel().' — '.$customer->name,
                'place' => $customer->place(),
                'phone' => $customer->phone,
                'fee' => money($customer->effectiveFee()),
                'unpaid' => money($customer->unpaid_total),
                'state' => $customer->statusLabel().((int) $customer->overdue_months > 0 && $customer->isActive() ? ' — '.__(':v1 mois en retard', ['v1' => $customer->overdue_months]) : ''),
                'status' => match (true) {
                    $customer->isTerminated() => 'terminated',
                    $customer->isSuspended() => 'suspended',
                    (int) $customer->overdue_months > 0 => 'late',
                    default => 'ok',
                },
                'url' => route('customers.show', $customer),
            ]),
        ]);
    }
}
