<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use Illuminate\Validation\ValidationException;

final class AiPromptGuardrailService
{
    /**
     * @var array<string, string>
     */
    private const PII_PATTERNS = [
        'email' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
        'cpf'   => '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/u',
        'cnpj'  => '/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/u',
        'phone' => '/(?<!\d)(?:(?:\+?55\s*)?\(?\d{2}\)?\s*)\d{4,5}[-.\s]?\d{4}(?!\d)|(?<!\d)9\d{4}[-.\s]?\d{4}(?!\d)/u',
        'cep'   => '/\b\d{5}-?\d{3}\b/u',
    ];

    /**
     * Pré-filtro GROSSEIRO (denylist) — NÃO é a defesa primária contra
     * prompt injection. Um denylist de regex é sempre contornável por
     * paráfrase, outro idioma ou codificação (base64, unicode, etc.). A
     * defesa estrutural real está em dois outros pontos, que valem mesmo
     * quando um payload passa por aqui sem casar nenhum padrão:
     *   - AiPayloadEnricher::withSecurityPreamble() — system prompt sempre
     *     definido pelo servidor (nunca aceita o do cliente) e reforçado com
     *     hierarquia explícita de instrução.
     *   - App\Domains\AI\Support\PromptComposer — contexto do prontuário vai
     *     marcado como dado (tag <clinic_data>), nunca como instrução.
     * Este filtro serve pra pegar as tentativas mais óbvias/preguiçosas
     * antes mesmo de gastar uma chamada ao provedor — camada extra, barata,
     * não a garantia.
     *
     * @var list<string>
     */
    private const PROMPT_INJECTION_PATTERNS = [
        '/\bignore\s+(previous|above|all)\s+instructions\b/iu',
        '/\bdisregard\s+(all|any)\s+(prior|previous)\b/iu',
        '/\b(reveal|show|print)\s+(the\s+)?(system\s+)?prompt\b/iu',
        '/\b(jailbreak|bypass\s+(all|your|the)|override\s+(the|your|all)\s+(instructions|rules|safety))\b/iu',
        '/^\s*(system|developer|assistant)\s*:/ium',
        '/\bnew\s+instructions\s*:/iu',
        '/\b(from\s+now\s+on|you\s+are\s+now)\b.{0,30}\b(you\s+are|act\s+as|no\s+longer)\b/iu',
        '/\b(act|pretend|roleplay)\s+as\s+(if\s+you\s+(are|were)|a[n]?\s)/iu',
        '/\bdeveloper\s+mode\b/iu',
        '/\bignore\s+(as\s+)?instru[cç][oõ]es\s+(anteriores|acima|do sistema)\b/iu',
        '/\bdesconsidere\s+(todas\s+)?(as\s+)?instru[cç][oõ]es\b/iu',
        '/\b(mostre|revele|exiba|imprima)\s+(o\s+)?prompt\s+(do\s+)?sistema\b/iu',
        '/\b(voc[eê]\s+agora\s+[ée]|finja\s+que\s+voc[eê]\s+[ée])\b/iu',
        '/\bnovas?\s+instru[cç][oõ]es\s*:/iu',
        '/\b(a\s+partir\s+de\s+agora|de\s+agora\s+em\s+diante)\b.{0,30}\bvoc[eê]\s+(é|[eé]s|age|deve)\b/iu',
        '/\b(aja|comporte-se|finja)\s+como\s+(se\s+)?(voc[eê]\s+)?(fosse|um|uma)\b/iu',
        '/\bmodo\s+desenvolvedor\b/iu',
    ];

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{payload:array<string, mixed>, guardrails:array<string, mixed>}
     */
    public function sanitizePayload(array $payload): array
    {
        // system_prompt NÃO entra no scan de injection: desde
        // AiPayloadEnricher::withSecurityPreamble() ele é SEMPRE definido
        // pelo servidor (nunca pelo cliente — ver comentário em
        // applyWorkflowDefaults), então não é superfície de ataque. Escaneá-lo
        // também é contraproducente: o próprio preâmbulo de segurança PRECISA
        // citar termos como "jailbreak"/"ignore instructions"/"modo
        // desenvolvedor" para instruir o modelo a resistir a eles — texto
        // nosso, sobre o ataque, ativaria o próprio filtro (falso positivo
        // que bloquearia 100% das chamadas).
        $searchableText = $this->flattenStrings([
            $payload['user_prompt'] ?? '',
            $payload['context'] ?? [],
            $payload['attachments'] ?? [],
        ]);

        if ($this->containsPromptInjection($searchableText)) {
            throw ValidationException::withMessages([
                'user_prompt' => __('ai.prompt_guardrail_blocked'),
            ]);
        }

        $piiTypes  = [];
        $sanitized = $payload;

        foreach (['user_prompt', 'system_prompt'] as $field) {
            if (isset($sanitized[$field]) && is_string($sanitized[$field])) {
                $sanitized[$field] = $this->redactPii($sanitized[$field], $piiTypes);
            }
        }

        if (isset($sanitized['context'])) {
            $sanitized['context'] = $this->sanitizeValue($sanitized['context'], $piiTypes);
        }

        if (isset($sanitized['attachments'])) {
            $sanitized['attachments'] = $this->sanitizeValue($sanitized['attachments'], $piiTypes);
        }

        $piiTypes = array_values(array_unique($piiTypes));

        return [
            'payload'    => $sanitized,
            'guardrails' => [
                'prompt_injection_checked' => true,
                'pii_redacted'             => $piiTypes !== [],
                'pii_types'                => $piiTypes,
                'sanitized_at'             => now()->toIso8601String(),
            ],
        ];
    }

