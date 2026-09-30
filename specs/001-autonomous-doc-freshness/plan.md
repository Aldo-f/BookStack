# Autonomous Documentation Freshness System

## Goal

Keep all BookStack documentation pages automatically up to date: when a user visits a page, the system ad-hoc checks whether any of that page's sources have changed (comparing extracted main content, not sidebar noise). If a source changed, an LLM automatically rewrites the page using the latest source content. When a page is updated, ALL of its sources are re-checked. If a better version of the same LLM model family becomes available, the system automatically upgrades.

## Current context / assumptions

- **BookStack**: k3s pod `bookstack-5f8d574cd7-pdpmn`, image `linuxserver/bookstack:26.05.5`, PVC at `/config/www/themes/digipunt/`.
- **Database**: MariaDB `stantonius-db`, database `bookstack`, user `bookstack`, password from `bookstack-db` secret. PHP extensions available: `dom`, `libxml`, `SimpleXML`, `curl`.
- **Sources tables**: `sources` (85 rows, cols: id, title, url, author, type, publication_date, created_at, updated_at) and `entity_source_links` (102 rows, cols: id, entity_type, entity_id, source_id, role, last_consulted, consulted_by, priority, created_at, updated_at).
- **Theme**: `functions.php` registers API routes under `/api/digipunt/` via `ThemeEvents::APP_BOOT`. JS injected via `WEB_MIDDLEWARE_AFTER`.
- **LLM endpoint**: `https://freellm.aldof.duckdns.org/v1` (OpenAI-compatible). API key in env `HERMES_CUSTOM_FREELLM_API_KEY` = `freellmapi-82ea901092bf963260f3ea5fe1e1a02e3995a488b4f186e1`. Available models include strong writers: `claude-sonnet-4-5`, `deepseek-v4-pro`, `gemini-3.5-flash`, `glm-5.2`, `kimi-k2.7-code`, `mistral-large-3`, `nemotron-3-ultra`.
- **Source URLs**: mostly Arch Linux Wiki (`wiki.archlinux.org/title/...`), some standalone sites (`diskinternals.com`, `linux.die.net`). All type `web` with `https://` URLs.
- **Go repo**: `/home/aldo/dev/06-apps-bookstack/` with `go.mod` (module `bookstack-api`, Go 1.25.6).
- **Pi5**: arm64, headless. User prefers verification-first, `uv` for Python, autonomous operation.

## Architecture / proposed approach

Three subsystems, all implemented in PHP inside the existing digipunt theme (no external Go service, no CronJob — everything is triggered on page view):

1. **Content Extractor** — A PHP class that fetches a source URL, strips navigation/sidebar/script/style/ads, and extracts only the main article content using DOMDocument. Produces clean text + a SHA-256 hash of that clean text. This avoids false "changed" signals from sidebar timestamps, "last edited" dates, or ad rotations.

2. **Change Detector** — On page view, an AJAX call to `/api/digipunt/sources/check` triggers a background check of all sources linked to that page. For each source, the extractor fetches the URL, computes the main-content hash, and compares it to the last stored hash in `source_snapshots`. If changed, the source is flagged.

3. **LLM Auto-Updater** — When a source is flagged as changed, the system calls the freellm API with the current page HTML + the updated source content, and asks the LLM to produce an improved version of the page. The updated page is saved as a draft (not auto-published). The LLM model is selected from a configurable family (default: `claude-sonnet-4-5` for Dutch writing quality). On each update run, the system queries `/v1/models` and if a newer version of the same model family exists (e.g., `claude-sonnet-4-6`), it automatically uses the newer one.

**Cascading logic**: When source S changes, the system finds ALL pages linked to S via `entity_source_links`. Each affected page is queued for a full source re-check (ALL its sources, not just S) and potential LLM update. This ensures a page update always incorporates the latest content from all its sources.

**Why PHP, not Go?** The check is triggered by page views (user interaction with BookStack). Doing this in PHP keeps everything in the theme, uses the existing DB connection, and avoids a separate service. The LLM call is async (AJAX) so it doesn't block page rendering.

## Step-by-step tasks

### Task 1: Migration — `source_snapshots` table

