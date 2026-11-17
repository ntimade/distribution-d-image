<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Historique de :v1', ['v1' => $customer->name]) }}</title>
    {{-- Le moteur PDF ne gère ni flex ni grid : la mise en page repose sur display: table. --}}
    <style>
        @page { size: A4; margin: 14mm; }
        body { margin: 0; color: #111; font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.4; }
        .head { display: table; width: 100%; padding-bottom: 10px; border-bottom: 2px solid #0b2a6b; }
        .head > div { display: table-cell; width: 50%; vertical-align: top; }
        .head .right { text-align: right; }
        .head span, .head strong { display: block; }
        .logo { max-width: 160px; max-height: 70px; margin-bottom: 6px; }
        h1 { margin: 0; font-size: 18px; color: #0b2a6b; }
        .totals { display: table; width: 100%; margin: 12px 0; }
        .totals > div { display: table-cell; width: 33%; padding: 6px 8px; border: 1px solid #ddd; }
        .totals strong { display: block; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f1f4fa; font-size: 9px; text-transform: uppercase; }
        .right { text-align: right; white-space: nowrap; }
        .history-event td { background: #fff7f0; }
        .badge { font-weight: bold; }
        .soft { color: #555; }
        .small { font-size: 9px; }
    </style>
</head>
<body>
    <div class="head">
        <div>
            @if ($organisation->logoDataUri())<img class="logo" src="{{ $organisation->logoDataUri() }}" alt="">@endif
            <strong>{{ $organisation->name }}</strong>
            @if ($organisation->address)<span>{{ $organisation->address }}</span>@endif
            @if ($organisation->phone)<span>{{ __('Tél. : :v1', ['v1' => $organisation->phone]) }}</span>@endif
        </div>
        <div class="right">
            <h1>{{ __('Historique du client') }}</h1>
            <strong>{{ $customer->name }}</strong>
            <span>{{ $customer->numberLabel() }}</span>
            <span>{{ $customer->place() }}</span>
            <span>{{ $customer->tariffLabel() }} — {{ money($customer->effectiveFee()) }} {{ __('par mois') }}</span>
            <span>{{ __('Imprimé le :v1', ['v1' => now()->format('d/m/Y')]) }}</span>
        </div>
    </div>

    <div class="totals">
        <div>{{ __('Total facturé') }}<strong>{{ money($billed) }}</strong></div>
        <div>{{ __('Total payé') }}<strong>{{ money($paid) }}</strong></div>
        <div>{{ __('Reste à payer') }}<strong>{{ money($unpaid) }}</strong></div>
    </div>

    @include('customers.history-table')
</body>
</html>
