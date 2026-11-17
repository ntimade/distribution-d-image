<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\Invoice;
use App\Models\Payment;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

// L'historique complet d'un client : abonnement, factures, paiements, coupures et réabonnements, avec le solde.
class CustomerHistoryController extends Controller
{
    public function show(Customer $customer): View
    {
        return view('customers.history', $this->data($customer));
    }

    public function pdf(Customer $customer): Response
    {
        $pdf = new Dompdf(new Options(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]));
        $pdf->loadHtml(view('customers.history-pdf', $this->data($customer) + ['organisation' => auth()->user()->organisation])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.Str::slug(__('Historique').' '.$customer->number.' '.$customer->name).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function data(Customer $customer): array
    {
        $customer->load('tariffGroup');

        return [
            'customer' => $customer,
            'rows' => self::rows($customer),
            'billed' => (float) $customer->invoices()->sum('amount'),
            'paid' => (float) $customer->payments()->sum('amount'),
            'unpaid' => $customer->unpaidTotal(),
        ];
    }

    /**
     * Les lignes dans l'ordre des dates : une facture ajoute au solde dû, un paiement le réduit.
     * Le solde positif est ce que le client doit ; négatif, c'est une avance.
     */
    public static function rows(Customer $customer): Collection
    {
        $rows = collect();

        foreach ($customer->events()->with('user')->get() as $event) {
            $rows->push((object) ['date' => $event->happened_on, 'order' => 0, 'id' => $event->id, 'kind' => 'event', 'badge' => $event->badge(),
                'label' => $event->label(), 'detail' => collect([
                    $event->type === CustomerEvent::RECONNECTED && (float) $event->amount > 0 ? __('Frais : :v1', ['v1' => money($event->amount)]) : null,
                    $event->type === CustomerEvent::SUSPENDED && (float) $event->amount > 0 ? __('Dû à la coupure : :v1', ['v1' => money($event->amount)]) : null,
                    $event->note,
                ])->filter()->implode(' — '), 'debit' => null, 'credit' => null]);
        }

        foreach ($customer->invoices()->get() as $invoice) {
            $rows->push((object) ['date' => self::invoiceDate($invoice), 'order' => 1, 'id' => $invoice->id, 'kind' => 'invoice', 'badge' => null,
                'label' => __('Facture :v1', ['v1' => $invoice->label()]),
                'detail' => $invoice->number().((float) $invoice->reconnection_fee > 0 ? ' — '.__('dont réabonnement :v1', ['v1' => money($invoice->reconnection_fee)]) : ''),
                'debit' => (float) $invoice->amount, 'credit' => null, 'invoice' => $invoice]);
        }

        foreach ($customer->payments()->with('allocations.invoice')->get() as $payment) {
            $rows->push((object) ['date' => $payment->paid_at, 'order' => 2, 'id' => $payment->id, 'kind' => 'payment', 'badge' => null,
                'label' => __('Paiement — :v1', ['v1' => $payment->methodLabel()]),
                'detail' => collect([$payment->reference, __('Mois réglés : :v1', ['v1' => $payment->coveredLabel()])])->filter()->implode(' — '),
                'debit' => null, 'credit' => (float) $payment->amount]);
        }

        $balance = 0.0;

        return $rows->sortBy([['date', 'asc'], ['order', 'asc'], ['id', 'asc']])->values()->map(function ($row) use (&$balance) {
            $balance = round($balance + (float) $row->debit - (float) $row->credit, 3);
            $row->balance = $row->kind === 'event' ? null : $balance;

            return $row;
        });
    }

    // Une facture compte au premier jour de son mois : elle passe avant les paiements du mois.
    private static function invoiceDate(Invoice $invoice)
    {
        return \Illuminate\Support\Carbon::createFromFormat('!Y-m', $invoice->period)->min($invoice->due_date);
    }
}
