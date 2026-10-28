<?php

declare(strict_types=1);

namespace App\Support\ClinicalSafety;

use App\Enums\ClinicalSafety\{AllergySeverity, AllergyStatus, MedicationSource};
use App\Models\{MedicalRecord, Patient, PatientAllergy};
use App\Support\ContactLensUse;

/**
 * Bloco de segurança clínica do PDF do prontuário (Fase 2 / A1-A3): alergias
 * e medicações do RETRATO gravado na consulta e o uso de lente de contato.
 * Prontuário anterior ao retrato (sem allergies_snapshot): mostra as
 * alergias ativas do cadastro, marcadas como "cadastro atual".
 */
final class RecordSafetyView
{
    /**
     * @return array{allergy_state: string, allergies: list<string>, allergies_current: bool, medications: list<string>|null, contact_lens_use: list<array{label: string, value: string}>}
     */
    public static function forPdf(MedicalRecord $record): array
    {
        $snapshot = is_array($record->allergies_snapshot) ? $record->allergies_snapshot : null;

        if ($snapshot !== null) {
            $state     = (string) ($snapshot['state'] ?? 'unknown');
            $allergies = array_map(fn (array $a) => self::allergyLine(
                (string) ($a['substance'] ?? ''),
                $a['reaction'] ?? null,
                AllergySeverity::tryFrom((string) ($a['severity'] ?? ''))?->label(),
                AllergyStatus::tryFrom((string) ($a['status'] ?? ''))?->label(),
            ), (array) ($snapshot['items'] ?? []));
            $current = false;
        } else {
            $patient = Patient::query()->withoutGlobalScopes()->find($record->patient_id);
            $active  = PatientAllergy::query()
                ->withoutGlobalScopes()
                ->where('patient_id', $record->patient_id)
                ->whereNull('deleted_at')
                ->active()
                ->orderBy('created_at')
                ->get();

            $state = $active->isNotEmpty() ? 'has' : ($patient?->allergies_denied_at ? 'denied' : 'unknown');

            $allergies = $active->map(fn (PatientAllergy $a) => self::allergyLine(
                $a->substance,
                $a->reaction,
                $a->severity?->label(),
                $a->status->label(),
            ))->values()->all();
            $current = true;
        }

        $medications = is_array($record->medications_snapshot)
            ? array_map(function (array $m): string {
                $line = (string) ($m['name'] ?? '');

                if (filled($m['active_ingredient'] ?? null) && ! str_contains(mb_strtolower($line), mb_strtolower((string) $m['active_ingredient']))) {
                    $line .= ' (' . $m['active_ingredient'] . ')';
                }

                if (filled($m['posology'] ?? null)) {
                    $line .= ' — ' . $m['posology'];
                }

                $source = MedicationSource::tryFrom((string) ($m['source'] ?? ''))?->label();

                return $line . ($source ? ' [' . $source . ']' : '');
            }, $record->medications_snapshot)
            : null;

        return [
            'allergy_state'     => $state,
            'allergies'         => $allergies,
            'allergies_current' => $current,
            'medications'       => $medications,
            'contact_lens_use'  => ContactLensUse::rows(is_array($record->contact_lens_use) ? $record->contact_lens_use : null),
        ];
    }

    private static function allergyLine(string $substance, ?string $reaction, ?string $severity, ?string $status): string
    {
        $details = array_filter([$reaction, $severity, $status]);

        return $substance . ($details !== [] ? ' (' . implode('; ', $details) . ')' : '');
    }
}
