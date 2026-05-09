@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

{{-- ── Subnav ──────────────────────────────────────────────────────────── --}}
<div class="row mb-3 align-items-center">
    <div class="col-12 col-md-auto">
        <div class="btn-group" role="group">
            <a href="{{ route('panel.patients.index') }}" class="btn btn-outline-white btn-sm">
                <i class="fas fa-arrow-left me-1"></i>{{ __('actions.sidemenu.patients') }}
            </a>
            <a href="{{ route('panel.patients.medicalrecords.create', $patient) }}" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i>{{ __('actions.new') }}
            </a>
        </div>
    </div>
</div>

<div class="row g-3"
     x-data="medicalRecordsList(@js(route('panel.patients.medicalrecords.ajaxlist', $patient)), @js(url('panel/patients/' . $patient->id . '/medicalrecords')))"
     x-on:show-record.window="openDetail($event.detail.id)">

    {{-- ── Coluna lateral: info do paciente ─────────────────────────────── --}}
    <div class="col-12 col-sm-2 col-md-2 col-lg-2 col-xl-2">
        <div class="patient-info-sticky">
            @include('system.medical_records._patient-info')
        </div>
    </div>

    {{-- ── Coluna principal: timeline ────────────────────────────────────── --}}
    <div class="col-12 col-sm-10 col-md-10 col-lg-10 col-xl-10">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-file-medical me-2 text-info"></i>
                    {{ __('actions.medical_records.title') }}
                </h5>
            </div>
            <div class="card-body">

                {{-- Spinner de carregamento inicial --}}
                <div x-show="loading" x-cloak class="text-center py-5">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">{{ __('actions.medical_records.loading_more') }}</span>
                    </div>
                </div>

                {{-- Empty state --}}
                <div x-show="isEmpty" x-cloak class="text-center py-5 text-muted">
                    <i class="fas fa-file-medical fa-3x mb-3 d-block opacity-25"></i>
                    <p class="mb-3">{{ __('actions.medical_records.no_records') }}</p>
                    <a href="{{ route('panel.patients.medicalrecords.create', $patient) }}"
                       class="btn btn-primary btn-sm">
                        <i class="fas fa-plus me-1"></i>{{ __('actions.new') }}
                    </a>
                </div>

                {{-- Timeline --}}
                <div x-show="!loading">
                    <ul class="timeline" id="timeline-list"></ul>

                    {{-- Sentinel: IntersectionObserver ancora aqui para disparar próxima página --}}
                    <div id="timeline-sentinel" style="height:1px;"></div>

                    {{-- Loading more --}}
                    <div x-show="loadingMore" class="text-center py-3">
                        <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
                        <span class="ms-2 text-muted small">{{ __('actions.medical_records.loading_more') }}</span>
                    </div>

                    {{-- Fim da lista --}}
                    <div x-show="!hasMore && !isEmpty" x-cloak class="text-center py-3 text-muted small">
                        <i class="fas fa-check-circle me-1 text-success"></i>
                        {{ __('actions.medical_records.end_of_list') }}
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- ── Offcanvas de detalhe ──────────────────────────────────────────── --}}
    <div class="offcanvas offcanvas-end" tabindex="-1" id="recordDetailOffcanvas" style="width:560px;">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title">
                <i class="fas fa-file-medical me-2 text-info"></i>
                {{ __('actions.medical_records.offcanvas_title') }}
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body" id="record-detail-body">
            <div class="text-center py-4">
                <div class="spinner-border text-secondary" role="status"></div>
            </div>
        </div>
    </div>

</div>
@endsection

