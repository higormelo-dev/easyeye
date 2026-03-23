@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection


@section('content')
<div class="row g-3">

    {{-- Coluna esquerda: info do paciente + ações --}}
    <div class="col-2">
        @include('system.medical_records._patient-info')
    </div>

    {{-- Coluna direita: timeline --}}
    <div class="col">

        {{-- x-on:show-record.window (forma longa) evita que o Blade interprete @show como diretiva --}}
        <div x-data="medicalRecordsList(@js(route('panel.patients.medicalrecords.ajaxlist', $patient)), @js(url('panel/patients/' . $patient->id . '/medicalrecords')))"
             x-init="init()"
             x-on:show-record.window="openDetail($event.detail.id)">

            {{-- Card principal --}}
            <div class="card">
                <h5 class="card-header">{{ $meta['action'] }}</h5>
                <div class="card-body">

                    {{-- Spinner --}}
                    <div x-show="loading" x-cloak class="text-center py-5">
                        <div class="spinner-border text-info" role="status">
                            <span class="visually-hidden">Carregando…</span>
                        </div>
                    </div>

                    {{-- Timeline --}}
                    <div x-show="!loading" id="records-list"></div>

                </div>
            </div>

            {{-- Offcanvas de detalhe --}}
            <div class="offcanvas offcanvas-end" tabindex="-1" id="recordDetailOffcanvas" style="width:540px;">
                <div class="offcanvas-header border-bottom">
                    <h5 class="offcanvas-title">
                        <i class="fas fa-file-medical me-2 text-info"></i>Prontuário
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

    </div>

</div>
@endsection

@section('javascript')
<script>
function medicalRecordsList(ajaxUrl, baseUrl) {
    return {
        loading: false,

        init() {
            this.fetchList(1);
        },

        fetchList(page) {
            this.loading = true;
            const url = new URL(ajaxUrl);
            url.searchParams.set('page', page);

            fetch(url)
                .then(r => r.text())
                .then(html => {
                    document.getElementById('records-list').innerHTML = html;
                    this.loading = false;
                    document.querySelectorAll('[data-page]').forEach(el => {
                        el.addEventListener('click', () => this.fetchList(parseInt(el.dataset.page)));
                    });
                });
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
    const yn = v => v
        ? '<span class="badge bg-danger">SIM</span>'
        : '<span class="badge bg-secondary">NÃO</span>';
    const ni = v => v || '<span class="text-muted">Não informado</span>';

    return `
        <p class="text-muted small mb-1">${r.code} &mdash; ${r.created_at_formatted}</p>
        <h6 class="mb-3">${r.doctor_name}</h6>
        <hr>

        <h6 class="text-secondary small text-uppercase mb-2">Queixa principal</h6>
        <div class="mb-4 p-3 bg-light rounded">${r.main_complaint ?? '<span class="text-muted">—</span>'}</div>

        <h6 class="text-secondary small text-uppercase mb-2">Histórico</h6>
        <div class="row row-cols-3 g-2 mb-4">
            <div class="col">
                <div class="border rounded p-2 text-center small">
                    <div class="text-muted mb-1">Diabético</div>${yn(r.diabetic)}
                    <div class="text-muted mt-1" style="font-size:.7rem;">Familiar: ${yn(r.diabetic_family)}</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded p-2 text-center small">
                    <div class="text-muted mb-1">Hipertenso</div>${yn(r.hypertensive)}
                    <div class="text-muted mt-1" style="font-size:.7rem;">Familiar: ${yn(r.hypertensive_family)}</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded p-2 text-center small">
                    <div class="text-muted mb-1">Glaucomatoso</div>${yn(r.glaucomatous)}
                    <div class="text-muted mt-1" style="font-size:.7rem;">Familiar: ${yn(r.glaucomatous_family)}</div>
                </div>
            </div>
        </div>

        <h6 class="text-secondary small text-uppercase mb-2">Tonometria</h6>
        <p class="small mb-4">
            <strong>OD:</strong> ${ni(r.tonometer_right)} &nbsp;
            <strong>OE:</strong> ${ni(r.tonometer_left)} &nbsp;
            <strong>Hora:</strong> ${ni(r.tonometer_time)}
        </p>

        ${r.observation_general ? `
        <h6 class="text-secondary small text-uppercase mb-2">Observação geral</h6>
        <p class="small mb-4">${r.observation_general}</p>
        ` : ''}

        ${r.observation_of_lenses ? `
        <h6 class="text-secondary small text-uppercase mb-2">Observação de lentes</h6>
        <p class="small mb-4">${r.observation_of_lenses}</p>
        ` : ''}
    `;
}
</script>
@endsection
