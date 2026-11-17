@extends('layouts.app')
@section('title', __('Historique de :v1', ['v1' => $customer->name]))

@section('content')
    <div class="page-head">
        <h1>{{ __('Historique de :v1', ['v1' => $customer->name]) }}</h1>
        <div class="actions">
            <a class="btn ghost" href="{{ route('customers.show', $customer) }}">{{ __('Retour à la fiche') }}</a>
            <a class="btn" href="{{ route('customers.history.pdf', $customer) }}" download data-pdf-share><x-icon name="receipt" /> {{ __('PDF') }}</a>
        </div>
    </div>

    <div class="stats">
        <div class="stat"><span>{{ __('Total facturé') }}</span><strong>{{ money($billed) }}</strong></div>
        <div class="stat"><span>{{ __('Total payé') }}</span><strong>{{ money($paid) }}</strong></div>
        <div @class(['stat', 'alert' => $unpaid > 0])><span>{{ __('Reste à payer') }}</span><strong>{{ money($unpaid) }}</strong></div>
    </div>

    <div class="card">
        <p class="soft small">{{ $customer->numberLabel() }} · {{ $customer->place() ?: '—' }} · {{ $customer->tariffLabel() }} · {{ money($customer->effectiveFee()) }} {{ __('par mois') }}</p>
        <div class="table-wrap">@include('customers.history-table')</div>
    </div>
@endsection
