@extends('layouts.app')
@section('title', __('Clients'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Clients') }}</h1>
        <a class="btn" href="{{ route('customers.create') }}"><x-icon name="plus" /> {{ __('Nouveau client') }}</a>
    </div>

    <div class="stats">
        <a class="stat" href="{{ route('customers.index', ['statut' => 'active']) }}"><span>{{ __('Actifs') }}</span><strong>{{ $counts['active'] ?? 0 }}</strong></a>
        <a @class(['stat', 'alert' => ($counts['suspended'] ?? 0) > 0]) href="{{ route('customers.index', ['statut' => 'suspended']) }}"><span>{{ __('Coupés') }}</span><strong>{{ $counts['suspended'] ?? 0 }}</strong></a>
        <a class="stat" href="{{ route('customers.index', ['statut' => 'terminated']) }}"><span>{{ __('Résiliés') }}</span><strong>{{ $counts['terminated'] ?? 0 }}</strong></a>
    </div>

    <form class="filters" method="GET">
        <div><label for="q">{{ __('Recherche') }}</label><input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Nom, téléphone, n° client') }}"></div>
        <div>
            <label for="statut">{{ __('État') }}</label>
            <select id="statut" name="statut">
                <option value="">{{ __('Tous') }}</option>
                @foreach (['active', 'suspended', 'terminated'] as $key)
                    <option value="{{ $key }}" @selected($status === $key)>{{ \App\Models\Customer::statusName($key) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="groupe">{{ __('Groupe tarifaire') }}</label>
            <select id="groupe" name="groupe">
                <option value="">{{ __('Tous') }}</option>
                @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected($groupId === $group->id)>{{ $group->name }}</option>
                @endforeach
            </select>
        </div>
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
            <p class="empty">{{ __('Aucun client.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-clients">
                <tr><x-sort-th :table="$customers" key="number" kind="number">{{ __('N°') }}</x-sort-th><x-sort-th :table="$customers" key="name">{{ __('Nom') }}</x-sort-th><th>{{ __('Téléphone') }}</th><x-sort-th :table="$customers" key="neighbourhood">{{ __('Quartier') }}</x-sort-th><th>{{ __('Tarif') }}</th><x-sort-th :table="$customers" key="fee" kind="number" class="right">{{ __('Par mois') }}</x-sort-th><th>{{ __('État') }}</th><x-sort-th :table="$customers" key="unpaid" kind="number" class="right">{{ __('Reste à payer') }}</x-sort-th></tr>
                @foreach ($customers as $customer)
                    <tr>
                        <td>{{ str_pad((string) $customer->number, 4, '0', STR_PAD_LEFT) }}</td>
                        <td><a href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a></td>
                        <td>{{ $customer->phone ?: '—' }}@if ($customer->phone && ! \App\Support\PhoneNumber::isInternational($customer->phone)) <span class="badge ko">{{ __('À corriger') }}</span>@endif</td>
                        <td>{{ $customer->neighbourhood ?: '—' }}</td>
                        <td>{{ $customer->hasOwnFee() ? __('Personnel') : ($customer->tariffGroup?->name ?? '—') }}</td>
                        <td class="right">{{ money($customer->effectiveFee()) }}</td>
                        <td>
                            <span class="badge {{ $customer->statusBadge() }}">{{ $customer->statusLabel() }}</span>
                            @if ($customer->isActive())
                                @if ($customer->overdue_months > 0)<span class="badge partial">{{ __(':v1 mois en retard', ['v1' => $customer->overdue_months]) }}</span>@else<span class="badge ok">{{ __('À jour') }}</span>@endif
                            @endif
                        </td>
                        <td @class(['right', 'danger-text' => $customer->unpaid > 0])>{{ money($customer->unpaid) }}</td>
                    </tr>
                @endforeach
            </table></div>
            {{ $customers->links() }}
        @endif
    </div>
@endsection
