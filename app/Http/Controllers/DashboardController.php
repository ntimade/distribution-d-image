<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TariffGroup;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $period = now()->format('Y-m');
        $month = Invoice::where('period', $period)->selectRaw('COALESCE(SUM(amount), 0) as billed, COALESCE(SUM(paid_amount), 0) as paid')->first();
        $statuses = Customer::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        // Le revenu mensuel attendu : le tarif de chaque client actif.
        $expected = Customer::active()->with('tariffGroup')->get()->sum(fn (Customer $customer) => $customer->effectiveFee());

        return view('dashboard', [
            'period' => $period,
            'customers' => (int) $statuses->sum(),
            'active' => (int) ($statuses[Customer::ACTIVE] ?? 0),
            'suspended' => (int) ($statuses[Customer::SUSPENDED] ?? 0),
            'terminated' => (int) ($statuses[Customer::TERMINATED] ?? 0),
            'groups' => TariffGroup::count(),
            'expected' => $expected,
            'billed' => (float) $month->billed,
            'collected' => (float) Payment::where('paid_at', '>=', now()->startOfMonth()->toDateString())
                ->where('paid_at', '<', now()->startOfMonth()->addMonthNoOverflow()->toDateString())->sum('amount'),
            'monthRemaining' => max(0, (float) $month->billed - (float) $month->paid),
            'overdueTotal' => (float) Invoice::overdue()->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'),
            'toCut' => CutoffController::toCut()->count(),
            'remindersWaiting' => InvoiceMessageController::remindersToSend()->count(),
            'upcoming' => Invoice::with('customer')->dueWithin(7)->orderBy('due_date')->limit(10)->get(),
            'recentPayments' => Payment::with('customer')->latest('paid_at')->latest('id')->limit(8)->get(),
        ]);
    }
}
