<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function pinterestAccounts(): HasMany
    {
        return $this->hasMany(PinterestAccount::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function urlSubmissions(): HasMany
    {
        return $this->hasMany(UrlSubmission::class);
    }

    public function urlBatches(): HasMany
    {
        return $this->hasMany(UrlBatch::class);
    }

    public function pins(): HasMany
    {
        return $this->hasMany(GeneratedPin::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(PinTemplate::class);
    }

    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function aiSettings(): HasOne
    {
        return $this->hasOne(AiProviderSetting::class);
    }

    public function imageSettings(): HasOne
    {
        return $this->hasOne(ImageProviderSetting::class);
    }

    /** Preferences row, created with defaults on first access. */
    public function preferences(): UserPreference
    {
        return $this->preference()->firstOrCreate([]);
    }

    public function aiSettingsOrDefault(): AiProviderSetting
    {
        return $this->aiSettings()->firstOrCreate([], [
            'provider' => config('pinbot.ai.default_provider'),
            'model' => config('services.openai.model'),
        ]);
    }

    public function imageSettingsOrDefault(): ImageProviderSetting
    {
        return $this->imageSettings()->firstOrCreate([], [
            'provider' => config('pinbot.images.default_provider'),
            'model' => config('services.openai.image_model'),
        ]);
    }
}
