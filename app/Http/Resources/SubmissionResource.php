<?php

namespace App\Http\Resources;

use App\Models\UrlSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UrlSubmission */
class SubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'language' => $this->language,
            'image_mode' => $this->image_mode,
            'attempts' => $this->attempts,
            'url_batch_id' => $this->url_batch_id,
            'project_id' => $this->project_id,
            'processed_at' => $this->processed_at,
            'created_at' => $this->created_at,
            'pin' => $this->whenLoaded('pin', fn () => $this->pin ? [
                'id' => $this->pin->id,
                'title' => $this->pin->title,
                'status' => $this->pin->status,
            ] : null),
            'extracted' => $this->whenLoaded('extracted', fn () => $this->extracted ? [
                'title' => $this->extracted->title,
                'meta_description' => $this->extracted->meta_description,
                'canonical_url' => $this->extracted->canonical_url,
                'final_url' => $this->extracted->final_url,
                'og_title' => $this->extracted->og_title,
                'og_description' => $this->extracted->og_description,
                'og_image' => $this->extracted->og_image,
                'twitter_image' => $this->extracted->twitter_image,
                'headings' => $this->extracted->headings,
                'images' => $this->extracted->images,
                'content_excerpt' => mb_substr((string) $this->extracted->content, 0, 1500),
            ] : null),
        ];
    }
}
