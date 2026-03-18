<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conectar Display de TV</title>
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
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 420px;
            text-align: center;
        }

        .icon { font-size: 3rem; margin-bottom: 1rem; }

        h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #38bdf8;
            margin-bottom: 0.5rem;
        }

        p {
            color: #94a3b8;
            font-size: 0.95rem;
            margin-bottom: 2rem;
            line-height: 1.5;
        }

        label {
            display: block;
            text-align: left;
            font-size: 0.85rem;
            color: #94a3b8;
            margin-bottom: 0.4rem;
            font-weight: 500;
        }

        input[type="text"] {
            width: 100%;
            padding: 0.75rem 1rem;
            background: #0f172a;
            border: 1px solid #475569;
            border-radius: 0.5rem;
            color: #f8fafc;
            font-size: 1.1rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            outline: none;
            transition: border-color 0.2s;
        }

        input[type="text"]:focus { border-color: #38bdf8; }
        input[type="text"].error { border-color: #f87171; }

        .error-msg {
            color: #f87171;
            font-size: 0.85rem;
            margin-top: 0.4rem;
            text-align: left;
            display: none;
        }

        button {
            width: 100%;
            padding: 0.85rem;
            margin-top: 1.5rem;
            background: #0ea5e9;
            color: #fff;
            border: none;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, opacity 0.2s;
        }

        button:hover:not(:disabled) { background: #0284c7; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }

        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">📺</div>
        <h1>Display de Sala de Espera</h1>
        <p>Digite o código da empresa para conectar esta TV ao sistema.</p>

        <label for="code">Código da empresa</label>
        <input type="text" id="code" placeholder="ENT-0000000001" maxlength="20" autocomplete="off">
        <div class="error-msg" id="errorMsg"></div>

        <button id="submitBtn" onclick="requestAccess()">
            Conectar
        </button>
    </div>

    <script>
        document.getElementById('code').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') requestAccess();
        });

        async function requestAccess() {
            const codeInput = document.getElementById('code');
            const errorMsg  = document.getElementById('errorMsg');
            const btn       = document.getElementById('submitBtn');
            const code      = codeInput.value.trim();

            if (!code) {
                codeInput.classList.add('error');
                errorMsg.style.display = 'block';
                errorMsg.textContent   = 'Informe o código da empresa.';
                return;
            }

            codeInput.classList.remove('error');
            errorMsg.style.display = 'none';
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Conectando...';

            try {
                const res = await fetch('{{ route('tv.request') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ code }),
                });

                const data = await res.json();

                if (!res.ok) {
                    codeInput.classList.add('error');
                    errorMsg.style.display = 'block';
                    errorMsg.textContent   = data.message ?? 'Código inválido.';
                    btn.disabled           = false;
                    btn.textContent        = 'Conectar';
                    return;
                }

                // Redireciona para a tela de espera com o PIN
                window.location.href = '/tv/wait/' + data.id;

            } catch {
                errorMsg.style.display = 'block';
                errorMsg.textContent   = 'Erro de conexão. Tente novamente.';
                btn.disabled           = false;
                btn.textContent        = 'Conectar';
            }
        }
    </script>
</body>
</html>
