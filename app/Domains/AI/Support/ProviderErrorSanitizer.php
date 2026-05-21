<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Normaliza mensagens de erro vindas dos provedores LLM antes que elas
 * subam para exception → `ai_runs.error_message` (visível ao médico)
 * ou para `Log::error()`.
 *
 * Objetivos:
 * - LGPD: provedores às vezes ecoam parte do payload em mensagens de erro.
 *   Se o payload contiver PHI (nome de paciente, achados clínicos),
 *   estaríamos persistindo e mostrando essa informação em outro lugar.
 * - Segurança: limitar tamanho para evitar payloads gigantes em logs.
 * - UX: remover quebras de linha e caracteres de controle para a mensagem
 *   caber em UI/notificação.
 *
 * NÃO é uma garantia anti-PHI completa — é um redutor de superfície.
 * O contexto clínico já é controlado pelo AiMedicalContextBuilder (Fase 6),
 * mas adicionamos esta camada para defesa em profundidade.
 */
final class ProviderErrorSanitizer
{
    private const MAX_LENGTH = 500;

    public static function sanitize(?string $rawMessage, string $fallback = 'Falha desconhecida na integração com provedor de IA.'): string
    {
        if ($rawMessage === null || trim($rawMessage) === '') {
            return $fallback;
        }

        // Remove caracteres de controle exceto espaço.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $rawMessage) ?? '';

        // Substitui quebras de linha por espaço único.
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';

        // Redige identificadores diretos e trechos de payload para reduzir risco de
        // vazamento de PHI em mensagens que o provedor ecoa de volta.
        $clean = self::redactSensitiveData($clean);

        $clean = trim($clean);

        if ($clean === '') {
            return $fallback;
        }

        if (mb_strlen($clean) > self::MAX_LENGTH) {
            $clean = mb_substr($clean, 0, self::MAX_LENGTH - 3) . '...';
        }

        return $clean;
    }

    private static function redactSensitiveData(string $message): string
    {
        $redacted = $message;

        /** @var array<string, string> $patternMap */
        $patternMap = [
            // CPF: 123.456.789-00 ou 12345678900
            '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/u' => '[REDACTED:CPF]',
            // CNPJ: 12.345.678/0001-90 ou 12345678000190
            '/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/u' => '[REDACTED:CNPJ]',
            // CNS com 15 dígitos
            '/\b\d{15}\b/u' => '[REDACTED:CNS]',
            // E-mail
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu' => '[REDACTED:EMAIL]',
            // Telefone BR (com DDD, opcional +55)
            '/\b(?:\+?55[\s.\-]?)?(?:\(?\d{2}\)?[\s.\-]?)(?:9?\d{4})[\s.\-]?\d{4}\b/u' => '[REDACTED:PHONE]',
        ];

        foreach ($patternMap as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $redacted) ?? $redacted;
        }

        // Alguns provedores retornam "content snippet: "<trecho do prompt>".
        $redacted = preg_replace(
            '/((?:content(?:\s+snippet)?|prompt|input|mensagem|message|snippet|trecho)\s*[:=]\s*)"(?:[^"\\\\]|\\\\.)*"/iu',
            '$1"[REDACTED:PAYLOAD]"',
            $redacted
        ) ?? $redacted;

        $redacted = preg_replace(
            "/((?:content(?:\\s+snippet)?|prompt|input|mensagem|message|snippet|trecho)\\s*[:=]\\s*)'(?:[^'\\\\]|\\\\.)*'/iu",
            "$1'[REDACTED:PAYLOAD]'",
            $redacted
        ) ?? $redacted;

        return $redacted;
    }
}
