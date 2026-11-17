@php
    $others = $otherMonths[$invoice->id] ?? collect();
    $customer = $invoice->customer;
@endphp
<article class="invoice">
    <header class="invoice-head">
        <div class="invoice-org">
            @if ($organisation->logo_path)
                <img class="invoice-logo" src="{{ $logoSrc }}" alt="{{ __('Logo de :v1', ['v1' => $organisation->name]) }}">
            @endif
            <strong>{{ $organisation->name }}</strong>
            @if ($organisation->address)<span>{{ $organisation->address }}</span>@endif
            @if ($organisation->phone)<span>{{ __('Tél. : :v1', ['v1' => $organisation->phone]) }}</span>@endif
        </div>
        <div class="invoice-title">
            <h1>{{ __('Facture câble TV') }}</h1>
            <span class="invoice-number">{{ $invoice->number() }}</span>
            <span>{{ __('Période : :v1', ['v1' => $invoice->label()]) }}</span>
            <span>{{ __('Imprimée le :v1', ['v1' => now()->format('d/m/Y')]) }}</span>
            <strong class="invoice-due">{{ __('À payer avant le :v1', ['v1' => $invoice->due_date->format('d/m/Y')]) }}</strong>
        </div>
    </header>

    <section class="invoice-parties">
        <div>
            <h2>{{ __('Abonné') }}</h2>
            <strong>{{ $customer->name }}</strong>
            <span>{{ $customer->numberLabel() }}</span>
            @if ($customer->phone)<span>{{ __('Tél. : :v1', ['v1' => $customer->phone]) }}</span>@endif
        </div>
        <div>
            <h2>{{ __('Installation') }}</h2>
            <strong>{{ $customer->place() ?: '—' }}</strong>
            <span>{{ $customer->tariffLabel() }}</span>
            <span>{{ __(':v1 téléviseur(s)', ['v1' => $customer->tv_count]) }}</span>
        </div>
    </section>

    <table class="invoice-totals">
        <tr class="subtotal"><th>{{ __('Abonnement :v1', ['v1' => of_period($invoice->period)]) }}</th><td>{{ money($invoice->subscriptionAmount()) }}</td></tr>
        @if ((float) $invoice->reconnection_fee > 0)
            <tr><th>{{ __('Frais de réabonnement') }}</th><td>{{ money($invoice->reconnection_fee) }}</td></tr>
        @endif
        @if ((float) $invoice->paid_amount > 0)
            <tr><th>{{ __('Déjà payé') }}</th><td>− {{ money($invoice->paid_amount) }}</td></tr>
        @endif
        @foreach ($others as $month)
            <tr><th>{{ __('Reste dû sur :v1', ['v1' => $month->label()]) }}</th><td>{{ money($month->balance()) }}</td></tr>
        @endforeach
        <tr class="grand-total"><th>{{ __('Total à payer') }}</th><td>{{ money($invoice->balance() + $others->sum(fn ($month) => $month->balance())) }}</td></tr>
    </table>

    <p class="invoice-note">{{ __('Paiement en espèces, Orange Money ou MTN Mobile Money. Sans paiement après l\'échéance, le signal peut être coupé.') }}</p>
    @if ($invoice->notes)<p class="invoice-note">{{ __('Observation : :v1', ['v1' => $invoice->notes]) }}</p>@endif
</article>
