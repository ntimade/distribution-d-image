@extends('layouts.app')
@section('title', __('Messages aux clients'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Messages aux clients') }}</h1>
        <x-info-button>{{ __('Chaque bouton ouvre WhatsApp ou les SMS avec le message déjà écrit : il ne reste qu\'à appuyer sur Envoyer. Pour une facture, le PDF se télécharge en même temps : joignez-le avec le trombone. Le bouton SMS ouvre la messagerie sur la conversation du client et le SMS part de votre forfait.') }}</x-info-button>
    </div>

    <nav class="steps" aria-label="{{ __('Étapes') }}">
        @foreach ([
            ['rappels', '1', __('Rappels à envoyer'), __(':v1 jours avant l\'échéance', ['v1' => $reminderDays])],
            ['factures', '2', __('Factures à envoyer'), __('Mois pas encore payés')],
            ['paiements', '3', __('Confirmations de paiement'), __('Après chaque paiement')],
            ['relances', '4', __('Relances'), __('Après l\'échéance')],
            ['coupures', '5', __('Coupures et réabonnements'), __('Avis au client')],
        ] as [$key, $number, $label, $hint])
            <a href="{{ route('messages.index', ['etat' => $key]) }}" @class(['step', 'active' => $state === $key]) @if ($state === $key) aria-current="page" @endif>
                <span class="step-number">{{ $number }}</span>
                <span class="step-text"><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                <span @class(['step-count', 'empty-count' => ! $counts[$key]])>{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    @if ($state === 'rappels')
        <p class="soft small">{{ __('Les mois à échéance d\'ici :v1 jours, sans rappel envoyé. Un mois payé d\'avance n\'apparaît pas ici. Une ligne disparaît dès que vous ouvrez le message.', ['v1' => $reminderDays]) }}</p>
        <div class="card">
            @if ($reminders->isEmpty())
                <p class="empty">{{ __('Aucun rappel à envoyer aujourd\'hui.') }}</p>
            @else
                <div class="table-wrap cards-wide"><table id="t-rappels">
                    <tr><th>{{ __('Client') }}</th><th>{{ __('Téléphone') }}</th><th>{{ __('Quartier') }}</th><x-sort-th :table="$reminders" key="due" kind="date">{{ __('Échéance') }}</x-sort-th><x-sort-th :table="$reminders" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><th></th></tr>
                    @foreach ($reminders as $invoice)
                        <tr>
                            <td><a href="{{ route('customers.show', $invoice->customer_id) }}">{{ $invoice->customer->name }}</a></td>
                            <td>{{ $invoice->customer->phone }}</td>
                            <td>{{ $invoice->customer->neighbourhood ?: '—' }}</td>
                            <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                            <td class="right">{{ money($invoice->balance()) }}</td>
                            <td><div class="actions">@include('messages.reminder-send', ['invoice' => $invoice])</div></td>
                        </tr>
                    @endforeach
                </table></div>
                {{ $reminders->links() }}
            @endif
        </div>

        @if ($unreachable->isNotEmpty())
            <div class="card">
                <h2>{{ __('Rappels impossibles : numéro absent ou à corriger') }}</h2>
                <div class="table-wrap cards-wide"><table>
                    <tr><th>{{ __('Client') }}</th><th>{{ __('Quartier') }}</th><th>{{ __('Échéance') }}</th><th></th></tr>
                    @foreach ($unreachable as $invoice)
                        <tr>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ $invoice->customer->neighbourhood ?: '—' }}</td>
                            <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                            <td><div class="actions"><a class="btn ghost small" href="{{ route('customers.edit', $invoice->customer) }}">{{ __('Corriger le numéro') }}</a></div></td>
                        </tr>
                    @endforeach
                </table></div>
            </div>
        @endif
    @elseif ($state === 'factures')
        <div class="card">
            @if ($invoices->isEmpty())
                <p class="empty">{{ __('Toutes les factures en cours ont été envoyées.') }}</p>
            @else
                <div class="table-wrap cards-wide"><table id="t-envois">
                    <tr><th>{{ __('Client') }}</th><th>{{ __('Quartier') }}</th><th>{{ __('Mois') }}</th><x-sort-th :table="$invoices" key="due" kind="date">{{ __('Échéance') }}</x-sort-th><x-sort-th :table="$invoices" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><th></th></tr>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td><a href="{{ route('customers.show', $invoice->customer_id) }}">{{ $invoice->customer->name }}</a></td>
                            <td>{{ $invoice->customer->neighbourhood ?: '—' }}</td>
                            <td>{{ $invoice->label() }}</td>
                            <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                            <td class="right">{{ money($invoice->balance()) }}</td>
                            <td><div class="actions">@include('messages.send', ['invoice' => $invoice, 'small' => true])</div></td>
                        </tr>
                    @endforeach
                </table></div>
                {{ $invoices->links() }}
            @endif
        </div>
    @elseif ($state === 'paiements')
        <p class="soft small">{{ __('Les paiements des :v1 derniers jours qui n\'ont pas encore été confirmés au client.', ['v1' => \App\Http\Controllers\InvoiceMessageController::PAYMENT_DAYS]) }}</p>
        <div class="card">
            @if ($payments->isEmpty())
                <p class="empty">{{ __('Tous les paiements récents ont été confirmés.') }}</p>
            @else
                <div class="table-wrap cards-wide"><table id="t-confirmation">
                    <tr><x-sort-th :table="$payments" key="date" kind="date">{{ __('Date') }}</x-sort-th><th>{{ __('Client') }}</th><th>{{ __('Mois réglés') }}</th><x-sort-th :table="$payments" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><th></th></tr>
                    @foreach ($payments as $payment)
                        <tr>
                            <td>{{ $payment->paid_at->format('d/m/Y') }}</td>
                            <td><a href="{{ route('customers.show', $payment->customer_id) }}">{{ $payment->customer->name }}</a></td>
                            <td>{{ $payment->coveredLabel() }}</td>
                            <td class="right">{{ money($payment->amount) }}</td>
                            <td><div class="actions">@include('messages.payment-send', ['payment' => $payment, 'small' => true])</div></td>
                        </tr>
                    @endforeach
                </table></div>
                {{ $payments->links() }}
            @endif
        </div>
    @elseif ($state === 'coupures')
        <p class="soft small">{{ __('Les clients actifs qui ont :v1 mois en retard ou plus (réglable dans les paramètres) reçoivent un préavis avant la coupure. Le bouton redevient plein quand un nouveau mois passe en retard.', ['v1' => $cutoffThreshold]) }}</p>
        <div class="card">
            <h2>{{ __('Préavis de coupure') }}</h2>
            @if ($warnings->isEmpty())
                <p class="empty">{{ __('Aucun client n\'a atteint le seuil de coupure.') }}</p>
            @else
                <div class="table-wrap cards-wide"><table id="t-preavis">
                    <tr><x-sort-th :table="$warnings" key="customer">{{ __('Client') }}</x-sort-th><th>{{ __('Quartier') }}</th><x-sort-th :table="$warnings" key="months" kind="number" class="right">{{ __('Mois en retard') }}</x-sort-th><x-sort-th :table="$warnings" key="total" kind="number" class="right">{{ __('Total dû') }}</x-sort-th><th></th></tr>
                    @foreach ($warnings as $row)
                        <tr>
                            <td><a href="{{ route('customers.show', $row->customer) }}">{{ $row->customer->name }}</a></td>
                            <td>{{ $row->customer->neighbourhood ?: '—' }}</td>
                            <td class="right danger-text">{{ $row->invoices->count() }}</td>
                            <td class="right danger-text">{{ money($row->total) }}</td>
                            <td>
                                <div class="actions">
                                    @include('messages.cutoff-warning-send', ['customer' => $row->customer, 'waiting' => $row->waiting])
                                    <a class="btn danger small" href="{{ route('customers.show', $row->customer) }}#signal">{{ __('Couper') }}</a>
                                </div>
                                @if ($row->opened_at)<span class="soft small cell-line">{{ __('Préavis ouvert le :v1', ['v1' => $row->opened_at->format('d/m/Y')]) }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </table></div>
                {{ $warnings->links() }}
            @endif
        </div>

        <p class="soft small">{{ __('Les coupures et réabonnements des :v1 derniers jours dont l\'avis n\'a pas encore été ouvert.', ['v1' => \App\Http\Controllers\InvoiceMessageController::EVENT_DAYS]) }}</p>
        <div class="card">
            <h2>{{ __('Avis de coupure et de réabonnement') }}</h2>
            @if ($events->isEmpty())
                <p class="empty">{{ __('Aucun avis à envoyer.') }}</p>
            @else
                <div class="table-wrap cards-wide"><table id="t-avis">
                    <tr><x-sort-th :table="$events" key="date" kind="date">{{ __('Date') }}</x-sort-th><th>{{ __('Client') }}</th><th>{{ __('Avis') }}</th><th>{{ __('Motif') }}</th><th></th></tr>
                    @foreach ($events as $event)
                        <tr>
                            <td>{{ $event->happened_on->format('d/m/Y') }}</td>
                            <td><a href="{{ route('customers.show', $event->customer_id) }}">{{ $event->customer->name }}</a></td>
                            <td><span class="badge {{ $event->badge() }}">{{ $event->label() }}</span></td>
                            <td>{{ $event->note ?: '—' }}</td>
                            <td><div class="actions">@include('messages.event-send', ['event' => $event])</div></td>
                        </tr>
                    @endforeach
                </table></div>
                {{ $events->links() }}
            @endif
        </div>
    @else
        <p class="soft small">{{ __('Les clients actifs dont l\'échéance est dépassée. La relance reprend tous les mois en retard et annonce la coupure.') }}</p>
        <div class="card">
            @if ($late->isEmpty())
                <p class="empty">{{ __('Aucun client en retard.') }}</p>
            @else
                <div class="table-wrap cards-wide"><table id="t-relances">
                    <tr><x-sort-th :table="$late" key="customer">{{ __('Client') }}</x-sort-th><th>{{ __('Quartier') }}</th><th>{{ __('Mois en retard') }}</th><x-sort-th :table="$late" key="total" kind="number" class="right">{{ __('Total dû') }}</x-sort-th><x-sort-th :table="$late" key="due" kind="date">{{ __('Échéance') }}</x-sort-th><th></th></tr>
                    @foreach ($late as $row)
                        <tr>
                            <td><a href="{{ route('customers.show', $row->customer) }}">{{ $row->customer->name }}</a></td>
                            <td>{{ $row->customer->neighbourhood ?: '—' }}</td>
                            <td>{{ $row->invoices->count() }}</td>
                            <td class="right danger-text">{{ money($row->total) }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($row->oldest_due)->format('d/m/Y') }}</td>
                            <td>
                                <div class="actions">@include('messages.late-send', ['customer' => $row->customer, 'waiting' => $row->waiting])</div>
                                @if ($row->opened_at)<span class="soft small cell-line">{{ __('Dernière relance ouverte le :v1', ['v1' => $row->opened_at->format('d/m/Y')]) }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </table></div>
                {{ $late->links() }}
            @endif
        </div>
    @endif
@endsection
