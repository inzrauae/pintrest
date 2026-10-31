<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PinAnalytic extends Model
{
    protected $table = 'pin_analytics';

    protected $fillable = ['generated_pin_id', 'date', 'impressions', 'saves', 'pin_clicks', 'outbound_clicks'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function pin(): BelongsTo
    {
        return $this->belongsTo(GeneratedPin::class, 'generated_pin_id');
    }
}
