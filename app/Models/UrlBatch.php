<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UrlBatch extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'project_id', 'name', 'status', 'options', 'total'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(UrlSubmission::class);
    }

    /** @return array<string,int> */
    public function statusCounts(): array
    {
        return $this->submissions()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** Marks the batch completed once nothing is left to process. */
    public function refreshCompletion(): void
    {
        if (in_array($this->status, ['paused', 'cancelled'], true)) {
            return;
        }

        $open = $this->submissions()->whereIn('status', ['pending', 'processing'])->exists();
        $this->update(['status' => $open ? 'processing' : 'completed']);
    }
}