@section('javascript')
@if(session('message'))
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.$ && typeof $.toast === 'function') {
        $.toast({
            heading: '{{ __("actions.done") }}',
            text: '{{ addslashes(session("message")) }}',
            position: 'top-right',
            loaderBg: '#006A4E',
            icon: 'success',
            hideAfter: 3500,
            stack: 6
        });
    }
});
</script>
@endif
<script>
function medicalRecordsList(ajaxUrl, baseUrl) {
    return {
        loading:     false,
        loadingMore: false,
        hasMore:     true,
        nextPage:    1,
        isEmpty:     false,
        observer:    null,
        _renderedIds: new Set(),

        init() {
            this.fetchPage(1);

            // bfcache: quando o navegador restaura a página via botão "voltar",
            // o Alpine reinicializa mas o DOM já tem itens — recarrega do zero.
            window.addEventListener('pageshow', (e) => {
                if (e.persisted) {
                    this._reset();
                    this.fetchPage(1);
                }
            });
        },

        _reset() {
            if (this.observer) {
                this.observer.disconnect();
                this.observer = null;
            }
            const list = document.getElementById('timeline-list');
            if (list) list.innerHTML = '';
            this._renderedIds = new Set();
            this.hasMore     = true;
            this.nextPage    = 1;
            this.isEmpty     = false;
            this.loading     = false;
            this.loadingMore = false;
        },

        fetchPage(page) {
            if (this.loading || this.loadingMore) return;

            if (page === 1) {
                this.loading = true;
                // Garante lista limpa no início (protege contra double-init e bfcache).
                const list = document.getElementById('timeline-list');
                if (list) { list.innerHTML = ''; }
                this._renderedIds = new Set();
            } else {
                this.loadingMore = true;
            }

            const url = new URL(ajaxUrl);
            url.searchParams.set('page', page);

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    this._appendUnique(data.html);

                    this.hasMore     = data.has_more;
                    this.nextPage    = data.next_page;
                    this.isEmpty     = (page === 1 && data.total === 0);
                    this.loading     = false;
                    this.loadingMore = false;

                    if (this.hasMore && !this.observer) {
                        this.setupObserver();
                    }
                    if (!this.hasMore && this.observer) {
                        this.observer.disconnect();
                        this.observer = null;
                    }
                });
        },

        // Insere apenas itens cujo data-record-id ainda não está no DOM.
        _appendUnique(html) {
            const list = document.getElementById('timeline-list');
            if (!list) return;

            const tmp = document.createElement('ul');
            tmp.innerHTML = html;

            tmp.querySelectorAll('li[data-record-id]').forEach(li => {
                const id = li.dataset.recordId;
                if (!this._renderedIds.has(id)) {
                    this._renderedIds.add(id);
                    list.appendChild(li);
                }
            });

            // Fallback: itens sem data-record-id são appendados normalmente.
            tmp.querySelectorAll('li:not([data-record-id])').forEach(li => {
                list.appendChild(li);
            });
        },

        setupObserver() {
            const sentinel = document.getElementById('timeline-sentinel');
            if (!sentinel) return;

            this.observer = new IntersectionObserver((entries) => {
                if (entries[0].isIntersecting && !this.loadingMore && this.hasMore) {
                    this.fetchPage(this.nextPage);
                }
            }, { rootMargin: '200px' });

            this.observer.observe(sentinel);
        },

        openDetail(id) {
            const offcanvas = bootstrap.Offcanvas.getOrCreateInstance(
                document.getElementById('recordDetailOffcanvas')
            );
            document.getElementById('record-detail-body').innerHTML =
                '<div class="text-center py-5"><div class="spinner-border text-secondary" role="status"></div></div>';
            offcanvas.show();

            fetch(baseUrl + '/' + id, { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    document.getElementById('record-detail-body').innerHTML = buildDetailHtml(data);
                });
        }
    };
}

function buildDetailHtml(r) {
    const L   = r.labels;
    const yn  = (v) => v
        ? `<span class="badge bg-danger">${L.yes}</span>`
        : `<span class="badge bg-secondary">${L.no}</span>`;
    const ni  = (v) => v || `<span class="text-muted">${L.not_informed}</span>`;

    return `
        <p class="text-muted small mb-1">${r.code} &mdash; ${r.created_at_formatted}</p>
        <h6 class="mb-3">${r.doctor_name}</h6>
        <hr>

        <h6 class="text-secondary small text-uppercase mb-2">${L.complaint}</h6>
        <div class="mb-4 p-3 bg-light rounded">
            ${r.main_complaint ? r.main_complaint : `<span class="text-muted">—</span>`}
        </div>

        <h6 class="text-secondary small text-uppercase mb-2">${L.history}</h6>
        <div class="row row-cols-3 g-2 mb-4">
            <div class="col">
                <div class="border rounded p-2 text-center small">
                    <div class="text-muted mb-1">${L.diabetic}</div>${yn(r.diabetic)}
                    <div class="text-muted mt-1" style="font-size:.7rem;">${L.family}: ${yn(r.diabetic_family)}</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded p-2 text-center small">
                    <div class="text-muted mb-1">${L.hypertensive}</div>${yn(r.hypertensive)}
                    <div class="text-muted mt-1" style="font-size:.7rem;">${L.family}: ${yn(r.hypertensive_family)}</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded p-2 text-center small">
                    <div class="text-muted mb-1">${L.glaucomatous}</div>${yn(r.glaucomatous)}
                    <div class="text-muted mt-1" style="font-size:.7rem;">${L.family}: ${yn(r.glaucomatous_family)}</div>
                </div>
            </div>
        </div>

        <h6 class="text-secondary small text-uppercase mb-2">${L.tonometry}</h6>
        <p class="small mb-4">
            <strong>OD:</strong> ${ni(r.tonometer_right)} &nbsp;
            <strong>OE:</strong> ${ni(r.tonometer_left)} &nbsp;
            <strong>H:</strong> ${ni(r.tonometer_time)}
        </p>

        ${r.observation_general ? `
        <h6 class="text-secondary small text-uppercase mb-2">${L.general_obs}</h6>
        <p class="small mb-4">${r.observation_general}</p>
        ` : ''}

        ${r.observation_of_lenses ? `
        <h6 class="text-secondary small text-uppercase mb-2">${L.lenses_obs}</h6>
        <p class="small mb-4">${r.observation_of_lenses}</p>
        ` : ''}
    `;
}
</script>
@endsection
