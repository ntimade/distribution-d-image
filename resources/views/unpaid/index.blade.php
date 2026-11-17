@extends('layouts.app')
@section('title', __('Impayés'))

@section('content')
    <div class="page-head"><h1>{{ __('Impayés') }}</h1></div>

    <div class="stats">
        <div @class(['stat', 'alert' => $overdueTotal > 0])><span>{{ __('Abonnements en retard') }}</span><strong>{{ money($overdueTotal) }}</strong></div>
        <div class="stat"><span>{{ __('À échoir, pas encore payés') }}</span><strong>{{ money($upcomingTotal) }}</strong></div>
    </div>

    <form class="filters" method="GET">
        <div><label for="q">{{ __('Recherche') }}</label><input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Nom, téléphone, n° client') }}"></div>
        <div>
            <label for="quartier">{{ __('Quartier') }}</label>
            <select id="quartier" name="quartier">
                <option value="">{{ __('Tous') }}</option>
                @foreach ($neighbourhoods as $name)
                    <option value="{{ $name }}" @selected($neighbourhood === $name)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn ghost">{{ __('Filtrer') }}</button>
    </form>

    <div class="card">
        @if ($customers->isEmpty())
            <p class="empty">{{ __('Aucun impayé.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-impayes">
                <tr><x-sort-th :table="$customers" key="name">{{ __('Client') }}</x-sort-th><x-sort-th :table="$customers" key="neighbourhood">{{ __('Quartier') }}</x-sort-th><th>{{ __('État') }}</th><x-sort-th :table="$customers" key="months" kind="number" class="right">{{ __('Mois en retard') }}</x-sort-th><x-sort-th :table="$customers" key="overdue" kind="number" class="right">{{ __('En retard') }}</x-sort-th><x-sort-th :table="$customers" key="unpaid" kind="number" class="right">{{ __('Total impayé') }}</x-sort-th><x-sort-th :table="$customers" key="due" kind="date">{{ __('Plus ancienne échéance') }}</x-sort-th><th></th></tr>
                @foreach ($customers as $customer)
                    <tr>
                        <td><a href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a> <span class="soft small">{{ $customer->numberLabel() }}</span></td>
                        <td>{{ $customer->neighbourhood ?: '—' }}</td>
                        <td><span class="badge {{ $customer->statusBadge() }}">{{ $customer->statusLabel() }}</span></td>
                        <td class="right">{{ $customer->overdue_months }}</td>
                        <td class="right @if ($customer->overdue_total > 0) danger-text @endif">{{ money($customer->overdue_total) }}</td>
                        <td class="right danger-text">{{ money($customer->unpaid_total) }}</td>
                        <td>{{ $customer->oldest_due ? \Illuminate\Support\Carbon::parse($customer->oldest_due)->format('d/m/Y') : '—' }}</td>
                        <td><div class="actions">
                            <a class="btn ghost small" href="{{ route('customers.show', $customer) }}#encaisser">{{ __('Encaisser') }}</a>
                            @if ($customer->isActive() && $customer->overdue_months > 0)
                                @include('messages.late-send', ['customer' => $customer])
                            @endif
                        </div></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $customers->links() }}
        @endif
    </div>
@endsection
