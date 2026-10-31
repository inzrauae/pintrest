# PinBot — AI-Powered Pinterest Pin Automation

Paste a URL (or hundreds), and PinBot securely extracts the page, writes an SEO-optimized Pin
title, description, keywords and alt text with AI, picks or generates a 2:3 image, lets you
review and edit everything, and publishes or schedules it through the **official Pinterest API v5**.

- **Backend:** Laravel 12 (PHP 8.2+), Sanctum SPA auth, Queues, Scheduler
- **Frontend:** React 18 + React Router + Tailwind CSS 4 (Vite)
- **Database:** MySQL 8 (SQLite works for local dev and tests)
- **AI:** OpenAI-compatible Chat Completions with Structured Outputs (any `OPENAI_BASE_URL`), plus an offline generator
- **Images:** Intervention Image 3 (Imagick when installed, otherwise GD)

---

## Features

| Area | What you get |
|---|---|
| Single URL | Validate → secure fetch → extract → AI copy → images → preview/edit → approve → publish or schedule |
| Bulk | Textarea or CSV upload, per-URL validation, background jobs, live progress, pause / resume / cancel / retry, CSV export |
| AI content | Structured JSON (`pin_title`, `pin_description`, `keywords`, `suggested_hashtags`, `image_selection_reason`, `image_generation_prompt`, `suggested_board`, `destination_url`, `alt_text`), schema-validated with retry, per-field regeneration, multi-language, no invented facts, duplicate-title avoidance |
| Images | Ranked page images (OG / Twitter / JSON-LD / `<img srcset>`), AI generation at 2:3, upload, crop editor, never upscales, optional text-overlay templates, private storage |
| Pinterest | OAuth (production + sandbox), encrypted tokens, continuous refresh, board sync / create, create Pin via `POST /v5/pins`, rate-limit back-off |
| Safety | SSRF protection, duplicate detection (URLs and Pins), image-rights confirmation, daily/hourly limits, publishing windows, opt-in auto-publish of **approved** Pins only |
| Ops | Dashboard, publishing queue, reports with daily chart, developer log with raw API errors, admin failed-jobs view |

---

## Quick start (local)

Requirements: PHP 8.2+ with `curl, dom, fileinfo, gd (or imagick), mbstring, openssl, pdo_mysql/pdo_sqlite, zip`, Composer 2, Node 20+.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# Local dev can use SQLite instead of MySQL:
#   DB_CONNECTION=sqlite   (and delete the other DB_* lines)
php artisan migrate --seed          # creates admin from SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD
npm run build                       # or `npm run dev` for hot reload

php artisan serve                   # http://127.0.0.1:8000
php artisan queue:work --queue=processing,publishing,default   # second terminal
php artisan schedule:work           # third terminal (scheduled & automatic publishing)
```

Sign in with the seeded admin (default `admin@example.com` / `password` unless you set `SEED_ADMIN_*`) and **change the password** in User Settings.

> Without `OPENAI_API_KEY` the **offline** text provider is used: it builds copy only from text on the page, so the whole flow works without any API key. Image generation needs a key.

---

## Configuration

All credentials live in `.env` and are **never sent to the browser**.

### Pinterest app

1. Create an app at <https://developers.pinterest.com/apps/>.
2. Add the redirect URI `https://YOUR-DOMAIN/pinterest/callback` (for local: `http://127.0.0.1:8000/pinterest/callback`). It must match `PINTEREST_REDIRECT_URI` exactly.
3. Set `PINTEREST_CLIENT_ID` and `PINTEREST_CLIENT_SECRET`.
4. Scopes requested: `boards:read, boards:write, pins:read, pins:write, user_accounts:read`.

