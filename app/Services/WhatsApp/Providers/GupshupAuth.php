<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Providers;

use App\Services\WhatsApp\WhatsAppAlerts;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\{ConnectionException, PendingRequest, Response};
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Cache, Crypt, Http, Log};
use Throwable;

/**
 * Token de acesso de cada app Gupshup (Partner API), com cache.
 *
 * Dois modos (config whatsapp.gupshup.auth_mode — vazio escolhe sozinho):
 *
 *  - universal (recomendado pela Gupshup; o token de app antigo deixa de
 *    funcionar em 01/04/2027): GUPSHUP_UNIVERSAL_TOKEN (gerado no portal,
 *    vale até 60 dias) gera um Universal App Token (UAT) por app, de curta
 *    duração, enviado como "Bearer". Máximo de 3 UATs ATIVOS por app (409
 *    depois disso), nome único entre os ativos, expiry entre agora+1h e
 *    agora+24h.
 *      POST /partner/app/{appId}/token           (gerar: name, expiry)
 *      https://partner-docs.gupshup.io/reference/mintuniversalapptoken
 *      GET  /partner/app/{appId}/tokens?status=ACTIVE   (listar: id, name, status)
 *      https://partner-docs.gupshup.io/reference/listuniversalapptokens
 *      POST /partner/app/{appId}/token/revoke    (revogar: id)
 *      https://partner-docs.gupshup.io/reference/revokeuniversalapptoken
 *      https://partner-docs.gupshup.io/docs/partner-authentication-guide-universal-tokens-ut-overview
 *
 *  - partner_token: e-mail + client secret → token de parceiro (24h) →
 *    token do app (longo, a Gupshup devolve sempre o mesmo), enviado puro
 *    no header Authorization (sem "Bearer").
 *      POST /partner/account/login  (form: email, secret)
 *      https://partner-docs.gupshup.io/reference/post_partner-account-login
 *      GET  /partner/app/{appId}/token  (Authorization: {token de parceiro})
 *      https://partner-docs.gupshup.io/reference/get_partner-app-appid-token
 *
 * Para não estourar o limite de UATs ativos com vários workers:
 *  - um worker só gera o token de um app por vez (Cache::lock); quem espera
 *    relê o cache e usa o token que o outro gerou;
 *  - o token fica no cache (cifrado, compartilhado — Redis em produção) com
 *    id e validade, e é reaproveitado até meia hora antes de vencer;
 *  - 401 numa chamada → renew(): relê o cache (outro worker pode já ter
 *    renovado) e só gera outro se o do cache for o que tomou 401 — revogando
 *    o anterior;
 *  - 409 (3 ativos) → revoga os UATs órfãos DESTE ambiente (prefixo do nome)
 *    e tenta uma vez; persistindo, erro transitório + alerta ao time.
 *
 * Tokens só em cache (cifrados), nunca em banco nem em log.
 */
class GupshupAuth
{
    public const MODE_UNIVERSAL = 'universal';

    public const MODE_PARTNER = 'partner_token';

    private const PARTNER_TOKEN_KEY = 'whatsapp:gupshup:partner-token';

    public function mode(): ?string
    {
        $mode = (string) config('whatsapp.gupshup.auth_mode', '');

        if ($mode === self::MODE_UNIVERSAL || $mode === self::MODE_PARTNER) {
            return $mode;
        }

        if (filled(config('whatsapp.gupshup.universal_token'))) {
            return self::MODE_UNIVERSAL;
        }

        if (filled(config('whatsapp.gupshup.partner_email')) && filled(config('whatsapp.gupshup.partner_secret'))) {
            return self::MODE_PARTNER;
        }

        return null;
    }

    /** Credenciais do parceiro presentes no .env para o modo escolhido. */
    public function configured(): bool
    {
        return match ($this->mode()) {
            self::MODE_UNIVERSAL => filled(config('whatsapp.gupshup.universal_token')),
            self::MODE_PARTNER   => filled(config('whatsapp.gupshup.partner_email')) && filled(config('whatsapp.gupshup.partner_secret')),
            default              => false,
        };
    }

