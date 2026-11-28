<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Declared here because the table was renamed by a migration (#366), which Larastan's migration scan does not follow.
 *
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property Carbon $created_at
 */
class KitchenPrintDevice extends Model
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

    /** The stations this device serves (#345): none means every station, so a single-station shop is unchanged. */
    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(KitchenStation::class, 'kitchen_print_device_station');
    }
}
