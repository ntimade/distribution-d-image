<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToOrganisation;

    protected $fillable = ['organisation_id', 'customer_id', 'period', 'due_date', 'amount', 'reconnection_fee', 'paid_amount', 'notes'];

    protected $casts = [
        'due_date' => 'date',
        'whatsapp_opened_at' => 'datetime',
        'sms_opened_at' => 'datetime',
        'reminder_opened_at' => 'datetime',
        'late_notice_opened_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereColumn('paid_amount', '<', 'amount');
    }

    // En retard à partir du lendemain de l'échéance.
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->unpaid()->where('due_date', '<', today()->toDateString());
    }

    // Mois à échéance aujourd'hui ou dans les prochains jours.
    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        // Borne de fin exclue : le résultat ne dépend pas du format de date de la base.
        return $query->unpaid()
            ->where('due_date', '>=', today()->toDateString())
            ->where('due_date', '<', today()->addDays($days + 1)->toDateString());
    }

    public function scopeReachable(Builder $query): Builder
    {
        return $query->whereHas('customer', fn (Builder $customer) => $customer->where('phone', 'like', '+%'));
    }

    public function scopeUnreachable(Builder $query): Builder
    {
        return $query->whereDoesntHave('customer', fn (Builder $customer) => $customer->where('phone', 'like', '+%'));
    }

    public function number(): string
    {
        return 'TV-'.str_replace('-', '', $this->period).'-'.$this->id;
    }

    public function label(): string
    {
        return period_label($this->period);
    }

    public function balance(): float
    {
        return round((float) $this->amount - (float) $this->paid_amount, 3);
    }

    // L'abonnement du mois, sans les frais de réabonnement.
    public function subscriptionAmount(): float
    {
        return round((float) $this->amount - (float) $this->reconnection_fee, 3);
    }

    // Tous les mois encore dus par ce client, du plus ancien au plus récent, celui-ci compris.
    public function unpaidMonths(): Collection
    {
        return self::unpaid()->where('customer_id', $this->customer_id)->orderBy('period')->get();
    }

    // Un mois réglé n'a plus aucune facture ni rappel à envoyer : seule la confirmation de paiement reste.
    public function canBeSent(): bool
    {
        return $this->balance() > 0;
    }

    public function isOverdue(): bool
    {
        return $this->balance() > 0 && $this->due_date->lt(today());
    }

    // Réglée alors que son échéance n'est pas encore arrivée.
    public function isPrepaid(): bool
    {
        return $this->balance() <= 0 && (float) $this->amount > 0 && $this->due_date->gt(today());
    }

    public function statusKey(): string
    {
        return match (true) {
            $this->isPrepaid() => 'prepaid',
            $this->balance() <= 0 => 'paid',
            $this->isOverdue() => 'late',
            (float) $this->paid_amount > 0 => 'partial',
            default => 'open',
        };
    }
}
