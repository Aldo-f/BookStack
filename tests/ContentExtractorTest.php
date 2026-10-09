<?php

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../SourceChecker.php';

class ContentExtractorTest extends TestCase
{
    private $testServerHtml;

    protected function setUp(): void
    {
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

        $this->assertStringContainsString('NTFS File System', $cleaned);
        $this->assertStringContainsString('ntfs3 kernel driver', $cleaned);
        $this->assertStringContainsString('ntfsfix', $cleaned);

        $this->assertStringNotContainsString('Related link 1', $cleaned);
        $this->assertStringNotContainsString('Last updated', $cleaned);
        $this->assertStringNotContainsString('Home', $cleaned);
        $this->assertStringNotContainsString('Copyright', $cleaned);
        $this->assertStringNotContainsString('tracking', $cleaned);
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
        $html1 = str_replace('Related link 1', 'Related link CHANGED', $this->testServerHtml);
        $html2 = str_replace('Last updated: 2026-09-30 15:00:00', 'Last updated: 2026-09-30 16:00:00', $this->testServerHtml);

        $hash1 = hash('sha256', \DigipuntTheme\ContentExtractor::cleanHtml($html1));
        $hash2 = hash('sha256', \DigipuntTheme\ContentExtractor::cleanHtml($html2));

        $this->assertEquals($hash1, $hash2, 'Sidebar changes must not affect content hash');
    }

    // ========== EDGE CASE TESTS ==========

    public function testHandlesEmptyInput()
    {
        $result = \DigipuntTheme\ContentExtractor::cleanHtml('');
        $this->assertEquals('', $result);
    }

    public function testExtractsFromArticleWithoutMainTag()
    {
        $html = '<html><body><article><h1>Test</h1><p>Content here.</p></article></body></html>';
        $cleaned = \DigipuntTheme\ContentExtractor::cleanHtml($html);
        $this->assertStringContainsString('Content here', $cleaned);
        $this->assertStringNotContainsString('<', $cleaned); // No HTML tags
    }

    public function testHandlesMalformedHtmlGracefully()
    {
        $html = '<html><body><main><p>Unclosed main<div>Mixed content</p></main>';
        $cleaned = \DigipuntTheme\ContentExtractor::cleanHtml($html);
        $this->assertStringContainsString('Mixed content', $cleaned);
    }

    public function testExtractsFromMwContentText()
    {
        $html = '<html><body><div id="mw-content-text"><p>MediaWiki content.</p></div></body></html>';
        $cleaned = \DigipuntTheme\ContentExtractor::cleanHtml($html);
        $this->assertStringContainsString('MediaWiki content', $cleaned);
    }

    public function testStripsInfiniteScrollAds()
    {
        $html = <<<'HTML'
<html><body>
  <div class="infobox">This is a sidebar infobox</div>
  <div class="related">Related articles list</div>
  <main><article><p>Main content appears here.</p></article></main>
  <div class="adsbygoogle">Ad content here</div>
</body></html>
HTML;
        $cleaned = \DigipuntTheme\ContentExtractor::cleanHtml($html);
        $this->assertStringContainsString('Main content', $cleaned);
        $this->assertStringNotContainsString('infobox', $cleaned);
        $this->assertStringNotContainsString('related', $cleaned);
        $this->assertStringNotContainsString('adsbygoogle', $cleaned);
    }

    public function testIgnoresTableOfContents()
    {
        $html = <<<'HTML'
<html><body>
  <div class="toc">Table of contents - should be ignored</div>
  <main><article><p>Main article content.</p></article></main>
</body></html>
HTML;
        $cleaned = \DigipuntTheme\ContentExtractor::cleanHtml($html);
        $this->assertStringContainsString('Main article', $cleaned);
        $this->assertStringNotContainsString('toc', strtolower($cleaned));
    }

    public function testHashUnchangedByAdInjection()
    {
        // Simulate real site behavior where ads are injected via tags
        $baseHtml = '<html><body><div class="content"><p>Core content</p></div></body></html>';
        $htmlWithAd = str_replace('</div></body>', '<div class="adsbygoogle">Ad</div></div></body>', $baseHtml);

        $hash1 = hash('sha256', \DigipuntTheme\ContentExtractor::cleanHtml($baseHtml));
        $hash2 = hash('sha256', \DigipuntTheme\ContentExtractor::cleanHtml($htmlWithAd));

        // If the ad div is in .content, it would filter out - verify hash stability
        $this->assertEquals($hash1, $hash2, 'Ad injection should not change main content hash');
    }

    public function testExtractHandlesMultipleSidebars()
    {
        $html = <<<'HTML'
<html><body>
  <aside class="sidebar">Sidebar 1</aside>
  <main><article><p>Main content</p></article></main>
  <aside class="sidebar">Sidebar 2</aside>
  <div class="footer-content">Footer</div>
</body></html>
HTML;
        $cleaned = \DigipuntTheme\ContentExtractor::cleanHtml($html);
        $this->assertStringContainsString('Main content', $cleaned);
        $this->assertStringNotContainsString('Sidebar', $cleaned);
        $this->assertStringNotContainsString('Footer', $cleaned);
    }

    public function testFallbackToBodyWhenNoMainContent()
    {
        $html = '<html><body><p>Fallback content</p></body></html>';
        $cleaned = \DigipuntTheme\ContentExtractor::cleanHtml($html);
        $this->assertStringContainsString('Fallback content', $cleaned);
    }

    public function testStripsScriptingAndTracking()
    {
        $html = <<<'HTML'
<html><body>
  <main><article><p>Legitimate content</p></article></main>
  <script>analytics.track('pageview');</script>
  <script>window.dataLayer = [];</script>
  <noscript>This should be stripped</noscript>
</body></html>
HTML;
        $cleaned = \DigipuntTheme\ContentExtractor::cleanHtml($html);
        $this->assertStringContainsString('Legitimate content', $cleaned);
        $this->assertStringNotContainsString('analytics', $cleaned);
        $this->assertStringNotContainsString('dataLayer', $cleaned);
        $this->assertStringNotContainsString('noscript', $cleaned);
    }

    public function testContentExtractorExtractReturnsCorrectStructure()
    {
        $url = 'https://example.com';
        // We can't test actual HTTP fetch without mocking, but we can test the structure
        // Skip this test if example.com is not accessible
        $this->markTestSkipped('Live fetch test requires network access');
    }
}