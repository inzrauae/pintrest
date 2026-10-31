<?php

namespace Tests;

use App\Models\GeneratedPin;
use App\Models\PinterestAccount;
use App\Models\PinterestBoard;
use App\Models\User;
use App\Services\Fetching\HostResolver;
use App\Services\Images\PinImageService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /** host => list of IPs returned by the fake DNS resolver. */
    protected array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'services.openai.api_key' => null,
            'pinbot.ai.default_provider' => 'fake',
            'pinbot.images.default_provider' => 'none',
            'services.pinterest.client_id' => 'test-client',
            'services.pinterest.client_secret' => 'test-secret',
        ]);

        // Never touch real DNS in tests: unknown hosts resolve to a public documentation-free IP.
        $this->app->instance(HostResolver::class, new class($this) implements HostResolver
        {
            public function __construct(private TestCase $test) {}

            public function resolve(string $host): array
            {
                return $this->test->dnsFor($host);
            }
        });
    }

    public function dnsFor(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        return $this->dns[$host] ?? ['93.184.216.34'];
    }

    /** A real JPEG of the given size (GD). */
    protected function jpeg(int $w = 1200, int $h = 1800): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 200, 80, 90));
        ob_start();
        imagejpeg($img, null, 85);

        return (string) ob_get_clean();
    }

    protected function articleHtml(string $title = 'How to Grow Basil Indoors', string $image = 'https://example.com/img/basil.jpg'): string
    {
        return <<<HTML
<!doctype html><html lang="en"><head>
<title>{$title} | Green Kitchen</title>
<meta name="description" content="A step-by-step guide to growing fresh basil indoors on a sunny windowsill, with watering and pruning tips.">
<link rel="canonical" href="https://example.com/basil-guide">
<meta property="og:title" content="{$title}">
<meta property="og:description" content="Grow fresh basil indoors all year with this simple windowsill guide covering light, watering and pruning.">
<meta property="og:image" content="{$image}">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="1800">
<meta name="twitter:image" content="/img/twitter.jpg">
<script>var tracking = 1;</script>
</head><body>
<header><nav><img src="/logo.png" alt="logo"></nav></header>
<article>
<h1>{$title}</h1>
<p>Basil loves warmth and at least six hours of sunlight per day.</p>
<h2>Watering</h2>
<p>Water when the top inch of soil feels dry.</p>
<img src="/img/step1.jpg" width="800" height="600" alt="Basil seedlings">
</article>
<footer>Copyright</footer>
</body></html>
HTML;
    }

    /**
     * A user with an active Pinterest account, a cached board, and a draft Pin with a ready image.
     *
     * @return array{0: User, 1: GeneratedPin, 2: PinterestAccount}
     */
    protected function publishablePin(array $pinAttributes = [], string $source = 'uploaded'): array
    {
        $user = User::factory()->create();
        $account = PinterestAccount::create([
            'user_id' => $user->id,
            'pinterest_user_id' => 'pu_1',
            'username' => 'tester',
            'environment' => 'production',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addDays(10),
            'scopes' => 'boards:read,pins:read,pins:write,user_accounts:read',
            'status' => 'active',
        ]);
        PinterestBoard::create(['pinterest_account_id' => $account->id, 'board_id' => 'b_1', 'name' => 'Gardening']);

        $pin = GeneratedPin::create(array_merge([
            'user_id' => $user->id,
            'pinterest_account_id' => $account->id,
            'board_id' => 'b_1',
            'title' => 'How to Grow Basil Indoors',
            'description' => 'A practical windowsill guide to growing basil indoors with watering and pruning tips.',
            'destination_url' => 'https://example.com/basil-guide',
            'status' => 'draft',
        ], $pinAttributes));

        $service = app(PinImageService::class);
        if ($source === 'uploaded') {
            $file = UploadedFile::fake()->createWithContent('pin.jpg', $this->jpeg());
            $service->importUpload($pin, $file);
        } else {
            Http::fake(['https://example.com/img/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
            $service->importFromUrl($pin, 'https://example.com/img/x.jpg');
        }

        return [$user, $pin->fresh(), $account];
    }
}
