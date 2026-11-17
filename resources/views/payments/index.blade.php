@extends('layouts.app')
@section('title', __('Paiements'))

@section('content')
    <div class="page-head"><h1>{{ __('Historique des paiements') }}</h1></div>

    <div class="card">
        <form class="filters" method="GET">
            <div>
                <label for="customer_id">{{ __('Client') }}</label>
                <select id="customer_id" name="customer_id">
                    <option value="">{{ __('Tous') }}</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(request('customer_id') == $customer->id)>{{ $customer->name }} ({{ $customer->numberLabel() }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="method">{{ __('Mode') }}</label>
                <select id="method" name="method">
                    <option value="">{{ __('Tous') }}</option>
                    @foreach ($methods as $key => $label)
                        <option value="{{ $key }}" @selected(request('method') === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="from">{{ __('Du') }}</label><input id="from" type="date" name="from" value="{{ request('from') }}"></div>
            <div><label for="to">{{ __('Au') }}</label><input id="to" type="date" name="to" value="{{ request('to') }}"></div>
            <button class="btn ghost">{{ __('Filtrer') }}</button>
        </form>

        <p><strong>{{ __('Total : :v1', ['v1' => money($total)]) }}</strong>
            @foreach ($byMethod as $method => $amount)
                <span class="soft small"> · {{ \App\Models\Payment::methodName($method) }} : {{ money($amount) }}</span>
            @endforeach
        </p>

        @if ($payments->isEmpty())
            <p class="empty">{{ __('Aucun paiement.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-paiements">
                <tr><x-sort-th :table="$payments" key="date" kind="date">{{ __('Date') }}</x-sort-th><x-sort-th :table="$payments" key="customer">{{ __('Client') }}</x-sort-th><th>{{ __('Mois réglés') }}</th><x-sort-th :table="$payments" key="method">{{ __('Mode') }}</x-sort-th><x-sort-th :table="$payments" key="reference">{{ __('Référence') }}</x-sort-th><x-sort-th :table="$payments" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><th></th></tr>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment->paid_at->format('d/m/Y') }}</td>
                        <td><a href="{{ route('customers.show', $payment->customer_id) }}">{{ $payment->customer->name }}</a></td>
                        <td>{{ $payment->coveredLabel() }}</td>
                        <td>{{ $payment->methodLabel() }}</td>
                        <td>{{ $payment->reference ?: '—' }}</td>
                        <td class="right">{{ money($payment->amount) }}</td>
                        <td><div class="actions">@include('messages.payment-send', ['payment' => $payment, 'small' => true])</div></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $payments->links() }}
        @endif
    </div>
@endsection
