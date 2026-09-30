{{-- Export menu for entities --}}
{{-- Show export options for books, pages, and chapters --}}

@if ($entity->canExport())
    <li class="nav-item {{ $entity->isSelected() ? 'active' : '' }}">
        <a href="#" class="nav-link js-export-trigger" data-entity-type="{{ $entity->type }}" data-entity-id="{{ $entity->id }}">
            <i class="icon-download"></i> {{ trans('entities.export') }}
            <span class="caret"></span>
        </a>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="{{ route('books.export.epub', $entity->id) }}">{{ trans('lang.nl.export_epub') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('books.export.zip', $entity->id) }}">{{ trans('lang.nl.export_zip') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('books.export.html', $entity->id) }}">{{ trans('lang.nl.export_html') }}</a></li>
        </ul>
    </li>
@endif