<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>EasyEye — Documentação da API</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
        }
        .card {
            width: min(380px, 92vw);
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 32px;
        }
        h1 { font-size: 1.1rem; margin: 0 0 4px; }
        p.hint { margin: 0 0 24px; font-size: .85rem; color: #94a3b8; }
        label { display: block; font-size: .85rem; margin-bottom: 6px; color: #cbd5e1; }
        input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #475569;
            background: #0f172a;
            color: #e2e8f0;
            font-size: 1rem;
        }
        input[type="password"]:focus { outline: 2px solid #38bdf8; border-color: transparent; }
        .error { color: #f87171; font-size: .85rem; margin-top: 8px; }
        button {
            margin-top: 20px;
            width: 100%;
            padding: 10px;
            border: 0;
            border-radius: 8px;
            background: #0ea5e9;
            color: #fff;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { background: #0284c7; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Documentação da API de Integradores</h1>
        <p class="hint">Acesso restrito. Informe a senha fornecida pela equipe EasyEye.</p>

        <form method="POST" action="{{ route('docs.api.auth.store') }}">
            @csrf
            <label for="password">Senha de acesso</label>
            <input id="password" name="password" type="password" required autofocus autocomplete="current-password">

            @error('password')
                <div class="error">{{ $message }}</div>
            @enderror

            <button type="submit">Acessar documentação</button>
        </form>
    </main>
</body>
</html>
