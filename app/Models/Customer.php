<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class Customer extends Model
{
    use BelongsToOrganisation;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const TERMINATED = 'terminated';

    protected $fillable = [
        'number', 'name', 'gender', 'phone', 'email', 'neighbourhood', 'address', 'landmark', 'latitude', 'longitude', 'tv_count',
        'tariff_group_id', 'monthly_fee', 'start_date', 'first_period', 'billing_from', 'due_day', 'status', 'status_since', 'notes',
    ];

    // Les formulaires envoient du texte : ces nombres restent des nombres, même juste après la création.
    protected $casts = ['due_day' => 'integer', 'tv_count' => 'integer', 'tariff_group_id' => 'integer', 'start_date' => 'date', 'status_since' => 'date', 'cutoff_warning_opened_at' => 'datetime'];

    protected static function booted(): void
    {
        // Numéro client suivant, propre à chaque compte.
        static::creating(function (Customer $customer) {
            // Valeurs par défaut : abonné aujourd'hui, échéance du compte, facturé dès ce mois.
            $customer->start_date ??= today();
            $customer->due_day ??= (int) (Organisation::whereKey($customer->organisation_id)->value('default_due_day') ?: 5);
            $customer->first_period ??= $customer->start_date->format('Y-m');
            $customer->billing_from ??= $customer->first_period;
            $customer->status ??= self::ACTIVE;
            $customer->status_since ??= $customer->start_date;

            if (! $customer->number) {
                $customer->number = (int) static::withoutGlobalScope('organisation')
                    ->where('organisation_id', $customer->organisation_id)->max('number') + 1;
            }
        });
    }

    public function tariffGroup(): BelongsTo
    {
        return $this->belongsTo(TariffGroup::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomerEvent::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('customers.status', self::ACTIVE);
    }

    public function scopeReachable(Builder $query): Builder
    {
        return $query->where('phone', 'like', '+%');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $digits = preg_replace('/[\s().\-+]/', '', $term);

        return $query->where(fn (Builder $where) => $where
            ->where('name', 'like', "%{$term}%")
            ->orWhere('neighbourhood', 'like', "%{$term}%")
            ->when(ctype_digit($digits), fn (Builder $number) => $number->orWhere('phone', 'like', "%{$digits}%")->orWhere('number', (int) $digits)));
    }

    public function numberLabel(): string
    {
        return 'N° '.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    // Le tarif appliqué aux prochaines factures : le tarif personnel, sinon celui du groupe.
    public function effectiveFee(): float
    {
        if ($this->monthly_fee !== null) {
            return (float) $this->monthly_fee;
        }

        return (float) ($this->tariffGroup?->monthly_fee ?? 0);
    }

    public function hasOwnFee(): bool
    {
        return $this->monthly_fee !== null;
    }

    public function tariffLabel(): string
    {
        if ($this->hasOwnFee()) {
            return $this->tariffGroup
                ? __('Tarif personnel (groupe :v1 ignoré)', ['v1' => $this->tariffGroup->name])
                : __('Tarif personnel');
        }

        return $this->tariffGroup ? __('Groupe :v1', ['v1' => $this->tariffGroup->name]) : __('Aucun tarif');
    }

    // Civilité des messages : « Monsieur/Madame » tant que le sexe n'est pas renseigné.
    public function title(): string
    {
        return match ($this->gender) {
            'M' => __('Monsieur'),
            'F' => __('Madame'),
            default => __('Monsieur/Madame'),
        };
    }

    // Pour les SMS : la civilité abrégée évite de payer un deuxième SMS.
    public function smsName(): string
    {
        $title = match ($this->gender) {
            'M' => __('M.'),
            'F' => __('Mme'),
            default => __('M./Mme'),
        };

        return $title.' '.$this->name;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::SUSPENDED;
    }

    public function isTerminated(): bool
    {
        return $this->status === self::TERMINATED;
    }

    public function statusLabel(): string
    {
        return self::statusName($this->status);
    }

    public static function statusName(string $status): string
    {
        return match ($status) {
            self::SUSPENDED => __('Coupé'),
            self::TERMINATED => __('Résilié'),
            default => __('Actif'),
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::SUSPENDED => 'ko',
            self::TERMINATED => 'off',
            default => 'ok',
        };
    }

    public function hasPosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    // « Bonamoussadi, rue 1.234, près de la pharmacie »
    public function place(): string
    {
        return collect([$this->neighbourhood, $this->address, $this->landmark])->filter()->implode(', ');
    }

    /**
     * L'échéance d'un mois : le jour choisi, ou le dernier jour des mois plus courts.
     * Le premier mois ne tombe jamais avant le début de l'abonnement.
     */
    public function dueDateFor(string $period): Carbon
    {
        $month = Carbon::createFromFormat('!Y-m', $period);
        $due = $month->copy()->day(min($this->due_day, $month->daysInMonth));

        return $due->lt($this->start_date) ? $this->start_date->copy() : $due;
    }

    public function unpaidTotal(): float
    {
        return round((float) $this->invoices()->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'), 3);
    }

    public function overdueTotal(): float
    {
        return round((float) $this->invoices()->overdue()->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'), 3);
    }

    public function overdueMonths(): int
    {
        return $this->invoices()->overdue()->count();
    }

    /**
     * Le dernier jour couvert par les mois entièrement réglés, à la veille de l'échéance suivante.
     * Null tant qu'aucun mois n'est réglé.
     */
    public function paidThrough(): ?Carbon
    {
        $lastPaid = null;

        foreach ($this->invoices()->orderBy('period')->get() as $invoice) {
            if ($invoice->balance() > 0) {
                break;
            }
            $lastPaid = $invoice;
        }

        return $lastPaid ? $this->dueDateFor(self::followingPeriod($lastPaid->period))->subDay() : null;
    }

    // La prochaine échéance à payer. Null pour un client coupé ou résilié qui a tout réglé.
    public function nextDueDate(): ?Carbon
    {
        $unpaid = $this->invoices()->unpaid()->orderBy('period')->first();

        if ($unpaid) {
            return $unpaid->due_date;
        }

        if (! $this->isActive()) {
            return null;
        }

        $last = $this->invoices()->max('period');
        $next = $last ? max(self::followingPeriod($last), $this->billing_from) : $this->billing_from;

        return $this->dueDateFor($next);
    }

    public function recordEvent(string $type, Carbon|string $date, ?string $note = null, ?float $amount = null): CustomerEvent
    {
        return $this->events()->create([
            'organisation_id' => $this->organisation_id,
            'user_id' => Auth::id(),
            'type' => $type,
            'happened_on' => $date,
            'amount' => $amount,
            'note' => $note,
        ]);
    }

    // La dernière coupure, pour l'avis de coupure à envoyer.
    public function lastSuspension(): ?CustomerEvent
    {
        return $this->events()->where('type', CustomerEvent::SUSPENDED)->latest('happened_on')->latest('id')->first();
    }

    public static function followingPeriod(string $period): string
    {
        return Carbon::createFromFormat('!Y-m', $period)->addMonthNoOverflow()->format('Y-m');
    }
}
