<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\{AnesthesiaType, AuthorizationStatus, SurgeryEye, SurgeryFacilityType, SurgeryRegime, SurgeryTeamRole, SurgeryUrgency, SurgicalCaseStatus};
use App\Models\{Covenant, Doctor, SurgeryType};
use App\Models\Surgery\{SurgeryFacility, SurgicalCase};
use App\Models\Surgery\SurgicalPostopEvaluation;
use Illuminate\Support\Collection;

/**
 * Serialização do caso cirúrgico para as telas (lista, cabeçalho do caso e as
 * listas auxiliares do formulário). Datas saem em ISO — a tela formata no
 * idioma do usuário; rótulos de enum saem já traduzidos.
 */
final class SurgicalCaseView
{
    /** Relações que a lista e o cabeçalho precisam (evita N+1). */
    public const WITH = ['patient.person', 'surgeon.person', 'covenant', 'facility', 'room'];

    /** @return array<string, mixed> */
    public static function row(SurgicalCase $case, array $severeCaseIds = []): array
    {
        return [
            'id'            => (string) $case->getKey(),
            'code'          => $case->code,
            'title'         => $case->title,
            'eye'           => ['value' => $case->eye->value, 'label' => $case->eye->label(), 'short' => $case->eye->short()],
            'status'        => self::status($case->status),
            'authorization' => self::authorization($case),
            'patient'       => [
                'id'   => (string) $case->patient_id,
                'name' => $case->patient?->person?->full_name,
            ],
            'surgeon' => [
                'id'   => (string) $case->surgeon_doctor_id,
                'name' => $case->surgeon?->person?->full_name,
            ],
            'covenant'        => $case->covenant?->name,
            'regime'          => $case->regime->label(),
            'facility'        => $case->facility?->name,
            'room'            => $case->room?->name,
            'scheduled_start' => $case->scheduled_start?->toIso8601String(),
            'scheduled_end'   => $case->scheduled_end?->toIso8601String(),
            'urgent'          => $case->urgency === SurgeryUrgency::Urgent,
            // Complicação grave registrada no pós-operatório: destaca o caso na lista e no cabeçalho.
            'severe_complication' => in_array((string) $case->getKey(), $severeCaseIds, true),
            'requested_at'        => $case->requested_at?->toIso8601String(),
            'revision'            => (int) $case->revision,
        ];
    }

    /** Cabeçalho completo do caso (tela de detalhe). @return array<string, mixed> */
    public static function detail(SurgicalCase $case): array
    {
        $case->loadMissing(self::WITH);

        return [
            ...self::row($case, self::severeCaseIds([(string) $case->getKey()])),
            'patient' => [
                'id'         => (string) $case->patient_id,
                'name'       => $case->patient?->person?->full_name,
                'code'       => $case->patient?->code,
                'birth_date' => $case->patient?->person?->birth_date?->toDateString(),
            ],
            'eye_value'            => $case->eye->value,
            'surgery_type_id'      => $case->surgery_type_id,
            'technique'            => $case->technique,
            'anesthesia'           => $case->anesthesia ? ['value' => $case->anesthesia->value, 'label' => $case->anesthesia->label()] : null,
            'regime_value'         => $case->regime->value,
            'urgency_value'        => $case->urgency->value,
            'estimated_duration'   => $case->estimated_duration_minutes,
            'facility_id'          => $case->surgery_facility_id,
            'room_id'              => $case->surgery_room_id,
            'covenant_id'          => $case->covenant_id,
            'covenant_card_number' => $case->covenant_card_number,
            'cid_codes'            => (array) $case->cid_codes,
            'clinical_indication'  => $case->clinical_indication,
            'days_requested'       => (int) $case->days_requested,
            'needs_opme'           => (bool) $case->needs_opme,
            'notes'                => $case->notes,
            'cancellation_reason'  => $case->cancellation_reason,
            'suspension_reason'    => $case->suspension_reason,
            'performed_at'         => $case->performed_at?->toIso8601String(),
            'discharged_at'        => $case->discharged_at?->toIso8601String(),
            'editable'             => [
                'request'  => $case->status->allowsRequestChanges(),
                'planning' => ! in_array($case->status, [SurgicalCaseStatus::Scheduled, SurgicalCaseStatus::Performed, SurgicalCaseStatus::Discharged, SurgicalCaseStatus::Cancelled], true),
                'any'      => $case->status->allowsAnyChange(),
            ],
        ];
    }

