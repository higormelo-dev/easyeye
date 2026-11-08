<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use App\Enums\AI\AiProvider;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\{RequestException, Response};
use RuntimeException;
use Throwable;

/**
 * Erro de um provedor de IA com o motivo já classificado — para quem chama
 * decidir SEM ler o texto: limite por minuto espera o que o provedor pediu;
 * falta de crédito e chave recusada não se repetem (precisam de uma pessoa).
 *
 * A mensagem continua a de antes ("OpenAI request failed [429/rate_limit_exceeded]: …"),
 * já sanitizada pelo provedor: logs, painel de uso e testes não mudam.
 *
 * Classificação pela documentação dos provedores (corpos de erro e headers);
 * os testes usam respostas montadas a partir dela, não chamadas reais.
 */
final class AiProviderRequestException extends RuntimeException
{
    /** Limite por minuto (pedidos/tokens): passa sozinho — esperar o que o provedor pediu. */
    public const CATEGORY_RATE_LIMIT = 'rate_limit';

    /** Sem crédito/cota na conta: não volta sozinho — sem nova tentativa, alerta ao time. */
    public const CATEGORY_QUOTA = 'quota';

    /** Chave recusada (inválida, revogada, sem permissão): não volta sozinho. */
    public const CATEGORY_AUTH = 'auth';

    /** 5xx, 529, sobrecarga: passageiro. */
    public const CATEGORY_OVERLOADED = 'overloaded';

    /** Pedido maior que o limite de tokens do modelo: esperar não adianta. */
    public const CATEGORY_TOO_LARGE = 'request_too_large';

    /** Demais 4xx (modelo inválido, parâmetro, conteúdo recusado). */
    public const CATEGORY_REQUEST = 'request';

    /** Códigos (error.code / error.type) de conta sem crédito ou sem cobrança ativa. */
    private const QUOTA_CODES = ['insufficient_quota', 'billing_hard_limit_reached', 'billing_not_active', 'insufficient_balance', 'quota_exceeded'];

    private const AUTH_CODES = ['invalid_api_key', 'authentication_error', 'permission_error', 'permission_denied', 'unauthenticated'];

    private const RATE_LIMIT_CODES = ['rate_limit_exceeded', 'rate_limit_error', 'resource_exhausted'];

    /**
     * @param int    $httpStatus        0 = não houve requisição (circuito aberto)
     * @param ?float $retryAfterSeconds espera pedida pelo provedor (headers ou texto), quando informada
     * @param bool   $accountWide       quota/auth vieram de sinal ESTRUTURADO do provedor (código do erro, 401):
     *                                  vale para a conta toda. False = inferido do texto da mensagem (ou 403 sem
     *                                  código), que pode ecoar conteúdo do pedido — não derruba as outras clínicas.
     */
    public function __construct(
        string $message,
        public readonly AiProvider $provider,
        public readonly int $httpStatus,
        public readonly ?string $errorCode,
        public readonly string $category,
        public readonly ?float $retryAfterSeconds = null,
        public readonly bool $accountWide = true,
    ) {
        parent::__construct($message);
    }

    /** $message: o texto já sanitizado que o provedor sempre lançou. */
    public static function fromResponse(AiProvider $provider, Response $response, string $message): self
    {
        $json   = is_array($response->json()) ? $response->json() : [];
        $status = $response->status();
        $code   = self::scalar(data_get($json, 'error.code'));
        $type   = self::scalar(data_get($json, 'error.type') ?? data_get($json, 'error.status') ?? data_get($json, 'type'));
        $raw    = (string) (self::scalar(data_get($json, 'error.message') ?? data_get($json, 'message')) ?? '');

        [$category, $accountWide] = self::categorize($status, [$code, $type], $raw, $json);
        $retryAfter               = in_array($category, [self::CATEGORY_RATE_LIMIT, self::CATEGORY_OVERLOADED], true)
            ? self::retryAfter($response, $raw, $json)
            : null;

        return new self($message, $provider, $status, $code ?? $type, $category, $retryAfter, $accountWide);
    }

    /**
     * Provedor pulado porque o circuito está aberto: o motivo é o da última
     * falha registrada (cota, chave, limite...) e a espera vai até reabrir.
     */
    public static function circuitOpen(AiProvider $provider, ?string $triggerType, ?CarbonInterface $openUntil): self
    {
        $category = match ($triggerType) {
            self::CATEGORY_QUOTA      => self::CATEGORY_QUOTA,
            self::CATEGORY_AUTH       => self::CATEGORY_AUTH,
            self::CATEGORY_RATE_LIMIT => self::CATEGORY_RATE_LIMIT,
            default                   => self::CATEGORY_OVERLOADED,
        };
        $seconds = $openUntil !== null ? max(1.0, (float) now()->diffInSeconds($openUntil, false)) : null;

        return new self(
            "{$provider->label()} indisponível: circuito aberto após falhas seguidas [" . ($triggerType ?? 'unknown') . '].',
            $provider,
            0,
            'circuit_open',
            $category,
            $seconds,
        );
    }

    /**
     * Nova tentativa imediata do cliente HTTP (Http::retry): só conexão caída
     * e 5xx/408. 429 (há espera pedida) e os demais 4xx sobem na hora, já
     * classificados — repetir cota esgotada ou chave recusada só atrasa a falha.
     */
    public static function shouldRetryHttp(Throwable $e): bool
    {
        return ! $e instanceof RequestException || $e->response->serverError() || $e->response->status() === 408;
    }

