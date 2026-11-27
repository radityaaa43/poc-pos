<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One option of a sold line, as it was at sale time (REQ-008). */
class TransactionDetailModifier extends Model
{
    protected $fillable = ['modifier_option_id', 'group_name', 'option_name', 'price_delta'];

    protected $casts = ['price_delta' => 'integer'];
}
