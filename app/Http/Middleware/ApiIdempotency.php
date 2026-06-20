<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotência opt-in para operações de escrita da API de integradores.
 *
 * Motivação: o cliente desktop (Rust) que re-tenta um upload de exame após um
 * timeout de rede geraria um exame duplicado E consumiria 2x a cota mensal. Com
 * o header `Idempotency-Key`, a primeira resposta de SUCESSO é memorizada e
 * re-tentativas com a mesma chave recebem a resposta original (replay), sem
 * re-executar o controller.
 *
 * Contrato:
 * - Só age em métodos NÃO-seguros (POST/PUT/PATCH/DELETE) E quando o header
 *   `Idempotency-Key` está presente. Sem o header, o comportamento é o de antes
 *   (totalmente compatível com clientes que ainda não o enviam).
 * - Chave de cache isolada por integrador + método + path + Idempotency-Key.
 * - Lock curto (Redis) evita processamento concorrente da mesma chave; uma
 *   segunda requisição simultânea recebe 409 enquanto a primeira não terminou.
 * - Apenas respostas 2xx são memorizadas (TTL 24h). Erros podem ser re-tentados
 *   com a mesma chave.
 *
 * Pré-requisito: rodar após auth_with_integrator (usa o integrador na chave).
 */
class ApiIdempotency
{
    /** Janela de retenção da resposta memorizada. */
    private const TTL_HOURS = 24;

    /** Validade do lock anti-concorrência, em segundos. */
    private const LOCK_SECONDS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        // Opt-in: sem header ou método seguro → segue sem idempotência.
        if (! $key || $request->isMethodSafe()) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_-]{8,128}$/', $key)) {
            return response()->json([
                'message' => __('auth.idempotency_key_invalid'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $integratorId = $request->attributes->get('integrator')?->id ?? 'anon';
        $cacheKey     = 'idem:' . hash(
            'sha256',
            $integratorId . '|' . $request->method() . '|' . $request->path() . '|' . $key,
        );

        $store = Cache::store();

        // Replay rápido: resposta já computada para esta chave.
        if (is_array($cached = $store->get($cacheKey))) {
            return $this->replay($cached);
        }

        // Lock para evitar que uma re-tentativa concorrente execute em paralelo.
        $lock = Cache::lock($cacheKey . ':lock', self::LOCK_SECONDS);

        if (! $lock->get()) {
            return response()->json([
                'message' => __('auth.idempotency_in_progress'),
            ], Response::HTTP_CONFLICT);
        }

        try {
            // Re-checagem após o lock: a primeira requisição pode ter terminado
            // entre o get() acima e a aquisição do lock.
            if (is_array($cached = $store->get($cacheKey))) {
                return $this->replay($cached);
            }

            $response = $next($request);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $store->put($cacheKey, [
                    'status'  => $response->getStatusCode(),
                    'content' => $response->getContent(),
                    'type'    => $response->headers->get('Content-Type', 'application/json'),
                ], now()->addHours(self::TTL_HOURS));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * Reconstrói a resposta memorizada, marcando-a como replay.
     *
     * @param array{status: int, content: string, type: string} $cached
     */
    private function replay(array $cached): Response
    {
        return response($cached['content'], $cached['status'])
            ->header('Content-Type', $cached['type'])
            ->header('Idempotency-Replayed', 'true');
    }
}