    /** Vale tentar de novo (depois da espera pedida, quando houver). */
    public function isRetryable(): bool
    {
        return in_array($this->category, [self::CATEGORY_RATE_LIMIT, self::CATEGORY_OVERLOADED], true);
    }

    /** Precisa de uma pessoa (crédito, chave): repetir só atrasa a falha. */
    public function needsHumanAction(): bool
    {
        return in_array($this->category, [self::CATEGORY_QUOTA, self::CATEGORY_AUTH], true);
    }

    /**
     * Categoria e se ela vale para a conta toda. Falta de crédito e chave
     * recusada só contam como da conta com sinal estruturado (código do erro,
     * cota diária do Gemini, 401); pelo texto da mensagem (Anthropic sem
     * saldo responde só "credit balance is too low", Gemini "API key not
     * valid") ou por um 403 sem código, a categoria é a mesma, mas não é
     * account-wide: um texto do provedor que ecoe o pedido de uma clínica não
     * pode suspender a IA de todas.
     *
     * @param list<?string>        $codes error.code e error.type/status
     * @param array<string, mixed> $json
     *
     * @return array{0: string, 1: bool}
     */
    private static function categorize(int $status, array $codes, string $raw, array $json): array
    {
        $codes = array_map('strtolower', array_filter($codes, fn (?string $c) => $c !== null && $c !== ''));
        $text  = strtolower($raw);
        $has   = fn (array $list): bool => array_intersect($codes, $list) !== [];

        return match (true) {
            $has(self::QUOTA_CODES) || ($status === 429 && self::geminiDailyQuota($json))                           => [self::CATEGORY_QUOTA, true],
            str_contains($text, 'credit balance is too low')                                                        => [self::CATEGORY_QUOTA, false],
            $status === 401 || $has(self::AUTH_CODES)                                                               => [self::CATEGORY_AUTH, true],
            $status === 403 || str_contains($text, 'api key not valid') || str_contains($text, 'incorrect api key') => [self::CATEGORY_AUTH, false],
            // OpenAI: pedido maior que o TPM do modelo vem como 429 rate_limit_exceeded.
            $status === 413 || str_contains($text, 'request too large')                     => [self::CATEGORY_TOO_LARGE, true],
            $status === 429 || $has(self::RATE_LIMIT_CODES)                                 => [self::CATEGORY_RATE_LIMIT, true],
            $status >= 500 || $status === 408 || in_array('overloaded_error', $codes, true) => [self::CATEGORY_OVERLOADED, true],
            default                                                                         => [self::CATEGORY_REQUEST, true],
        };
    }

    /**
     * Espera pedida pelo provedor, em segundos: retry-after-ms, retry-after
     * (segundos ou data), RetryInfo do Gemini, "try again in 6s" no texto e,
     * por último, o maior x-ratelimit-reset-*.
     *
     * @param array<string, mixed> $json
     */
    private static function retryAfter(Response $response, string $raw, array $json): ?float
    {
        $ms = $response->header('retry-after-ms');

        if (is_numeric($ms) && (float) $ms > 0) {
            return (float) $ms / 1000;
        }

        $header = trim($response->header('retry-after'));

        if (is_numeric($header) && (float) $header > 0) {
            return (float) $header;
        }

        if ($header !== '' && ($at = strtotime($header)) !== false && $at > time()) {
            return (float) ($at - time());
        }

        foreach ((array) data_get($json, 'error.details', []) as $detail) {
            if (is_array($detail) && str_ends_with((string) ($detail['@type'] ?? ''), 'RetryInfo')) {
                $delay = self::duration((string) ($detail['retryDelay'] ?? ''));

                if ($delay !== null) {
                    return $delay;
                }
            }
        }

        if (preg_match('/(?:try again|retry) in ((?:\d+(?:\.\d+)?\s*(?:ms|h|m|s)\s*)+)/i', $raw, $m)) {
            $delay = self::duration($m[1]);

            if ($delay !== null) {
                return $delay;
            }
        }

        $resets = array_filter(array_map(
            fn (string $name) => self::duration($response->header($name)),
            ['x-ratelimit-reset-requests', 'x-ratelimit-reset-tokens'],
        ), fn (?float $seconds) => $seconds !== null && $seconds > 0);

        // O maior: não se sabe qual dos dois limites estourou, e voltar cedo só gera outro 429.
        return $resets === [] ? null : max($resets);
    }

    /** "6m0s", "1.5s", "20ms", "34.57s" → segundos. */
    private static function duration(string $value): ?float
    {
        if (! preg_match_all('/(\d+(?:\.\d+)?)\s*(ms|h|m|s)/i', $value, $parts, PREG_SET_ORDER)) {
            return null;
        }

        $seconds = 0.0;

        foreach ($parts as [, $amount, $unit]) {
            $seconds += (float) $amount * match (strtolower($unit)) {
                'ms'    => 0.001,
                'm'     => 60,
                'h'     => 3600,
                default => 1,
            };
        }

        return $seconds;
    }

    /** @param array<string, mixed> $json */
    private static function geminiDailyQuota(array $json): bool
    {
        foreach ((array) data_get($json, 'error.details', []) as $detail) {
            foreach ((array) (is_array($detail) ? ($detail['violations'] ?? []) : []) as $violation) {
                if (is_array($violation) && str_contains((string) ($violation['quotaId'] ?? ''), 'PerDay')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
