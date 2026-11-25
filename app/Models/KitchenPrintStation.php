<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KitchenPrintStation extends Model
{
    protected $fillable = ['outlet_id', 'name'];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function prints(): HasMany
    {
        return $this->hasMany(KitchenTicketPrint::class);
    }
}
