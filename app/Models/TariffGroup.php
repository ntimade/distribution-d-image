<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Un tarif mensuel partagé par plusieurs clients. Le tarif personnel d'un client prime sur celui-ci.
class TariffGroup extends Model
{
    use BelongsToOrganisation;

    protected $fillable = ['name', 'monthly_fee', 'description'];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
