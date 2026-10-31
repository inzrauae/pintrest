<?php

namespace App\Services\Fetching;

class FetchResult
{
    public function __construct(
        public readonly string $requestedUrl,
        public readonly string $finalUrl,
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body,
        public readonly array $headers = [],
    ) {}

    public function mime(): string
    {
        return strtolower(trim(explode(';', $this->contentType)[0]));
    }

    public function charset(): ?string
    {
        return preg_match('/charset=([\w-]+)/i', $this->contentType, $m) ? strtolower($m[1]) : null;
    }
}
