<?php

declare(strict_types=1);

namespace App\Services\Lgpd;

use App\Domains\Tiss\Models\TissGuide;
use App\Models\{Patient, PatientExam};
use App\Models\Surgery\{
    SurgeryAuthorizationNotice,
    SurgeryDocumentIssue,
    SurgicalAuthorizationEvent,
    SurgicalCase,
    SurgicalCaseExam,
    SurgicalCaseIolPlan,
    SurgicalCaseMaterial,
    SurgicalCaseProcedure,
    SurgicalChecklistEntry,
    SurgicalChecklistPhase,
    SurgicalDescription,
    SurgicalDescriptionAddendum,
    SurgicalImplant,
    SurgicalOperation,
    SurgicalPostopEvaluation,
    SurgicalPostopEvaluationAddendum,
    SurgicalPostopReturn,
    SurgicalTeamMember
};
use App\Models\WhatsApp\WhatsAppMessage;
use BackedEnum;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\Collection;

/**
 * Seção "cirurgias" da exportação LGPD de UM paciente em UMA clínica (art. 18,
 * II e V): cada caso cirúrgico com o pedido, a autorização, o consentimento e
 * os documentos emitidos, o checklist, a cirurgia, o implante (rastreabilidade
 * RDC 556/2021), a descrição, o pós-operatório e os avisos enviados.
 *
 * Regras:
 *  - tudo filtra pela clínica do paciente E pelo caso/paciente — nunca outra clínica;
 *  - chaves internas (ids de vínculo, colunas de auditoria), a senha de autorização
 *    da operadora e o CAMINHO do arquivo da cópia assinada ficam de fora; da cópia
 *    assinada sai só o metadado (nome, tipo, tamanho, hash, data);
 *  - ordem estável (por data) e memória limitada: um caso por vez.
 * O exportador principal chama `export()` numa chave `surgery`; guias TISS dos
 * casos (solicitação de internação, anexo de OPME) já entram em `tiss_guides`
 * porque têm `patient_id`.
 */
final class SurgeryDataExporter
{
    /**
     * Tabelas do módulo cirúrgico com dado do paciente (coluna `patient_id` ou `surgical_case_id`) que a
     * exportação cobre. Tabela nova com essas colunas SEM entrar aqui reprova SurgeryLgpdCoverageTest.
     * (tiss_guides é coberta por PatientDataExporter::tissGuides + tissRequests.).
     */
    public const COVERED_TABLES = [
        'surgical_cases', 'surgical_case_procedures', 'surgical_case_materials', 'surgical_case_iol_plans',
        'surgical_case_exams', 'surgical_team_members', 'surgical_authorization_events', 'surgery_authorization_notices',
        'surgery_document_issues', 'surgical_checklist_phases', 'surgical_checklist_entries', 'surgical_operations',
        'surgical_implants', 'surgical_descriptions', 'surgical_description_addenda', 'surgical_postop_evaluations',
        'surgical_postop_evaluation_addenda', 'surgical_postop_returns',
    ];

    /** Colunas que não são dado do titular. */
    private const INTERNAL = [
        'id', 'entity_id', 'patient_id', 'surgical_case_id', 'created_by', 'updated_by', 'deleted_by', 'deleted_at',
        'authorization_password', 'signed_copy_path', 'signed_copy_uploaded_by', 'recorded_by', 'registered_by',
        'surgical_authorization_event_id', 'whatsapp_message_id', 'stock_movement_id', 'reversal_movement_id',
        'entity_product_id', 'stock_lot_id',
    ];

    /** @return list<array<string, mixed>> */
    public function export(Patient $patient): array
    {
        $entityId = (string) $patient->entity_id;

        return SurgicalCase::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patient->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(fn (SurgicalCase $case) => $this->surgicalCase($case, $entityId))
            ->all();
    }

