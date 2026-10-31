<?php

namespace App\Services\Fetching;

use App\Support\ActivityLogger;
use App\Support\UrlNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * SSRF-hardened HTTP GET for user-supplied URLs.
 *
 * - http/https only, on an allow-list of ports, no credentials in URLs
 * - every hostname is resolved and ALL resolved IPs must be public
 * - the connection is pinned to the validated IP (CURLOPT_RESOLVE) to defeat DNS rebinding
 * - redirects are followed manually so each hop is re-validated
 * - timeouts, response-size cap and content-type allow-list
 */
class SafeHttpClient
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Validate a URL and return [host, port, pinnedIp]. Throws UnsafeUrlException when not allowed.
     *
     * @return array{0:string,1:int,2:string}
     */
    public function assertSafe(string $url): array
    {
        if (strlen($url) > 2048) {
            throw new UnsafeUrlException('The URL is too long.');
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UnsafeUrlException('The URL is not valid.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException('Only http and https URLs are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('URLs containing credentials are not allowed.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, config('pinbot.fetch.allowed_ports'), true)) {
            throw new UnsafeUrlException("Port {$port} is not allowed.");
        }

        if (in_array($host, config('pinbot.fetch.blocked_hosts'), true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            $this->reject($url, 'blocked_hostname');
        }

        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw new FetchException('The website address could not be resolved (DNS lookup failed).', retryable: true);
        }

        foreach ($ips as $ip) {
            if (! IpGuard::isPublic($ip)) {
                $this->reject($url, 'private_ip', ['ip' => $ip]);
            }
        }

        // Prefer IPv4 for wider server compatibility.
        $ipv4 = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));

        return [$host, $port, $ipv4[0] ?? $ips[0]];
    }

    /**
     * @param  list<string>  $allowedMimes  e.g. ['text/html'] or ['image/*']
     */
    public function get(string $url, array $allowedMimes, ?int $maxBytes = null): FetchResult
    {
        $maxBytes ??= (int) config('pinbot.fetch.max_html_bytes');
        $maxRedirects = (int) config('pinbot.fetch.max_redirects');
        $current = $url;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            [$host, $port, $ip] = $this->assertSafe($current);

            $pinned = str_contains($ip, ':') ? "[{$ip}]" : $ip;

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'connect_timeout' => config('pinbot.fetch.connect_timeout'),
                    'timeout' => config('pinbot.fetch.timeout'),
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"]],
                ])->withHeaders([
                    'User-Agent' => config('pinbot.fetch.user_agent'),
                    'Accept' => in_array('text/html', $allowedMimes, true)
                        ? 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'
                        : 'image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8',
                ])->get($current);
            } catch (ConnectionException $e) {
                throw new FetchException('The website could not be reached (connection failed or timed out).', retryable: true, previous: $e);
            }

            $status = $response->status();

            if ($status >= 300 && $status < 400 && $response->header('Location')) {
                $next = UrlNormalizer::absolute($response->header('Location'), $current);
                if (! $next) {
                    throw new FetchException('The website redirected to an unsupported location.');
                }
                $current = $next;

                continue;
            }

            if ($status === 429 || $status >= 500) {
                throw new FetchException("The website responded with HTTP {$status}.", retryable: true, status: $status);
            }
            if ($status >= 400) {
                throw new FetchException("The website responded with HTTP {$status}.", retryable: false, status: $status);
            }

            $contentType = (string) $response->header('Content-Type');
            $mime = strtolower(trim(explode(';', $contentType)[0]));
            if (! $this->mimeAllowed($mime, $allowedMimes)) {
                throw new FetchException('Unsupported content type: '.($mime ?: 'unknown').'.');
            }

            $declared = (int) $response->header('Content-Length');
            if ($declared > $maxBytes) {
                throw new FetchException('The response is too large to process.');
            }

            $body = $this->readLimited($response->toPsrResponse()->getBody(), $maxBytes);

            return new FetchResult($url, $current, $status, $contentType, $body, $response->headers());
        }

        throw new FetchException('Too many redirects.');
    }

    private function readLimited($stream, int $maxBytes): string
    {
        $buffer = '';
        while (! $stream->eof()) {
            $buffer .= $stream->read(65536);
            if (strlen($buffer) > $maxBytes) {
                $stream->close();
                throw new FetchException('The response is too large to process.');
            }
        }

        return $buffer;
    }

    private function mimeAllowed(string $mime, array $allowed): bool
    {
        foreach ($allowed as $pattern) {
            if ($pattern === $mime || (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    private function reject(string $url, string $reason, array $context = []): never
    {
        ActivityLogger::security('ssrf_blocked', ['url' => $url, 'reason' => $reason] + $context + ['user_id' => auth()->id()]);

        throw new UnsafeUrlException('This address points to a private or restricted network and cannot be fetched.');
    }
}
