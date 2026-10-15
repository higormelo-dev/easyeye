<?php

declare(strict_types=1);

namespace App\Services\Surgery\Sections;

use App\Enums\Surgery\{AnesthesiaType, ImplantKind, ImplantStockSource, SurgeryEye, SurgicalCaseStatus};
use App\Models\Surgery\{SurgicalCase, SurgicalDescription, SurgicalImplant};
use App\Services\Surgery\Guards\{AuthorizationStillValidGuard, ChecklistTimeOutGuard, ConsentSignedGuard};
use App\Services\Surgery\{SurgeryChecklistService, SurgeryDescriptionService, SurgeryImplantDocumentService, SurgeryParticipants};
use App\Services\Surgery\SurgeryImplantService;
use App\Support\CurrentDoctor;
use Illuminate\Support\Collection;

/**
 * Aba "Cirurgia" (M4): requisitos para registrar a cirurgia como realizada,
 * implantes (rastreabilidade RDC 556/2021 + documentos), descrição cirúrgica e
 * adendos. Aparece com a cirurgia agendada, realizada ou com alta.
 */
final class OperationSection implements SurgeryShowSection
{
    public function __construct(
        private readonly SurgeryParticipants $participants,
        private readonly SurgeryDescriptionService $descriptions,
        private readonly SurgeryImplantDocumentService $implantDocuments,
    ) {
    }

    public function key(): string
    {
        return 'operation';
    }

    public function order(): int
    {
        return 60;
    }

    public function label(): string
    {
        return (string) __('surgery_operation.tab');
    }

    public function visible(SurgicalCase $case, array $can): bool
    {
        return in_array($case->status, [SurgicalCaseStatus::Scheduled, SurgicalCaseStatus::Performed, SurgicalCaseStatus::Discharged], true);
    }

    public function props(SurgicalCase $case, array $can): array
    {
        $case->loadMissing(['iolPlans', 'operation.performer.person', 'team']);

        $doctor    = CurrentDoctor::issuer();
        $status    = $case->status;
        $isSurgeon = $doctor !== null && $can['perform'] && $this->participants->isSurgeon($case, $doctor);
        $isMember  = $doctor !== null && $can['perform'] && $this->participants->isMember($case, $doctor);
        $open      = in_array($status, [SurgicalCaseStatus::Scheduled, SurgicalCaseStatus::Performed], true);

        $implants    = SurgicalImplant::query()->where('surgical_case_id', $case->getKey())->orderBy('implanted_at')->orderBy('id')->get();
        $description = SurgicalDescription::query()->where('surgical_case_id', $case->getKey())->with('addenda')->first();

        return [
            'performed' => $case->operation !== null,
            'operation' => $case->operation === null ? null : [
                'performed_at'      => $case->operation->performed_at?->toIso8601String(),
                'team_confirmed_at' => $case->operation->team_confirmed_at?->toIso8601String(),
                'complications'     => $case->operation->complications,
                'no_implant_reason' => $case->operation->no_implant_reason,
                'overrides'         => (array) $case->operation->override_reasons,
                'performer'         => $case->operation->performer?->person?->full_name,
            ],
            'requirements' => $status === SurgicalCaseStatus::Scheduled ? $this->requirements($case, $implants) : [],
            'implants'     => $implants->map(fn (SurgicalImplant $i) => $this->implant($i))->all(),
            'iol_plans'    => $case->iolPlans->map(fn ($p) => [
                'eye'               => $p->eye->value,
                'eye_label'         => $p->eye->label(),
                'model_label'       => $p->model_label,
                'power'             => $p->power,
                'entity_product_id' => $p->entity_product_id,
            ])->all(),
            'description' => $description === null ? null : $this->description($description),
            'team'        => $case->team->map(fn ($m) => [
                'role_label' => $m->role->label(),
                'name'       => $m->name,
                'registry'   => $m->registry,
            ])->all(),
            'state' => [
                'can_perform'       => $isSurgeon && $status === SurgicalCaseStatus::Scheduled,
                'can_register'      => $isMember && $open,
                'can_describe'      => $isSurgeon && $open && ($description === null || ! $description->isFinalized()),
                'can_finalize'      => $isSurgeon && $status === SurgicalCaseStatus::Performed && $description !== null && ! $description->isFinalized(),
                'can_addendum'      => $isSurgeon && in_array($status, [SurgicalCaseStatus::Performed, SurgicalCaseStatus::Discharged], true) && $description?->isFinalized() === true,
                'can_print'         => $can['manage'],
                'case_revision'     => (int) $case->revision,
                'performing_doctor' => $doctor !== null,
            ],
            'options' => [
                'eyes'         => $this->eyeOptions($case),
                'anesthesias'  => array_map(fn (AnesthesiaType $a) => ['value' => $a->value, 'label' => $a->label()], AnesthesiaType::cases()),
                'sources'      => array_map(fn (ImplantStockSource $s) => ['value' => $s->value, 'label' => $s->label()], ImplantStockSource::cases()),
                'kinds'        => array_map(fn (ImplantKind $k) => ['value' => $k->value, 'label' => $k->label()], ImplantKind::cases()),
                'label_max'    => SurgeryImplantDocumentService::MAX_LABELS,
                'override_min' => AuthorizationStillValidGuard::MIN_REASON,
            ],
            'urls' => $this->urls($case),
        ];
    }

