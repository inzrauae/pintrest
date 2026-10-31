<?php

namespace App\Http\Resources;

use App\Models\GeneratedPin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GeneratedPin */
class PinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $selected = $this->relationLoaded('images') ? $this->images->firstWhere('is_selected', true) : null;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'title' => $this->title,
            'description' => $this->description,
            'alt_text' => $this->alt_text,
            'destination_url' => $this->destination_url,
            'language' => $this->language,
            'keywords' => $this->whenLoaded('keywords', fn () => $this->keywordList('keyword')),
            'hashtags' => $this->whenLoaded('keywords', fn () => $this->keywordList('hashtag')),
            'suggested_board' => $this->suggested_board,
            'image_selection_reason' => $this->image_selection_reason,
            'image_generation_prompt' => $this->image_generation_prompt,
            'pinterest_account_id' => $this->pinterest_account_id,
            'board_id' => $this->board_id,
            'pin_template_id' => $this->pin_template_id,
            'project_id' => $this->project_id,
            'url_submission_id' => $this->url_submission_id,
            'pinterest_pin_id' => $this->pinterest_pin_id,
            'pinterest_url' => $this->pinterest_pin_id ? "https://www.pinterest.com/pin/{$this->pinterest_pin_id}/" : null,
            'error_message' => $this->error_message,
            'warnings' => $this->ai_metadata['warnings'] ?? [],
            'ai' => array_intersect_key($this->ai_metadata ?? [], array_flip(['provider', 'model', 'attempts'])),
            'image' => $selected,
            'images' => $this->whenLoaded('images', fn () => $this->images->sortByDesc('is_selected')->values()),
            'schedule' => $this->whenLoaded('schedule'),
            'account' => $this->whenLoaded('account', fn () => $this->account?->only(['id', 'username', 'environment', 'status'])),
            'board_name' => $this->board_name ?? null,
            'source_url' => $this->whenLoaded('submission', fn () => $this->submission?->url),
            'approved_at' => $this->approved_at,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
