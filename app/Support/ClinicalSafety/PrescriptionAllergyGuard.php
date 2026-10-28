<?php

declare(strict_types=1);

namespace App\Support\ClinicalSafety;

use App\Exceptions\AllergyConflictException;
use App\Models\{MedicalRecordDocumentation, Patient, User};
use App\Services\AuditService;

/**
 * Trava de alergia em TODO documento do tipo receita (Fase 2 / A4):
 * receituário de medicamentos, receitas de catarata/pterígio, modelos de
 * receita da clínica salvos pela Documentação. Conflito sem motivo → exceção
 * (422); com motivo → segue e o motivo fica no documento (safety_override) e
 * na trilha de auditoria (evento `allergy_override`).
 */
final class PrescriptionAllergyGuard
{
    /** Tamanho mínimo do motivo (evita "ok", "."). */
    public const MIN_REASON_LENGTH = 5;

    public function __construct(
        private readonly AllergyConflictChecker $checker,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $items itens estruturados da receita
     *
     * @return array<string, mixed>|null metadados do "seguir assim" (null = sem conflito)
     *
     * @throws AllergyConflictException conflito sem motivo
     */
    public function guard(Patient $patient, string $content, array $items = [], ?string $reason = null): ?array
    {
        $conflicts = $this->checker->check($patient, $items, $content);

        if ($conflicts === []) {
            return null;
        }

        $reason = trim((string) $reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new AllergyConflictException($conflicts);
        }

        $user = auth()->user();

        return [
            'conflicts' => $conflicts,
            'reason'    => $reason,
            'by'        => $user instanceof User ? (string) $user->getKey() : null,
            'by_name'   => $user instanceof User ? $user->name : null,
            'at'        => now()->toIso8601String(),
        ];
    }

    /** Evento próprio na trilha de auditoria (além do `created` do documento). */
    public function recordOverride(MedicalRecordDocumentation $documentation, array $override): void
    {
        $this->audit->log(
            event: 'allergy_override',
            model: $documentation,
            old: null,
            new: [
                'reason'    => $override['reason'] ?? null,
                'conflicts' => $override['conflicts'] ?? [],
            ],
        );
    }
}
