<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h5 style="font-weight:600;font-size:1.0625rem;color:#1e293b;margin:0;line-height:1.3;">
            {{ $meta['title'] ?? 'Título da Página' }}
        </h5>
    </div>
    @if(!empty($meta['breadcrumbs']) && count($meta['breadcrumbs']) > 0)
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                @foreach($meta['breadcrumbs'] as $breadcrumb)
                    <li class="breadcrumb-item {{ ($breadcrumb['active'] ?? false) ? 'active' : '' }}"
                        @if($breadcrumb['active'] ?? false) aria-current="page" @endif>
                        @if($breadcrumb['active'] ?? false)
                            {{ $breadcrumb['label'] ?? 'Página atual' }}
                        @else
                            <a href="{{ $breadcrumb['url'] }}">{{ $breadcrumb['label'] ?? 'Página atual' }}</a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif
</div>
