<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\DTOs\AI\AiRequestData;

/**
 * Monta o texto enviado ao provedor a partir de user_prompt + contexto —
 * ponto ÚNICO usado pelos 3 providers (antes triplicado, um
 * composePromptWithContext() por provider, sujeito a divergir).
 *
 * Defesa contra prompt injection INDIRETA (OWASP LLM01): `context` não é
 * texto digitado pelo médico — é dado do banco (queixa principal, HDA,
 * histórico) via AiMedicalContextBuilder, e PODE conter texto de terceiros
 * (import de exame externo, integração, ou um campo malicioso preenchido
 * por qualquer usuário com acesso de escrita ao prontuário). O
 * AiPromptGuardrailService já bloqueia padrões óbvios (denylist — sempre
 * contornável por paráfrase), mas a defesa estrutural é aqui: o contexto
 * vai marcado como DADO, nunca como instrução, dentro de uma tag que o
 * system prompt (ver AiPayloadEnricher::withSecurityPreamble) instrui o
 * modelo a NUNCA tratar como comando — mesmo que o conteúdo pareça uma
 * instrução ("ignore isso", "aja como", etc.).
 */
final class PromptComposer
{
    private const CONTEXT_TAG = 'clinic_data';

    public static function composeUserContent(AiRequestData $request): string
    {
        $prompt = $request->userPrompt;

        if ($request->expectsJson) {
            $prompt .= "\n\nResponda em JSON válido.";
        }

        if ($request->context === []) {
            return $prompt;
        }

        $contextJson = json_encode($request->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contextJson = $contextJson !== false ? $contextJson : '{}';

        $tag = self::CONTEXT_TAG;

        return trim(
            $prompt
            . "\n\n<{$tag}>\n"
            . $contextJson
            . "\n</{$tag}>",
        );
    }
}
