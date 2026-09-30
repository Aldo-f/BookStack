<?php

namespace BookStack\Exports\Controllers;

use BookStack\Entities\Models\Chapter;
use BookStack\Entities\Queries\ChapterQueries;
use BookStack\Exceptions\NotFoundException;
use BookStack\Exports\ExportFormatter;
use BookStack\Exports\ZipExports\ZipExportBuilder;
use BookStack\Http\Controller;
use BookStack\Permissions\Permission;
use Throwable;

class ChapterExportController extends Controller
{
    public function __construct(
        protected ChapterQueries $queries,
        protected ExportFormatter $exportFormatter,
    ) {
        $this->middleware(Permission::ContentExport->middleware());
        $this->middleware('throttle:exports');
    }

    /**
     * Exports a chapter to pdf.
     *
     * @throws NotFoundException
     * @throws Throwable
     */
    public function pdf(string $bookSlug, string $chapterSlug)
    {
        $chapter = $this->queries->findVisibleBySlugsOrFail($bookSlug, $chapterSlug);
        $pdfContent = $this->exportFormatter->chapterToPdf($chapter);

        return $this->download()->directly($pdfContent, $chapterSlug . '.pdf');
    }

    /**
     * Export a chapter to a self-contained HTML file.
     *
     * @throws NotFoundException
     * @throws Throwable
     */
    public function html(string $bookSlug, string $chapterSlug)
    {
        $chapter = $this->queries->findVisibleBySlugsOrFail($bookSlug, $chapterSlug);
        $containedHtml = $this->exportFormatter->chapterToContainedHtml($chapter);

        return $this->download()->directly($containedHtml, $chapterSlug . '.html');
    }

    /**
     * Export a chapter to a simple plaintext .txt file.
     */
    public function plainText(string $bookSlug, string $chapterSlug)
    {
        $chapter = $this->queries->findVisibleBySlugsOrFail($bookSlug, $chapterSlug);
        $chapterText = $this->exportFormatter->chapterToPlainText($chapter);

        return $this->download()->directly($chapterText, $chapterSlug . '.txt');
    }

    /**
     * Export a chapter to a simple markdown file.
     */
    public function markdown(string $bookSlug, string $chapterSlug)
    {
        $chapter = $this->queries->findVisibleBySlugsOrFail($bookSlug, $chapterSlug);
        $chapterText = $this->exportFormatter->chapterToMarkdown($chapter);

        return $this->download()->directly($chapterText, $chapterSlug . '.md');
    }

    /**
     * Export a chapter to a contained ZIP export file.
     * @throws NotFoundException
     */
    public function zip(string $bookSlug, string $chapterSlug, ZipExportBuilder $builder)
    {
        $chapter = $this->queries->findVisibleBySlugsOrFail($bookSlug, $chapterSlug);
        $zip = $builder->buildForChapter($chapter);

        return $this->download()->streamedFileDirectly($zip, $chapterSlug . '.zip', true);
    }

    /**
     * Export a chapter as an EPUB file.
     */
    public function epub(string $bookSlug, string $chapterSlug)
    {
        $chapter = $this->queries->findVisibleBySlugsOrFail($bookSlug, $chapterSlug);
        $epubContent = $this->exportFormatter->chapterToEpub($chapter);
        return $this->download()->directly($epubContent, $chapterSlug . '.epub');
    }
}
