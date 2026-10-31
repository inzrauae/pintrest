<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PinKeyword extends Model
{
    protected $fillable = ['generated_pin_id', 'keyword', 'type'];

    public function pin(): BelongsTo
    {
        return $this->belongsTo(GeneratedPin::class, 'generated_pin_id');
    }
}
