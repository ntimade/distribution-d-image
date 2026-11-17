@extends('layouts.app')
@section('title', __('Tableau de bord'))

@section('content')
    <div class="page-head"><h1>{{ __('Tableau de bord') }}</h1></div>

    @if ($customers === 0)
        <div class="card">
            <h2>{{ __('Pour commencer') }}</h2>
            <p class="soft">{{ __('Créez vos groupes tarifaires (vos offres et leurs prix), puis abonnez vos clients avec leur quartier et leur position sur la carte. Les factures se créent ensuite chaque mois.') }}</p>
            <div class="actions">
                <a class="btn" href="{{ route('groups.create') }}">{{ __('Créer un groupe tarifaire') }}</a>
                <a class="btn ghost" href="{{ route('customers.create') }}">{{ __('Abonner un client') }}</a>
            </div>
        </div>
    @endif

    @if ($remindersWaiting > 0)
        <div class="flash info">{{ __(':v1 rappel(s) d\'abonnement à envoyer aujourd\'hui.', ['v1' => $remindersWaiting]) }} <a href="{{ route('messages.index') }}">{{ __('Voir la liste') }}</a></div>
    @endif
    @if ($toCut > 0)
        <div class="flash error">{{ __(':v1 client(s) à couper pour impayés.', ['v1' => $toCut]) }} <a href="{{ route('cutoffs.index') }}">{{ __('Voir la liste') }}</a></div>
    @endif

    <div class="stats">
        <div class="stat"><span>{{ __('Clients actifs') }}</span><strong>{{ $active }}</strong></div>
        <div @class(['stat', 'alert' => $suspended > 0])><span>{{ __('Clients coupés') }}</span><strong>{{ $suspended }}</strong></div>
        <div class="stat"><span>{{ __('Revenu mensuel attendu') }}</span><strong>{{ money($expected) }}</strong></div>
        <div class="stat"><span>{{ __('Facturé :v1', ['v1' => of_period($period)]) }}</span><strong>{{ money($billed) }}</strong></div>
        <div class="stat"><span>{{ __('Encaissé ce mois-ci') }}</span><strong>{{ money($collected) }}</strong></div>
        <div class="stat"><span>{{ __('Reste à encaisser ce mois') }}</span><strong>{{ money($monthRemaining) }}</strong></div>
        <div @class(['stat', 'alert' => $overdueTotal > 0])><span>{{ __('Abonnements en retard') }}</span><strong>{{ money($overdueTotal) }}</strong></div>
        <div class="stat"><span>{{ __('Résiliés') }}</span><strong>{{ $terminated }}</strong></div>
    </div>

    <div class="card">
        <h2>{{ __('Échéances des 7 prochains jours') }}</h2>
        @if ($upcoming->isEmpty())
            <p class="empty">{{ __('Aucune échéance dans les 7 prochains jours.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table>
                <tr><th>{{ __('Échéance') }}</th><th>{{ __('Client') }}</th><th>{{ __('Quartier') }}</th><th class="right">{{ __('Reste à payer') }}</th></tr>
                @foreach ($upcoming as $invoice)
                    <tr>
                        <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                        <td><a href="{{ route('customers.show', $invoice->customer_id) }}">{{ $invoice->customer->name }}</a></td>
                        <td>{{ $invoice->customer->neighbourhood ?: '—' }}</td>
                        <td class="right">{{ money($invoice->balance()) }}</td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Derniers paiements') }}</h2>
        @if ($recentPayments->isEmpty())
            <p class="empty">{{ __('Aucun paiement.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table>
                <tr><th>{{ __('Date') }}</th><th>{{ __('Client') }}</th><th>{{ __('Mode') }}</th><th class="right">{{ __('Montant') }}</th></tr>
                @foreach ($recentPayments as $payment)
                    <tr>
                        <td>{{ $payment->paid_at->format('d/m/Y') }}</td>
                        <td><a href="{{ route('customers.show', $payment->customer_id) }}">{{ $payment->customer->name }}</a></td>
                        <td>{{ $payment->methodLabel() }}</td>
                        <td class="right">{{ money($payment->amount) }}</td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>
@endsection