    /** @return array<string, mixed> */
    private function surgicalCase(SurgicalCase $case, string $entityId): array
    {
        $caseId = (string) $case->getKey();

        $rows = fn (string $model, string $order = 'created_at'): Collection => $this->forCase($model, $caseId, $entityId)->orderBy($order)->get();

        $descriptionIds = $this->forCase(SurgicalDescription::class, $caseId, $entityId)->pluck('id');
        $evaluations    = $rows(SurgicalPostopEvaluation::class);
        $documents      = $rows(SurgeryDocumentIssue::class, 'issued_at');
        $authEvents     = $rows(SurgicalAuthorizationEvent::class, 'occurred_at');
        $notices        = $rows(SurgeryAuthorizationNotice::class);
        $examLinks      = $rows(SurgicalCaseExam::class);
        $examNames      = PatientExam::query()->withoutGlobalScopes()->whereIn('id', $examLinks->pluck('patient_exam_id'))->with('examType:id,name')->get()->keyBy('id');

        return [
            ...$this->clean($case),
            'status_label' => $case->status->label(),
            'eye_label'    => $case->eye->label(),
            'regime_label' => $case->regime->label(),
            'surgeon'      => $case->surgeon?->person?->full_name,
            'covenant'     => $case->covenant?->name,
            'facility'     => $case->facility?->name,
            'room'         => $case->room?->name,
            'procedures'   => $this->map($rows(SurgicalCaseProcedure::class, 'position')),
            'materials'    => $this->map($rows(SurgicalCaseMaterial::class, 'position')),
            'iol_plans'    => $this->map($rows(SurgicalCaseIolPlan::class)),
            'exams'        => $examLinks->map(fn (Model $link) => [
                'role'         => $link->role,
                'notes'        => $link->notes,
                'exam'         => $examNames->get($link->patient_exam_id)?->examType?->name,
                'performed_at' => $examNames->get($link->patient_exam_id)?->exam_performed_at?->toDateString(),
            ])->all(),
            'team'                  => $this->map($rows(SurgicalTeamMember::class, 'position')),
            'authorization_events'  => $this->map($authEvents),
            'authorization_notices' => $notices->map(fn (Model $notice) => $this->noticeWithMessage($notice))->all(),
            'documents'             => $documents->map(fn (SurgeryDocumentIssue $d) => $this->document($d))->all(),
            'checklist'             => [
                'phases'  => $this->map($rows(SurgicalChecklistPhase::class)),
                'entries' => $this->map($rows(SurgicalChecklistEntry::class, 'answered_at')),
            ],
            'operation'           => $this->map($rows(SurgicalOperation::class)),
            'implants'            => $this->map($rows(SurgicalImplant::class)),
            'description'         => $this->map($rows(SurgicalDescription::class)),
            'description_addenda' => $this->map(
                SurgicalDescriptionAddendum::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereIn('surgical_description_id', $descriptionIds)->orderBy('created_at')->get(),
            ),
            'postop_evaluations' => $evaluations->map(fn (SurgicalPostopEvaluation $e) => $this->evaluation($e))->all(),
            'postop_addenda'     => $this->map(
                SurgicalPostopEvaluationAddendum::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereIn('surgical_postop_evaluation_id', $evaluations->pluck('id'))->orderBy('created_at')->get(),
            ),
            'postop_returns' => $this->map($rows(SurgicalPostopReturn::class)),
            'tiss_requests'  => $this->tissRequests($caseId, $entityId),
        ];
    }

    /**
     * Avaliação pós-operatória legível para o titular: acuidade visual e achados por NOME (nunca o
     * UUID do cadastro nem o código cru do enum), mais o marco e o olho no idioma da exportação.
     *
     * @return array<string, mixed>
     */
    private function evaluation(SurgicalPostopEvaluation $e): array
    {
        $e->loadMissing(['vaUncorrected', 'vaCorrected']);

        return [
            ...$this->clean($e),
            'milestone_label'      => $e->milestone->label(),
            'eye_label'            => $e->eye->label(),
            'va_uncorrected_label' => $e->vaUncorrected?->name,
            'va_corrected_label'   => $e->vaCorrected?->name,
            'findings_labels'      => array_map(fn ($f) => $f->label(), $e->findingEnums()),
            'adherence_label'      => $e->adherence?->label(),
        ];
    }

