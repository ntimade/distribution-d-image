{{-- Préavis de coupure d'un client qui a atteint le seuil de retard réglé dans les paramètres. --}}
@if (\App\Support\PhoneNumber::isInternational($customer->phone))
    <form class="inline" method="POST" action="{{ route('messages.cutoff_warning', [$customer, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send>
        @csrf
        <button @unless (config('messaging.enabled')) disabled title="{{ __('Envoi des messages WhatsApp et SMS désactivé') }}" @endunless @class(['btn small', 'ghost' => ! ($waiting ?? true)])><x-icon name="send" /> {{ __('Préavis WhatsApp') }}</button>
    </form>
    <form class="inline" method="POST" action="{{ route('messages.cutoff_warning', [$customer, 'sms']) }}" data-message-send>
        @csrf
        <button @unless (config('messaging.enabled')) disabled title="{{ __('Envoi des messages WhatsApp et SMS désactivé') }}" @endunless class="btn ghost small"><x-icon name="message-square" /> {{ __('Préavis SMS') }}</button>
    </form>
@else
    <a class="btn ghost small" href="{{ route('customers.edit', $customer) }}">{{ __('Corriger le numéro') }}</a>
@endif
