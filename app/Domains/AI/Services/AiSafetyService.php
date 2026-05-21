<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\AI\AiRiskLevel;

/**
 * Inspeciona o conteúdo gerado por IA antes de virar rascunho para o médico.
 *
 * Política atual (definida com o produto): "detectar e anotar". Não bloqueia
 * execução — apenas adiciona notas de segurança quando padrões de risco são
 * identificados. O médico continua sendo quem decide aprovar/editar/rejeitar,
 * em conformidade com a Resolução CFM 2.454/2026 (supervisão humana obrigatória).
 *
 * Heurísticas são propositalmente conservadoras: preferimos falso positivo
 * (médico recebe nota desnecessária) a falso negativo (médico aprova sem aviso).
 * Mais regras podem ser adicionadas via configuração ou subclasse.
 */
final class AiSafetyService
{
    /**
     * Termos taxativos que sugerem afirmação de diagnóstico não-condicionalizado.
     * O conteúdo correto seria "sugere", "compatível com", "achados sugestivos de".
     */
    private const DIAGNOSTIC_ASSERTIVENESS_PATTERNS = [
        '/\b(o paciente|paciente)\s+(tem|apresenta|sofre de)\b/iu',
        '/\b(definitivamente|certamente|sem d[úu]vida|com certeza)\b/iu',
        '/\bdiagn[óo]stico\s+(definitivo|conclusivo|final)\b/iu',
    ];

    /**
     * Prescrições com dose/posologia explícita sem instrução de revisão médica.
     * IA deve sugerir conduta, não prescrever direto ao paciente.
     */
    private const DIRECT_PRESCRIPTION_PATTERNS = [
        '/\b(tome|use|inicie|prescrev[ao])\b.{0,40}\b\d+\s*(mg|mcg|ml|gotas?|comprimidos?|c[aá]psulas?)\b/iu',
        '/\b\d+\s*(mg|mcg|ml|gotas?|comprimidos?|c[aá]psulas?)\b.{0,20}\b(de\s+\d+\s+em\s+\d+|a cada|\d+\s*x\s*ao dia|\d+\s*vezes)\b/iu',
    ];

    /**
     * Linguagem dirigida ao paciente em 2ª pessoa, sugerindo comunicação
     * direta sem mediação médica.
     */
    private const PATIENT_FACING_PATTERNS = [
        '/\b(voc[êe]\s+(deve|precisa|tem que)|no seu caso)\b/iu',
    ];

    /**
     * @return list<string> safety notes detectadas (vazio se nada suspeito).
     */
    public function inspect(string $content, AiRiskLevel $riskLevel): array
    {
        $notes = [];

        if ($content === '') {
            return $notes;
        }

        if ($this->matchesAny($content, self::DIAGNOSTIC_ASSERTIVENESS_PATTERNS)) {
            $notes[] = __('ai.safety.diagnostic_assertiveness');
        }

        if ($this->matchesAny($content, self::DIRECT_PRESCRIPTION_PATTERNS)) {
            $notes[] = __('ai.safety.direct_prescription');
        }

        if ($this->matchesAny($content, self::PATIENT_FACING_PATTERNS)) {
            $notes[] = __('ai.safety.patient_facing_language');
        }

        if ($riskLevel === AiRiskLevel::High && $notes === []) {
            // Risco alto sempre recebe nota explícita — mesmo sem padrão detectado,
            // o médico precisa ser lembrado da carga de revisão.
            $notes[] = __('ai.safety.high_risk_reminder');
        }

        return $notes;
    }

    /**
     * @param array<int, string> $patterns
     */
    private function matchesAny(string $content, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return true;
            }
        }

        return false;
    }
}
