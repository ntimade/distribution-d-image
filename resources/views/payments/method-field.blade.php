{{-- Le mode de paiement : liste des modes courants. Un ancien texte libre reste proposé tel quel. --}}
<x-field name="method" :label="__('Mode de paiement')">
    <select id="method" name="method">
        <option value="">{{ __('Choisir…') }}</option>
        @foreach ($methods as $key => $label)
            <option value="{{ $key }}" @selected(old('method', $value ?? 'cash') === $key)>{{ __($label) }}</option>
        @endforeach
        @if (($value ?? null) && ! array_key_exists($value, $methods))
            <option value="{{ $value }}" selected>{{ $value }}</option>
        @endif
    </select>
</x-field>
