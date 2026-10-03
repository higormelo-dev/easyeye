<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Domains\AI\Services\AiMedicalContextBuilder;
use App\DTOs\AI\AiRequestData;

/**
 * Resumo auditável (LGPD) do que vai para o provedor numa execução de IA —
 * sem o conteúdo: impressão digital das instruções (system prompt) enviadas,
 * categorias de dados, chaves do contexto e nº de imagens. Gravado em
 * ai_runs.dispatch_audit no momento do envio.
 */
final class AiDispatchAudit
{
    /**
     * @return array{system_prompt_sha256: ?string, data_categories: list<string>, context_keys: list<string>, images: int, dispatched_at: string}
     */
    public static function summarize(AiRequestData $request): array
    {
        // Chaves com "_" são marcadores internos (ex.: _built_by), não dado.
        $keys = array_values(array_filter(
            array_map('strval', array_keys($request->context)),
            static fn (string $key) => ! str_starts_with($key, '_'),
        ));
        sort($keys);

        $categories = array_map(static fn (string $key) => self::category($key), $keys);

        if (trim($request->userPrompt) !== '') {
            $categories[] = 'request_text';
        }

        if ($request->attachments !== []) {
            $categories[] = 'images';
        }

        $categories = array_values(array_unique($categories));
        sort($categories);

        return [
            'system_prompt_sha256' => filled($request->systemPrompt) ? hash('sha256', (string) $request->systemPrompt) : null,
            'data_categories'      => $categories,
            'context_keys'         => $keys,
            'images'               => count($request->attachments),
            'dispatched_at'        => now()->toIso8601String(),
        ];
    }

    private static function category(string $key): string
    {
        return match (true) {
            in_array($key, AiMedicalContextBuilder::DEMOGRAPHIC_KEYS, true) => 'demographics',
            in_array($key, AiMedicalContextBuilder::CLINICAL_KEYS, true)    => 'clinical_record',
            $key === 'selected_exams'                                       => 'exam_metadata',
            $key === 'conversation_history'                                 => 'conversation_history',
            $key === 'medicamento'                                          => 'medicine_catalog',
            default                                                         => 'other_context',
        };
    }
}
