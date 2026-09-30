<?php

namespace BookStack\Exports;

use BookStack\Entities\Models\Book;
use BookStack\Entities\Models\Chapter;
use BookStack\Entities\Models\Page;
use BookStack\Entities\Tools\BookContents;
use BookStack\Entities\Tools\Markdown\HtmlToMarkdown;
use BookStack\Entities\Tools\PageContent;
use BookStack\Uploads\ImageService;
use BookStack\Util\CspService;
use BookStack\Util\HtmlDocument;
use BookStack\Util\HtmlToPlainText;
use DOMElement;
use Exception;
use Throwable;

class ExportFormatter
{
    public function __construct(
        protected ImageService $imageService,
        protected PdfGenerator $pdfGenerator,
        protected CspService $cspService
    ) {
    }

    /**
     * Convert a page to a self-contained HTML file.
     * Includes required CSS & image content. Images are base64 encoded into the HTML.
     *
     * @throws Throwable
     */
    public function pageToContainedHtml(Page $page): string
    {
        $page->html = (new PageContent($page))->render();
        $pageHtml = view('exports.page', [
            'page'       => $page,
            'format'     => 'html',
            'cspContent' => $this->cspService->getCspMetaTagValue(),
            'locale'     => user()->getLocale(),
        ])->render();

        return $this->containHtml($pageHtml);
    }

    /**
     * Convert a chapter to a self-contained HTML file.
     *
     * @throws Throwable
     */
    public function chapterToContainedHtml(Chapter $chapter): string
    {
        $pages = $chapter->getVisiblePages();
        $pages->each(function ($page) {
            $page->html = (new PageContent($page))->render();
        });
        $html = view('exports.chapter', [
            'chapter'    => $chapter,
            'pages'      => $pages,
            'format'     => 'html',
            'cspContent' => $this->cspService->getCspMetaTagValue(),
            'locale'     => user()->getLocale(),
        ])->render();

        return $this->containHtml($html);
    }

    /**
     * Convert a book to a self-contained HTML file.
     *
     * @throws Throwable
     */
    public function bookToContainedHtml(Book $book): string
    {
        $bookTree = (new BookContents($book))->getTree(false, true);
        $html = view('exports.book', [
            'book'         => $book,
            'bookChildren' => $bookTree,
            'format'       => 'html',
            'cspContent'   => $this->cspService->getCspMetaTagValue(),
            'locale'       => user()->getLocale(),
        ])->render();

        return $this->containHtml($html);
    }

    /**
     * Convert a page to a PDF file.
     *
     * @throws Throwable
     */
    public function pageToPdf(Page $page): string
    {
        $page->html = (new PageContent($page))->render();
        $html = view('exports.page', [
            'page'   => $page,
            'format' => 'pdf',
            'engine' => $this->pdfGenerator->getActiveEngine(),
            'locale' => user()->getLocale(),
        ])->render();

        return $this->htmlToPdf($html);
    }

    /**
     * Convert a chapter to a PDF file.
     *
     * @throws Throwable
     */
    public function chapterToPdf(Chapter $chapter): string
    {
        $pages = $chapter->getVisiblePages();
        $pages->each(function ($page) {
            $page->html = (new PageContent($page))->render();
        });

        $html = view('exports.chapter', [
            'chapter' => $chapter,
            'pages'   => $pages,
            'format'  => 'pdf',
            'engine'  => $this->pdfGenerator->getActiveEngine(),
            'locale'  => user()->getLocale(),
        ])->render();

        return $this->htmlToPdf($html);
    }

    /**
     * Convert a book to a PDF file.
     *
     * @throws Throwable
     */
    public function bookToPdf(Book $book): string
    {
        $bookTree = (new BookContents($book))->getTree(false, true);
        $html = view('exports.book', [
            'book'         => $book,
            'bookChildren' => $bookTree,
            'format'       => 'pdf',
            'engine'       => $this->pdfGenerator->getActiveEngine(),
            'locale'       => user()->getLocale(),
        ])->render();

        return $this->htmlToPdf($html);
    }

    /**
     * Convert normal web-page HTML to a PDF.
     *
     * @throws Exception
     */
    protected function htmlToPdf(string $html): string
    {
        $html = $this->containHtml($html);
        $doc = new HtmlDocument();
        $doc->loadCompleteHtml($html);

        $this->replaceIframesWithLinks($doc);
        $this->openDetailElements($doc);
        $cleanedHtml = $doc->getHtml();

        return $this->pdfGenerator->fromHtml($cleanedHtml);
    }

    /**
     * Within the given HTML content, Open any detail blocks.
     */
    protected function openDetailElements(HtmlDocument $doc): void
    {
        $details = $doc->queryXPath('//details');
        /** @var DOMElement $detail */
        foreach ($details as $detail) {
            $detail->setAttribute('open', 'open');
        }
    }

