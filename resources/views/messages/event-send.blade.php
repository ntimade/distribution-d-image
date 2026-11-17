{{-- L'avis de coupure ou la confirmation de réabonnement d'un client. --}}
@if (\App\Support\PhoneNumber::isInternational($event->customer->phone))
    <form class="inline" method="POST" action="{{ route('messages.event', [$event, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send>
        @csrf
        <button @unless (config('messaging.enabled')) disabled title="{{ __('Envoi des messages WhatsApp et SMS désactivé') }}" @endunless @class(['btn small', 'ghost' => $event->whatsapp_opened_at])><x-icon name="send" /> {{ __('WhatsApp') }}</button>
    </form>
    <form class="inline" method="POST" action="{{ route('messages.event', [$event, 'sms']) }}" data-message-send>
        @csrf
        <button @unless (config('messaging.enabled')) disabled title="{{ __('Envoi des messages WhatsApp et SMS désactivé') }}" @endunless class="btn ghost small"><x-icon name="message-square" /> {{ __('SMS') }}</button>
    </form>
@else
    <a class="btn ghost small" href="{{ route('customers.edit', $event->customer) }}">{{ __('Corriger le numéro') }}</a>
@endif