    /**
     * Quais destes casos têm complicação grave marcada em avaliação pós-operatória
     * (uma consulta só para a página inteira).
     *
     * @param list<string> $caseIds
     *
     * @return list<string>
     */
    public static function severeCaseIds(array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }

        return SurgicalPostopEvaluation::query()
            ->whereIn('surgical_case_id', $caseIds)
            ->where('severe', true)
            ->distinct()
            ->pluck('surgical_case_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** Lateralidade do exame (1 = OD, 2 = OE) → sigla localizada; outro valor = sem olho definido. */
    public static function lateralityLabel(?int $laterality): ?string
    {
        return match ($laterality) {
            1       => SurgeryEye::Right->short(),
            2       => SurgeryEye::Left->short(),
            default => null,
        };
    }

    /** @return array{value: string, label: string, tone: string} */
    public static function status(SurgicalCaseStatus $status): array
    {
        return ['value' => $status->value, 'label' => $status->label(), 'tone' => $status->tone()];
    }

    /** @return array<string, mixed> */
    public static function authorization(SurgicalCase $case): array
    {
        $expired = $case->authorization_status === AuthorizationStatus::Expired
            || (in_array($case->authorization_status, [AuthorizationStatus::Authorized, AuthorizationStatus::PartiallyAuthorized], true) && $case->authorizationIsExpired());

        return [
            'status'      => $case->authorization_status->value,
            'label'       => $expired ? AuthorizationStatus::Expired->label() : $case->authorization_status->label(),
            'number'      => $case->authorization_number,
            'valid_until' => $case->authorization_valid_until?->toDateString(),
            'expired'     => $expired,
        ];
    }

    /**
     * Listas do formulário de pedido (cirurgiões, convênios, tipos, locais).
     *
     * @return array<string, mixed>
     */
    public static function formOptions(string $entityId): array
    {
        return [
            'surgeons' => Doctor::query()
                ->ofEntity($entityId)
                ->where('doctors.active', true)
                ->with('person:id,full_name')
                ->get()
                ->map(fn (Doctor $d) => ['id' => (string) $d->getKey(), 'name' => $d->person?->full_name, 'registry' => $d->record])
                ->sortBy('name')
                ->values()
                ->all(),
            'covenants' => Covenant::query()
                ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Covenant $c) => ['id' => (string) $c->getKey(), 'name' => $c->name])
                ->all(),
            'surgery_types' => SurgeryType::query()
                ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'category'])
                ->map(fn (SurgeryType $t) => ['id' => (string) $t->getKey(), 'name' => $t->name, 'category' => SurgeryType::$categories[(int) $t->category] ?? null])
                ->all(),
            'facilities' => SurgeryFacility::query()
                ->where('entity_id', $entityId)
                ->where('active', true)
                ->with(['rooms' => fn ($q) => $q->where('active', true)->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->map(fn (SurgeryFacility $f) => [
                    'id'    => (string) $f->getKey(),
                    'name'  => $f->name,
                    'type'  => $f->type->value,
                    'rooms' => $f->rooms->map(fn ($r) => ['id' => (string) $r->getKey(), 'name' => $r->name])->all(),
                ])
                ->all(),
            'eyes'           => self::options(SurgeryEye::options()),
            'regimes'        => self::options(SurgeryRegime::options()),
            'urgencies'      => self::options(SurgeryUrgency::options()),
            'anesthesias'    => self::options(AnesthesiaType::options()),
            'team_roles'     => self::options(SurgeryTeamRole::options()),
            'facility_types' => self::options(SurgeryFacilityType::options()),
        ];
    }

    /**
     * @param array<string, string> $options
     *
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $options): array
    {
        return array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $label], array_keys($options), array_values($options));
    }

    /** @param Collection<int, SurgicalCase> $cases @return list<array<string, mixed>> */
    public static function rows(Collection $cases): array
    {
        return $cases->map(fn (SurgicalCase $c) => self::row($c))->values()->all();
    }
}
