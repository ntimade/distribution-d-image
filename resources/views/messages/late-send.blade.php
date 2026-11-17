@if (\App\Support\PhoneNumber::isInternational($customer->phone))
    <form class="inline" method="POST" action="{{ route('messages.late', [$customer, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send>
        @csrf
        <button @unless (config('messaging.enabled')) disabled title="{{ __('Envoi des messages WhatsApp et SMS désactivé') }}" @endunless @class(['btn small', 'ghost' => ! ($waiting ?? true)])><x-icon name="send" /> {{ __('Relance WhatsApp') }}</button>
    </form>
    <form class="inline" method="POST" action="{{ route('messages.late', [$customer, 'sms']) }}" data-message-send>
        @csrf
        <button @unless (config('messaging.enabled')) disabled title="{{ __('Envoi des messages WhatsApp et SMS désactivé') }}" @endunless class="btn ghost small"><x-icon name="message-square" /> {{ __('Relance SMS') }}</button>
    </form>
@else
    <a class="btn ghost small" href="{{ route('customers.edit', $customer) }}">{{ __('Corriger le numéro') }}</a>
@endif
