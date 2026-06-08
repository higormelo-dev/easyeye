<?php

namespace App\Services;

use App\Enums\EntityGate;
use App\Exceptions\LockedMedicalRecordException;
use App\Models\{Entity, MedicalRecord, Patient};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Encapsula a lógica de negócio do prontuário médico.
 * Controllers delegam para este service — nunca contêm lógica de domínio.
 */
class MedicalRecordService
{
    /**
     * Cria um novo prontuário e persiste no banco.
     */
    public function store(array $data, Patient $patient): MedicalRecord
    {
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail(session('selected_entity_id')));

        return MedicalRecord::create(
            array_merge($this->normalize($data), ['patient_id' => $patient->id]),
        );
    }

    /**
     * Atualiza um prontuário existente — lança exceção se estiver assinado/bloqueado.
     *
     * @throws LockedMedicalRecordException
     */
    public function update(MedicalRecord $record, array $data): MedicalRecord
    {
        // A trait Signable já lança LockedMedicalRecordException em updating se is_locked=true
        $record->update($this->normalize($data));

        return $record->fresh();
    }

    /**
     * Soft deleta o prontuário — não é possível deletar prontuário assinado.
     *
     * @throws LockedMedicalRecordException
     */
    public function delete(MedicalRecord $record): void
    {
        if ($record->is_locked) {
            throw new LockedMedicalRecordException();
        }

        $record->delete();
    }

    /**
     * Restaura um prontuário soft-deletado.
     */
    public function restore(string $id): MedicalRecord
    {
        $record = MedicalRecord::withTrashed()->findOrFail($id);
        $record->restore();

        return $record;
    }

    /**
     * Calcula a esfera estática a partir da esfera dinâmica e da adição.
     *
     * Fórmula optométrica: Esf. Estática = Esf. Dinâmica + Adição
     *
     * @param float|null $addition Valor de adição (ex: +2.00)
     *
     * @return array{
     *   right: float|null,
     *   left: float|null,
     *   static_spherical_right: float|null,
     *   static_spherical_left: float|null
     * }
     */
    public function calculatePresbyopia(?float $dynamicRight, ?float $dynamicLeft, ?float $addition): array
    {
        $right = $dynamicRight !== null && $addition !== null
            ? round($dynamicRight + $addition, 2)
            : null;

        $left = $dynamicLeft !== null && $addition !== null
            ? round($dynamicLeft + $addition, 2)
            : null;

        return [
            'right'                  => $right,
            'left'                   => $left,
            'static_spherical_right' => $right,
            'static_spherical_left'  => $left,
        ];
    }

    /**
     * Normaliza os dados do request antes de persistir:
     *   - Strings vazias → null para campos FK e numéricos
     *   - Converte booleanos (checkboxes HTML enviam '1' ou estarão ausentes)
     */
    private function normalize(array $data): array
    {
        $nullableIds = [
            'doctor_id', 'visual_acuity_type_id', 'near_point_convergence_id',
            'cover_test_type_id', 'color_vision_type_id',
            'visual_acuity_without_correction_right_id', 'visual_acuity_without_correction_left_id',
            'visual_acuity_with_correction_right_id', 'visual_acuity_with_correction_left_id',
            'addition_type_id', 'lens_away_id', 'lens_near_id', 'schedule_id',
        ];

        $nullableNumerics = [
            'tonometer_right', 'tonometer_left',
            'pachymetry_right', 'pachymetry_left',
            'dynamic_spherical_right', 'dynamic_spherical_left',
            'dynamic_cylindrical_right', 'dynamic_cylindrical_left',
            'dynamic_axis_right', 'dynamic_axis_left',
            'static_spherical_right', 'static_spherical_left',
            'static_cylindrical_right', 'static_cylindrical_left',
            'static_axis_right', 'static_axis_left',
            'follow_up_days',
        ];

        $booleans = [
            'diabetic', 'diabetic_family',
            'hypertensive', 'hypertensive_family',
            'glaucomatous', 'glaucomatous_family',
        ];

        foreach ($nullableIds as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = blank($data[$field]) ? null : $data[$field];
            }
        }

        foreach ($nullableNumerics as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = blank($data[$field]) ? null : $data[$field];
            }
        }

        foreach ($booleans as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filter_var($data[$field] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $data;
    }
}
