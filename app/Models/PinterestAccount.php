<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PinterestAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'pinterest_user_id', 'username', 'profile_image', 'account_type', 'environment',
        'access_token', 'refresh_token', 'token_expires_at', 'refresh_token_expires_at', 'scopes',
        'status', 'last_error', 'connected_at',
    ];

    // Tokens never leave the backend.
    protected $hidden = ['access_token', 'refresh_token', 'last_error'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function boards(): HasMany
    {
        return $this->hasMany(PinterestBoard::class);
    }

    public function isSandbox(): bool
    {
        return $this->environment === 'sandbox';
    }

    public function tokenExpiresSoon(int $seconds = 300): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->lte(now()->addSeconds($seconds));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, preg_split('/[\s,]+/', (string) $this->scopes), true);
    }
}