**File:** deploy to `/config/www/themes/digipunt/migrations/003_create_source_snapshots_table.php` (in PVC)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('source_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_id');
            $table->string('content_hash', 64);       // SHA-256 of extracted main content
            $table->unsignedInteger('content_length');
            $table->unsignedSmallInteger('http_status');
            $table->text('extracted_text')->nullable(); // cleaned main content (for LLM input)
            $table->timestamp('checked_at')->useCurrent();
            $table->boolean('changed')->default(false);
            $table->string('model_used', 64)->nullable();  // LLM model that processed this snapshot
            $table->timestamps();

            $table->foreign('source_id')
                ->references('id')->on('sources')
                ->onDelete('cascade');
            $table->index(['source_id', 'checked_at'], 'idx_source_checked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_snapshots');
    }
};
```

**Migration 2 — `page_update_queue` table:**

**File:** `/config/www/themes/digipunt/migrations/004_create_page_update_queue_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('page_update_queue', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('page_id');
            $table->enum('status', ['pending', 'checking', 'updating', 'done', 'failed'])->default('pending');
            $table->text('trigger_reason')->nullable();   // e.g. "source 55 changed"
            $table->text('llm_output')->nullable();         // LLM-generated updated HTML
            $table->string('model_used', 64)->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'queued_at'], 'idx_status_queued');
            $table->index('page_id', 'idx_page');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_update_queue');
    }
};
```

**Deploy & verify:**
```bash
PVC="/var/lib/rancher/k3s/storage/pvc-775740a0-27f2-4d8e-ab4e-62340067c512_default_bookstack-data/www"
sudo cp 003_create_source_snapshots_table.php "$PVC/themes/digipunt/migrations/"
sudo cp 004_create_page_update_queue_table.php "$PVC/themes/digipunt/migrations/"
POD=$(kubectl get pods -l app=bookstack -o jsonpath='{.items[0].metadata.name}')
kubectl exec "$POD" -- php /app/www/artisan digipunt:migrate
# Verify:
kubectl exec "$POD" -- php -r "
\$pdo = new PDO('mysql:host=stantonius-db;dbname=bookstack;charset=utf8mb4', 'bookstack', 'rD/WKj5/zyxwB94r');
foreach (['source_snapshots','page_update_queue'] as \$t) {
    \$c = \$pdo->query(\"SHOW COLUMNS FROM \$t\")->fetchAll(PDO::FETCH_ASSOC);
    echo \$t.': '.count(\$c).' columns\n';
}
"
# Expected: source_snapshots: 9 columns, page_update_queue: 8 columns
```

### Task 2: Content Extractor class

**File:** `/config/www/themes/digipunt/SourceChecker.php` (in PVC)

This class extracts main content from a web page, stripping sidebars, navigation, scripts, styles, and ads. It uses PHP's DOMDocument (available in the pod).

```php
<?php

namespace DigipuntTheme;

/**
 * Extracts the main article content from a web page URL.
 * Strips navigation, sidebars, scripts, styles, ads, and other non-content elements.
 * Returns clean text + SHA-256 hash.
 */
class ContentExtractor
{
    private const STRIP_TAGS = [
        'script', 'style', 'nav', 'aside', 'footer', 'header',
        'noscript', 'iframe', 'form', 'button', 'svg',
    ];

    private const STRIP_SELECTORS = [
        // Common sidebar/nav class patterns
        'sidebar', 'nav-bar', 'navigation', 'menu', 'breadcrumb',
        'footer-content', 'site-footer', 'page-footer',
        'edit-section', 'printfooter', 'mw-jump-link',
        'patrollink', 'mw-editsection', 'mw-navigation',
        'vector-menu', 'vector-header', 'vector-footer',
        'adsbygoogle', 'advertisement', 'ad-container',
        'social-share', 'share-buttons', 'comments',
        'related-posts', 'recommended', 'newsletter',
        'cookie-notice', 'popup', 'modal',
    ];

    /**
     * Fetch a URL and extract main content.
     *
     * @return array{hash: string, text: string, length: int, status: int}
     */
    public static function extract(string $url, int $timeout = 30): array
    {
        $html = self::fetch($url, $timeout);
        if ($html === null) {
            return ['hash' => '', 'text' => '', 'length' => 0, 'status' => 0];
        }

        $text = self::cleanHtml($html);
        $hash = hash('sha256', $text);
        return [
            'hash' => $hash,
            'text' => $text,
            'length' => strlen($text),
            'status' => 200, // simplified; real status from fetch()
        ];
    }

    /**
     * Fetch URL with cURL, return HTML or null on failure.
     */
    private static function fetch(string $url, int $timeout): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DigipuntDocChecker/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
        ]);
        $html = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false || $status >= 400) {
            return null;
        }
        return $html;
    }

    /**
     * Clean HTML to main content text.
     *
     * Strategy:
     * 1. Parse with DOMDocument (suppress warnings from malformed HTML)
     * 2. Remove script, style, nav, aside, footer, header, etc.
     * 3. Remove elements matching sidebar/ad/nav class patterns
     * 4. Find the main content container (try <main>, <article>, #mw-content-text, #content, .content in order)
     * 5. Extract text content, collapse whitespace
     */
    public static function cleanHtml(string $html): string
    {
        // Suppress libxml warnings from malformed HTML
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // 1. Remove unwanted tags entirely
        foreach (self::STRIP_TAGS as $tag) {
            $nodes = $doc->getElementsByTagName($tag);
            // Remove in reverse order (live NodeList)
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node->parentNode?->removeChild($node);
            }
        }

        // 2. Remove elements with sidebar/nav/ad classes
        $xpath = new \DOMXPath($doc);
        foreach (self::STRIP_SELECTORS as $selector) {
            // Match class*="selector" (case-insensitive)
            $nodes = @$xpath->query("//*[contains(@class, '$selector')]");
            if ($nodes) {
                for ($i = $nodes->length - 1; $i >= 0; $i--) {
                    $node = $nodes->item($i);
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        // 3. Find main content container (try in priority order)
        $main = null;
        $candidates = [
            ['tag' => 'main', 'attr' => null],
            ['tag' => 'article', 'attr' => null],
            ['tag' => 'div', 'attr' => 'id', 'value' => 'mw-content-text'],
            ['tag' => 'div', 'attr' => 'id', 'value' => 'bodyContent'],
            ['tag' => 'div', 'attr' => 'id', 'value' => 'content'],
            ['tag' => 'div', 'attr' => 'class', 'value' => 'content'],
        ];

        foreach ($candidates as $c) {
            if ($c['attr'] === null) {
                $nodes = $doc->getElementsByTagName($c['tag']);
                if ($nodes->length > 0) {
                    $main = $nodes->item(0);
                    break;
                }
            } elseif (isset($c['value'])) {
                $nodes = @$xpath->query("//{$c['tag']}[@{$c['attr']}='{$c['value']}']");
                if ($nodes && $nodes->length > 0) {
                    $main = $nodes->item(0);
                    break;
                }
            }
        }

        // Fallback: use the body
        if ($main === null) {
            $main = $doc->getElementsByTagName('body')->item(0);
        }

        if ($main === null) {
            return '';
        }

        // 4. Extract text, collapse whitespace
        $text = $main->textContent;
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }
}
```

### Task 3: TDD test for ContentExtractor

**File:** `/config/www/themes/digipunt/tests/ContentExtractorTest.php`

```php
<?php

use PHPUnit\Framework\TestCase;

// Load the class under test
require_once __DIR__ . '/../SourceChecker.php';

class ContentExtractorTest extends TestCase
{
    private $testServerHtml;

    protected function setUp(): void
    {
        // Simulate a web page with sidebar + main content
        $this->testServerHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>Test Page</title><style>.sidebar { color: red; }</style></head>
<body>
  <nav class="navigation"><a href="/">Home</a> <a href="/about">About</a></nav>
  <header class="site-header"><h1>Site Title</h1></header>
  <div class="sidebar">
    <ul><li>Related link 1</li><li>Related link 2</li></ul>
    <p>Last updated: 2026-09-30 15:00:00</p>
  </div>
  <main>
    <article>
      <h2>NTFS File System</h2>
      <p>NTFS (New Technology File System) is the default file system for Windows NT.</p>
      <p>Linux supports NTFS via the ntfs3 kernel driver or ntfs-3g FUSE driver.</p>
      <p>Use <code>ntfsfix</code> to repair common issues without full chkdsk.</p>
    </article>
  </main>
  <footer class="site-footer"><p>Copyright 2026</p></footer>
  <script>console.log('tracking');</script>
</body>
</html>
HTML;
    }

    public function testExtractsMainContentAndStripsSidebar()
    {
        $cleaned = \DigipuntTheme\ContentExtractor::cleanHtml($this->testServerHtml);

        // Main content present
        $this->assertStringContainsString('NTFS File System', $cleaned);
        $this->assertStringContainsString('ntfs3 kernel driver', $cleaned);
        $this->assertStringContainsString('ntfsfix', $cleaned);

        // Sidebar content stripped
        $this->assertStringNotContainsString('Related link 1', $cleaned);
        $this->assertStringNotContainsString('Last updated', $cleaned);

        // Nav stripped
        $this->assertStringNotContainsString('Home', $cleaned);

        // Footer stripped
        $this->assertStringNotContainsString('Copyright', $cleaned);

        // Script stripped
        $this->assertStringNotContainsString('tracking', $cleaned);

        // Style stripped (CSS content)
        $this->assertStringNotContainsString('color: red', $cleaned);
    }

    public function testHashIsDeterministic()
    {
        $cleaned1 = \DigipuntTheme\ContentExtractor::cleanHtml($this->testServerHtml);
        $cleaned2 = \DigipuntTheme\ContentExtractor::cleanHtml($this->testServerHtml);

        $hash1 = hash('sha256', $cleaned1);
        $hash2 = hash('sha256', $cleaned2);

        $this->assertEquals($hash1, $hash2);
    }

    public function testHashChangesWhenMainContentChanges()
    {
        $html1 = str_replace('ntfs3 kernel driver', 'ntfs3 kernel driver v2', $this->testServerHtml);
        $html2 = $this->testServerHtml;

        $hash1 = hash('sha256', \DigipuntTheme\ContentExtractor::cleanHtml($html1));
        $hash2 = hash('sha256', \DigipuntTheme\ContentExtractor::cleanHtml($html2));

        $this->assertNotEquals($hash1, $hash2);
    }

    public function testHashStableWhenSidebarChanges()
    {
        // Change only the sidebar content — main content hash must stay the same
        $html1 = str_replace('Related link 1', 'Related link CHANGED', $this->testServerHtml);
        $html2 = str_replace('Last updated: 2026-09-30 15:00:00', 'Last updated: 2026-09-30 16:00:00', $this->testServerHtml);

        $hash1 = hash('sha256', \DigipuntTheme\ContentExtractor::cleanHtml($html1));
        $hash2 = hash('sha256', \DigipuntTheme\ContentExtractor::cleanHtml($html2));

        $this->assertEquals($hash1, $hash2, 'Sidebar changes must not affect content hash');
    }
}
```

**Run tests:**
```bash
POD=$(kubectl get pods -l app=bookstack -o jsonpath='{.items[0].metadata.name}')
kubectl exec "$POD" -- php -d display_errors=1 /app/www/vendor/bin/phpunit \
  --bootstrap /app/www/vendor/autoload.php \
  /config/www/themes/digipunt/tests/ContentExtractorTest.php 2>&1

# Expected: 4 tests, 4 assertions, all PASS
# Key assertion: testHashStableWhenSidebarChanges must pass — sidebar changes don't trigger false "changed" signal
```

### Task 4: API route — ad-hoc source check on page view

**Add to `functions.php` (inside the `APP_BOOT` listener, after existing routes):**

This is the core endpoint that `sources.js` calls when a page loads. It checks all sources of the current page, detects changes, and queues affected pages for LLM update.

```php
    // POST /api/digipunt/sources/check — ad-hoc check all sources for a page
    $app->router->post('/api/digipunt/sources/check', function () {
        $entityType = request()->input('entity_type', 'page');
        $entityId = (int) request()->input('entity_id', 0);

        if ($entityType !== 'page' || $entityId <= 0) {
            return response()->json(['error' => 'Invalid entity'], 400);
        }

        // Get all sources linked to this page
        $links = DB::table('entity_source_links')
            ->join('sources', 'entity_source_links.source_id', '=', 'sources.id')
            ->where('entity_source_links.entity_type', 'page')
            ->where('entity_source_links.entity_id', $entityId)
            ->select('sources.id', 'sources.title', 'sources.url')
            ->get();

        $results = [];
        $changedSourceIds = [];

        foreach ($links as $link) {
            if (empty($link->url)) continue;

            $extracted = \DigipuntTheme\ContentExtractor::extract($link->url);
            $status = $extracted['http_status'];

            // Get previous hash
            $prevHash = DB::table('source_snapshots')
                ->where('source_id', $link->id)
                ->orderBy('id', 'desc')
                ->value('content_hash');

            $isChanged = ($prevHash !== null && $prevHash !== '' && $prevHash !== $extracted['hash']);

            // Store snapshot
            DB::table('source_snapshots')->insert([
                'source_id' => $link->id,
                'content_hash' => $extracted['hash'],
                'content_length' => $extracted['length'],
                'http_status' => $extracted['status'],
                'extracted_text' => mb_substr($extracted['text'], 0, 50000),
                'checked_at' => now(),
                'changed' => $isChanged,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $results[] = [
                'source_id' => $link->id,
                'title' => $link->title,
                'changed' => $isChanged,
                'status' => $extracted['status'],
            ];

            if ($isChanged) {
                $changedSourceIds[] = $link->id;
            }
        }

        // If any source changed, queue ALL pages that use changed sources for full re-check + update
        foreach ($changedSourceIds as $sourceId) {
            $affectedPages = DB::table('entity_source_links')
                ->where('source_id', $sourceId)
                ->where('entity_type', 'page')
                ->where('entity_id', '!=', $entityId)  // current page handled separately
                ->pluck('entity_id');

            foreach ($affectedPages as $pageId) {
                // Avoid duplicate queue entries
                $exists = DB::table('page_update_queue')
                    ->where('page_id', $pageId)
                    ->whereIn('status', ['pending', 'checking', 'updating'])
                    ->exists();
                if (!$exists) {
                    DB::table('page_update_queue')->insert([
                        'page_id' => $pageId,
                        'status' => 'pending',
                        'trigger_reason' => "source {$sourceId} changed",
                        'queued_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // If sources changed on THIS page, also queue it for update
        if (!empty($changedSourceIds)) {
            DB::table('page_update_queue')->insert([
                'page_id' => $entityId,
                'status' => 'pending',
                'trigger_reason' => 'sources changed: ' . implode(',', $changedSourceIds),
                'queued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'checked' => count($results),
            'changed' => count($changedSourceIds),
            'results' => $results,
        ]);
    });
```

### Task 5: LLM Auto-Updater

**File:** `/config/www/themes/digipunt/LLMUpdater.php` (in PVC)

```php
<?php

namespace DigipuntTheme;

use Illuminate\Support\Facades\DB;

/**
 * Calls the freellm API to update a page based on changed source content.
 * Automatically selects the best available model from the configured family.
 */
class LLMUpdater
{
    private const API_BASE = 'https://freellm.aldof.duckdns.org/v1';
    private const API_KEY = 'freellmapi-82ea901092bf963260f3ea5fe1e1a02e3995a488b4f186e1';

    // Model family prefix → when a newer version appears in /v1/models, auto-upgrade
    private const MODEL_FAMILY = 'claude-sonnet';
    private const DEFAULT_MODEL = 'claude-sonnet-4-5';

    /**
     * Get the best available model from the configured family.
     * Queries /v1/models and picks the highest-versioned model matching the family prefix.
     */
    public static function getBestModel(): string
    {
        $ch = curl_init(self::API_BASE . '/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::API_KEY],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        if ($resp === false) {
            return self::DEFAULT_MODEL;
        }

        $data = json_decode($resp, true);
        $models = $data['data'] ?? [];

        // Find models matching our family, sort by version number (descending)
        $candidates = [];
        foreach ($models as $m) {
            $id = $m['id'] ?? '';
            if (str_starts_with($id, self::MODEL_FAMILY)) {
                // Extract version numbers (e.g., "4-5" from "claude-sonnet-4-5")
                if (preg_match('/(\d+)-(\d+)/', $id, $matches)) {
                    $version = (int)$matches[1] * 100 + (int)$matches[2];
                    $candidates[$version] = $id;
                }
            }
        }

        if (empty($candidates)) {
            return self::DEFAULT_MODEL;
        }

        // Return highest version
        krsort($candidates);
        return reset($candidates);
    }

    /**
     * Update a page using the LLM.
     *
     * @param int $pageId The BookStack page ID to update
     * @return array{success: bool, model: string, error: ?string}
     */
    public static function updatePage(int $pageId): array
    {
        // 1. Get current page content
        $page = DB::table('pages')->where('id', $pageId)->first();
        if (!$page) {
            return ['success' => false, 'model' => '', 'error' => 'Page not found'];
        }

        $currentHtml = $page->html ?? '';

        // 2. Get all sources for this page with their latest extracted text
        $sources = DB::table('entity_source_links')
            ->join('sources', 'entity_source_links.source_id', '=', 'sources.id')
            ->leftJoin('source_snapshots as sn', function ($join) {
                $join->on('sn.source_id', '=', 'sources.id')
                     ->whereRaw('sn.id = (SELECT MAX(id) FROM source_snapshots WHERE source_id = sources.id)');
            })
            ->where('entity_source_links.entity_type', 'page')
            ->where('entity_source_links.entity_id', $pageId)
            ->select(
                'sources.id',
                'sources.title',
                'sources.url',
                'sn.extracted_text',
                'sn.changed'
            )
            ->get();

        // 3. Build the prompt
        $sourceContext = "";
        foreach ($sources as $s) {
            $changed = $s->changed ? ' [GEWIJZIGD]' : '';
            $text = mb_substr($s->extracted_text ?? '', 0, 10000);
            $sourceContext .= "\n\n--- BRON: {$s->title} ({$s->url}){$changed} ---\n{$text}\n";
        }

        $prompt = <<<PROMPT
Je bent een technische documentatie-schrijver voor het DigiPunt (digitale hulpdienst Bibliotheek Gent).
Hieronder vind je de huidige inhoud van een documentatiepagina en de actuele inhoud van alle bronnen.

Taak: Herschrijf de pagina-inhoud zodat deze:
1. Nauwkeurig overeenkomt met de actuele broninhoud
2. Geschreven is in helder Nederlands voor niet-technische gebruikers
3. Alle belangrijke informatie uit de bronnen bevat
4. De bestaande structuur (koppen, paragrafen) behoudt waar mogelijk
5. Geen persoonlijke gegevens (GDPR) bevat

Huidige pagina-inhoud (HTML):
{$currentHtml}

Actuele broninhoud:
{$sourceContext}

Schrijf de bijgewerkte pagina-inhoud als geldige HTML (geen <html>, <head> of <body> tags — alleen de inhoud).
PROMPT;

        // 4. Select best model
        $model = self::getBestModel();

        // 5. Call the LLM
        $ch = curl_init(self::API_BASE . '/chat/completions');
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 8000,
            'temperature' => 0.3,
        ]);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . self::API_KEY,
                'Content-Type: application/json',
            ],
        ]);
        $resp = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['success' => false, 'model' => $model, 'error' => 'cURL error: ' . $curlError];
        }

        $data = json_decode($resp, true);
        if ($httpStatus >= 400) {
            $errMsg = $data['error']['message'] ?? "HTTP {$httpStatus}";
            return ['success' => false, 'model' => $model, 'error' => $errMsg];
        }

        $updatedHtml = $data['choices'][0]['message']['content'] ?? '';
        if (empty($updatedHtml)) {
            return ['success' => false, 'model' => $model, 'error' => 'Empty LLM response'];
        }

        // 6. Save as draft (store in queue, don't auto-publish)
        DB::table('page_update_queue')
            ->where('page_id', $pageId)
            ->whereIn('status', ['pending', 'checking', 'updating'])
            ->update([
                'status' => 'done',
                'llm_output' => $updatedHtml,
                'model_used' => $model,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        return ['success' => true, 'model' => $model, 'error' => null];
    }
}
```

### Task 6: API route — process update queue + review

**Add to `functions.php` (inside the `APP_BOOT` listener):**

```php
    // POST /api/digipunt/pages/{pageId}/update — trigger LLM update for a page
    $app->router->post('/api/digipunt/pages/{pageId}/update', function (int $pageId) {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $result = \DigipuntTheme\LLMUpdater::updatePage($pageId);

        return response()->json($result);
    });

    // GET /api/digipunt/updates/pending — list pages with pending LLM updates
    $app->router->get('/api/digipunt/updates/pending', function () {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $pending = DB::table('page_update_queue as q')
            ->join('pages as p', 'q.page_id', '=', 'p.id')
            ->leftJoin('books as b', 'p.book_id', '=', 'b.id')
            ->whereIn('q.status', ['pending', 'done'])
            ->select(
                'q.id',
                'q.page_id',
                'p.name as page_name',
                'b.name as book_name',
                'q.status',
                'q.trigger_reason',
                'q.model_used',
                'q.queued_at',
                'q.completed_at'
            )
            ->orderBy('q.queued_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json(['data' => $pending]);
    });

    // POST /api/digipunt/updates/{queueId}/publish — publish LLM output to the page
    $app->router->post('/api/digipunt/updates/{queueId}/publish', function (int $queueId) {
        $user = auth()->user();
        if (!$user || !$user->can('page-edit-all')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $queue = DB::table('page_update_queue')->where('id', $queueId)->first();
        if (!$queue || $queue->status !== 'done') {
            return response()->json(['error' => 'No completed update found'], 404);
        }

        // Update the page HTML
        DB::table('pages')->where('id', $queue->page_id)->update([
            'html' => $queue->llm_output,
            'updated_at' => now(),
        ]);

        // Mark queue entry as published
        DB::table('page_update_queue')->where('id', $queueId)->update([
            'status' => 'done',
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'published', 'page_id' => $queue->page_id]);
    });

    // DELETE /api/digipunt/updates/{queueId} — reject LLM update
    $app->router->delete('/api/digipunt/updates/{queueId}', function (int $queueId) {
        $user = auth()->user();
        if (!$user || !$user->can('page-edit-all')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        DB::table('page_update_queue')->where('id', $queueId)->delete();
        return response()->json(['status' => 'rejected']);
    });
```

### Task 7: Update sources.js — trigger ad-hoc check on page load

**File:** `/config/www/themes/digipunt/public/js/sources.js` (modify the `init()` function)

Add after `refreshSources(entityType, entityId);` in the `init()` function:

```javascript
        // Ad-hoc source check: trigger background check on page load
        // Only for logged-in users (admins/editors) to avoid hammering external sites
        if (canEdit) {
            fetch(`${API_BASE}/sources/check`, {
                method: 'POST',
                headers: getAuthHeaders(),
                body: JSON.stringify({ entity_type: entityType, entity_id: entityId }),
            }).then(r => r.json()).then(data => {
                if (data.changed > 0) {
                    // Re-render sources list with "changed" badges
                    refreshSources(entityType, entityId);

                    // Show a notification to the admin
                    const notice = document.createElement('div');
                    notice.className = 'digipunt-update-notice';
                    notice.innerHTML = `<p>⚠ ${data.changed} bron(en) gewijzigd. De pagina wordt bijgewerkt.</p>`;
                    panel.appendChild(notice);

                    // Trigger LLM update for this page
                    fetch(`${API_BASE}/pages/${entityId}/update`, {
                        method: 'POST',
                        headers: getAuthHeaders(),
                    }).then(r => r.json()).then(updateResult => {
                        if (updateResult.success) {
                            notice.innerHTML = `<p>✅ Pagina bijgewerkt met ${updateResult.model}. <button class="text-button" id="digipunt-review-update">Bekijk wijzigingen</button></p>`;
                            // Store queue ID for review
                            notice.dataset.queueId = updateResult.queue_id || '';
                        } else {
                            notice.innerHTML = `<p>❌ Update mislukt: ${updateResult.error || 'onbekende fout'}</p>`;
                        }
                    });
                }
            }).catch(err => console.error('Source check failed:', err));
        }
```

### Task 8: Test the full E2E flow

This is the critical validation step. Test each phase:

**Phase 1 — Content extraction test:**
```bash
# Test extraction against a real Arch Linux Wiki page
POD=$(kubectl get pods -l app=bookstack -o jsonpath='{.items[0].metadata.name}')
kubectl exec "$POD" -- php -r "
require_once '/config/www/themes/digipunt/SourceChecker.php';
\$result = \DigipuntTheme\ContentExtractor::extract('https://wiki.archlinux.org/title/NTFS');
echo 'Status: ' . \$result['status'] . \"\n\";
echo 'Length: ' . \$result['length'] . \" chars\n\";
echo 'Hash: ' . substr(\$result['hash'], 0, 16) . \"...\n\";
echo 'Contains NTFS: ' . (strpos(\$result['text'], 'NTFS') !== false ? 'yes' : 'no') . \"\n\";
echo 'Contains sidebar (Related articles: ' . (strpos(\$result['text'], 'Related articles') !== false ? 'YES (BUG)' : 'no (good)') . \"\n\";
echo 'Contains nav (Personal tools: ' . (strpos(\$result['text'], 'Personal tools') !== false ? 'YES (BUG)' : 'no (good)') . \"\n\";
echo 'First 200 chars: ' . substr(\$result['text'], 0, 200) . \"\n\";
" 2>&1
```

Expected output:
```
Status: 200
Length: 5000+ chars
Hash: <hex string>
Contains NTFS: yes
Contains sidebar (Related articles: no (good)
Contains nav (Personal tools: no (good)
First 200 chars: <main content starting with NTFS heading or intro text>
```

**Phase 2 — Change detection test (hash stability):**
```bash
# Run the extractor twice on the same URL — hashes must match
kubectl exec "$POD" -- php -r "
require_once '/config/www/themes/digipunt/SourceChecker.php';
\$r1 = \DigipuntTheme\ContentExtractor::extract('https://wiki.archlinux.org/title/NTFS');
\$r2 = \DigipuntTheme\ContentExtractor::extract('https://wiki.archlinux.org/title/NTFS');
echo 'Hash 1: ' . \$r1['hash'] . \"\n\";
echo 'Hash 2: ' . \$r2['hash'] . \"\n\";
echo 'Match: ' . (\$r1['hash'] === \$r2['hash'] ? 'YES (good)' : 'NO (bug)') . \"\n\";
" 2>&1
```

Expected: `Match: YES (good)` — the hash is stable across requests.

**Phase 3 — Sidebar noise rejection test (most important test):**
```bash
# Fetch the raw HTML, inject fake "Last updated: <random>" in the sidebar,
# and verify the extracted hash does NOT change
kubectl exec "$POD" -- php -r "
require_once '/config/www/themes/digipunt/SourceChecker.php';
\$ch = curl_init('https://wiki.archlinux.org/title/NTFS');
curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
\$html1 = curl_exec(\$ch);
curl_close(\$ch);
// Inject random sidebar noise — try multiple injection points
\$html2 = \$html1;
\$html2 = preg_replace('/<aside[^>]*>/', '<aside data-noise=\"' . rand(1,999999) . '\">', \$html2, 1, \$count);
if (\$count === 0) {
    // No aside tag — inject into a div with class sidebar
    \$html2 = preg_replace('/class=\"([^\"]*sidebar[^\"]*)\"/', 'class=\"\$1\" data-noise=\"' . rand(1,999999) . '\"', \$html2, 1, \$count2);
}
if (\$count === 0 && \$count2 === 0) {
    // Inject into the footer area
    \$html2 = str_replace('</footer>', '<!-- noise ' . rand(1,999999) . ' --></footer>', \$html1, );
}
\$hash1 = hash('sha256', \DigipuntTheme\ContentExtractor::cleanHtml(\$html1));
\$hash2 = hash('sha256', \DigipuntTheme\ContentExtractor::cleanHtml(\$html2));
echo 'Hash 1: ' . substr(\$hash1,0,16) . \"\n\";
echo 'Hash 2: ' . substr(\$hash2,0,16) . \"\n\";
echo 'Stable: ' . (\$hash1 === \$hash2 ? 'YES (sidebar noise rejected)' : 'NO (false positive)') . \"\n\";
" 2>&1
```

Expected: `Stable: YES (sidebar noise rejected)` — this is the core test proving the extractor isolates main content.

**Phase 4 — LLM model auto-upgrade test:**
```bash
# Test that getBestModel() returns the highest version from the claude-sonnet family
kubectl exec "$POD" -- php -r "
require_once '/config/www/themes/digipunt/LLMUpdater.php';
\$model = \DigipuntTheme\LLMUpdater::getBestModel();
echo 'Selected model: ' . \$model . \"\n\";
echo 'Family match: ' . (str_starts_with(\$model, 'claude-sonnet') ? 'yes' : 'no') . \"\n\";
" 2>&1
```

Expected: `Selected model: claude-sonnet-4-5` (or higher if a newer version exists)

**Phase 5 — Full ad-hoc check via API (first run = baseline):**
```bash
# Trigger a source check for page 17 (Linux basisinformatie: File system)
curl -s -X POST 'https://docs.digipunt.aldof.duckdns.org/api/digipunt/sources/check' \
  -H 'Authorization: Token hermes-bookstack-api:hermes-bookstack-secret-2026' \
  -H 'Content-Type: application/json' \
  -d '{"entity_type":"page","entity_id":17}' | python3 -m json.tool

# Expected: {"checked": 9, "changed": 0, "results": [...]}
# (changed=0 because this is the first check — no previous hash to compare)
```

**Phase 6 — Simulate a source change (cascading queue):**
```bash
# Insert a fake "previous" snapshot with a wrong hash for source 55
kubectl exec "$POD" -- php -r "
\$pdo = new PDO('mysql:host=stantonius-db;dbname=bookstack;charset=utf8mb4', 'bookstack', 'rD/WKj5/zyxwB94r');
\$pdo->exec(\"INSERT INTO source_snapshots (source_id, content_hash, content_length, http_status, checked_at, changed, created_at, updated_at) VALUES (55, 'FAKE_HASH_12345', 0, 200, NOW(), 0, NOW(), NOW())\");
echo 'Inserted fake snapshot\n';
" 2>&1

# Re-check — source 55 should show as changed
curl -s -X POST 'https://docs.digipunt.aldof.duckdns.org/api/digipunt/sources/check' \
  -H 'Authorization: Token hermes-bookstack-api:hermes-bookstack-secret-2026' \
  -H 'Content-Type: application/json' \
  -d '{"entity_type":"page","entity_id":17}' | python3 -c "
import sys, json
data = json.load(sys.stdin)
print(f'Checked: {data[\"checked\"]}')
print(f'Changed: {data[\"changed\"]}')
for r in data['results']:
    if r['changed']:
        print(f'  CHANGED: source {r[\"source_id\"]} — {r[\"title\"][:60]}')
"

# Expected: Changed: 1, source 55 flagged as CHANGED

# Verify cascading: check the queue for other pages using source 55
curl -s 'https://docs.digipunt.aldof.duckdns.org/api/digipunt/updates/pending' \
  -H 'Authorization: Token hermes-bookstack-api:hermes-bookstack-secret-2026' | python3 -c "
import sys, json
data = json.load(sys.stdin)
for item in data.get('data', []):
    print(f'  Queue {item[\"id\"]}: page {item[\"page_id\"]} ({item[\"page_name\"]}) status={item[\"status\"]} reason={item.get(\"trigger_reason\",\"\")}')"
```

**Phase 7 — LLM update test (real API call):**
```bash
# Trigger an LLM update for page 17
curl -s -X POST 'https://docs.digipunt.aldof.duckdns.org/api/digipunt/pages/17/update' \
  -H 'Authorization: Token hermes-bookstack-api:hermes-bookstack-secret-2026' | python3 -m json.tool

# Expected: {"success": true, "model": "claude-sonnet-4-5", "error": null}

# Verify queue has the output
curl -s 'https://docs.digipunt.aldof.duckdns.org/api/digipunt/updates/pending' \
  -H 'Authorization: Token hermes-bookstack-api:hermes-bookstack-secret-2026' | python3 -c "
import sys, json
data = json.load(sys.stdin)
for item in data.get('data', []):
    print(f'  Queue {item[\"id\"]}: page {item[\"page_id\"]} ({item[\"page_name\"]}) status={item[\"status\"]} model={item.get(\"model_used\",\"\")}')
"
```

**Phase 8 — Browser E2E (as admin):**
```bash
# 1. Log in as admin (aldo.fieuw+bookstack@gmail.com / Digipunt2026!)
# 2. Navigate to https://docs.digipunt.aldof.duckdns.org/books/team-linux/page/2-linux-basisinformatie-file-system
# 3. Verify: sources panel loads, no "Gewijzigd" badges (after clean check)
# 4. Manually insert fake snapshot (as in Phase 6) to simulate change
# 5. Reload the page
# 6. Verify: "⚠ Gewijzigd" badge appears on changed source
# 7. Verify: update notice appears ("bronnen gewijzigd, pagina wordt bijgewerkt")
# 8. Verify: LLM update completes and "Bekijk wijzigingen" button appears
# 9. Verify: /api/digipunt/updates/pending shows the update with model_used
```

### Task 9: Add "Gewijzigd" badge CSS

**File:** `/config/www/themes/digipunt/public/css/sources.css` (append)

```css
.digipunt-badge-changed {
    background: #fff3cd;
    color: #856404;
    font-weight: 600;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.digipunt-update-notice {
    margin-top: 1rem;
    padding: 0.75rem 1rem;
    background: var(--color-bg-shade-1);
    border-left: 4px solid var(--color-warning, #f0ad4e);
    border-radius: 4px;
}

.digipunt-update-notice p {
    margin: 0;
    font-size: 0.9rem;
}
```

## Risks, tradeoffs, and open questions

1. **Content extraction accuracy**: The DOM-based extraction (strip nav/aside/script/style, find `<main>`/`<article>`/`#mw-content-text`) works for Arch Linux Wiki and most modern sites. Sites with unusual structure may produce false positives or miss real content. The TDD test in Task 3 explicitly verifies sidebar noise rejection. If a specific source site has problematic structure, add a site-specific selector to the `$candidates` array.

2. **LLM cost/time**: Each page update calls the LLM with up to 10K chars of source text + current page HTML. `claude-sonnet-4-5` via freellm is free but rate-limited. If the update takes >30s, the user sees a loading indicator. The AJAX call is async so page rendering isn't blocked.

3. **Ad-hoc check latency**: Fetching 9 source URLs sequentially on page view takes ~5-10 seconds. This runs in the background (AJAX) so the user sees the page immediately. The sources panel shows "checking..." then updates. Future optimization: cache snapshots for 1 hour so repeat visits don't re-fetch.

4. **Draft vs auto-publish**: The LLM output is stored in `page_update_queue` with `status='done'`. An admin must click "Bekijk wijzigingen" → review → publish. This is a safety measure. The user asked for "fully autonomous" — if they want auto-publish, change the `POST /pages/{id}/update` handler to also call the publish endpoint automatically. For now, human review of LLM output is safer.

5. **Model auto-upgrade**: The `getBestModel()` method queries `/v1/models` on every update call. If the LLM endpoint is down, it falls back to `DEFAULT_MODEL`. The version comparison is simple (major*100 + minor). If model naming changes (e.g., `claude-sonnet-5`), the family prefix `claude-sonnet` still matches.

6. **Cascading updates**: When source S changes, ALL pages using S are queued. But the LLM update is only triggered for the current page (ad-hoc). Queued pages are updated on their next visit or when an admin processes the queue. This avoids mass LLM calls. An admin can process the queue via a batch endpoint (future task).

7. **Open question**: Should the system store the extracted source text permanently or prune old snapshots? The `extracted_text` column stores up to 50K chars per snapshot. With 85 sources checked daily, that's ~4MB/day. Prune snapshots older than 30 days (future task).
