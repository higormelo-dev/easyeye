<div class="row page-titles">
    <div class="col-md-5 align-self-center">
        <h4 class="text-themecolor">
            {{ $meta['title'] ?? 'Título da Página' }}
        </h4>
    </div>
    @if(!empty($meta['breadcrumbs']) && count($meta['breadcrumbs']) > 0)
        <div class="col-md-7 align-self-center text-end">
            <ol class="breadcrumb justify-content-end">
                @foreach($meta['breadcrumbs'] as $breadcrumb)
                    <li class="breadcrumb-item {{ ($breadcrumb['active'] ?? false) ? 'active' : '' }}"
                        @if($breadcrumb['active'] ?? false) aria-current="page" @endif>
                        @if($breadcrumb['active'] ?? false)
                            {{ $breadcrumb['label'] ?? 'Página atual' }}
                        @else
                            <a href="{{ $breadcrumb['url'] }}" class="link-secondary link-underline link-underline-opacity-0">
                                {{ $breadcrumb['label'] ?? 'Página atual' }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
</div>