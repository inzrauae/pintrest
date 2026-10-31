<?php

namespace Tests\Feature;

use App\Services\Fetching\FetchException;
use App\Services\Fetching\SafeHttpClient;
use App\Services\Fetching\UnsafeUrlException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafeFetchingTest extends TestCase
{
    private function client(): SafeHttpClient
    {
        return app(SafeHttpClient::class);
    }

    public function test_rejects_non_http_schemes_credentials_and_ports(): void
    {
        foreach (['ftp://example.com/x', 'file:///etc/passwd', 'gopher://example.com', 'https://user:pass@example.com/', 'http://example.com:22/'] as $url) {
            try {
                $this->client()->assertSafe($url);
                $this->fail("{$url} should be rejected");
            } catch (UnsafeUrlException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_private_and_metadata_destinations(): void
    {
        $this->dns['internal.example.com'] = ['10.0.0.12'];
        $this->dns['rebind.example.com'] = ['93.184.216.34', '127.0.0.1']; // any private IP poisons the host

        foreach (['http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/', 'http://localhost/', 'http://internal.example.com/', 'http://rebind.example.com/', 'http://2130706433/'] as $url) {
            $this->dns['2130706433'] = ['127.0.0.1'];
            try {
                $this->client()->assertSafe($url);
                $this->fail("{$url} should be rejected");
            } catch (UnsafeUrlException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_redirects_are_revalidated(): void
    {
        $this->dns['evil.test'] = ['93.184.216.34'];
        Http::fake([
            'https://evil.test/start' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
            '*' => Http::response('secret', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(UnsafeUrlException::class);
        $this->client()->get('https://evil.test/start', ['text/html']);
    }

    public function test_follows_safe_redirects_and_reports_final_url(): void
    {
        Http::fake([
            'https://example.com/old' => Http::response('', 301, ['Location' => '/new']),
            'https://example.com/new' => Http::response('<html><title>x</title></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $result = $this->client()->get('https://example.com/old', ['text/html']);

        $this->assertSame('https://example.com/new', $result->finalUrl);
        $this->assertSame('utf-8', $result->charset());
    }

    public function test_enforces_content_type_and_size_limits(): void
    {
        Http::fake([
            'https://example.com/file.zip' => Http::response('PK..', 200, ['Content-Type' => 'application/zip']),
            'https://example.com/huge' => Http::response(str_repeat('a', 5000), 200, ['Content-Type' => 'text/html']),
        ]);

        try {
            $this->client()->get('https://example.com/file.zip', ['text/html']);
            $this->fail('zip should be rejected');
        } catch (FetchException $e) {
            $this->assertStringContainsString('Unsupported content type', $e->getMessage());
        }

        $this->expectExceptionMessage('too large');
        $this->client()->get('https://example.com/huge', ['text/html'], 1000);
    }

    public function test_server_errors_are_retryable_client_errors_are_not(): void
    {
        Http::fake([
            'https://example.com/503' => Http::response('', 503),
            'https://example.com/404' => Http::response('', 404),
        ]);

        try {
            $this->client()->get('https://example.com/503', ['text/html']);
        } catch (FetchException $e) {
            $this->assertTrue($e->retryable);
        }
        try {
            $this->client()->get('https://example.com/404', ['text/html']);
        } catch (FetchException $e) {
            $this->assertFalse($e->retryable);
        }
    }
}
