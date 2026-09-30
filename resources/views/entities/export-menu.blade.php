{{-- Export menu for entities --}}
{{-- Show export options for books, pages, and chapters --}}

@php
    $entityType = $entity->type ?? 'page';
    $entityId = $entity->id ?? 0;
    $routePrefix = match($entityType) {
        'book' => 'books',
        'chapter' => 'chapters',
        'page' => 'pages',
        default => 'pages',
    };
@endphp

@if ($entity->canExport())
    <li class="nav-item {{ $entity->isSelected() ? 'active' : '' }}">
        <a href="#" class="nav-link js-export-trigger" data-entity-type="{{ $entityType }}" data-entity-id="{{ $entityId }}">
            <i class="icon-download"></i> {{ trans('entities.export') }}
            <span class="caret"></span>
        </a>
        <ul class="dropdown-menu">
            @if ($entityType === 'page')
                <li><a class="dropdown-item" href="{{ route("books.export.pdf", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'pageSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_pdf') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.epub", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'pageSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_epub') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.html", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'pageSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_html') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.plainText", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'pageSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_text') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.markdown", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'pageSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_markdown') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.zip", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'pageSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_zip') }}</a></li>
            @elseif ($entityType === 'chapter')
                <li><a class="dropdown-item" href="{{ route("books.export.pdf", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'chapterSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_pdf') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.epub", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'chapterSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_epub') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.html", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'chapterSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_html') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.plainText", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'chapterSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_text') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.markdown", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'chapterSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_markdown') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.zip", ['bookSlug' => $entity->book->slug ?? $entity->slug, 'chapterSlug' => $entity->slug ?? $entity->id]) }}">{{ trans('entities.export_zip') }}</a></li>
            @else {{-- book --}}
                <li><a class="dropdown-item" href="{{ route("books.export.pdf", ['bookSlug' => $entity->slug]) }}">{{ trans('entities.export_pdf') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.epub", ['bookSlug' => $entity->slug]) }}">{{ trans('entities.export_epub') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.html", ['bookSlug' => $entity->slug]) }}">{{ trans('entities.export_html') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.plainText", ['bookSlug' => $entity->slug]) }}">{{ trans('entities.export_text') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.markdown", ['bookSlug' => $entity->slug]) }}">{{ trans('entities.export_markdown') }}</a></li>
                <li><a class="dropdown-item" href="{{ route("books.export.zip", ['bookSlug' => $entity->slug]) }}">{{ trans('entities.export_zip') }}</a></li>
            @endif
        </ul>
    </li>
@endif
