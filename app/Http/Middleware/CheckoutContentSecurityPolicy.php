<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy das telas que carregam o SDK de cartão dos
 * gateways: Minha assinatura, a tela de IA (Uso e créditos — compra de
 * pacotes), /subscription/expired e o cadastro no site (contratar já
 * pagando).
 *
 * O painel é SPA (Inertia): o cabeçalho vale para o documento carregado
 * nessas telas e continua valendo enquanto o usuário navega dali para
 * qualquer outra tela do painel. Por isso a política libera tudo o que o
 * painel usa, sem abrir script-src para hosts arbitrários:
 *  - imagens/vídeos/PDF do storage (disco public e S3 — URLs assinadas do
 *    AWS_URL/AWS_ENDPOINT ou do bucket na AWS) em img/media/frame/connect-src;
 *  - blob: (prévias de upload, PDF e montagem gerados no navegador) em
 *    img/media/frame-src e workers;
 *  - ViaCEP (preenchimento de endereço pelo CEP) em connect-src;
 *  - Reverb (WebSocket) — host do .env ou, sem ele, o do próprio site.
 *
 * script-src: o próprio app + os 4 SDKs oficiais (e o Turnstile no cadastro).
 * 'unsafe-inline' fica pelos scripts inline dos entry-points (tema, @routes)
 * e 'unsafe-eval' porque o MercadoPago.js v2 exige; o ganho é a lista de
 * hosts: script de outra origem (skimmer injetado) não carrega e não há para
 * onde mandar dados (connect-src/form-action).
 *
 * Fontes das diretivas:
 *  - Stripe.js: https://docs.stripe.com/security/guide#content-security-policy
 *  - Mercado Pago (MercadoPago.js/Bricks — *.mercadopago.com, *.mlstatic.com,
 *    'unsafe-eval'): https://github.com/mercadopago/sdk-js/discussions/16
 *  - Pagar.me tokenizecard.js (checkout.pagar.me → api.pagar.me):
 *    https://docs.pagar.me/reference/pagarme-js
 *  - PagBank (criptografia local, script em assets.pagseguro.com.br):
 *    https://developer.pagbank.com.br/docs/criptografia-e-chave-publica
 *  - Turnstile: https://developers.cloudflare.com/turnstile/reference/content-security-policy/
 *
 * Desenvolvimento: o servidor do Vite (arquivo public/hot) e o Reverb
 * (REVERB_HOST/PORT/SCHEME) entram em script/connect-src. billing.csp.mode:
 * enforce (padrão), report-only (só relatório no console) ou off.
 */
class CheckoutContentSecurityPolicy
{
    private const SCRIPT_HOSTS = [
        'https://sdk.mercadopago.com', 'https://*.mercadopago.com', 'https://*.mlstatic.com',
        'https://checkout.pagar.me',
        'https://js.stripe.com', 'https://*.js.stripe.com',
        'https://assets.pagseguro.com.br',
        'https://challenges.cloudflare.com',
    ];

    private const CONNECT_HOSTS = [
        // CEP → endereço (cadastro de paciente, médico e empresa).
        'https://viacep.com.br',
        'https://api.mercadopago.com', 'https://*.mercadopago.com', 'https://*.mercadopago.com.br', 'https://*.mercadolibre.com', 'https://*.mercadolivre.com', 'https://*.mlstatic.com',
        'https://api.pagar.me',
        'https://api.stripe.com',
        'https://challenges.cloudflare.com',
    ];

    private const FRAME_HOSTS = [
        'https://*.mercadopago.com', 'https://*.mercadopago.com.br', 'https://*.mercadolibre.com', 'https://*.mlstatic.com',
        'https://js.stripe.com', 'https://*.js.stripe.com', 'https://hooks.stripe.com',
        'https://challenges.cloudflare.com',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('billing.csp.mode', 'enforce');

        // Painel é SPA: a CSP só vale para o documento carregado. Quem chega
        // a uma tela de checkout navegando de OUTRA tela (visita Inertia)
        // recebe 409 + X-Inertia-Location e o Inertia faz o carregamento
        // completo — com o cabeçalho. Filtros e recargas parciais na própria
        // tela continuam SPA.
        if ($mode !== 'off' && $this->arrivesFromAnotherPage($request)) {
            return response('', 409)->header('X-Inertia-Location', $request->fullUrl());
        }

        $response = $next($request);

        if ($mode === 'off' || $response->headers->has('Content-Security-Policy') || $response->headers->has('Content-Security-Policy-Report-Only')) {
            return $response;
        }

        $header = $mode === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        $response->headers->set($header, self::policy($request));

        return $response;
    }

    private function arrivesFromAnotherPage(Request $request): bool
    {
        if (! $request->isMethod('GET') || ! $request->header('X-Inertia') || $request->header('X-Inertia-Partial-Component')) {
            return false;
        }

        $referer = (string) $request->headers->get('referer');
        $from    = $referer === '' ? null : parse_url($referer, PHP_URL_PATH);

        // Navegador sempre manda o Referer na visita Inertia (mesma origem).
        // Sem ele (cliente de API/teste) não dá para saber de onde veio: segue.
        return is_string($from) && rtrim($from, '/') !== rtrim('/' . ltrim($request->path(), '/'), '/');
    }

