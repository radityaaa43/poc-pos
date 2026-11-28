<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionDetailModifier extends Model
{
    protected $fillable = ['modifier_option_id', 'group_name', 'option_name', 'price_delta', 'components'];

    protected $casts = ['price_delta' => 'integer', 'components' => 'array'];

    protected $hidden = ['components'];
}
