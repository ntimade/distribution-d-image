@extends('layouts.app')
@section('title', __('Carte'))

@push('head')<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">@endpush

@section('content')
    <div class="page-head"><h1>{{ __('Carte des clients') }}</h1></div>

    <form class="filters" method="GET">
        <div>
            <label for="quartier">{{ __('Quartier') }}</label>
            <select id="quartier" name="quartier">
                <option value="">{{ __('Tous') }}</option>
                @foreach ($neighbourhoods as $name)
                    <option value="{{ $name }}" @selected($neighbourhood === $name)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn ghost">{{ __('Afficher') }}</button>
    </form>

    <div class="card">
        <div class="location-bar">
            <p id="location-status" class="small">{{ __('Touchez « Ma position » pour vous situer sur la carte.') }}</p>
            <button type="button" class="btn ghost small" id="my-position">{{ __('Ma position') }}</button>
        </div>
        <div id="map"></div>
        <div class="map-legend small">
            <span><i style="background: #16a34a"></i> {{ __('À jour') }}</span>
            <span><i style="background: #f26a1b"></i> {{ __('En retard') }}</span>
            <span><i style="background: #dc2626"></i> {{ __('Coupé') }}</span>
            <span><i style="background: #94a3b8"></i> {{ __('Résilié') }}</span>
            <span><i style="background: #0ea5e9"></i> {{ __('Vous') }}</span>
        </div>
        <p class="soft small">{{ __(':v1 client(s) sur la carte.', ['v1' => count($points)]) }}
            @if ($withoutPosition > 0){{ __(':v1 sans position : renseignez-la dans la fiche du client.', ['v1' => $withoutPosition]) }}@endif
        </p>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset_v('js/location.js') }}"></script>
    <script>
        (function () {
            var points = @json($points);
            var colors = { ok: '#16a34a', late: '#f26a1b', suspended: '#dc2626', terminated: '#94a3b8' };
            var map = L.map('map');
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            function line(parent, text, tag) {
                var element = document.createElement(tag || 'div');
                element.textContent = text;
                parent.appendChild(element);
                return element;
            }

            function popupFor(point) {
                var popup = document.createElement('div');
                popup.className = 'meter-popup';
                line(popup, point.name, 'a').href = point.url;
                if (point.place) {
                    line(popup, point.place).className = 'soft';
                }
                if (point.phone) {
                    line(popup, point.phone);
                }
                line(popup, point.state);
                line(popup, {{ Js::from(__('Par mois')) }} + ' : ' + point.fee);
                line(popup, {{ Js::from(__('Reste à payer')) }} + ' : ' + point.unpaid);
                return popup;
            }

            var markers = points.map(function (point) {
                return L.circleMarker([point.lat, point.lng], {
                    radius: 9, weight: 2, color: '#fff', fillOpacity: 1, fillColor: colors[point.status]
                }).bindPopup(popupFor(point)).addTo(map);
            });

            if (markers.length) {
                map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2), { maxZoom: 16 });
            } else {
                map.setView([4.05, 9.7], 12);
            }

            var status = document.getElementById('location-status');
            var userLayer = null;

            document.getElementById('my-position').addEventListener('click', function () {
                locateUser(function (coords) {
                    userLayer = showUserPosition(map, coords, userLayer);
                    map.setView([coords.latitude, coords.longitude], 16);
                    setLocationStatus(status, {{ Js::from(__('Votre position est affichée en bleu, précision d\'environ ')) }} + Math.round(coords.accuracy) + ' m.');
                }, function (message) {
                    setLocationStatus(status, message, true);
                });
            });
        })();
    </script>
@endpush
