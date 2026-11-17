@extends('layouts.app')
@section('title', $group->exists ? __('Modifier le groupe') : __('Nouveau groupe'))

@section('content')
    <div class="page-head"><h1>{{ $group->exists ? __('Modifier le groupe') : __('Nouveau groupe') }}</h1></div>

    <div class="card narrow">
        <form method="POST" action="{{ $group->exists ? route('groups.update', $group) : route('groups.store') }}">
            @csrf
            @if ($group->exists) @method('PUT') @endif
            <x-field name="name" :label="__('Nom')" :value="$group->name" :required="true" :placeholder="__('Bouquet standard, Quartier Bépanda…')" />
            <x-field name="monthly_fee" :label="__('Tarif mensuel')" type="number" step="any" min="0" :value="$group->monthly_fee !== null ? (float) $group->monthly_fee : null" :required="true"
                     :help="$group->exists ? __('Le nouveau tarif vaut pour les prochaines factures des :v1 client(s) du groupe, sauf ceux qui ont un tarif personnel.', ['v1' => $group->customers_count]) : null" />
            <x-field name="description" :label="__('Description')" :value="$group->description" />
            <div class="form-actions">
                <button type="submit" class="btn">{{ __('Enregistrer') }}</button>
                <a class="btn ghost" href="{{ route('groups.index') }}">{{ __('Annuler') }}</a>
            </div>
        </form>
    </div>
@endsection
