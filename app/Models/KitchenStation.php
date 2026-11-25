<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KitchenStation extends Model
{
    use SoftDeletes;

    public const DEFAULT_NAME = 'Dapur';

    protected $fillable = ['name', 'is_default'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /** The default station first, then by name: the order of the settings list and the product select. */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('is_default')->orderBy('name');
    }

    /** The id of the default station (Dapur); creates it again if something wiped the table. */
    public static function defaultId(): int
    {
        return (int) static::firstOrCreate(['is_default' => true], ['name' => self::DEFAULT_NAME])->getKey();
    }
}
