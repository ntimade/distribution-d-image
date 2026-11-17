<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Support\SortedTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UnpaidController extends Controller
{
    public function index(Request $request): View
    {
        $neighbourhood = (string) $request->query('quartier');
        $rest = fn () => Invoice::unpaid()->selectRaw('COALESCE(SUM(amount - paid_amount), 0)')->whereColumn('invoices.customer_id', 'customers.id');

        return view('unpaid.index', [
            'neighbourhood' => $neighbourhood,
            'neighbourhoods' => CustomerController::neighbourhoods(),
            'overdueTotal' => (float) Invoice::overdue()->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'),
            'upcomingTotal' => (float) Invoice::unpaid()->where('due_date', '>=', today()->toDateString())->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'),
            'customers' => SortedTable::query(Customer::query()
                ->whereHas('invoices', fn ($invoice) => $invoice->unpaid())
                ->when($neighbourhood !== '', fn ($q) => $q->where('neighbourhood', $neighbourhood))
                ->search($request->query('q'))
                ->addSelect([
                    'customers.*',
                    'unpaid_total' => $rest(),
                    'overdue_total' => Invoice::overdue()->selectRaw('COALESCE(SUM(amount - paid_amount), 0)')->whereColumn('invoices.customer_id', 'customers.id'),
                    'overdue_months' => Invoice::overdue()->selectRaw('COUNT(*)')->whereColumn('invoices.customer_id', 'customers.id'),
                    'oldest_due' => Invoice::unpaid()->selectRaw('MIN(due_date)')->whereColumn('invoices.customer_id', 'customers.id'),
                ])
                ->orderByDesc('overdue_total')->orderBy('name'), 'impayes', [
                    'name' => 'name',
                    'neighbourhood' => 'neighbourhood',
                    'months' => 'overdue_months',
                    'overdue' => 'overdue_total',
                    'unpaid' => 'unpaid_total',
                    'due' => DB::raw('oldest_due'),
                ]),
        ]);
    }
}
