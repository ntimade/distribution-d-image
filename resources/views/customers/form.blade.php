@extends('layouts.app')
@section('title', $customer->exists ? __('Modifier le client') : __('Nouveau client'))

@push('head')<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">@endpush

@section('content')
    <div class="page-head"><h1>{{ $customer->exists ? __('Modifier le client') : __('Nouveau client') }}</h1></div>

    @php($billed = $customer->exists && $customer->invoices()->exists())

    <form method="POST" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">
        @csrf
        @if ($customer->exists) @method('PUT') @endif

        <div class="card">
            <h2>{{ __('Identité') }}</h2>
            <div class="form-grid">
                <x-field name="name" :label="__('Nom complet')" :value="$customer->name" :required="true" />
                <x-field name="gender" :label="__('Civilité')">
                    <select id="gender" name="gender">
                        <option value="">—</option>
                        <option value="M" @selected(old('gender', $customer->gender) === 'M')>{{ __('Monsieur') }}</option>
                        <option value="F" @selected(old('gender', $customer->gender) === 'F')>{{ __('Madame') }}</option>
                    </select>
                </x-field>
            </div>
            <div class="form-grid">
                <x-field name="phone" :label="__('Téléphone (WhatsApp)')" type="tel" :value="$customer->phone" :help="__('Sert aux factures, rappels et avis envoyés par WhatsApp ou SMS.')" />
                <x-field name="email" :label="__('E-mail')" type="email" :value="$customer->email" />
            </div>
        </div>

        <div class="card">
            <h2>{{ __('Abonnement') }}</h2>
            <div class="form-grid">
                <x-field name="tariff_group_id" :label="__('Groupe tarifaire')" :help="__('Le tarif du groupe s\'applique aux clients sans tarif personnel.')">
                    <select id="tariff_group_id" name="tariff_group_id">
                        <option value="">{{ __('Aucun groupe') }}</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}" @selected((int) old('tariff_group_id', $customer->tariff_group_id) === $group->id)>{{ $group->name }} — {{ money($group->monthly_fee) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field name="monthly_fee" :label="__('Tarif personnel par mois')" type="number" step="any" min="0" :value="$customer->monthly_fee !== null ? (float) $customer->monthly_fee : null"
                         :help="__('Laissez vide pour appliquer le tarif du groupe. S\'il est rempli, il prime sur celui du groupe.')" />
            </div>
            <div class="form-grid">
                <x-field name="tv_count" :label="__('Nombre de téléviseurs')" type="number" min="1" max="50" step="1" :value="$customer->tv_count ?? 1" :required="true" />
                <x-field name="due_day" :label="__('Jour d\'échéance')" type="number" min="1" max="31" step="1" :value="$customer->due_day" :required="true"
                         :help="__('Le client paie avant ce jour chaque mois.')" />
                <x-field name="start_date" :label="__('Début de l\'abonnement')" type="date" :value="$customer->start_date?->toDateString()" :required="! $billed"
                         :disabled="$billed" :help="$billed ? __('Non modifiable : des mois sont déjà facturés.') : __('Le premier mois facturé est celui du début.')" />
            </div>
            @if ($groups->isEmpty())
                <p class="soft small">{{ __('Aucun groupe tarifaire : saisissez un tarif personnel, ou') }} <a href="{{ route('groups.create') }}">{{ __('créez un groupe') }}</a>.</p>
            @endif
        </div>

        <div class="card">
            <h2>{{ __('Localisation') }}</h2>
            <div class="form-grid">
                <x-field name="neighbourhood" :label="__('Quartier')" :value="$customer->neighbourhood" list="neighbourhood-list" />
                <x-field name="address" :label="__('Adresse')" :value="$customer->address" />
                <x-field name="landmark" :label="__('Repère')" :value="$customer->landmark" :placeholder="__('Près de la pharmacie, portail bleu…')" />
            </div>
            <datalist id="neighbourhood-list">
                @foreach ($neighbourhoods as $name)<option value="{{ $name }}"></option>@endforeach
            </datalist>
            <div class="location-bar">
                <p id="location-status" class="small">{{ __('Cliquez sur la carte à l\'emplacement du client. Si vous êtes sur place, utilisez votre position.') }}</p>
                <button type="button" class="btn ghost small" id="use-location">{{ __('Placer à ma position') }}</button>
            </div>
            <div id="picker" class="map-picker"></div>
            <div class="form-grid">
                <x-field name="latitude" :label="__('Latitude')" type="number" step="any" min="-90" max="90" :value="$customer->latitude" />
                <x-field name="longitude" :label="__('Longitude')" type="number" step="any" min="-180" max="180" :value="$customer->longitude" />
            </div>
        </div>

        <div class="card">
            <x-field name="notes" :label="__('Observation')" :value="$customer->notes" />
            <div class="form-actions">
                <button type="submit" class="btn">{{ $customer->exists ? __('Enregistrer') : __('Abonner le client') }}</button>
                <a class="btn ghost" href="{{ $customer->exists ? route('customers.show', $customer) : route('customers.index') }}">{{ __('Annuler') }}</a>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset_v('js/location.js') }}"></script>
    <script>
        (function () {
            var status = document.getElementById('location-status');
            var lat = document.getElementById('latitude');
            var lng = document.getElementById('longitude');
            var map = L.map('picker');
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);
            var marker = null;
            var userLayer = null;

            function place(latitude, longitude) {
                if (marker) { marker.setLatLng([latitude, longitude]); }
                else { marker = L.marker([latitude, longitude]).addTo(map); }
            }

            if (lat.value && lng.value) {
                place(lat.value, lng.value);
                map.setView([lat.value, lng.value], 16);
            } else {
                map.setView([4.05, 9.7], 12);
            }

            document.getElementById('use-location').addEventListener('click', function () {
                if (lat.value && lng.value && !confirm({{ Js::from(__('Remplacer la position actuelle du client ?')) }})) {
                    return;
                }
                locateUser(function (coords) {
                    lat.value = coords.latitude.toFixed(7);
                    lng.value = coords.longitude.toFixed(7);
                    place(lat.value, lng.value);
                    userLayer = showUserPosition(map, coords, userLayer);
                    map.setView([lat.value, lng.value], 17);
                    setLocationStatus(status, {{ Js::from(__('Position enregistrée, précision d\'environ ')) }} + Math.round(coords.accuracy) + ' m.');
                }, function (message) {
                    setLocationStatus(status, message, true);
                });
            });

            map.on('click', function (event) {
                lat.value = event.latlng.lat.toFixed(7);
                lng.value = event.latlng.lng.toFixed(7);
                place(lat.value, lng.value);
            });

            [lat, lng].forEach(function (input) {
                input.addEventListener('change', function () {
                    if (lat.value && lng.value) {
                        place(lat.value, lng.value);
                        map.setView([lat.value, lng.value], Math.max(map.getZoom(), 14));
                    }
                });
            });
        })();
    </script>
@endpush
