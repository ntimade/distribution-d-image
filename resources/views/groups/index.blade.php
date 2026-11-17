@extends('layouts.app')
@section('title', __('Groupes tarifaires'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Groupes tarifaires') }}</h1>
        <a class="btn" href="{{ route('groups.create') }}"><x-icon name="plus" /> {{ __('Nouveau groupe') }}</a>
    </div>

    <p class="soft small">{{ __('Un groupe donne le même tarif mensuel à plusieurs clients. Un client peut aussi avoir son tarif personnel : il prime sur celui du groupe. Changer un tarif ne modifie que les prochaines factures.') }}</p>

    <div class="card">
        @if ($groups->isEmpty())
            <p class="empty">{{ __('Aucun groupe tarifaire.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-groupes">
                <tr><x-sort-th :table="$groups" key="name">{{ __('Nom') }}</x-sort-th><th>{{ __('Description') }}</th><x-sort-th :table="$groups" key="fee" kind="number" class="right">{{ __('Tarif mensuel') }}</x-sort-th><x-sort-th :table="$groups" key="customers" kind="number" class="right">{{ __('Clients') }}</x-sort-th><th class="right">{{ __('Actifs') }}</th><th class="right">{{ __('Avec tarif personnel') }}</th><th></th></tr>
                @foreach ($groups as $group)
                    <tr>
                        <td><a href="{{ route('customers.index', ['groupe' => $group->id]) }}">{{ $group->name }}</a></td>
                        <td>{{ $group->description ?: '—' }}</td>
                        <td class="right">{{ money($group->monthly_fee) }}</td>
                        <td class="right">{{ $group->customers_count }}</td>
                        <td class="right">{{ $group->active_count }}</td>
                        <td class="right">{{ $group->own_fee_count }}</td>
                        <td><div class="actions">
                            <a class="btn ghost small" href="{{ route('groups.edit', $group) }}">{{ __('Modifier') }}</a>
                            <form class="inline" method="POST" action="{{ route('groups.destroy', $group) }}" data-confirm="{{ __('Supprimer ce groupe ?') }}" onsubmit="return confirm(this.dataset.confirm)">
                                @csrf @method('DELETE')
                                <button class="btn danger small">{{ __('Supprimer') }}</button>
                            </form>
                        </div></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $groups->links() }}
        @endif
        @if ($withoutGroup > 0)
            <p class="soft small">{{ __(':v1 client(s) sans groupe, avec un tarif personnel.', ['v1' => $withoutGroup]) }}</p>
        @endif
    </div>
@endsection
