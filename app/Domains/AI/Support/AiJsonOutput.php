<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Extrai o objeto JSON da resposta de um modelo. Mesmo com modo JSON, alguns
 * provedores devolvem cerca de markdown (```json) ou uma frase antes/depois —
 * mesma tolerância do parse feito no front (AiAssistantPanel::parseStructured).
 */
final class AiJsonOutput
{
    /** @return array<string, mixed>|null objeto decodificado; null se não houver JSON de objeto válido */
    public static function decode(string $content): ?array
    {
        $content = trim($content);

        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded) && ! array_is_list($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end   = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
