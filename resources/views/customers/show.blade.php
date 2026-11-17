@extends('layouts.app')
@section('title', $customer->name)

@section('content')
    @php($reachable = \App\Support\PhoneNumber::isInternational($customer->phone))
    <div class="page-head">
        <h1>{{ $customer->name }} <span class="soft small">{{ $customer->numberLabel() }}</span> <span class="badge {{ $customer->statusBadge() }}">{{ $customer->statusLabel() }}</span></h1>
        <div class="actions">
            <a class="btn ghost" href="{{ route('customers.history', $customer) }}">{{ __('Historique complet') }}</a>
            <a class="btn ghost" href="{{ route('customers.edit', $customer) }}">{{ __('Modifier') }}</a>
        </div>
    </div>

    @if ($justPaid && $reachable)
        <div class="flash info">
            {{ __('Confirmez ce paiement au client :') }}
            <div class="actions" style="margin-top: 8px">@include('messages.payment-send', ['payment' => $justPaid, 'small' => true])</div>
        </div>
    @endif

    @if ($justEvent && in_array($justEvent->type, ['suspended', 'reconnected'], true))
        <div class="flash info">
            {{ $justEvent->type === 'suspended' ? __('Prévenez le client de la coupure :') : __('Confirmez le réabonnement au client :') }}
            <div class="actions" style="margin-top: 8px">@include('messages.event-send', ['event' => $justEvent->setRelation('customer', $customer)])</div>
        </div>
    @endif

    @foreach (['suspended_on', 'reconnected_on', 'terminated_on', 'reconnection_fee'] as $field)
        @error($field)<div class="flash error">{{ $message }}</div>@enderror
    @endforeach

    <div class="card">
        <dl class="detail">
            <div><dt>{{ __('Téléphone') }}</dt><dd>{{ $customer->phone ?: '—' }}@if ($customer->phone && ! $reachable) <span class="badge ko">{{ __('À corriger') }}</span>@endif</dd></div>
            <div><dt>{{ __('Localisation') }}</dt><dd>{{ $customer->place() ?: '—' }}@if ($customer->hasPosition()) · <a href="{{ route('map.index', ['quartier' => $customer->neighbourhood]) }}">{{ __('Voir sur la carte') }}</a>@endif</dd></div>
            <div><dt>{{ __('Tarif') }}</dt><dd>{{ $customer->tariffLabel() }}</dd></div>
            <div><dt>{{ __('Par mois') }}</dt><dd><strong>{{ money($customer->effectiveFee()) }}</strong></dd></div>
            <div><dt>{{ __('Téléviseurs') }}</dt><dd>{{ $customer->tv_count }}</dd></div>
            <div><dt>{{ __('Abonné depuis') }}</dt><dd>{{ $customer->start_date->format('d/m/Y') }}</dd></div>
            <div><dt>{{ __('Échéance') }}</dt><dd>{{ __('Le :v1 de chaque mois', ['v1' => $customer->due_day]) }}</dd></div>
            <div><dt>{{ __('État') }}</dt><dd>{{ $customer->statusLabel() }}@if ($customer->status_since && ! $customer->isActive()) {{ __('depuis le :v1', ['v1' => $customer->status_since->format('d/m/Y')]) }}@endif</dd></div>
            <div><dt>{{ __('Reste à payer') }}</dt><dd @class(['danger-text' => $unpaid > 0])>{{ money($unpaid) }}</dd></div>
            <div><dt>{{ __('Payé jusqu\'au') }}</dt><dd>{{ $customer->paidThrough()?->format('d/m/Y') ?? '—' }}</dd></div>
            <div><dt>{{ __('Prochaine échéance') }}</dt><dd>{{ $customer->nextDueDate()?->format('d/m/Y') ?? '—' }}</dd></div>
        </dl>
        @if ($customer->notes)<p class="soft small">{{ __('Observation : :v1', ['v1' => $customer->notes]) }}</p>@endif
        @if ($customer->isActive() && $customer->overdueMonths() > 0)
            <div class="actions">
                @include('messages.late-send', ['customer' => $customer])
                @if ($customer->overdueMonths() >= \App\Http\Controllers\CutoffController::threshold())
                    @include('messages.cutoff-warning-send', ['customer' => $customer])
                @endif
            </div>
            @if ($customer->cutoff_warning_opened_at)<p class="soft small">{{ __('Préavis ouvert le :v1', ['v1' => $customer->cutoff_warning_opened_at->format('d/m/Y')]) }}</p>@endif
        @endif
    </div>

    <div class="card" id="encaisser">
        <h2>{{ __('Encaisser un paiement') }}</h2>
        <p class="soft small">{{ __('Le paiement règle d\'abord les mois les plus anciens. S\'il dépasse ce qui est dû, il couvre les mois suivants : c\'est une avance.') }}</p>
        <div class="actions" style="margin-bottom: 12px">
            @if ($unpaid > 0)
                <button type="button" class="btn ghost small" data-fill-amount="{{ (float) $unpaid }}">{{ __('Tout le reste à payer') }}</button>
            @endif
            @if ($customer->isActive() && $customer->effectiveFee() > 0)
                @foreach ([1, 3, 6, 12] as $months)
                    <button type="button" class="btn ghost small" data-fill-amount="{{ (float) $unpaid + $months * $customer->effectiveFee() }}">{{ $unpaid > 0 ? __('Reste + :v1 mois d\'avance', ['v1' => $months]) : __(':v1 mois d\'avance', ['v1' => $months]) }}</button>
                @endforeach
            @endif
        </div>
        <form method="POST" action="{{ route('payments.store', $customer) }}">
            @csrf
            <div class="form-grid">
                <x-field name="amount" :label="__('Montant')" type="number" step="any" min="0" :required="true" />
                <x-field name="paid_at" :label="__('Date')" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}" :required="true" />
            </div>
            <div class="form-grid">
                @include('payments.method-field', ['value' => null])
                <x-field name="reference" :label="__('Référence')" :placeholder="__('N° de transaction Mobile Money…')" />
            </div>
            <button type="submit" class="btn">{{ __('Enregistrer le paiement') }}</button>
        </form>
    </div>

    <div class="card" id="signal">
        <h2>{{ __('Signal et abonnement') }}</h2>
        @if ($customer->isActive())
            <div class="form-grid">
                <form method="POST" action="{{ route('customers.suspend', $customer) }}" data-confirm="{{ __('Couper le signal de ce client ? Plus aucune facture ne sera créée jusqu\'au réabonnement.') }}" onsubmit="return confirm(this.dataset.confirm)">
                    @csrf
                    <h3>{{ __('Coupure') }}</h3>
                    <x-field name="suspended_on" :label="__('Date de coupure')" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}" :required="true" />
                    <x-field name="note" :label="__('Motif')" :value="$customer->overdueMonths() > 0 ? __(':v1 mois impayé(s)', ['v1' => $customer->overdueMonths()]) : null" />
                    <button class="btn danger">{{ __('Couper le signal') }}</button>
                </form>
                <form method="POST" action="{{ route('customers.terminate', $customer) }}" data-confirm="{{ __('Résilier l\'abonnement de ce client ?') }}" onsubmit="return confirm(this.dataset.confirm)">
                    @csrf
                    <h3>{{ __('Résiliation') }}</h3>
                    <x-field name="terminated_on" :label="__('Date de résiliation')" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}" :required="true" />
                    <x-field name="note" :label="__('Motif')" />
                    <button class="btn ghost">{{ __('Résilier l\'abonnement') }}</button>
                </form>
            </div>
        @else
            <p class="soft small">{{ __('Le réabonnement rétablit le signal : la facturation reprend au mois du réabonnement, avec les frais de réabonnement sur ce mois.') }}</p>
            <form method="POST" action="{{ route('customers.reconnect', $customer) }}">
                @csrf
                <div class="form-grid">
                    <x-field name="reconnected_on" :label="__('Date du réabonnement')" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}" :required="true" />
                    <x-field name="reconnection_fee" :label="__('Frais de réabonnement')" type="number" step="any" min="0" :value="$reconnectionFee" />
                    <x-field name="note" :label="__('Observation')" />
                </div>
                <button class="btn">{{ __('Réabonner le client') }}</button>
            </form>
            @if ($customer->isSuspended())
                <form class="inline" method="POST" action="{{ route('customers.terminate', $customer) }}" data-confirm="{{ __('Résilier l\'abonnement de ce client ?') }}" onsubmit="return confirm(this.dataset.confirm)">
                    @csrf
                    <input type="hidden" name="terminated_on" value="{{ now()->toDateString() }}">
                    <p style="margin: 10px 0 0"><button class="btn ghost small">{{ __('Résilier l\'abonnement') }}</button></p>
                </form>
            @endif
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Factures mensuelles') }}</h2>
        @if ($invoices->isEmpty())
            <p class="empty">{{ __('Aucune facture pour le moment : la première sera créée pour :v1.', ['v1' => period_label($customer->billing_from)]) }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-factures">
                <tr><x-sort-th :table="$invoices" key="period" kind="date">{{ __('Mois') }}</x-sort-th><x-sort-th :table="$invoices" key="due" kind="date">{{ __('Échéance') }}</x-sort-th><x-sort-th :table="$invoices" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><x-sort-th :table="$invoices" key="paid" kind="number" class="right">{{ __('Payé') }}</x-sort-th><th>{{ __('État') }}</th><th></th></tr>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->label() }}</a>@if ((float) $invoice->reconnection_fee > 0) <span class="soft small cell-line">{{ __('dont réabonnement :v1', ['v1' => money($invoice->reconnection_fee)]) }}</span>@endif</td>
                        <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                        <td class="right">{{ money($invoice->amount) }}</td>
                        <td class="right">{{ money($invoice->paid_amount) }}</td>
                        <td>@include('invoices.status', ['invoice' => $invoice])</td>
                        <td>
                            <div class="actions">
                                @if ($reachable)
                                    @include('messages.send', ['invoice' => $invoice->setRelation('customer', $customer), 'small' => true])
                                    @if ($invoice->canBeSent() && ! $invoice->isOverdue())
                                        @include('messages.reminder-send', ['invoice' => $invoice])
                                    @endif
                                @else
                                    <a class="btn ghost small" href="{{ route('invoices.pdf', $invoice) }}" download data-pdf-share><x-icon name="receipt" /> {{ __('PDF') }}</a>
                                @endif
                                <a class="btn ghost small" href="{{ route('invoices.edit', $invoice) }}">{{ __('Corriger') }}</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </table></div>
            {{ $invoices->links() }}
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Paiements') }}</h2>
        @if ($payments->isEmpty())
            <p class="empty">{{ __('Aucun paiement.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-paiements">
                <tr><x-sort-th :table="$payments" key="date" kind="date">{{ __('Date') }}</x-sort-th><th>{{ __('Mois réglés') }}</th><x-sort-th :table="$payments" key="method">{{ __('Mode') }}</x-sort-th><x-sort-th :table="$payments" key="reference">{{ __('Référence') }}</x-sort-th><x-sort-th :table="$payments" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><th></th></tr>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment->paid_at->format('d/m/Y') }}</td>
                        <td>{{ $payment->coveredLabel() }}</td>
                        <td>{{ $payment->methodLabel() }}</td>
                        <td>{{ $payment->reference ?: '—' }}</td>
                        <td class="right">{{ money($payment->amount) }}</td>
                        <td>
                            <div class="actions">
                                @include('messages.payment-send', ['payment' => $payment->setRelation('customer', $customer), 'small' => true])
                                <a class="btn ghost small" href="{{ route('payments.edit', $payment) }}">{{ __('Corriger') }}</a>
                                <form class="inline" method="POST" action="{{ route('payments.destroy', $payment) }}" data-confirm="{{ __('Annuler ce paiement ?') }}" onsubmit="return confirm(this.dataset.confirm)">
                                    @csrf @method('DELETE')
                                    <button class="btn danger small">{{ __('Annuler') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </table></div>
            {{ $payments->links() }}
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Historique de l\'abonnement') }}</h2>
        @if ($events->isEmpty())
            <p class="empty">{{ __('Aucun événement.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table>
                <tr><th>{{ __('Date') }}</th><th>{{ __('Événement') }}</th><th>{{ __('Détail') }}</th><th>{{ __('Par') }}</th><th></th></tr>
                @foreach ($events as $event)
                    <tr>
                        <td>{{ $event->happened_on->format('d/m/Y') }}</td>
                        <td><span class="badge {{ $event->badge() }}">{{ $event->label() }}</span></td>
                        <td>{{ $event->note ?: '—' }}@if ($event->type === 'reconnected' && (float) $event->amount > 0) <span class="soft small cell-line">{{ __('Frais : :v1', ['v1' => money($event->amount)]) }}</span>@endif</td>
                        <td>{{ $event->user?->name ?? '—' }}</td>
                        <td>
                            {{-- L'avis de coupure ne se propose que tant que le client est coupé. --}}
                            @if ($event->type === 'reconnected' || ($event->type === 'suspended' && $customer->isSuspended()))
                                <div class="actions">@include('messages.event-send', ['event' => $event->setRelation('customer', $customer)])</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table></div>
        @endif
        @if (! $customer->invoices()->exists() && ! $customer->payments()->withTrashed()->exists())
            <form method="POST" action="{{ route('customers.destroy', $customer) }}" data-confirm="{{ __('Supprimer ce client ?') }}" onsubmit="return confirm(this.dataset.confirm)">
                @csrf @method('DELETE')
                <p style="margin: 12px 0 0"><button class="btn danger small">{{ __('Supprimer le client') }}</button></p>
            </form>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-fill-amount]').forEach(function (button) {
            button.addEventListener('click', function () {
                var amount = document.getElementById('amount');
                amount.value = button.dataset.fillAmount;
                amount.focus();
            });
        });
    </script>
@endpush
