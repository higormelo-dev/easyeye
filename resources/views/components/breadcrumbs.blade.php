<div class="row page-titles">
    <div class="col-md-5 align-self-center">
        <h4 class="text-themecolor">{{$meta['title'] ?? 'Título da Página' }}</h4>
    </div>
    @if(!empty($meta['breadcrumbs']) && count($meta['breadcrumbs']) > 0)
        <div class="col-md-7 align-self-center text-end">
            <div class="d-flex justify-content-end align-items-center">
                <ol class="breadcrumb justify-content-end">
                    @foreach($meta['breadcrumbs'] as $index => $breadcrumb)
                        <li class="breadcrumb-item {{ ($breadcrumb['active'] ?? false) ? 'active' : '' }}"
                            @if($breadcrumb['active'] ?? false) aria-current="page" @endif>
                            @if($breadcrumb['active'] ?? false)
                                {{ $breadcrumb['label'] ?? 'Página atual' }}
                            @else
                                <a href="{{$breadcrumb['url'] }}" class="link-underline link-underline-opacity-0">
                                    {{ $breadcrumb['label'] ?? 'Página atual' }}
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    @endif
</div>
