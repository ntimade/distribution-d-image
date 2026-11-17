<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use BelongsToOrganisation, SoftDeletes;

    protected $fillable = ['organisation_id', 'customer_id', 'user_id', 'amount', 'paid_at', 'method', 'reference'];

    protected $casts = ['paid_at' => 'date', 'whatsapp_opened_at' => 'datetime', 'sms_opened_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    // Le mode tel qu'il s'affiche : les modes connus sont traduits, un texte libre reste tel quel.
    public function methodLabel(): string
    {
        return self::methodName($this->method);
    }

    public static function methodName(?string $method): string
    {
        if (! $method) {
            return '—';
        }

        $label = config("payment_methods.{$method}");

        return $label ? __($label) : $method;
    }

    // « novembre 2026, décembre 2026 » : les mois réglés par ce paiement.
    public function coveredLabel(): string
    {
        return $this->allocations->sortBy(fn (PaymentAllocation $allocation) => $allocation->invoice->period)
            ->map(fn (PaymentAllocation $allocation) => $allocation->invoice->label())->implode(', ') ?: '—';
    }
}