    /**
     * Within the given HTML document, replace any iframe elements
     * with anchor links within paragraph blocks.
     */
    protected function replaceIframesWithLinks(HtmlDocument $doc): void
    {
        $iframes = $doc->queryXPath('//iframe');

        /** @var DOMElement $iframe */
        foreach ($iframes as $iframe) {
            $link = $iframe->getAttribute('src');
            if (str_starts_with($link, '//')) {
                $link = 'https:' . $link;
            }

            $anchor = $doc->createElement('a', $link);
            $anchor->setAttribute('href', $link);
            $paragraph = $doc->createElement('p');
            $paragraph->appendChild($anchor);
            $iframe->parentNode->replaceChild($paragraph, $iframe);
        }
    }

    /**
     * Bundle of the contents of a html file to be self-contained.
     *
     * @throws Exception
     */
    protected function containHtml(string $htmlContent): string
    {
        $imageTagsOutput = [];
        preg_match_all("/\<img.*?src\=(\'|\")(.*?)(\'|\").*?\>/i", $htmlContent, $imageTagsOutput);

        // Replace image src with base64 encoded image strings
        if (count($imageTagsOutput[0]) > 0) {
            foreach ($imageTagsOutput[0] as $index => $imgMatch) {
                $oldImgTagString = $imgMatch;
                $srcString = $imageTagsOutput[2][$index];
                $imageEncoded = $this->imageService->imageUrlToBase64($srcString);
                if ($imageEncoded === null) {
                    $imageEncoded = $srcString;
                }
                $newImgTagString = str_replace($srcString, $imageEncoded, $oldImgTagString);
                $htmlContent = str_replace($oldImgTagString, $newImgTagString, $htmlContent);
            }
        }

        $linksOutput = [];
        preg_match_all("/\<a.*href\=(\'|\")(.*?)(\'|\").*?\>/i", $htmlContent, $linksOutput);

        // Update relative links to be absolute, with instance url
        if (count($linksOutput[0]) > 0) {
            foreach ($linksOutput[0] as $index => $linkMatch) {
                $oldLinkString = $linkMatch;
                $srcString = $linksOutput[2][$index];
                if (!str_starts_with(trim($srcString), 'http')) {
                    $newSrcString = url($srcString);
                    $newLinkString = str_replace($srcString, $newSrcString, $oldLinkString);
                    $htmlContent = str_replace($oldLinkString, $newLinkString, $htmlContent);
                }
            }
        }

        return $htmlContent;
    }

    /**
     * Converts the page contents into simple plain text.
     * We re-generate the plain text from HTML at this point, post-page-content rendering.
     */
    public function pageToPlainText(Page $page, bool $pageRendered = false, bool $fromParent = false): string
    {
        $html = $pageRendered ? $page->html : (new PageContent($page))->render();
        $contentText = (new HtmlToPlainText())->convert($html);
        return $page->name . ($fromParent ? "\n" : "\n\n") . $contentText;
    }

    /**
     * Convert a chapter into a plain text string.
     */
    public function chapterToPlainText(Chapter $chapter): string
    {
        $text = $chapter->name . "\n" . $chapter->descriptionInfo()->getPlain();
        $text = trim($text) . "\n\n";

        $parts = [];
        foreach ($chapter->getVisiblePages() as $page) {
            $parts[] = $this->pageToPlainText($page, false, true);
        }

        return $text . implode("\n\n", $parts);
    }

    /**
     * Convert a book into a plain text string.
     */
    public function bookToPlainText(Book $book): string
    {
        $bookTree = (new BookContents($book))->getTree(false, true);
        $text = $book->name . "\n" . $book->descriptionInfo()->getPlain();
        $text = rtrim($text) . "\n\n";

        $parts = [];
        foreach ($bookTree as $bookChild) {
            if ($bookChild->isA('chapter')) {
                $parts[] = $this->chapterToPlainText($bookChild);
            } else {
                $parts[] = $this->pageToPlainText($bookChild, true, true);
            }
        }

        return $text . implode("\n\n", $parts);
    }

    /**
     * Convert a page to a Markdown file.
     */
    public function pageToMarkdown(Page $page): string
    {
        if ($page->markdown) {
            return '# ' . $page->name . "\n\n" . $page->markdown;
        }

        return '# ' . $page->name . "\n\n" . (new HtmlToMarkdown($page->html))->convert();
    }

    /**
     * Convert a chapter to a Markdown file.
     */
    public function chapterToMarkdown(Chapter $chapter): string
    {
        $text = '# ' . $chapter->name . "\n\n";

        $description = (new HtmlToMarkdown($chapter->descriptionInfo()->getHtml()))->convert();
        if ($description) {
            $text .= $description . "\n\n";
        }

        foreach ($chapter->getVisiblePages() as $page) {
            $text .= $this->pageToMarkdown($page) . "\n\n";
        }

        return trim($text);
    }

