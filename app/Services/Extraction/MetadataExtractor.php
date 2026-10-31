<?php

namespace App\Services\Extraction;

use App\Support\UrlNormalizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Pulls Pinterest-relevant metadata, images and readable text out of an HTML page.
 * All returned strings are plain text (tags stripped, whitespace collapsed).
 */
class MetadataExtractor
{
    private const NOISE_TAGS = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'form', 'nav', 'footer', 'header', 'aside'];

    private const IMAGE_NOISE = '/(logo|icon|sprite|avatar|emoji|pixel|spinner|loader|badge|banner-ad|\/ads?\/|gravatar|placeholder|blank\.|spacer|tracking|1x1)/i';

    public function extract(string $html, string $baseUrl, ?string $charset = null): array
    {
        $dom = $this->load($html, $charset);
        $xpath = new DOMXPath($dom);

        $meta = fn (string $q) => $this->clean($this->attr($xpath, $q, 'content'));

        $canonical = $this->attr($xpath, '//link[translate(@rel,"CANONICAL","canonical")="canonical"]', 'href');
        $base = $this->attr($xpath, '//base', 'href');
        $resolveBase = $base ? (UrlNormalizer::absolute($base, $baseUrl) ?? $baseUrl) : $baseUrl;

        $jsonLd = $this->jsonLd($xpath);

        $ogImage = UrlNormalizer::absolute($this->attr($xpath, '//meta[@property="og:image" or @property="og:image:url" or @name="og:image"]', 'content'), $resolveBase);
        $twitterImage = UrlNormalizer::absolute($this->attr($xpath, '//meta[@name="twitter:image" or @property="twitter:image" or @name="twitter:image:src"]', 'content'), $resolveBase);

        $data = [
            'title' => $this->clean($xpath->evaluate('string(//title)')),
            'meta_description' => $meta('//meta[translate(@name,"DESCRIPTION","description")="description"]'),
            'canonical_url' => $canonical ? UrlNormalizer::absolute($canonical, $resolveBase) : null,
            'og_title' => $meta('//meta[@property="og:title" or @name="og:title"]'),
            'og_description' => $meta('//meta[@property="og:description" or @name="og:description"]'),
            'og_image' => $ogImage,
            'twitter_image' => $twitterImage,
            'page_language' => $this->clean($xpath->evaluate('string(/html/@lang)')) ?: null,
            'headings' => $this->headings($xpath),
            'structured' => $jsonLd,
        ];

        $data['images'] = $this->images($xpath, $resolveBase, $ogImage, $twitterImage, $jsonLd['image'] ?? null);
        $data['content'] = $this->mainContent($dom, $xpath, $jsonLd);

        return $data;
    }

    private function load(string $html, ?string $charset): DOMDocument
    {
        if (! $charset && preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m)) {
            $charset = strtolower($m[1]);
        }
        if ($charset && ! in_array($charset, ['utf-8', 'utf8'], true)) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
            $html = $converted !== false ? $converted : $html;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The XML prolog forces libxml to treat the input as UTF-8. LIBXML_NONET blocks network access.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private function attr(DOMXPath $xpath, string $query, string $attr): ?string
    {
        $node = $xpath->query($query)->item(0);

        return $node instanceof DOMElement ? trim($node->getAttribute($attr)) ?: null : null;
    }

    public function clean(?string $text, int $max = 2000): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** @return list<array{level:string,text:string}> */
    private function headings(DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query('//h1|//h2|//h3') as $node) {
            $text = $this->clean($node->textContent, 200);
            if ($text) {
                $out[] = ['level' => strtolower($node->nodeName), 'text' => $text];
            }
            if (count($out) >= config('pinbot.extraction.max_headings')) {
                break;
            }
        }

        return $out;
    }

    /** Extracts name/description/image/type from the first useful JSON-LD block. */
    private function jsonLd(DOMXPath $xpath): array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $decoded = json_decode(trim($node->textContent), true);
            if (! is_array($decoded)) {
                continue;
            }
            $items = isset($decoded['@graph']) ? $decoded['@graph'] : (array_is_list($decoded) ? $decoded : [$decoded]);
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $type = is_array($item['@type'] ?? null) ? implode(',', $item['@type']) : ($item['@type'] ?? '');
                if (! preg_match('/Product|Article|BlogPosting|NewsArticle|Recipe|HowTo/i', $type)) {
                    continue;
                }
                $image = $item['image'] ?? null;
                if (is_array($image)) {
                    $image = $image['url'] ?? ($image[0]['url'] ?? ($image[0] ?? null));
                }
                $price = $item['offers']['price'] ?? ($item['offers'][0]['price'] ?? null);
                $currency = $item['offers']['priceCurrency'] ?? ($item['offers'][0]['priceCurrency'] ?? null);

                return array_filter([
                    'type' => $type,
                    'name' => $this->clean(is_string($item['name'] ?? $item['headline'] ?? null) ? ($item['name'] ?? $item['headline']) : null, 300),
                    'description' => $this->clean(is_string($item['description'] ?? null) ? $item['description'] : null, 1000),
                    'image' => is_string($image) ? $image : null,
                    'price' => is_scalar($price) && $price !== '' ? trim($price.' '.(is_string($currency) ? $currency : '')) : null,
                    'brand' => $this->clean(is_string($item['brand']['name'] ?? null) ? $item['brand']['name'] : (is_string($item['brand'] ?? null) ? $item['brand'] : null), 100),
                ]);
            }
        }

        return [];
    }

    /** @return list<array{url:string,alt:?string,width:?int,height:?int,source:string}> */
    private function images(DOMXPath $xpath, string $base, ?string $og, ?string $twitter, ?string $ld): array
    {
        $found = [];
        $add = function (?string $url, string $source, ?string $alt = null, ?int $w = null, ?int $h = null) use (&$found, $base) {
            $abs = UrlNormalizer::absolute($url, $base);
            if (! $abs || preg_match('/\.svg(\?|$)/i', $abs) || preg_match(self::IMAGE_NOISE, $abs)) {
                return;
            }
            if (($w !== null && $w < 100) || ($h !== null && $h < 100)) {
                return;
            }
            $key = strtok($abs, '#');
            if (! isset($found[$key])) {
                $found[$key] = ['url' => $abs, 'alt' => $alt, 'width' => $w, 'height' => $h, 'source' => $source];
            }
        };

        $ogWidth = (int) $this->attr($xpath, '//meta[@property="og:image:width"]', 'content') ?: null;
        $ogHeight = (int) $this->attr($xpath, '//meta[@property="og:image:height"]', 'content') ?: null;

        $add($og, 'og:image', null, $ogWidth, $ogHeight);
        $add($twitter, 'twitter:image');
        $add($ld, 'json-ld');
        $add($this->attr($xpath, '//link[@rel="image_src"]', 'href'), 'image_src');

        foreach ($xpath->query('//img') as $img) {
            /** @var DOMElement $img */
            if ($this->insideNoise($img)) {
                continue;
            }
            $src = $this->bestSrc($img);
            $w = (int) $img->getAttribute('width') ?: null;
            $h = (int) $img->getAttribute('height') ?: null;
            $add($src, 'img', $this->clean($img->getAttribute('alt'), 300), $w, $h);
            if (count($found) >= config('pinbot.extraction.max_images')) {
                break;
            }
        }

        return array_values($found);
    }

    private function bestSrc(DOMElement $img): ?string
    {
        foreach (['data-srcset', 'srcset'] as $attr) {
            $set = $img->getAttribute($attr);
            if ($set) {
                $best = null;
                $bestWidth = 0;
                foreach (explode(',', $set) as $candidate) {
                    $bits = preg_split('/\s+/', trim($candidate));
                    $width = isset($bits[1]) ? (int) $bits[1] : 1;
                    if ($bits[0] !== '' && $width >= $bestWidth) {
                        $best = $bits[0];
                        $bestWidth = $width;
                    }
                }
                if ($best) {
                    return $best;
                }
            }
        }

        foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attr) {
            $value = trim($img->getAttribute($attr));
            if ($value !== '' && ! str_starts_with($value, 'data:')) {
                return $value;
            }
        }

        return null;
    }

    private function insideNoise(DOMNode $node): bool
    {
        for ($n = $node->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
            if (in_array(strtolower($n->nodeName), ['nav', 'footer', 'header', 'aside'], true)) {
                return true;
            }
        }

        return false;
    }

    private function mainContent(DOMDocument $dom, DOMXPath $xpath, array $jsonLd): ?string
    {
        foreach (self::NOISE_TAGS as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $root = $xpath->query('//article')->item(0)
            ?? $xpath->query('//main')->item(0)
            ?? $xpath->query('//*[@role="main"]')->item(0)
            ?? $xpath->query('//*[@id="content" or @class="content" or @id="main"]')->item(0)
            ?? $dom->getElementsByTagName('body')->item(0);

        if (! $root) {
            return null;
        }

        $blocks = [];
        foreach ($xpath->query('.//h1|.//h2|.//h3|.//h4|.//p|.//li|.//blockquote|.//td|.//figcaption', $root) as $node) {
            $text = $this->clean($node->textContent, 1500);
            if ($text && mb_strlen($text) > 2 && ! in_array($text, $blocks, true)) {
                $blocks[] = $text;
            }
        }

        $text = $blocks ? implode("\n", $blocks) : (string) $this->clean($root->textContent, 50000);

        $prefix = [];
        foreach (['type' => 'Type', 'name' => 'Name', 'brand' => 'Brand', 'price' => 'Price', 'description' => 'Description'] as $key => $label) {
            if (! empty($jsonLd[$key])) {
                $prefix[] = "{$label}: {$jsonLd[$key]}";
            }
        }
        if ($prefix) {
            $text = "[Structured data]\n".implode("\n", $prefix)."\n\n".$text;
        }

        $text = trim($text);

        return $text === '' ? null : mb_substr($text, 0, (int) config('pinbot.extraction.max_content_chars'));
    }
}
