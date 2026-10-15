<?php

declare(strict_types=1);

namespace App\Exceptions\Surgery;

use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use RuntimeException;

/**
 * Regra do módulo cirúrgico recusou a operação (estado do caso mudou, conflito
 * de sala, checklist aberto, lote vencido...). A mensagem já vem traduzida e
 * NADA foi gravado (a transação do service desfaz).
 *
 * 409 = o estado atual não permite (outra requisição mexeu antes);
 * 422 = o dado informado não serve. `context` leva dados estruturados para a
 * tela (ex.: o conflito de sala devolve quem ocupa a janela).
 */
class SurgeryRuleException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly int $status = 409,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, scalar|null> $replace */
    public static function state(string $key, array $replace = []): self
    {
        return new self((string) __($key, $replace), 409);
    }

    /**
     * @param array<string, scalar|null> $replace
     * @param array<string, mixed>       $context
     */
    public static function invalid(string $key, array $replace = [], array $context = []): self
    {
        return new self((string) __($key, $replace), 422, $context);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'context' => $this->context], $this->status);
        }

        return back()->with('error', $this->getMessage());
    }
}
