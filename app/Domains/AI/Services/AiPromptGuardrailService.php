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
     * @var list<string>
     */
    private const PROMPT_INJECTION_PATTERNS = [
        '/\bignore\s+(previous|above|all)\s+instructions\b/iu',
        '/\bdisregard\s+(all|any)\s+(prior|previous)\b/iu',
        '/\b(reveal|show|print)\s+(the\s+)?(system\s+)?prompt\b/iu',
        '/\b(jailbreak|bypass|override)\b/iu',
        '/^\s*(system|developer|assistant)\s*:/ium',
        '/\bignore\s+(as\s+)?instru[cç][oõ]es\s+(anteriores|acima|do sistema)\b/iu',
        '/\bdesconsidere\s+(todas\s+)?(as\s+)?instru[cç][oõ]es\b/iu',
        '/\b(mostre|revele|exiba|imprima)\s+(o\s+)?prompt\s+(do\s+)?sistema\b/iu',
        '/\b(voc[eê]\s+agora\s+[ée]|finja\s+que\s+voc[eê]\s+[ée])\b/iu',
    ];

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{payload:array<string, mixed>, guardrails:array<string, mixed>}
     */
    public function sanitizePayload(array $payload): array
    {
        $searchableText = $this->flattenStrings([
            $payload['user_prompt'] ?? '',
            $payload['system_prompt'] ?? '',
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