    /**
     * O que o servidor vai conferir ao registrar a cirurgia (a tela mostra antes
     * de o cirurgião tentar). `override` = nome do campo de justificativa que
     * destrava a condição, quando existe.
     *
     * @param Collection<int, SurgicalImplant> $implants
     *
     * @return list<array{key: string, ok: bool, message: string, override: string|null}>
     */
    private function requirements(SurgicalCase $case, $implants): array
    {
        $rows = [];

        $auth = AuthorizationStillValidGuard::evaluate($case);

        $rows[] = [
            'key'      => 'authorization',
            'ok'       => $auth === 'valid',
            'message'  => (string) __('surgery_operation.requirements.authorization_' . $auth),
            'override' => $auth === 'expired' ? 'authorization_override_reason' : null,
        ];

        if ($case->iolPlans->isNotEmpty()) {
            $implanted = $implants->filter(fn (SurgicalImplant $i) => $i->isActive() && $i->kind === ImplantKind::Iol)->map(fn (SurgicalImplant $i) => $i->eye->value);
            $missing   = $case->iolPlans->filter(fn ($p) => ! $implanted->contains($p->eye->value));

            $rows[] = [
                'key'     => 'implant',
                'ok'      => $missing->isEmpty(),
                'message' => $missing->isEmpty()
                    ? (string) __('surgery_operation.requirements.implant_ok')
                    : (string) __('surgery_operation.requirements.implant_missing', ['eyes' => $missing->map(fn ($p) => $p->eye->label())->implode(', ')]),
                'override' => $missing->isEmpty() ? null : 'no_implant_reason',
            ];
        }

        $plans = $case->iolPlans->keyBy(fn ($p) => $p->eye->value);
        $bad   = $implants->filter(fn (SurgicalImplant $i) => $i->isActive() && $i->kind === ImplantKind::Iol
            && blank($i->plan_deviation_reason) && $plans->has($i->eye->value)
            && SurgeryImplantService::deviates($i->power, $i->entity_product_id, $plans[$i->eye->value]));

        if ($plans->isNotEmpty() && $implants->contains(fn (SurgicalImplant $i) => $i->isActive() && $i->kind === ImplantKind::Iol)) {
            $rows[] = [
                'key'     => 'plan',
                'ok'      => $bad->isEmpty(),
                'message' => $bad->isEmpty()
                    ? (string) __('surgery_operation.requirements.plan_ok')
                    : (string) __('surgery_operation.requirements.plan_mismatch', ['eyes' => $bad->map(fn ($i) => $i->eye->label())->implode(', ')]),
                'override' => $bad->isEmpty() ? null : 'plan_deviation_reason',
            ];
        }

        // Pausa Cirúrgica (etapa do checklist): só aparece se a condição está registrada.
        if (in_array(ChecklistTimeOutGuard::class, (array) config('surgery.perform_guards', []), true)
            && class_exists(SurgeryChecklistService::class)) {
            $closed = app(SurgeryChecklistService::class)->isTimeOutClosed($case);

            $rows[] = [
                'key'      => 'time_out',
                'ok'       => $closed,
                'message'  => (string) __($closed ? 'surgery_operation.requirements.time_out_ok' : 'surgery_operation.requirements.time_out_open'),
                'override' => $closed ? null : 'time_out_override_reason',
            ];
        }

        // Termo de consentimento (TCLE) com a cópia assinada anexada.
        if (in_array(ConsentSignedGuard::class, (array) config('surgery.perform_guards', []), true)) {
            $consent = ConsentSignedGuard::state($case);

            $rows[] = [
                'key'      => 'consent',
                'ok'       => $consent === 'signed',
                'message'  => (string) __('surgery_operation.requirements.consent_' . $consent),
                'override' => $consent === 'signed' ? null : 'consent_override_reason',
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function implant(SurgicalImplant $i): array
    {
        $record = $this->implantDocuments->currentRecord($i);

        return [
            'id'                  => (string) $i->getKey(),
            'status'              => $i->status->value,
            'kind'                => $i->kind->value,
            'kind_label'          => $i->kind->label(),
            'source'              => $i->stock_source->value,
            'source_label'        => $i->stock_source->label(),
            'eye'                 => $i->eye->value,
            'eye_label'           => $i->eye->label(),
            'product_name'        => $i->product_name,
            'manufacturer'        => $i->manufacturer,
            'model_reference'     => $i->model_reference,
            'anvisa_registration' => $i->anvisa_registration,
            'power'               => $i->power,
            'lot_number'          => $i->lot_number,
            'serial_number'       => $i->serial_number,
            'expiry_date'         => $i->expiry_date?->toDateString(),
            'quantity'            => (int) $i->quantity,
            'implanted_at'        => $i->implanted_at?->toIso8601String(),
            'voided_at'           => $i->voided_at?->toIso8601String(),
            'void_reason'         => $i->void_reason,
            'void_stock_action'   => $i->void_stock_action,
            'record'              => $record === null ? null : [
                'id'        => (string) $record->getKey(),
                'issued_at' => $record->issued_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function description(SurgicalDescription $d): array
    {
        return [
            'status'              => $d->status->value,
            'finalized'           => $d->isFinalized(),
            'revision'            => (int) $d->revision,
            'performed_start_at'  => $d->performed_start_at?->format('Y-m-d\TH:i'),
            'performed_end_at'    => $d->performed_end_at?->format('Y-m-d\TH:i'),
            'anesthesia_used'     => $d->anesthesia_used?->value,
            'findings'            => $d->findings,
            'technique_performed' => $d->technique_performed,
            'no_complications'    => (bool) $d->no_complications,
            'complications'       => $d->complications,
            'finalized_at'        => $d->finalized_at?->toIso8601String(),
            'content_hash'        => $d->content_hash,
            'problems'            => $d->isFinalized() ? [] : $this->descriptions->problems($d),
            'addenda'             => $d->addenda->map(fn ($a) => [
                'id'         => (string) $a->getKey(),
                'text'       => $a->text,
                'reason'     => $a->reason,
                'doctor'     => $a->doctor_name,
                'registry'   => $a->doctor_registry,
                'created_at' => $a->created_at?->toIso8601String(),
                'hash'       => substr((string) $a->content_hash, 0, 16),
            ])->all(),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function eyeOptions(SurgicalCase $case): array
    {
        $eyes = $case->eye === SurgeryEye::Both ? [SurgeryEye::Right, SurgeryEye::Left] : [$case->eye];

        return array_map(fn (SurgeryEye $e) => ['value' => $e->value, 'label' => $e->label()], $eyes);
    }

    /** @return array<string, string> */
    private function urls(SurgicalCase $case): array
    {
        return [
            'products'             => route('panel.surgery.operation.products', $case),
            'implant_store'        => route('panel.surgery.operation.implants.store', $case),
            'implant_void'         => route('panel.surgery.operation.implants.void', [$case, '__ID__']),
            'implant_record'       => route('panel.surgery.operation.implants.record', [$case, '__ID__']),
            'implant_record_pdf'   => route('panel.surgery.operation.implants.record-pdf', [$case, '__ID__']),
            'implant_labels'       => route('panel.surgery.operation.implants.labels', [$case, '__ID__']),
            'card'                 => route('panel.surgery.operation.card', $case),
            'description_save'     => route('panel.surgery.operation.description.save', $case),
            'description_finalize' => route('panel.surgery.operation.description.finalize', $case),
            'description_addendum' => route('panel.surgery.operation.description.addenda', $case),
            'description_pdf'      => route('panel.surgery.operation.description.pdf', $case),
            'perform'              => route('panel.surgery.operation.perform', $case),
        ];
    }
}
