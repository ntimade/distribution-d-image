<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Une étape de l'abonnement d'un client, gardée pour son historique.
class CustomerEvent extends Model
{
    use BelongsToOrganisation;

    public const SUBSCRIBED = 'subscribed';

    public const SUSPENDED = 'suspended';

    public const RECONNECTED = 'reconnected';

    public const TERMINATED = 'terminated';

    public const TARIFF_CHANGED = 'tariff_changed';

    protected $fillable = ['organisation_id', 'customer_id', 'user_id', 'type', 'happened_on', 'amount', 'note'];

    protected $casts = ['happened_on' => 'date', 'whatsapp_opened_at' => 'datetime', 'sms_opened_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return match ($this->type) {
            self::SUBSCRIBED => __('Abonnement'),
            self::SUSPENDED => __('Coupure'),
            self::RECONNECTED => __('Réabonnement'),
            self::TERMINATED => __('Résiliation'),
            self::TARIFF_CHANGED => __('Changement de tarif'),
            default => $this->type,
        };
    }

    public function badge(): string
    {
        return match ($this->type) {
            self::SUBSCRIBED, self::RECONNECTED => 'ok',
            self::SUSPENDED, self::TERMINATED => 'ko',
            default => 'off',
        };
    }
}
