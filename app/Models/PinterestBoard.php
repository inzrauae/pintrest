<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PinterestBoard extends Model
{
    use HasFactory;

    protected $table = 'pinterest_boards_cache';

    protected $fillable = [
        'pinterest_account_id', 'board_id', 'name', 'description', 'privacy', 'pin_count', 'image_url', 'synced_at',
    ];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PinterestAccount::class, 'pinterest_account_id');
    }
}
