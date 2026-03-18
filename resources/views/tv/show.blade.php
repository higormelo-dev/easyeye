<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $tv->entity->name ?? 'Sala de Espera' }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: #060d1a;
            color: #f1f5f9;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* ── Top bar ─────────────────────────────────────────── */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 2.5rem;
            background: #0a1628;
            border-bottom: 2px solid #1e3a5f;
            flex-shrink: 0;
        }
        .entity-name { font-size: 1.3rem; font-weight: 800; color: #38bdf8; text-transform: uppercase; letter-spacing: 0.06em; }
        .top-right { display: flex; align-items: center; gap: 1.5rem; }
        .status-row { display: flex; align-items: center; gap: 0.4rem; font-size: 0.78rem; color: #475569; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: #334155; flex-shrink: 0; }
        .dot.on { background: #22c55e; box-shadow: 0 0 6px #22c55e; }
        .clock { font-size: 2rem; font-weight: 700; color: #e2e8f0; font-variant-numeric: tabular-nums; letter-spacing: 0.04em; }

        /* ── Body ────────────────────────────────────────────── */
        .body {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 1.5rem 3rem 1.2rem;
            gap: 1.2rem;
            overflow: hidden;
            min-height: 0;
        }

        /* ── Section label ───────────────────────────────────── */
        .section-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            padding: 0.4rem 1rem;
            border-radius: 0.4rem 0.4rem 0 0;
        }
        .label-hero   { background: #14532d; color: #bbf7d0; }
        .label-recent { background: #0c2340; color: #bae6fd; }

        /* Indicador de múltiplos (ex: "2 / 3") */
        .carousel-indicator {
            font-size: 0.75rem;
            font-weight: 600;
            opacity: 0.7;
            letter-spacing: 0.05em;
        }

        /* Barra de progresso do carrossel */
        .carousel-progress-bar {
            height: 3px;
            background: #166534;
            flex-shrink: 0;
            overflow: hidden;
        }
        .carousel-progress-fill {
            height: 100%;
            background: #22c55e;
            width: 0%;
            transition: width linear;
        }

        /* ── Hero ────────────────────────────────────────────── */
        .hero-wrap { flex-shrink: 0; }
        .hero-body {
            background: #071a0f;
            border-radius: 0 0 0.5rem 0.5rem;
            border: 1px solid #166534;
            border-top: none;
            padding: 1.8rem 2.5rem;
            min-height: 9rem;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        .hero-slot {
            width: 100%;
            animation: fadeIn 0.4s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .hero-empty {
            text-align: center;
            color: #1a3a24;
            font-size: 1.1rem;
            width: 100%;
        }
        .hero-name {
            font-size: 3.2rem;
            font-weight: 900;
            color: #86efac;
            line-height: 1.1;
            letter-spacing: 0.01em;
            animation: glow 2.5s ease-in-out infinite;
        }
        @keyframes glow {
            0%,100% { text-shadow: 0 0 0 rgba(134,239,172,0); }
            50%      { text-shadow: 0 0 24px rgba(134,239,172,0.5); }
        }
        .hero-doctor {
            font-size: 1.4rem;
            font-weight: 600;
            color: #4ade80;
            margin-top: 0.5rem;
        }

        /* ── Recent grid ─────────────────────────────────────── */
        .recent-wrap { flex: 1; display: flex; flex-direction: column; min-height: 0; }
        .recent-body {
            flex: 1;
            background: #0a1525;
            border-radius: 0 0 0.5rem 0.5rem;
            border: 1px solid #1e3a5f;
            border-top: none;
            padding: 0.8rem 1rem;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #1e293b transparent;
        }
        .recent-body::-webkit-scrollbar { width: 4px; }
        .recent-body::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 2px; }
        .recent-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 0.6rem;
        }
        .recent-card {
            background: #0d1a2e;
            border-left: 3px solid #0ea5e9;
            border-radius: 0.4rem;
            padding: 0.7rem 0.9rem;
        }
        .recent-name   { font-size: 1rem; font-weight: 700; color: #e0f2fe; line-height: 1.2; }
        .recent-doctor { font-size: 0.78rem; font-weight: 600; color: #0369a1; margin-top: 0.12rem; }
        .empty { text-align: center; padding: 1.2rem; color: #1e293b; font-size: 0.95rem; }
    </style>
</head>
<body>

<div class="top-bar">
    <div class="entity-name">{{ $tv->entity->name ?? config('app.name') }}</div>
    <div class="top-right">
        <div class="status-row">
            <div class="dot" id="dot"></div>
            <span id="statusLabel">Conectando...</span>
        </div>
        <div class="clock" id="clock">--:--:--</div>
    </div>
</div>

<div class="body">

    {{-- ── Sendo chamado ─────────────────────────────────── --}}
    <div class="hero-wrap">
        <div class="section-label label-hero">
            <span>&#9654; Sendo chamado agora</span>
            <span class="carousel-indicator" id="carouselIndicator"></span>
        </div>
        <div class="carousel-progress-bar">
            <div class="carousel-progress-fill" id="progressFill"></div>
        </div>
        <div class="hero-body" id="heroBody">
            @php $inprogress = collect($active)->where('situation', 3)->values(); @endphp
            @if($inprogress->isEmpty())
                <div class="hero-slot"><div class="hero-empty">Nenhum paciente sendo chamado</div></div>
            @else
                @php $first = $inprogress->first(); @endphp
                <div class="hero-slot">
                    <div class="hero-name">{{ $first['name'] }}</div>
                    <div class="hero-doctor">Dr(a). {{ $first['doctor_name'] }}</div>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Últimos 6 chamados ────────────────────────────── --}}
    <div class="recent-wrap">
        <div class="section-label label-recent">
            <span>&#10003; Últimos chamados</span>
        </div>
        <div class="recent-body">
            <div class="recent-grid" id="recentGrid">
                @forelse($recentlyCalled as $p)
                    <div class="recent-card">
                        <div class="recent-name">{{ $p['name'] }}</div>
                        <div class="recent-doctor">Dr(a). {{ $p['doctor_name'] }}</div>
                    </div>
                @empty
                    <div class="empty" style="grid-column:span 6">Nenhum atendimento hoje</div>
                @endforelse
            </div>
        </div>
    </div>

</div>

<script>
    // ── Clock ────────────────────────────────────────────────────────
    (function tick() {
        const n = new Date(), p = v => String(v).padStart(2,'0');
        document.getElementById('clock').textContent = `${p(n.getHours())}:${p(n.getMinutes())}:${p(n.getSeconds())}`;
        setTimeout(tick, 1000);
    })();

    // ── Audio ────────────────────────────────────────────────────────
    function beep() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator(), g = ctx.createGain();
            osc.connect(g); g.connect(ctx.destination);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(660, ctx.currentTime + 0.3);
            g.gain.setValueAtTime(0.35, ctx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.55);
            osc.start(); osc.stop(ctx.currentTime + 0.55);
        } catch {}
    }

    function h(s) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(s || ''));
        return d.innerHTML;
    }

    // ── Carrossel ────────────────────────────────────────────────────
    const SLIDE_MS   = 5000; // tempo em cada paciente (ms)
    const POLL_URL   = '{{ route('tv.poll', $tv->token) }}';
    const POLL_MS    = 5000;

    let patients     = @json(collect($active)->where('situation', 3)->values());
    let carouselIdx  = 0;
    let carouselTimer = null;
    let progressTimer = null;

    function showSlide(idx) {
        const heroBody  = document.getElementById('heroBody');
        const indicator = document.getElementById('carouselIndicator');
        const fill      = document.getElementById('progressFill');

        if (patients.length === 0) {
            indicator.textContent = '';
            fill.style.transition = 'none';
            fill.style.width = '0%';
            heroBody.innerHTML = '<div class="hero-slot"><div class="hero-empty">Nenhum paciente sendo chamado</div></div>';
            return;
        }

        const p = patients[idx];
        indicator.textContent = patients.length > 1 ? `${idx + 1} / ${patients.length}` : '';

        heroBody.innerHTML = `
            <div class="hero-slot">
                <div class="hero-name">${h(p.name)}</div>
                <div class="hero-doctor">Dr(a). ${h(p.doctor_name)}</div>
            </div>`;

        // Barra de progresso
        fill.style.transition = 'none';
        fill.style.width = '0%';
        // Força reflow para reiniciar a animação
        void fill.offsetWidth;
        fill.style.transition = `width ${SLIDE_MS}ms linear`;
        fill.style.width = '100%';
    }

    function startCarousel() {
        clearInterval(carouselTimer);
        carouselIdx = 0;
        showSlide(carouselIdx);

        if (patients.length > 1) {
            carouselTimer = setInterval(() => {
                carouselIdx = (carouselIdx + 1) % patients.length;
                showSlide(carouselIdx);
            }, SLIDE_MS);
        }
    }

    // ── Dados e polling ──────────────────────────────────────────────
    let prevIds  = new Set(@json(collect($active)->where('situation', 3)->pluck('id')->values()));
    let lastHash = '';

    function applyData(data) {
        const progress = (data.active ?? []).filter(p => p.situation === 3);
        const recent   = data.recently_called ?? [];

        // Detecta novos pacientes chamados → beep
        const curIds = new Set(progress.map(p => p.id));
        let hasNew = false;
        for (const id of curIds) { if (!prevIds.has(id)) { hasNew = true; break; } }
        prevIds = curIds;
        if (hasNew) beep();

        // Atualiza carrossel apenas se a lista de pacientes mudou
        const newIds = JSON.stringify(progress.map(p => p.id));
        const oldIds = JSON.stringify(patients.map(p => p.id));
        if (newIds !== oldIds) {
            patients = progress;
            startCarousel();
        }

        // Últimos chamados
        document.getElementById('recentGrid').innerHTML = recent.length
            ? recent.map(p => `
                <div class="recent-card">
                    <div class="recent-name">${h(p.name)}</div>
                    <div class="recent-doctor">Dr(a). ${h(p.doctor_name)}</div>
                </div>`).join('')
            : '<div class="empty" style="grid-column:span 6">Nenhum atendimento hoje</div>';
    }

    function setStatus(on) {
        document.getElementById('dot').className = 'dot' + (on ? ' on' : '');
        document.getElementById('statusLabel').textContent = on ? 'Conectado' : 'Sem conexão';
    }

    async function poll() {
        try {
            const res  = await fetch(POLL_URL, { cache: 'no-store' });
            if (!res.ok) { setStatus(false); return; }
            const json = await res.text();
            if (json !== lastHash) { lastHash = json; applyData(JSON.parse(json)); }
            setStatus(true);
        } catch { setStatus(false); }
    }

    // Inicia
    startCarousel();
    poll();
    setInterval(poll, POLL_MS);
</script>

</body>
</html>
