<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aguardando aprovação</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: #0f172a;
            color: #f8fafc;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 1rem;
            padding: 3rem 2.5rem;
            width: 100%;
            max-width: 480px;
            text-align: center;
        }

        .icon { font-size: 3rem; margin-bottom: 1rem; }

        h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #38bdf8;
            margin-bottom: 0.5rem;
        }

        .subtitle {
            color: #94a3b8;
            font-size: 0.95rem;
            margin-bottom: 2.5rem;
            line-height: 1.5;
        }

        .pin-label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #64748b;
            margin-bottom: 0.75rem;
        }

        .pin {
            font-size: 4rem;
            font-weight: 800;
            letter-spacing: 0.4em;
            color: #fbbf24;
            font-variant-numeric: tabular-nums;
            margin-bottom: 2.5rem;
            text-shadow: 0 0 30px rgba(251,191,36,0.3);
        }

        .waiting-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            color: #64748b;
            font-size: 0.9rem;
        }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #64748b;
            animation: blink 1.4s ease-in-out infinite;
        }

        .dot:nth-child(2) { animation-delay: 0.2s; }
        .dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes blink {
            0%, 80%, 100% { opacity: 0.2; }
            40% { opacity: 1; }
        }

        .rejected {
            color: #f87171;
            font-size: 1rem;
            display: none;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🔐</div>
        <h1>Aguardando aprovação</h1>
        <p class="subtitle">
            Informe o código abaixo ao administrador do sistema.<br>
            A tela será ativada automaticamente após a aprovação.
        </p>

        <div class="pin-label">Código de verificação</div>
        <div class="pin">{{ implode(' ', str_split($tv->pin, 1)) }}</div>

        <div class="waiting-indicator" id="waitingIndicator">
            <div class="dot"></div>
            <div class="dot"></div>
            <div class="dot"></div>
            <span>Aguardando aprovação...</span>
        </div>

        <div class="rejected" id="rejectedMsg">
            ✗ Solicitação recusada. <a href="/tv" style="color:#38bdf8;">Tentar novamente</a>
        </div>
    </div>

    <script>
        const requestId  = '{{ $tv->id }}';
        const statusUrl  = '/tv/status/' + requestId;
        let   pollHandle = null;

        async function poll() {
            try {
                const res  = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();

                if (data.status === 'active' && data.redirect) {
                    clearInterval(pollHandle);
                    window.location.href = data.redirect;
                    return;
                }

                if (data.status === 'rejected' || data.status === 'not_found') {
                    clearInterval(pollHandle);
                    document.getElementById('waitingIndicator').style.display = 'none';
                    document.getElementById('rejectedMsg').style.display      = 'block';
                }
            } catch {
                // Ignore network errors, keep polling
            }
        }

        pollHandle = setInterval(poll, 3000);
        poll(); // first check immediately
    </script>
</body>
</html>