    /**
     * Convert a book into a plain text string.
     */
    public function bookToMarkdown(Book $book): string
    {
        $bookTree = (new BookContents($book))->getTree(false, true);
        $text = '# ' . $book->name . "\n\n";

        $description = (new HtmlToMarkdown($book->descriptionInfo()->getHtml()))->convert();
        if ($description) {
            $text .= $description . "\n\n";
        }

        foreach ($bookTree as $bookChild) {
            if ($bookChild instanceof Chapter) {
                $text .= $this->chapterToMarkdown($bookChild) . "\n\n";
            } else {
                $text .= $this->pageToMarkdown($bookChild) . "\n\n";
            }
        }

        return trim($text);
    }

    /**
     * Convert a page to an EPUB file.
     */
    public function pageToEpub(Page $page): string
    {
        $page->html = (new PageContent($page))->render();
        return $this->htmlToEpub($page->html, $page->name);
    }

    /**
     * Convert a chapter to an EPUB file.
     */
    public function chapterToEpub(Chapter $chapter): string
    {
        $pages = $chapter->getVisiblePages();
        $parts = [];
        foreach ($pages as $page) {
            $parts[] = $this->pageToEpub($page);
        }
        return implode("\n\n", $parts);
    }

    /**
     * Convert a book to an EPUB file.
     */
    public function bookToEpub(Book $book): string
    {
        $bookTree = (new BookContents($book))->getTree(false, true);
        $parts = [];
        foreach ($bookTree as $child) {
            if ($child instanceof Chapter) {
                $parts[] = $this->chapterToEpub($child);
            } else {
                $parts[] = $this->pageToEpub($child);
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Build an EPUB binary from HTML content.
     */
    protected function htmlToEpub(string $html, string $title): string
    {
        $zip = new \ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'bs-epub-');
        $zip->open($tmp, \ZipArchive::CREATE);

        // mimetype (must be first, uncompressed)
        $zip->addFromString('mimetype', 'application/epub+zip');

        // META-INF/container.xml
        $container = '<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>';
        $zip->addFromString('META-INF/container.xml', $container);

        // OEBPS/package.opf
        $packageOpf = '<?xml version="1.0" encoding="UTF-8"?>
<package version="3.0" xmlns="http://www.idpf.org/2007/opf" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:title>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</dc:title>
    <dc:language>nl</dc:language>
    <dc:identifier id="uid">urn:uuid:' . uniqid() . '</dc:identifier>
    <meta property="dcterms:modified">' . gmdate('Y-m-d\TH:i:s\Z') . '</meta>
  </metadata>
  <spine toc="ncx">
    <itemref idref="nav"/>
  </spine>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml"/>
    <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
    <item id="style" href="style.css" media-type="text/css"/>
  </manifest>
</package>';
        $zip->addFromString('OEBPS/content.opf', $packageOpf);

        // nav.xhtml
        $nav = '<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
<head><title>Navigation</title></head>
<body><nav epub:type="toc">' .
        '<ol><li><a href="chapter.xhtml">' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</a></li></ol>' .
        '</nav></body></html>';
        $zip->addFromString('OEBPS/nav.xhtml', $nav);

        // toc.ncx
        $ncx = '<?xml version="1.0" encoding="UTF-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
  <head><meta name="dtb:totalPageCount" content="1"/><meta name="dtb:maxPageNumber" content="0"/></head>
  <docTitle><text>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</text></docTitle>
  <navMap>
    <navPoint id="1"><navLabel><text>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</text></navLabel>
      <content src="chapter.xhtml"/></navPoint>
  </navMap>
</ncx>';
        $zip->addFromString('OEBPS/toc.ncx', $ncx);

        // style.css
        $css = 'body { font-family: Georgia, serif; line-height: 1.6; margin: 1em; }
h2 { color: #222; border-bottom: 2px solid #ccc; padding-bottom: 5px; margin-top: 1.5em; }
pre { background: #f4f4f4; padding: 10px; overflow-x: auto; font-size: 0.9em; }
code { background: #f4f4f4; padding: 2px 5px; }
blockquote { border-left: 4px solid #ccc; margin: 1em 0; padding-left: 1em; color: #555; }
table { border-collapse: collapse; width: 100%; margin: 1em 0; }
th, td { border: 1px solid #999; padding: 8px; }
th { background: #e8e8e8; }
ul, ol { margin: 0.5em 0; padding-left: 2em; }
p { margin: 0.5em 0; }
hr { border: none; border-top: 1px solid #ccc; margin: 1.5em 0; }';
        $zip->addFromString('OEBPS/style.css', $css);

        // chapter.xhtml (the main content)
        $chapter = '<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head><title>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</title>
<link rel="stylesheet" type="text/css" href="style.css"/></head>
<body>' . $html . '</body></html>';
        $zip->addFromString('OEBPS/chapter.xhtml', $chapter);

        $zip->close();
        $epubData = file_get_contents($tmp);
        unlink($tmp);
        return $epubData;
    }
}
