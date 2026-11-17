<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payment;
use App\Services\BillingService;
use App\Support\SortedTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(private BillingService $billing)
    {
    }

    public function index(Request $request): View
    {
        $query = Payment::query()
            ->when($request->query('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->query('method'), fn ($q, $method) => $q->where('method', $method))
            ->when($request->query('from'), fn ($q, $date) => $q->whereDate('paid_at', '>=', $date))
            ->when($request->query('to'), fn ($q, $date) => $q->whereDate('paid_at', '<=', $date));

        return view('payments.index', [
            'total' => (clone $query)->sum('amount'),
            'byMethod' => (clone $query)->selectRaw('method, COALESCE(SUM(amount), 0) as total')->groupBy('method')->pluck('total', 'method'),
            'payments' => SortedTable::query($query->with(['customer', 'allocations.invoice'])->latest('paid_at')->latest('id'), 'paiements', [
                'date' => 'paid_at',
                'customer' => DB::table('customers')->select('customers.name')->whereColumn('customers.id', 'payments.customer_id')->limit(1),
                'method' => 'method',
                'reference' => 'reference',
                'amount' => 'amount',
            ]),
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'number']),
            'methods' => config('payment_methods'),
        ]);
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validated($request);

        $payment = $this->billing->recordPayment($customer, (float) $data['amount'], $data['paid_at'], $data['method'] ?? null, $data['reference'] ?? null);

        return redirect()->route('customers.show', $customer)
            ->with('success', __('Paiement enregistré. Mois réglés : :v1.', ['v1' => $payment->coveredLabel()])
                .($customer->paidThrough() ? ' '.__('Abonnement payé jusqu\'au :v1', ['v1' => $customer->paidThrough()->format('d/m/Y')]).'.' : ''))
            ->with('notify_payment', $payment->id);
    }

    public function edit(Payment $payment): View
    {
        return view('payments.edit', ['payment' => $payment->load('customer', 'allocations.invoice'), 'methods' => config('payment_methods')]);
    }

    public function update(Request $request, Payment $payment): RedirectResponse
    {
        $data = $this->validated($request);

        $this->billing->updatePayment($payment, (float) $data['amount'], $data['paid_at'], $data['method'] ?? null, $data['reference'] ?? null);

        return redirect()->route('customers.show', $payment->customer_id)->with('success', __('Paiement corrigé. Les mois réglés ont été recalculés.'));
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $this->billing->cancelPayment($payment);

        return back()->with('success', __('Paiement annulé. Les mois réglés ont été recalculés.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], [], ['amount' => __('montant'), 'paid_at' => __('date du paiement')]);
    }
}
