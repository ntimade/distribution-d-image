<table class="history-table">
    <thead>
        <tr><th>{{ __('Date') }}</th><th>{{ __('Opération') }}</th><th>{{ __('Détail') }}</th><th class="right">{{ __('Facturé') }}</th><th class="right">{{ __('Payé') }}</th><th class="right">{{ __('Solde dû') }}</th></tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr @class(['history-event' => $row->kind === 'event'])>
                <td>{{ $row->date->format('d/m/Y') }}</td>
                <td>@if ($row->badge)<span class="badge {{ $row->badge }}">{{ $row->label }}</span>@else{{ $row->label }}@endif</td>
                <td>{{ $row->detail ?: '—' }}</td>
                <td class="right">{{ $row->debit !== null ? money($row->debit) : '' }}</td>
                <td class="right">{{ $row->credit !== null ? money($row->credit) : '' }}</td>
                <td class="right">{{ $row->balance !== null ? money($row->balance) : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="6">{{ __('Aucune opération.') }}</td></tr>
        @endforelse
    </tbody>
</table>
<p class="soft small">{{ __('Un solde négatif est une avance : des mois payés avant leur facture.') }}</p>