    public static function policy(?Request $request = null): string
    {
        [$devScript, $devConnect] = self::developmentSources();
        $realtime                 = self::realtimeSources($request);
        $storage                  = self::storageSources();

        $directives = [
            'default-src' => ["'self'"],
            'script-src'  => ["'self'", "'unsafe-inline'", "'unsafe-eval'", ...self::SCRIPT_HOSTS, ...$devScript, ...self::assetSources()],
            // Card Payment Brick (Mercado Pago) traz CSS e fontes de *.mlstatic.com.
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com', 'https://*.mlstatic.com', ...$devScript, ...self::assetSources()],
            // Fontes de ícones (Tabler/Font Awesome) e imagens do app vêm do
            // Vite em dev e do ASSET_URL (CDN) se configurado.
            'font-src' => ["'self'", 'data:', 'https://fonts.gstatic.com', 'https://*.mlstatic.com', ...$devScript, ...self::assetSources()],
            'img-src'  => ["'self'", 'data:', 'blob:', 'https:', ...$storage, ...$devScript, ...self::assetSources()],
            // Áudio/vídeo de exames e prévias geradas no navegador.
            'media-src'   => ["'self'", 'data:', 'blob:', ...$storage, ...self::assetSources()],
            'connect-src' => ["'self'", ...self::CONNECT_HOSTS, ...$storage, ...$realtime, ...$devConnect],
            // PDFs (laudos, documentos, prévia) em iframe: rota do app, blob: ou URL assinada do storage.
            'frame-src'       => ["'self'", 'blob:', ...$storage, ...self::FRAME_HOSTS],
            'worker-src'      => ["'self'", 'blob:'],
            'object-src'      => ["'none'"],
            'base-uri'        => ["'self'"],
            'form-action'     => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $name) => $name . ' ' . implode(' ', array_values(array_unique($sources))))
            ->implode('; ');
    }

    /** @return list<string> origem do ASSET_URL (CDN de assets), se houver */
    private static function assetSources(): array
    {
        $url = parse_url((string) config('app.asset_url'));

        if (! is_array($url) || empty($url['host'])) {
            return [];
        }

        return [($url['scheme'] ?? 'https') . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '')];
    }

    /** @return array{0: list<string>, 1: list<string>} servidor do Vite em `npm run dev` */
    private static function developmentSources(): array
    {
        if (! Vite::isRunningHot()) {
            return [[], []];
        }

        $hot = trim((string) @file_get_contents(Vite::hotFile()));
        $url = parse_url($hot);

        if (! is_array($url) || empty($url['host'])) {
            return [[], []];
        }

        $origin = ($url['scheme'] ?? 'http') . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
        $ws     = (($url['scheme'] ?? 'http') === 'https' ? 'wss' : 'ws') . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');

        return [[$origin], [$origin, $ws]];
    }

    /**
     * Origens do storage de arquivos (imagens, PDFs, vídeos): o disco public
     * (APP_URL/storage, normalmente o próprio site) e o S3 — AWS_URL,
     * AWS_ENDPOINT (MinIO/compatível; subdomínio do bucket incluído) ou o
     * host do bucket na AWS. Sem o S3 configurado, só o public.
     *
     * @return list<string>
     */
    private static function storageSources(): array
    {
        $origins = [];

        foreach ([config('filesystems.disks.public.url'), config('filesystems.disks.s3.url')] as $url) {
            if (($origin = self::originOf((string) $url)) !== null) {
                $origins[] = $origin;
            }
        }

        $bucket = (string) config('filesystems.disks.s3.bucket', '');
        $region = (string) config('filesystems.disks.s3.region', '');

        if (($endpoint = self::originOf((string) config('filesystems.disks.s3.endpoint'))) !== null) {
            $origins[] = $endpoint;
            // Estilo virtual-host: https://<bucket>.<endpoint>.
            $origins[] = preg_replace('#^(https?://)#', '$1*.', $endpoint);
        } elseif ($bucket !== '') {
            $origins[] = 'https://' . $bucket . '.s3.amazonaws.com';
            $origins[] = 'https://s3.amazonaws.com';

            if ($region !== '') {
                $origins[] = 'https://' . $bucket . '.s3.' . $region . '.amazonaws.com';
                $origins[] = 'https://s3.' . $region . '.amazonaws.com';
            }
        }

        return array_values(array_unique($origins));
    }

    private static function originOf(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || empty($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * @return list<string> Reverb (WebSocket) — aviso de pagamento em tempo
     *                      real. Sem REVERB_HOST, o front conecta no host do
     *                      próprio site (echo.js): esse host, na porta do Reverb.
     */
    private static function realtimeSources(?Request $request = null): array
    {
        $options = (array) config('broadcasting.connections.reverb.options', []);
        $host    = (string) ($options['host'] ?? '');

        if ($host === '' && config('broadcasting.connections.reverb.key')) {
            $host = (string) ($request?->getHost() ?? '');
        }

        if ($host === '') {
            return [];
        }

        $port   = (int) ($options['port'] ?? 443);
        $secure = ($options['scheme'] ?? 'https') === 'https';
        $suffix = in_array($port, [80, 443], true) ? '' : ':' . $port;

        return [($secure ? 'wss' : 'ws') . '://' . $host . $suffix, ($secure ? 'https' : 'http') . '://' . $host . $suffix];
    }
}