    public function redactText(string $text): string
    {
        $piiTypes = [];

        return $this->redactPii($text, $piiTypes);
    }

    private function containsPromptInjection(string $text): bool
    {
        foreach (self::PROMPT_INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $piiTypes
     */
    private function redactPii(string $text, array &$piiTypes): string
    {
        $text = preg_replace_callback(
            '/\b(?:\d[ -]*?){13,19}\b/u',
            function (array $matches) use (&$piiTypes): string {
                $digits = preg_replace('/\D/u', '', (string) $matches[0]) ?? '';

                if (! $this->passesLuhn($digits)) {
                    return (string) $matches[0];
                }

                $piiTypes[] = 'credit_card';

                return '<CREDIT_CARD_REDACTED>';
            },
            $text,
        ) ?? $text;

        foreach (self::PII_PATTERNS as $type => $pattern) {
            $text = preg_replace_callback(
                $pattern,
                function () use (&$piiTypes, $type): string {
                    $piiTypes[] = $type;

                    return '<' . strtoupper($type) . '_REDACTED>';
                },
                $text,
            ) ?? $text;
        }

        return $text;
    }

    /**
     * @param array<int, string> $piiTypes
     */
    private function sanitizeValue(mixed $value, array &$piiTypes): mixed
    {
        if (is_string($value)) {
            return $this->redactPii($value, $piiTypes);
        }

        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $child) {
            $sanitized[$key] = $this->sanitizeValue($child, $piiTypes);
        }

        return $sanitized;
    }

    private function flattenStrings(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return '';
        }

        $parts = [];

        foreach ($value as $child) {
            $parts[] = $this->flattenStrings($child);
        }

        return implode("\n", array_filter($parts));
    }

    private function passesLuhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum       = 0;
        $alternate = false;

        for ($index = $length - 1; $index >= 0; $index--) {
            $number = (int) $digits[$index];

            if ($alternate) {
                $number *= 2;

                if ($number > 9) {
                    $number -= 9;
                }
            }

            $sum += $number;
            $alternate = ! $alternate;
        }

        return $sum % 10 === 0;
    }
}
