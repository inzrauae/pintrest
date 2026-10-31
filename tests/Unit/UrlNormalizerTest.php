<?php

namespace Tests\Unit;

use App\Support\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    public function test_equivalent_urls_normalize_to_the_same_value(): void
    {
        $a = UrlNormalizer::normalize('HTTPS://Example.com:443/blog/post/?utm_source=x&b=2&a=1#frag');
        $b = UrlNormalizer::normalize('https://example.com/blog/post?a=1&b=2');

        $this->assertSame($b, $a);
        $this->assertSame(UrlNormalizer::hash('https://example.com/blog/post?a=1&b=2&fbclid=z'), UrlNormalizer::hash($a));
    }

    public function test_different_paths_differ(): void
    {
        $this->assertNotSame(UrlNormalizer::hash('https://example.com/a'), UrlNormalizer::hash('https://example.com/b'));
    }

    public function test_resolves_relative_urls(): void
    {
        $base = 'https://example.com/blog/post/index.html';
        $this->assertSame('https://example.com/img/a.jpg', UrlNormalizer::absolute('/img/a.jpg', $base));
        $this->assertSame('https://cdn.example.com/a.jpg', UrlNormalizer::absolute('//cdn.example.com/a.jpg', $base));
        $this->assertSame('https://example.com/blog/post/a.jpg', UrlNormalizer::absolute('a.jpg', $base));
        $this->assertSame('https://example.com/blog/a.jpg', UrlNormalizer::absolute('../a.jpg', $base));
        $this->assertNull(UrlNormalizer::absolute('javascript:alert(1)', $base));
        $this->assertNull(UrlNormalizer::absolute('data:image/png;base64,xx', $base));
        $this->assertNull(UrlNormalizer::absolute('mailto:a@b.c', $base));
    }
}