    /**
     * Validade do token universal: GUPSHUP_UNIVERSAL_TOKEN_EXPIRES_AT, se
     * informada; senão o "exp" do próprio token (é um JWT — lido sem
     * validar a assinatura, só para avisar no manager). Null = desconhecida.
     */
    public function universalTokenExpiresAt(): ?Carbon
    {
        $configured = config('whatsapp.gupshup.universal_token_expires_at');

        if (filled($configured)) {
            try {
                return Carbon::parse((string) $configured)->endOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        $parts = explode('.', (string) config('whatsapp.gupshup.universal_token'));

        if (count($parts) !== 3) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $exp    = is_array($claims) ? ($claims['exp'] ?? null) : null;

        return is_numeric($exp) ? Carbon::createFromTimestamp((int) $exp, (string) config('app.timezone')) : null;
    }

    /**
     * Valor do header Authorization para as chamadas do app.
     *
     * @return array{ok: bool, authorization?: string, error?: string, error_code?: string, retryable?: bool}
     */
    public function authorization(string $appId): array
    {
        if (($missing = $this->missingCredentials()) !== null) {
            return $missing;
        }

        $cached = $this->cachedApp($appId);

        if ($cached !== null) {
            return $this->ok($cached['token']);
        }

        return $this->locked($appId, function () use ($appId): array {
            // Outro worker pode ter gerado enquanto este esperava o lock.
            $cached = $this->cachedApp($appId);

            return $cached !== null ? $this->ok($cached['token']) : $this->issue($appId);
        });
    }

    /**
     * A chamada com $staleAuthorization tomou 401. Se o cache já tem outro
     * token (outro worker renovou), devolve esse sem gerar nada; senão
     * descarta o token recusado (revogando o UAT) e gera um novo.
     *
     * @return array{ok: bool, authorization?: string, error?: string, error_code?: string, retryable?: bool}
     */
    public function renew(string $appId, string $staleAuthorization): array
    {
        if (($missing = $this->missingCredentials()) !== null) {
            return $missing;
        }

        return $this->locked($appId, function () use ($appId, $staleAuthorization): array {
            $cached = $this->cachedApp($appId);

            if ($cached !== null && ! hash_equals($this->header($cached['token']), $staleAuthorization)) {
                return $this->ok($cached['token']);
            }

            Cache::forget($this->appKey($appId));

            if ($this->mode() === self::MODE_UNIVERSAL) {
                if (filled($cached['id'] ?? null)) {
                    $this->revoke($appId, (string) $cached['id']);
                }
            } else {
                // Token de app recusado: o de parceiro também pode ter caído.
                Cache::forget(self::PARTNER_TOKEN_KEY);
            }

            return $this->issue($appId);
        });
    }

    /**
     * Validade do lock de geração do token (s). Cobre o pior caso do que roda
     * sob ele — listar UATs + revogar até 3 + gerar de novo, cada chamada
     * com o timeout HTTP — para o lock não vencer no meio e outro worker
     * gerar um UAT a mais. Piso de 120 s.
     */
    public static function lockSeconds(): int
    {
        return max(120, (int) config('whatsapp.gupshup.token_lock_seconds', 120));
    }

    /** Chave do lock de geração do token do app (um worker por vez). */
    public static function lockKey(string $appId): string
    {
        return 'whatsapp:gupshup:token-lock:' . sha1($appId);
    }

    /**
     * Prefixo do nome dos UATs gerados por ESTE ambiente — só esses são
     * revogados na limpeza do 409 (outro sistema/ambiente no mesmo app fica
     * intacto).
     */
    public static function tokenNamePrefix(): string
    {
        return 'easyeye-' . Str::slug((string) config('app.env', 'app')) . '-';
    }

    public function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('whatsapp.gupshup.base_url', 'https://partner.gupshup.io'), '/'))
            ->timeout((int) config('whatsapp.http.timeout_seconds', 15))
            ->connectTimeout((int) config('whatsapp.http.connect_timeout_seconds', 5))
            ->acceptJson();
    }

    /** @return array{ok: false, error: string, error_code: string, retryable: false}|null */
    private function missingCredentials(): ?array
    {
        if ($this->mode() === null || ! $this->configured()) {
            return ['ok' => false, 'error' => 'Credenciais do parceiro Gupshup ausentes no .env.', 'error_code' => 'missing_partner_credentials', 'retryable' => false];
        }

        return null;
    }

    /**
     * @param Closure(): array<string, mixed> $callback
     *
     * @return array<string, mixed>
     */
    private function locked(string $appId, Closure $callback): array
    {
        $wait = max(0, (int) config('whatsapp.gupshup.token_lock_wait_seconds', 10));

        try {
            return Cache::lock(self::lockKey($appId), self::lockSeconds())->block($wait, $callback);
        } catch (LockTimeoutException) {
            $cached = $this->cachedApp($appId);

            if ($cached !== null) {
                return $this->ok($cached['token']);
            }

            return ['ok' => false, 'error' => 'Outro processo está gerando o token do app na Gupshup — nova tentativa em instantes.', 'error_code' => 'token_busy', 'retryable' => true];
        }
    }

    /** @return array<string, mixed> */
    private function issue(string $appId): array
    {
        $result = $this->mode() === self::MODE_UNIVERSAL ? $this->mintAppToken($appId) : $this->fetchAppToken($appId);

        return $result['ok'] ? $this->ok($result['token']) : $result;
    }

    /** @return array{ok: true, authorization: string} */
    private function ok(string $token): array
    {
        return ['ok' => true, 'authorization' => $this->header($token)];
    }

    private function header(string $token): string
    {
        return $this->mode() === self::MODE_UNIVERSAL ? "Bearer {$token}" : $token;
    }

    /** @return array{ok: bool, token?: string, error?: string, error_code?: string, retryable?: bool} */
    private function mintAppToken(string $appId, bool $afterCleanup = false): array
    {
        // Piso de 2h: a Gupshup recusa expiry abaixo de agora+1h (o pedido
        // de exatamente 1h chega "abaixo" pela latência). Teto: 24h.
        $hours = max(2, min(24, (int) config('whatsapp.gupshup.app_token_hours', 6)));

        $response = $this->call(fn () => $this->client()
            ->withHeaders(['Authorization' => 'Bearer ' . config('whatsapp.gupshup.universal_token')])
            ->asForm()
            ->post('/partner/app/' . rawurlencode($appId) . '/token', [
                // Nome único entre os ativos do app; prefixo do ambiente.
                'name'   => self::tokenNamePrefix() . Str::lower(Str::random(12)),
                'expiry' => now()->addHours($hours)->getTimestampMs(),
            ]));

        if (! $response['ok']) {
            $status = $response['status'] ?? 0;

            if ($status === 409 && ! $afterCleanup && $this->revokeOrphans($appId) > 0) {
                return $this->mintAppToken($appId, true);
            }

            if ($status === 409) {
                WhatsAppAlerts::critical('uat_limit', ['app_id' => $appId]);

                return ['ok' => false, 'error' => 'Gupshup: limite de 3 tokens de app ativos atingido.', 'error_code' => 'uat_limit', 'retryable' => true];
            }

            if ($status === 401 || $status === 403) {
                WhatsAppAlerts::critical('universal_token_invalid', ['app_id' => $appId, 'http_status' => $status]);

                return ['ok' => false, 'error' => 'Gupshup recusou o token universal (vencido ou revogado).', 'error_code' => 'universal_token_invalid', 'retryable' => true];
            }

            return $response;
        }

        $token = (string) ($response['body']['token'] ?? '');

        if ($token === '') {
            return ['ok' => false, 'error' => 'Gupshup não devolveu o token do app.', 'error_code' => 'token_missing', 'retryable' => true];
        }

        // Reaproveitado até meia hora antes de vencer.
        $this->storeApp($appId, [
            'token'      => $token,
            'id'         => (string) ($response['body']['id'] ?? ''),
            'expires_at' => now()->addHours($hours)->timestamp,
        ], now()->addMinutes($hours * 60 - 30));

        return ['ok' => true, 'token' => $token];
    }

    /**
     * Revoga os UATs ativos deste ambiente (prefixo do nome) que não estão no
     * cache — órfãos de um cache perdido. Só roda sem token no cache e sob o
     * lock do app, então nenhum deles está em uso. Devolve quantos revogou.
     */
    private function revokeOrphans(string $appId): int
    {
        $list = $this->call(fn () => $this->client()
            ->withHeaders(['Authorization' => 'Bearer ' . config('whatsapp.gupshup.universal_token')])
            ->get('/partner/app/' . rawurlencode($appId) . '/tokens', ['status' => 'ACTIVE']));

        if (! $list['ok']) {
            return 0;
        }

        $revoked = 0;

        foreach ((array) ($list['body']['tokens'] ?? []) as $token) {
            $name = (string) ($token['name'] ?? '');

            if (($token['status'] ?? 'ACTIVE') === 'ACTIVE' && str_starts_with($name, self::tokenNamePrefix()) && filled($token['id'] ?? null)) {
                $revoked += $this->revoke($appId, (string) $token['id']) ? 1 : 0;
            }
        }

        Log::warning('[whatsapp:gupshup] limite de UATs: tokens órfãos revogados.', ['app_id' => $appId, 'revoked' => $revoked]);

        return $revoked;
    }

    private function revoke(string $appId, string $tokenId): bool
    {
        $result = $this->call(fn () => $this->client()
            ->withHeaders(['Authorization' => 'Bearer ' . config('whatsapp.gupshup.universal_token')])
            ->asForm()
            ->post('/partner/app/' . rawurlencode($appId) . '/token/revoke', ['id' => $tokenId]));

        // 410 = já venceu (nada a revogar).
        return $result['ok'] || ($result['status'] ?? 0) === 410;
    }

    /** @return array{ok: bool, token?: string, error?: string, error_code?: string, retryable?: bool} */
    private function fetchAppToken(string $appId, bool $retried = false): array
    {
        $partner = $this->partnerToken();

        if (! $partner['ok']) {
            return $partner;
        }

        $response = $this->call(fn () => $this->client()
            ->withHeaders(['Authorization' => $partner['token']])
            ->get('/partner/app/' . rawurlencode($appId) . '/token'));

        if (! $response['ok'] && ($response['status'] ?? 0) === 401 && ! $retried) {
            Cache::forget(self::PARTNER_TOKEN_KEY);

            return $this->fetchAppToken($appId, true);
        }

        if (! $response['ok']) {
            return $response;
        }

        $token = (string) data_get($response['body'], 'token.token', '');

        if ($token === '') {
            return ['ok' => false, 'error' => 'Gupshup não devolveu o token do app.', 'error_code' => 'token_missing', 'retryable' => true];
        }

        // O token do app não expira (expiresOn 0); renovado só após 401.
        $this->storeApp($appId, ['token' => $token, 'id' => null, 'expires_at' => null], now()->addDays(7));

        return ['ok' => true, 'token' => $token];
    }

    /** @return array{ok: bool, token?: string, error?: string, error_code?: string, retryable?: bool} */
    private function partnerToken(): array
    {
        $cached = $this->cached(self::PARTNER_TOKEN_KEY);

        if ($cached !== null) {
            return ['ok' => true, 'token' => $cached];
        }

        $secret = (string) config('whatsapp.gupshup.partner_secret');

        // A página do login mostra o campo "secret"; a de geração do client
        // secret e a especificação OpenAPI falam em "password" — vai o mesmo
        // valor nos dois para funcionar com qualquer leitura da Gupshup.
        // https://partner-docs.gupshup.io/docs/generate-secret-and-token
        $response = $this->call(fn () => $this->client()->asForm()->post('/partner/account/login', [
            'email'    => (string) config('whatsapp.gupshup.partner_email'),
            'secret'   => $secret,
            'password' => $secret,
        ]));

        if (! $response['ok']) {
            return $response;
        }

        $token = (string) ($response['body']['token'] ?? '');

        if ($token === '') {
            return ['ok' => false, 'error' => 'Gupshup não devolveu o token de parceiro.', 'error_code' => 'token_missing', 'retryable' => true];
        }

        // Vale 24h: renova com 1h de folga.
        $this->store(self::PARTNER_TOKEN_KEY, $token, now()->addHours(23));

        return ['ok' => true, 'token' => $token];
    }

    /**
     * @param callable(): Response $request
     *
     * @return array{ok: bool, body?: array<string, mixed>, status?: int, error?: string, error_code?: string, retryable?: bool}
     */
    private function call(callable $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            return ['ok' => false, 'error' => 'Falha de conexão com a Gupshup.', 'error_code' => 'connection', 'retryable' => true];
        }

        if ($response->successful()) {
            return ['ok' => true, 'body' => (array) $response->json(), 'status' => $response->status()];
        }

        $status = $response->status();

        return [
            'ok'         => false,
            'status'     => $status,
            'error'      => 'Gupshup (autenticação) HTTP ' . $status . ': ' . GupshupProvider::errorMessage($response),
            'error_code' => $status === 401 || $status === 403 ? 'auth_failed' : ($status === 429 ? 'rate_limited' : 'http_' . $status),
            'retryable'  => $status === 429 || $status >= 500,
        ];
    }

    private function appKey(string $appId): string
    {
        return 'whatsapp:gupshup:app-token:' . ($this->mode() ?? 'none') . ':' . sha1($appId);
    }

    /** @return array{token: string, id: ?string, expires_at: ?int}|null */
    private function cachedApp(string $appId): ?array
    {
        $raw = $this->cached($this->appKey($appId));

        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! is_string($data['token'] ?? null) || $data['token'] === '') {
            Cache::forget($this->appKey($appId));

            return null;
        }

        return ['token' => $data['token'], 'id' => $data['id'] ?? null, 'expires_at' => $data['expires_at'] ?? null];
    }

    /** @param array{token: string, id: ?string, expires_at: ?int} $data */
    private function storeApp(string $appId, array $data, DateTimeInterface $until): void
    {
        $this->store($this->appKey($appId), (string) json_encode($data), $until);
    }

    private function cached(string $key): ?string
    {
        $value = Cache::get($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            Cache::forget($key);

            return null;
        }
    }

    private function store(string $key, string $value, DateTimeInterface $until): void
    {
        Cache::put($key, Crypt::encryptString($value), $until);
    }
}
