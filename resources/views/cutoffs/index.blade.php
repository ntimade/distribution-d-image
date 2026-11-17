@extends('layouts.app')
@section('title', __('Coupures'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Coupures') }}</h1>
        <x-info-button>{{ __('Un client actif apparaît dans « À couper » quand il a au moins :v1 mois en retard (réglable dans les paramètres). La coupure se fait sur le terrain : enregistrez-la ensuite dans la fiche du client, puis envoyez-lui l\'avis.', ['v1' => $threshold]) }}</x-info-button>
    </div>

    <nav class="steps" aria-label="{{ __('Étapes') }}">
        @foreach ([
            ['a-couper', '1', __('À couper'), __(':v1 mois en retard ou plus', ['v1' => $threshold])],
            ['coupes', '2', __('Coupés'), __('En attente de réabonnement')],
            ['resilies', '3', __('Résiliés'), __('Abonnement terminé')],
        ] as [$key, $number, $label, $hint])
            <a href="{{ route('cutoffs.index', ['etat' => $key]) }}" @class(['step', 'active' => $state === $key]) @if ($state === $key) aria-current="page" @endif>
                <span class="step-number">{{ $number }}</span>
                <span class="step-text"><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                <span @class(['step-count', 'empty-count' => ! $counts[$key]])>{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="card">
        @if ($customers->isEmpty())
            <p class="empty">{{ $state === 'a-couper' ? __('Aucun client à couper.') : __('Aucun client.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-coupures">
                <tr><x-sort-th :table="$customers" key="name">{{ __('Client') }}</x-sort-th><th>{{ __('Téléphone') }}</th><x-sort-th :table="$customers" key="neighbourhood">{{ __('Quartier') }}</x-sort-th>@if ($state === 'a-couper')<x-sort-th :table="$customers" key="months" kind="number" class="right">{{ __('Mois en retard') }}</x-sort-th>@else<x-sort-th :table="$customers" key="since" kind="date">{{ __('Depuis le') }}</x-sort-th>@endif<x-sort-th :table="$customers" key="unpaid" kind="number" class="right">{{ __('Reste à payer') }}</x-sort-th><th></th></tr>
                @foreach ($customers as $customer)
                    <tr>
                        <td><a href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a> <span class="soft small">{{ $customer->numberLabel() }}</span></td>
                        <td>{{ $customer->phone ?: '—' }}</td>
                        <td>{{ $customer->place() ?: '—' }}</td>
                        @if ($state === 'a-couper')
                            <td class="right danger-text">{{ $customer->overdue_months }}</td>
                        @else
                            <td>{{ $customer->status_since?->format('d/m/Y') ?? '—' }}</td>
                        @endif
                        <td @class(['right', 'danger-text' => $customer->unpaid_total > 0])>{{ money($customer->unpaid_total) }}</td>
                        <td><div class="actions">
                            @if ($state === 'a-couper')
                                @include('messages.cutoff-warning-send', ['customer' => $customer])
                                <a class="btn danger small" href="{{ route('customers.show', $customer) }}#signal">{{ __('Couper') }}</a>
                            @else
                                <a class="btn ghost small" href="{{ route('customers.show', $customer) }}#encaisser">{{ __('Encaisser') }}</a>
                                <a class="btn small" href="{{ route('customers.show', $customer) }}#signal">{{ __('Réabonner') }}</a>
                            @endif
                        </div></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $customers->links() }}
        @endif
    </div>
@endsection
