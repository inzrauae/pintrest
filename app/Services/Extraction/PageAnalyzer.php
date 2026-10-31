<?php

namespace App\Services\Extraction;

use App\Models\ExtractedPageData;
use App\Models\UrlSubmission;
use App\Services\Fetching\FetchException;
use App\Services\Fetching\SafeHttpClient;

/**
 * Fetches a submitted URL safely and stores the extracted page data.
 */
class PageAnalyzer
{
    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly MetadataExtractor $extractor,
    ) {}

    public function analyze(UrlSubmission $submission): ExtractedPageData
    {
        $result = $this->http->get($submission->url, ['text/html', 'application/xhtml+xml']);

        if (trim($result->body) === '') {
            throw new FetchException('The page returned no content.');
        }

        $data = $this->extractor->extract($result->body, $result->finalUrl, $result->charset());

        if (! $data['title'] && ! $data['og_title'] && ! $data['content']) {
            throw new FetchException('No readable content was found on this page.');
        }

        return ExtractedPageData::updateOrCreate(
            ['url_submission_id' => $submission->id],
            [
                'final_url' => $result->finalUrl,
                'canonical_url' => $data['canonical_url'],
                'http_status' => $result->status,
                'title' => $data['title'] ? mb_substr($data['title'], 0, 500) : null,
                'meta_description' => $data['meta_description'],
                'og_title' => $data['og_title'] ? mb_substr($data['og_title'], 0, 500) : null,
                'og_description' => $data['og_description'],
                'og_image' => $data['og_image'],
                'twitter_image' => $data['twitter_image'],
                'images' => $data['images'],
                'headings' => $data['headings'],
                'content' => $data['content'],
                'page_language' => $data['page_language'],
                'content_hash' => $data['content'] ? hash('sha256', $data['content']) : null,
            ],
        );
    }
}
