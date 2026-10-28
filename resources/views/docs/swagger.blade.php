<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>EasyEye — API de Integradores</title>
    {{-- Versão pinada do swagger-ui-dist (supply chain: não usar "latest") --}}
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css">
    <style>
        body { margin: 0; }
        .docs-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            background: #0f172a;
            color: #e2e8f0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .docs-topbar strong { font-size: .95rem; }
        .docs-topbar form { margin: 0; }
        .docs-topbar button {
            background: transparent;
            border: 1px solid #475569;
            color: #cbd5e1;
            border-radius: 6px;
            padding: 5px 12px;
            font-size: .8rem;
            cursor: pointer;
        }
        .docs-topbar button:hover { border-color: #94a3b8; color: #fff; }
    </style>
</head>
<body>
    <header class="docs-topbar">
        <strong>EasyEye — API de Integradores</strong>
        <form method="POST" action="{{ route('docs.api.logout') }}">
            @csrf
            <button type="submit">Encerrar acesso</button>
        </form>
    </header>

    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
    <script>
        window.ui = SwaggerUIBundle({
            url: @json(route('docs.api.spec')),
            dom_id: '#swagger-ui',
            deepLinking: true,
            docExpansion: 'list',
            defaultModelsExpandDepth: 1,
            persistAuthorization: true, // mantém o Bearer entre reloads (sessionStorage)
            tryItOutEnabled: true,
            presets: [SwaggerUIBundle.presets.apis],
        });
    </script>
</body>
</html>
