<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Support\SortedTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

// Les coupures : les clients à couper (trop de mois en retard) et ceux qui sont coupés.
class CutoffController extends Controller
{
    private const STATES = ['a-couper', 'coupes', 'resilies'];

    public function index(Request $request): View
    {
        $state = in_array($request->query('etat'), self::STATES, true) ? $request->query('etat') : self::STATES[0];
        $threshold = self::threshold();

        $columns = [
            'unpaid_total' => Invoice::unpaid()->selectRaw('COALESCE(SUM(amount - paid_amount), 0)')->whereColumn('invoices.customer_id', 'customers.id'),
            'overdue_months' => Invoice::overdue()->selectRaw('COUNT(*)')->whereColumn('invoices.customer_id', 'customers.id'),
            'oldest_due' => Invoice::overdue()->selectRaw('MIN(due_date)')->whereColumn('invoices.customer_id', 'customers.id'),
        ];

        $query = match ($state) {
            'coupes' => Customer::where('status', Customer::SUSPENDED)->orderByDesc('status_since'),
            'resilies' => Customer::where('status', Customer::TERMINATED)->orderByDesc('status_since'),
            default => self::toCut()->orderBy('name'),
        };

        return view('cutoffs.index', [
            'state' => $state,
            'threshold' => $threshold,
            'counts' => [
                'a-couper' => self::toCut()->count(),
                'coupes' => Customer::where('status', Customer::SUSPENDED)->count(),
                'resilies' => Customer::where('status', Customer::TERMINATED)->count(),
            ],
            'customers' => SortedTable::query($query->addSelect(['customers.*', ...$columns]), 'coupures', [
                'name' => 'name',
                'neighbourhood' => 'neighbourhood',
                'months' => 'overdue_months',
                'unpaid' => 'unpaid_total',
                'since' => 'status_since',
            ]),
            'reconnectionFee' => (float) auth()->user()->organisation->reconnection_fee,
        ]);
    }

    // Les clients actifs qui ont au moins le nombre de mois en retard réglé dans les paramètres.
    public static function toCut(): Builder
    {
        return Customer::active()->whereHas('invoices', fn (Builder $invoice) => $invoice->overdue(), '>=', self::threshold());
    }

    public static function threshold(): int
    {
        return max(1, (int) auth()->user()->organisation->cutoff_after_months);
    }
}