    /**
     * Solicitação de internação e anexo de OPME do caso (TISS): estado e o que o titular
     * informou/foi informado (allowlist do payload; sem XML nem texto técnico da operadora).
     *
     * @return list<array<string, mixed>>
     */
    private function tissRequests(string $caseId, string $entityId): array
    {
        return TissGuide::query()
            ->withoutGlobalScopes()
            ->with(['items'])
            ->where('entity_id', $entityId)
            ->where('surgical_case_id', $caseId)
            ->orderBy('created_at')
            ->get()
            ->map(function (TissGuide $g): array {
                $surgical = (array) ($g->payload['surgical'] ?? []);
                $data     = (array) ($surgical['data'] ?? []);
                $manual   = (array) ($surgical['overrides'] ?? []);

                return [
                    'guide_type'            => $g->guide_type instanceof BackedEnum ? $g->guide_type->value : (string) $g->guide_type,
                    'status'                => $g->status instanceof BackedEnum ? $g->status->value : (string) $g->status,
                    'guide_number_provider' => $g->guide_number_provider,
                    'authorization_number'  => $g->authorization_number,
                    'beneficiary'           => ['name' => $g->beneficiary_name, 'card_number' => $g->beneficiary_card_number],
                    'clinical_indication'   => $g->clinical_indication,
                    'requested'             => [
                        'diagnoses'              => $data['diagnoses'] ?? [],
                        'suggested_date'         => $manual['suggested_date'] ?? $data['suggested_date'] ?? null,
                        'days_requested'         => $data['days_requested'] ?? null,
                        'opme'                   => $data['opme'] ?? null,
                        'urgency_code'           => $data['urgency_code'] ?? null,
                        'regime_code'            => $data['regime_code'] ?? null,
                        'justification'          => $manual['justification'] ?? $data['justification'] ?? null,
                        'specification'          => $manual['specification'] ?? $data['specification'] ?? null,
                        'observation'            => $manual['observation'] ?? null,
                        'referenced_guide'       => $manual['referenced_guide_number'] ?? $data['referenced_guide_number'] ?? null,
                        'operator_authorization' => [
                            'guide_number' => $data['authorization']['operator_guide_number'] ?? null,
                            'date'         => $data['authorization']['date'] ?? null,
                        ],
                    ],
                    'items' => $g->items->map(fn ($i) => [
                        'description'             => $i->description,
                        'quantity'                => $i->quantity,
                        'manufacturer'            => $i->metadata['manufacturer'] ?? null,
                        'anvisa_registration'     => $i->metadata['anvisa_registration'] ?? null,
                        'manufacturer_ref'        => $i->metadata['manufacturer_ref'] ?? null,
                        'operating_authorization' => $i->metadata['operating_authorization'] ?? null,
                    ])->values()->all(),
                ];
            })->all();
    }

    /** @param class-string<Model> $model */
    private function forCase(string $model, string $caseId, string $entityId): Builder
    {
        return $model::query()->withoutGlobalScopes()->where('entity_id', $entityId)->where('surgical_case_id', $caseId);
    }

    /** @param Collection<int, Model> $models @return list<array<string, mixed>> */
    private function map(Collection $models): array
    {
        return $models->map(fn (Model $m) => $this->clean($m))->values()->all();
    }

    /** @return array<string, mixed> */
    private function clean(Model $model): array
    {
        return array_diff_key($model->attributesToArray(), array_flip(self::INTERNAL));
    }

    /** Documento emitido: texto congelado + metadado da cópia assinada (o arquivo em si não sai). @return array<string, mixed> */
    private function document(SurgeryDocumentIssue $d): array
    {
        return [
            ...$this->clean($d),
            'type_label'      => $d->type->label(),
            'has_signed_copy' => $d->hasSignedCopy(),
            'superseded'      => $d->superseded_by_id !== null,
        ];
    }

    /** Aviso de autorização enviado ao paciente: o evento e a mensagem do WhatsApp. @return array<string, mixed> */
    private function noticeWithMessage(Model $notice): array
    {
        $message = $notice->getAttribute('whatsapp_message_id')
            ? WhatsAppMessage::query()->withoutGlobalScopes()->whereKey($notice->getAttribute('whatsapp_message_id'))->first()
            : null;

        return [
            ...$this->clean($notice),
            'message' => $message ? [
                'channel' => 'whatsapp',
                'kind'    => $message->kind,
                'phone'   => $message->phone,
                'body'    => $message->body,
                'status'  => $message->status,
                'sent_at' => $message->sent_at?->toIso8601String(),
            ] : null,
        ];
    }
}
