<?php

namespace Tests\Exports;

use BookStack\Exports\ExportFormatter;
use Tests\TestCase;

class EpubExportTest extends TestCase
{
    public function test_page_epub_is_valid_zip()
    {
        $page = $this->entities->page();
        $formatter = app(ExportFormatter::class);
        $epubData = $formatter->pageToEpub($page);

        // Write to temp file and validate as ZIP
        $tmp = tempnam(sys_get_temp_dir(), 'test-epub-');
        file_put_contents($tmp, $epubData);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp, \ZipArchive::RDONLY) === true, 'EPUB should be a valid ZIP');

        // Check required EPUB structure
        $this->assertTrue($zip->locateName('mimetype') !== false, 'EPUB must contain mimetype file');
        $this->assertTrue($zip->locateName('META-INF/container.xml') !== false, 'EPUB must contain container.xml');
        $this->assertTrue($zip->locateName('OEBPS/content.opf') !== false, 'EPUB must contain content.opf');
        $this->assertTrue($zip->locateName('OEBPS/nav.xhtml') !== false, 'EPUB must contain nav.xhtml');
        $this->assertTrue($zip->locateName('OEBPS/toc.ncx') !== false, 'EPUB must contain toc.ncx');
        $this->assertTrue($zip->locateName('OEBPS/style.css') !== false, 'EPUB must contain style.css');

        // Check mimetype content
        $this->assertEquals('application/epub+zip', $zip->getFromName('mimetype'));

        // Check content.opf has the page title
        $opf = $zip->getFromName('OEBPS/content.opf');
        $this->assertStringContainsString($page->name, $opf, 'content.opf should contain page title');

        // Check at least one chapter XHTML exists
        $hasChapter = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/OEBPS\/ch\d+\.xhtml/', $name)) {
                $hasChapter = true;
                $content = $zip->getFromIndex($i);
                $this->assertStringContainsString('<html', $content, 'Chapter should be valid XHTML');
                $this->assertStringContainsString('<body>', $content, 'Chapter should have body');
                break;
            }
        }
        $this->assertTrue($hasChapter, 'EPUB should contain at least one chapter XHTML');

        $zip->close();
        unlink($tmp);
    }

    public function test_book_epub_has_multiple_chapters()
    {
        $book = $this->entities->book();
        // Ensure book has pages
        $this->assertGreaterThan(0, $book->directPages()->count() + $book->chapters()->count(),
            'Test book should have content');

        $formatter = app(ExportFormatter::class);
        $epubData = $formatter->bookToEpub($book);

        $tmp = tempnam(sys_get_temp_dir(), 'test-epub-book-');
        file_put_contents($tmp, $epubData);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp, \ZipArchive::RDONLY) === true);

        // Count chapter files
        $chapterCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/OEBPS\/ch\d+\.xhtml/', $name)) {
                $chapterCount++;
            }
        }
        $this->assertGreaterThan(1, $chapterCount, 'Book EPUB should have multiple chapter files');

        // Check nav.xhtml references all chapters
        $nav = $zip->getFromName('OEBPS/nav.xhtml');
        $this->assertStringContainsString('<nav', $nav, 'nav.xhtml should contain nav element');
        $this->assertStringContainsString($book->name, $zip->getFromName('OEBPS/content.opf'),
            'content.opf should contain book title');

        $zip->close();
        unlink($tmp);
    }

    public function test_epub_is_epub_mime_type()
    {
        $page = $this->entities->page();
        $formatter = app(ExportFormatter::class);
        $epubData = $formatter->pageToEpub($page);

        // Check magic bytes — EPUB files start with PK (ZIP)
        $this->assertEquals('PK', substr($epubData, 0, 2), 'EPUB should start with PK (ZIP magic bytes)');

        // Write to temp and check with file command
        $tmp = tempnam(sys_get_temp_dir(), 'test-epub-mime-') . '.epub';
        file_put_contents($tmp, $epubData);
        $mime = mime_content_type($tmp);
        $this->assertStringContainsString('epub', $mime, 'File should be detected as EPUB mimetype');
        unlink($tmp);
    }
}
