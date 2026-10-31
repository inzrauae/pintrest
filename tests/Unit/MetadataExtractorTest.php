<?php

namespace Tests\Unit;

use App\Services\Extraction\MetadataExtractor;
use Tests\TestCase;

class MetadataExtractorTest extends TestCase
{
    public function test_extracts_metadata_images_and_content(): void
    {
        $data = (new MetadataExtractor)->extract($this->articleHtml(), 'https://example.com/basil-guide?x=1');

        $this->assertSame('How to Grow Basil Indoors | Green Kitchen', $data['title']);
        $this->assertStringStartsWith('A step-by-step guide', $data['meta_description']);
        $this->assertSame('https://example.com/basil-guide', $data['canonical_url']);
        $this->assertSame('How to Grow Basil Indoors', $data['og_title']);
        $this->assertSame('https://example.com/img/basil.jpg', $data['og_image']);
        $this->assertSame('https://example.com/img/twitter.jpg', $data['twitter_image']);
        $this->assertSame('en', $data['page_language']);

        $urls = array_column($data['images'], 'url');
        $this->assertSame('https://example.com/img/basil.jpg', $urls[0]);
        $this->assertContains('https://example.com/img/step1.jpg', $urls);
        $this->assertNotContains('https://example.com/logo.png', $urls, 'logos inside <nav> are excluded');

        $this->assertStringContainsString('six hours of sunlight', $data['content']);
        $this->assertStringNotContainsString('tracking', $data['content'], 'scripts are stripped');
        $this->assertStringNotContainsString('Copyright', $data['content'], 'footer is stripped');
        $this->assertSame(['level' => 'h2', 'text' => 'Watering'], $data['headings'][1]);
    }

    public function test_reads_json_ld_product_data_and_srcset(): void
    {
        $html = '<html><head><title>Shop</title><script type="application/ld+json">'
            .json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Linen Apron', 'image' => ['https://shop.test/apron.jpg'], 'offers' => ['price' => '29.00', 'priceCurrency' => 'USD']])
            .'</script></head><body><main><p>Durable linen apron.</p>'
            .'<img srcset="/a-small.jpg 300w, /a-large.jpg 1200w" src="/a.jpg"></main></body></html>';

        $data = (new MetadataExtractor)->extract($html, 'https://shop.test/apron');

        $this->assertStringContainsString('Name: Linen Apron', $data['content']);
        $this->assertStringContainsString('Price: 29.00 USD', $data['content']);
        $urls = array_column($data['images'], 'url');
        $this->assertContains('https://shop.test/apron.jpg', $urls);
        $this->assertContains('https://shop.test/a-large.jpg', $urls);
    }

    public function test_html_is_sanitized(): void
    {
        $html = '<html><head><title>Hi &amp; <b>bold</b></title><meta name="description" content="<script>x</script>Safe text"></head><body><p>ok</p></body></html>';
        $data = (new MetadataExtractor)->extract($html, 'https://a.test/');

        $this->assertSame('Hi & bold', $data['title']);
        $this->assertSame('xSafe text', $data['meta_description']);
    }
}