**Access levels — important limitations (verify on Pinterest's site, as these change):**

- **Trial access:** Pinterest only allows Pin creation through the **sandbox** API (`api-sandbox.pinterest.com`); sandbox Pins are visible only to you. Use **Connect sandbox** on the Pinterest Accounts page.
- **Standard access** (after Pinterest's review) is required to publish real Pins with **Connect Pinterest**.
- **Scheduling:** the public v5 API has no "publish at" parameter, so PinBot schedules with Laravel's scheduler and creates the Pin at the chosen time (inside your publishing window and daily limit).
- PinBot uses only the official API: no browser automation, password collection, private endpoints or scraping of Pinterest.

### AI providers

| Variable | Purpose |
|---|---|
| `OPENAI_API_KEY` | Enables the `openai` text provider and image generation |
| `OPENAI_BASE_URL` | Any OpenAI-compatible endpoint (Azure OpenAI proxy, OpenRouter, a local LLM, …) |
| `OPENAI_MODEL` | Default text model (overridable per user in AI Settings) |
| `OPENAI_IMAGE_MODEL` | `gpt-image-1` (1024×1536 = 2:3) or `dall-e-3` |
| `PINBOT_AI_PROVIDER` | Force `openai` or `fake` (offline) as the default |

Add a provider by implementing `App\Services\Ai\LlmClient` (or `App\Services\Images\ImageGenerator`) and registering it in `LlmProviderFactory::PROVIDERS` (or `ImageGeneratorFactory::PROVIDERS`).

### Fetching & images

`PINBOT_FETCH_*` controls timeouts and size caps. `PINBOT_OVERLAY_FONT` is the TTF used for template text overlays (`apt install fonts-dejavu-core`).

---

## Architecture

```
app/
  Console/Commands/      pins:dispatch-scheduled, pins:auto-publish, pinterest:refresh-tokens
  Http/Controllers/Api/  thin REST controllers (validation + authorization)
  Http/Resources/        API response shapes
  Jobs/                  ProcessUrlSubmission, PublishPinJob (retries + exponential back-off)
  Models/                Eloquent models (tokens use the `encrypted` cast)
  Policies/              per-user ownership (data isolation)
  Services/
    Fetching/            SafeHttpClient (SSRF guard), IpGuard, HostResolver
    Extraction/          MetadataExtractor, PageAnalyzer
    Ai/                  PinContentGenerator, PinContentValidator, OpenAiClient, OfflineLlmClient
    Images/              ImageProcessor, PinImageService, ImageCandidateRanker, OpenAiImageGenerator
    Pinterest/           PinterestOAuthService, PinterestClient, BoardService, PinPublisher
    PinGenerationService, PublishingService, UrlSubmissionService, UsageLimiter
  Support/               UrlNormalizer, ActivityLogger (redacts secrets)
resources/js/            React SPA (pages/, components/, lib/)
```

### Pin lifecycle

`draft` → (`approved`) → `scheduled` → `publishing` → `published` · any failure → `failed` (retryable from the queue).

### Database tables

`users`, `user_preferences`, `pinterest_accounts`, `pinterest_boards_cache`, `projects`, `url_batches`, `url_submissions`, `extracted_page_data`, `generated_pins`, `pin_images`, `pin_keywords`, `pin_templates`, `publishing_jobs`, `scheduled_pins`, `publication_logs`, `ai_provider_settings`, `image_provider_settings` (+ Laravel's `jobs`, `failed_jobs`, `cache`, `sessions`).

### Main API endpoints

| Method | Path | |
|---|---|---|
| POST | `/api/urls/analyze` | single URL → Pin (409 if already submitted, `force: true` to override) |
| POST | `/api/urls/bulk` | `urls` text and/or `csv` file |
| GET | `/api/urls`, `/api/urls/{id}` | submissions |
| GET/POST | `/api/batches/{id}` `/pause` `/resume` `/cancel` `/retry` `/export` | bulk control |
| POST | `/api/pins/generate` | (re)generate from a submission |
| GET/PATCH/DELETE | `/api/pins/{id}` | read / edit / delete |
| POST | `/api/pins/{id}/regenerate` | `fields: [title, description, keywords, hashtags, alt_text, image_prompt, board]` |
| POST | `/api/pins/{id}/approve` `/publish` `/schedule` | `allow_duplicate` to override duplicate guard |
| POST | `/api/pins/{id}/images/upload` `/import` `/generate` | add images |
| GET | `/api/publishing-queue` | scheduled / approved / publishing / failed / jobs |
| POST | `/api/pinterest/connect` | returns the Pinterest authorize URL |
| GET | `/pinterest/callback` | OAuth redirect target (web route) |
| GET/POST | `/api/pinterest/boards` | list (`?refresh=1` to sync) / create |
| DELETE | `/api/pinterest/accounts/{id}` | disconnect (deletes tokens) |

All endpoints require authentication, enforce ownership, validate input, return JSON errors (`{message, errors}`) and paginate lists.

---

## Security

- **SSRF:** only `http/https`, ports 80/443/8080/8443, no URL credentials. Every hostname is resolved and **all** IPs must be public (blocks loopback, RFC1918, link-local/cloud metadata, CGNAT, multicast, IPv6 ULA/link-local, IPv4-mapped IPv6, NAT64…). The connection is **pinned to the validated IP** (`CURLOPT_RESOLVE`) to defeat DNS rebinding, and redirects are followed manually with each hop re-validated. Timeouts, response size caps and content-type allow-lists apply. Image bytes are verified with `finfo`, not the server's header.
- **Sanitisation:** extracted text is stripped of tags/entities; AI output is schema-validated and stripped; CSV exports neutralise formula injection.
- **Auth:** session cookies via Sanctum with CSRF protection, throttled login/registration, per-route rate limits (`api`, `generation`, `publishing`).
- **Secrets:** Pinterest tokens are encrypted at rest (`APP_KEY`), hidden from JSON, and redacted from logs. API keys only come from the environment.
- **Isolation:** every record is owned by a user and checked by a policy; images live on a private disk behind an authorized route.
- **Publishing safeguards:** nothing is published without user approval by default; auto-publish must be explicitly enabled with an acknowledgement and only ever publishes **approved** Pins; duplicate Pins (same destination + board) need confirmation; a per-Pin lock plus stored Pinterest Pin ID prevent double-creation; third-party images need a rights confirmation.

---

## Testing

```bash
php artisan test
```

The suite (unit + feature, 87 tests) uses in-memory SQLite, a fake DNS resolver and `Http::fake()`, so it never touches the network or creates real Pins. It covers SSRF/URL validation, redirects, size/content-type limits, metadata extraction, AI output validation and retries, image selection and processing, duplicate detection, OAuth (state, sandbox, failures), board pagination, Pin creation with mocked API responses (success, 400, 401 + refresh, revoked tokens, 429 back-off), scheduling and publishing windows, auto-publish safeguards, bulk processing (isolation, CSV, quotas, pause/cancel/retry), permissions and rate limiting.

---

## Deployment (Ubuntu 22.04/24.04 VPS, Nginx)

```bash
sudo apt update
sudo apt install -y nginx mysql-server supervisor unzip git fonts-dejavu-core \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  php8.3-gd php8.3-imagick php8.3-bcmath php8.3-intl
# Composer: https://getcomposer.org/download/  ·  Node 20: https://github.com/nodesource/distributions

sudo mysql -e "CREATE DATABASE pinbot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'pinbot'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
  GRANT ALL ON pinbot.* TO 'pinbot'@'localhost'; FLUSH PRIVILEGES;"

cd /var/www && sudo git clone <your-repo> pinbot && cd pinbot
sudo chown -R www-data:www-data /var/www/pinbot
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci && sudo -u www-data npm run build
sudo -u www-data cp .env.example .env    # set APP_ENV=production, APP_DEBUG=false, APP_URL=https://…, DB_*, PINTEREST_*, OPENAI_*
sudo -u www-data php artisan key:generate
sudo -u www-data php artisan migrate --force --seed
sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache && sudo -u www-data php artisan view:cache
```

In production `.env`, also set `SANCTUM_STATEFUL_DOMAINS=your-domain.com`, `SESSION_SECURE_COOKIE=true`, and `SESSION_DOMAIN=your-domain.com`.

**Nginx** (`/etc/nginx/sites-available/pinbot`):

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/pinbot/public;
    index index.php;
    client_max_body_size 20M;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 300;   # single-URL generation runs synchronously
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/pinbot /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx && sudo certbot --nginx -d your-domain.com
```

Also raise `max_execution_time = 300` in `/etc/php/8.3/fpm/php.ini`.

**Queue worker** (`/etc/supervisor/conf.d/pinbot-worker.conf`):

```ini
[program:pinbot-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/pinbot/artisan queue:work --queue=publishing,processing,default --sleep=3 --tries=1 --max-time=3600 --timeout=300
user=www-data
numprocs=2
autostart=true
autorestart=true
stopwaitsecs=320
redirect_stderr=true
stdout_logfile=/var/www/pinbot/storage/logs/worker.log
```

(`--tries=1` is intentional: the jobs manage their own retries and back-off.)

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start pinbot-worker:*
```

**Scheduler** (`sudo crontab -u www-data -e`):

```cron
* * * * * cd /var/www/pinbot && php artisan schedule:run >> /dev/null 2>&1
```

**Updating:** `git pull && composer install --no-dev -o && npm ci && npm run build && php artisan migrate --force && php artisan optimize && sudo supervisorctl restart pinbot-worker:*`

### Apache alternative

Point `DocumentRoot` to `/var/www/pinbot/public`, enable `mod_rewrite` and `AllowOverride All` (Laravel's `public/.htaccess` handles routing), and use `php8.3-fpm` via `proxy_fcgi`.

---

## Scheduled commands

| Command | Frequency | Purpose |
|---|---|---|
| `pins:dispatch-scheduled` | every minute | publish due scheduled Pins (respects pause, window, daily limit) |
| `pins:auto-publish` | every 5 min | opt-in: publish approved Pins one at a time with spacing |
| `pinterest:refresh-tokens` | daily | refresh tokens expiring within 3 days |
| `queue:prune-failed` | daily | keep 30 days of failed jobs |
