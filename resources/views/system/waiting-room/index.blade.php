@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

<div class="card">
    <h5 class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fa fa-clock-o me-2"></i>{{ $meta['action'] }}</span>
        <small class="text-muted fw-normal" id="last-updated"></small>
    </h5>
    <div class="card-body p-0">
        <div id="waiting-list">
            @include('system.waiting-room._list', ['schedules' => collect()])
        </div>
    </div>
</div>

@endsection

@section('javascript')
<script>
(function () {
    const url    = @js(route('panel.waiting-room.ajaxlist'));
    const token  = document.querySelector('meta[name="csrf-token"]').content;

    function load() {
        fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.text())
        .then(html => {
            document.getElementById('waiting-list').innerHTML = html;
            const el = document.getElementById('last-updated');
            if (el) el.textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            initTooltips();
        });
    }

    function initTooltips() {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            bootstrap.Tooltip.getOrCreateInstance(el);
        });
    }

    function updateSituation(scheduleId, situation) {
        fetch(@js(url('/panel/schedules')) + '/' + scheduleId + '/situation', {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': token,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ situation })
        })
        .then(r => r.json())
        .then(res => {
            if (window.showSuccessToast) showSuccessToast(res.message);
            load();
        })
        .catch(() => {
            if (window.showErrorToast) showErrorToast('Erro ao atualizar.');
        });
    }

    // Delega clique nos botões de situação
    document.getElementById('waiting-list').addEventListener('click', function (e) {
        const btn = e.target.closest('[data-situation-action]');
        if (!btn) return;
        updateSituation(btn.dataset.id, parseInt(btn.dataset.situationAction));
    });

    load();
    setInterval(load, 30000); // atualiza a cada 30s
})();
</script>
@endsection
