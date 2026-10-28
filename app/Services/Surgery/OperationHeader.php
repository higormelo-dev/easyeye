<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Models\{Entity, Patient};
use App\Models\Surgery\{SurgicalCase, SurgicalImplant};

/**
 * Cabeçalho CONGELADO dos documentos da cirurgia (descrição, cartão e
 * etiquetas): clínica, paciente, caso, cirurgião (nome e registro), equipe e
 * implantes vigentes naquele momento. O PDF sai deste retrato, nunca do
 * cadastro vivo.
 */
final class OperationHeader
{
    /** @return array<string, mixed> */
    public static function build(SurgicalCase $case): array
    {
        $case->loadMissing(['surgeon.person', 'facility', 'room', 'team']);

        $entity  = Entity::query()->find($case->entity_id);
        $patient = Patient::query()->with('person')->find($case->patient_id);
        $person  = $patient?->person;

        return [
            'clinic' => [
                'name'    => $entity?->name,
                'cnpj'    => $entity?->national_registration,
                'cnes'    => $entity?->cnes,
                'address' => $entity ? trim(implode(', ', array_filter([$entity->address, $entity->number, $entity->district, $entity->city . ($entity->state ? '/' . $entity->state : '')]))) : null,
                'phone'   => $entity?->telephone ?: $entity?->cellphone,
            ],
            'patient' => [
                'name'       => $person?->full_name,
                'birth_date' => $person?->birth_date?->toDateString(),
                'code'       => $patient?->code,
            ],
            'case' => [
                'code'      => $case->code,
                'title'     => $case->title,
                'eye'       => $case->eye->value,
                'facility'  => $case->facility?->name,
                'room'      => $case->room?->name,
                'scheduled' => $case->scheduled_start?->toIso8601String(),
            ],
            'surgeon' => [
                'name'      => $case->surgeon?->person?->full_name,
                'registry'  => $case->surgeon?->record,
                'specialty' => $case->surgeon?->record_specialty,
            ],
            'team' => $case->team->map(fn ($m) => [
                'role'     => $m->role->value,
                'name'     => $m->name,
                'registry' => $m->registry,
            ])->values()->all(),
            'implants' => SurgicalImplant::query()
                ->where('surgical_case_id', $case->getKey())
                ->where('status', 'active')
                ->orderBy('implanted_at')->orderBy('id')
                ->get()
                ->map(fn (SurgicalImplant $i) => [
                    'id' => (string) $i->getKey(),
                    ...$i->frozenContent(),
                ])->all(),
        ];
    }
}
