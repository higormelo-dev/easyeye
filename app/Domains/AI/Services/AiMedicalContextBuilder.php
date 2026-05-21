<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Models\MedicalRecord;
use App\Models\Patient;
use Carbon\CarbonImmutable;

/**
 * Constrói o contexto clínico mínimo enviado ao provedor LLM a partir de
 * um Patient/MedicalRecord do banco — em vez de aceitar contexto bruto do frontend.
 *
 * Estratégia de minimização (LGPD art. 6º, V — necessidade):
 *   - Inclui apenas campos com utilidade clínica direta.
 *   - Remove identificadores diretos: CPF, RG, email, telefone, endereço.
 *   - Aplica anonimização de nome para iniciais ("João Silva Santos" → "J. S. S.").
 *
 * O médico vê o paciente real na sua tela; o LLM vê só o conjunto mínimo.
 * Se a clínica precisar enviar mais contexto, deve passar via campo `extra`
 * do formulário — auditado e visível ao médico antes da execução.
 */
final class AiMedicalContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(?Patient $patient, ?MedicalRecord $record): array
    {
        $context = [];

        if ($patient) {
            $context = array_merge($context, $this->patientContext($patient));
        }

        if ($record) {
            $context = array_merge($context, $this->medicalRecordContext($record));
        }

        return array_filter(
            $context,
            static fn ($value) => $value !== null && $value !== '' && $value !== []
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function patientContext(Patient $patient): array
    {
        $patient->loadMissing('person');
        $person = $patient->person;

        if (!$person) {
            return ['patient_code' => $patient->code];
        }

        $age = $this->ageFromBirthDate($person->birth_date);

        return [
            'patient_code'     => $patient->code,
            'patient_initials' => $this->initialsOf((string) $person->full_name),
            'age_years'        => $age,
            'gender'           => $person->gender,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function medicalRecordContext(MedicalRecord $record): array
    {
        return [
            'medical_record_code'    => $record->code,
            'main_complaint'         => $this->truncate($record->main_complaint),
            'history_present_illness'=> $this->truncate($record->hda),
            'others_history'         => $this->truncate($record->others_history),
            'medications_in_use'     => $this->truncate($record->medications_in_use),
            'ocular_surgical_history'=> $this->truncate($record->ocular_surgical_history),
            'comorbidities'          => $this->comorbidities($record),
            'tonometry'              => $this->tonometry($record),
            'visual_acuity'          => $this->visualAcuity($record),
            'biomicroscopy'          => [
                'right' => $this->truncate($record->biomicroscopy_right),
                'left'  => $this->truncate($record->biomicroscopy_left),
            ],
            'fundoscopy'             => [
                'right' => $this->truncate($record->fundoscopy_right ?? null),
                'left'  => $this->truncate($record->fundoscopy_left ?? null),
            ],
        ];
    }

    private function initialsOf(string $fullName): string
    {
        $fullName = trim($fullName);

        if ($fullName === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];

        $initials = array_map(
            static fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)) . '.',
            array_filter($parts, static fn (string $p) => $p !== '')
        );

        return implode(' ', $initials);
    }

    private function ageFromBirthDate(mixed $birthDate): ?int
    {
        if (!$birthDate) {
            return null;
        }

        try {
            $date = $birthDate instanceof \DateTimeInterface
                ? CarbonImmutable::instance($birthDate)
                : CarbonImmutable::parse((string) $birthDate);
        } catch (\Throwable) {
            return null;
        }

        return (int) $date->diffInYears(CarbonImmutable::now());
    }

    private function truncate(?string $value, int $max = 600): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);

        if ($clean === '') {
            return null;
        }

        if (mb_strlen($clean) <= $max) {
            return $clean;
        }

        return mb_substr($clean, 0, $max - 3) . '...';
    }

    /**
     * @return list<string>
     */
    private function comorbidities(MedicalRecord $record): array
    {
        $flags = [];

        if ($record->diabetic) {
            $flags[] = 'diabetes_mellitus';
        }
        if ($record->hypertensive) {
            $flags[] = 'hipertensao_arterial';
        }
        if ($record->glaucomatous) {
            $flags[] = 'glaucoma';
        }

        return $flags;
    }

    /**
     * @return array<string, string|null>
     */
    private function tonometry(MedicalRecord $record): array
    {
        return [
            'right_mmHg' => $this->truncate($record->tonometer_right, 16),
            'left_mmHg'  => $this->truncate($record->tonometer_left, 16),
            'time'       => $this->truncate($record->tonometer_time, 16),
        ];
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function visualAcuity(MedicalRecord $record): array
    {
        return [
            'dynamic' => [
                'right_spherical'   => $this->truncate($record->dynamic_spherical_right, 16),
                'left_spherical'    => $this->truncate($record->dynamic_spherical_left, 16),
                'right_cylindrical' => $this->truncate($record->dynamic_cylindrical_right, 16),
                'left_cylindrical'  => $this->truncate($record->dynamic_cylindrical_left, 16),
                'right_axis'        => $this->truncate($record->dynamic_axis_right, 16),
                'left_axis'         => $this->truncate($record->dynamic_axis_left, 16),
            ],
            'static' => [
                'right_spherical'   => $this->truncate($record->static_spherical_right, 16),
                'left_spherical'    => $this->truncate($record->static_spherical_left, 16),
                'right_cylindrical' => $this->truncate($record->static_cylindrical_right, 16),
                'left_cylindrical'  => $this->truncate($record->static_cylindrical_left, 16),
                'right_axis'        => $this->truncate($record->static_axis_right, 16),
                'left_axis'         => $this->truncate($record->static_axis_left, 16),
            ],
        ];
    }
}
