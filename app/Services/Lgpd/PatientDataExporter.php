<?php

declare(strict_types=1);

namespace App\Services\Lgpd;

use App\Domains\AI\Models\AiRun;
use App\Domains\Tiss\Models\{TissGlosa, TissGuide, TissGuideItem};
use App\Models\{
    AdditionType,
    BillingClaim,
    ColorVisionType,
    CoverTestType,
    Doctor,
    FinancialCashEntry,
    Lense,
    LgpdRequest,
    MedicalRecord,
    MedicalRecordDocumentation,
    MedicalRecordEvolution,
    MedicalRecordFile,
    MedicalRecordProcedure,
    NearPointConvergence,
    Patient,
    PatientCall,
    PatientConsent,
    PatientDocumentShare,
    PatientExam,
    People,
    RecordVersion,
    Schedule,
    ScheduleSituationLog,
    VisualAcuityType,
    WaitingList
};
use App\Models\WhatsApp\WhatsAppMessage;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Exportação COMPLETA dos dados de um paciente em UMA clínica — LGPD art. 18,
 * II (acesso) e V (portabilidade); art. 19, II (declaração clara e completa).
 *
 * Escopo: um Patient = uma clínica (entity_id) = um controlador. Toda consulta
 * filtra pelo paciente E pela clínica dele; nada de outras clínicas do mesmo
 * titular. O que fica de fora, e por quê, vai declarado em `export_scope`
 * (binários só como metadado, segredo comercial, dados do médico, excluídos).
 */
final class PatientDataExporter
{
    public const FORMAT_VERSION = 3; // 3: plano do convênio no bloco da clínica

    /** @var array<class-string, array<string, ?string>> nomes de catálogo por id */
    private array $names = [];

    // Filtro de clínica: a do paciente ou nula (registro legado sem entity_id
    // também é dele — o patient_id já é de uma clínica só).

    public function export(Patient $patient): array
    {
        $patient->load(['person.patientAccount', 'entity', 'covenant', 'covenantPlan', 'skinType', 'irisType']);
        $scheduleIds = Schedule::withTrashed()->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->where('patient_id', $patient->id)->pluck('id');

        return [
            'exported_at'        => now()->toIso8601String(),
            'format_version'     => self::FORMAT_VERSION,
            'clinic'             => $this->clinic($patient),
            'personal_data'      => $this->personalData($patient->person),
            'portal_account'     => $this->portalAccount($patient->person),
            'medical_records'    => $this->medicalRecords($patient),
            'exams'              => $this->exams($patient),
            'appointments'       => $this->appointments($patient),
            'waiting_list'       => $this->waitingList($patient),
            'messages'           => $this->messages($patient, $scheduleIds),
            'panel_calls'        => $this->panelCalls($patient, $scheduleIds),
            'financial'          => $this->financial($patient),
            'tiss_guides'        => $this->tissGuides($patient),
            'ai_processing'      => $this->aiProcessing($patient),
            'consents'           => $this->consents($patient),
            'document_shares'    => $this->documentShares($patient),
            'lgpd_requests'      => $this->lgpdRequests($patient),
            'access_log_summary' => $this->accessLogSummary($patient),
            'export_scope'       => $this->exportScope(),
        ];
    }

    private function clinic(Patient $patient): array
    {
        return [
            'name'          => $patient->entity?->name,
            'patient_code'  => $patient->code,
            'card_number'   => $patient->card_number,
            'covenant'      => $patient->covenant?->name,
            'covenant_plan' => $patient->covenantPlan ? [
                'name'             => $patient->covenantPlan->name,
                'ans_registration' => $patient->covenantPlan->ans_code,
            ] : null,
            'active'          => $patient->active,
            'patient_since'   => $this->iso($patient->created_at),
            'skin_type'       => $patient->skinType?->name,
            'iris_type'       => $patient->irisType?->name,
            'priority_rating' => $patient->priority_rating,
        ];
    }

    private function personalData(?People $person): ?array
    {
        if (! $person) {
            return null;
        }

        return [
            'full_name'         => $person->full_name,
            'nickname'          => $person->nickname,
            'birth_date'        => $person->birth_date?->toDateString(),
            'gender'            => $person->gender_label,
            'marital_status'    => People::$maritalStatuses[$person->marital_status] ?? $person->marital_status,
            'occupation'        => $person->occupation,
            'mother_name'       => $person->mother_name,
            'father_name'       => $person->father_name,
            'email'             => $person->email,
            'telephone'         => $person->telephone,
            'cellphone'         => $person->cellphone,
            'whatsapp'          => $person->whatsapp,
            'national_registry' => $person->national_registry,
            'state_registry'    => [
                'number'    => $person->state_registry,
                'agency'    => $person->state_registry_agency,
                'state'     => $person->state_registry_initial,
                'issued_at' => $this->iso($person->state_registry_date),
            ],
            'address' => [
                'zipcode'    => $person->zipcode,
                'address'    => $person->address,
                'number'     => $person->number,
                'complement' => $person->complement,
                'district'   => $person->district,
                'city'       => $person->city,
                'state'      => $person->state,
                'country'    => $person->country,
                'latitude'   => $person->latitude,
                'longitude'  => $person->longitude,
            ],
            'has_photo' => filled($person->photo),
        ];
    }

    /** Conta do Portal do Paciente — sem senha/token e sem as outras clínicas vinculadas. */
    private function portalAccount(?People $person): ?array
    {
        $account = $person?->patientAccount;

        return $account ? [
            'email'              => $account->email,
            'email_verified_at'  => $this->iso($account->email_verified_at),
            'two_factor_enabled' => (bool) $account->two_factor_enabled,
            'active'             => (bool) $account->active,
            'created_at'         => $this->iso($account->created_at),
        ] : null;
    }

    private function medicalRecords(Patient $patient): array
    {
        $records = MedicalRecord::query()
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->where('patient_id', $patient->id)
            ->with(['schedule', 'signedBy.user'])
            ->orderByDesc('created_at')
            ->get();
        $ids = $records->pluck('id');

        $byRecord = fn (string $model, array $with = []) => $model::query()->with($with)
            ->whereIn('medical_record_id', $ids)->where('patient_id', $patient->id)
            ->orderBy('created_at')->get()->groupBy('medical_record_id');
        $documentations = $byRecord(MedicalRecordDocumentation::class);
        $files          = $byRecord(MedicalRecordFile::class);
        $evolutions     = $byRecord(MedicalRecordEvolution::class);
        $procedures     = $byRecord(MedicalRecordProcedure::class, ['procedure']);
        $versions       = RecordVersion::query()->with('user')
            ->where('versionable_type', MedicalRecord::class)->whereIn('versionable_id', $ids)
            ->orderBy('version')->get()->groupBy('versionable_id');

        $this->loadClinicalNames([
            ...$records->map(fn (MedicalRecord $r) => $r->getAttributes())->all(),
            ...$versions->flatten()->map(fn (RecordVersion $v) => (array) $v->data)->all(),
        ]);

        return $records->map(fn (MedicalRecord $r) => [
            'code'             => $r->code,
            'date'             => $this->iso($r->created_at),
            'appointment_date' => $this->iso($r->schedule?->date_time),
            'doctor'           => $this->doctorName($r->doctor_id),
            ...$this->clinicalContent($r->getAttributes()),
            'is_signed'  => $r->isSigned(),
            'signed_at'  => $this->iso($r->signed_at),
            'signed_by'  => $r->signedBy?->user?->name,
            'evolutions' => ($evolutions[$r->id] ?? collect())->map(fn (MedicalRecordEvolution $e) => [
                'content'    => $e->content,
                'doctor'     => $this->doctorName($e->doctor_id),
                'created_at' => $this->iso($e->created_at),
            ])->values()->all(),
            'procedures' => ($procedures[$r->id] ?? collect())->map(fn (MedicalRecordProcedure $p) => [
                'procedure'         => $p->procedure?->name,
                'eye'               => $p->eye,
                'solicitation_type' => $p->solicitation_type,
                'status'            => $this->label($p->status),
                'notes'             => $p->notes,
                'executed_at'       => $this->iso($p->executed_at),
                'doctor'            => $this->doctorName($p->doctor_id),
            ])->values()->all(),
            'documentations' => ($documentations[$r->id] ?? collect())->map(fn (MedicalRecordDocumentation $d) => [
                'type'              => $d->getTypeLabel(),
                'title'             => $d->title,
                'content'           => $d->contentForRender(),
                'generated_with_ai' => filled($d->ai_run_id),
                'created_at'        => $this->iso($d->created_at),
            ])->values()->all(),
            'files' => ($files[$r->id] ?? collect())->map(fn (MedicalRecordFile $f) => [
                'original_name' => $f->original_name,
                'mime_type'     => $f->mime_type,
                'file_size'     => $f->file_size,
                'created_at'    => $this->iso($f->created_at),
            ])->values()->all(),
            // Estado do prontuário ANTES de cada alteração (Versionable).
            'versions' => ($versions[$r->id] ?? collect())->map(fn (RecordVersion $v) => [
                'version'               => $v->version,
                'changed_at'            => $this->iso($v->created_at),
                'changed_by'            => $v->user?->name,
                'reason'                => $v->reason,
                'content_before_change' => [
                    'doctor' => $this->doctorName(((array) $v->data)['doctor_id'] ?? null),
                    ...$this->clinicalContent((array) $v->data),
                ],
            ])->values()->all(),
        ])->all();
    }

    /**
     * Campos clínicos do prontuário a partir dos atributos (atuais ou de uma
     * versão): mesmo formato nos dois casos, com nomes no lugar dos ids.
     *
     * @param array<string, mixed> $a
     */
    private function clinicalContent(array $a): array
    {
        $v      = fn (string $key) => $a[$key] ?? null;
        $eyes   = fn (string $right, string $left) => ['od' => $v($right), 'oe' => $v($left)];
        $name   = fn (string $model, string $key) => $this->name($model, $v($key));
        $refrac = fn (string $p) => [
            'od' => ['spherical' => $v("{$p}_spherical_right"), 'cylindrical' => $v("{$p}_cylindrical_right"), 'axis' => $v("{$p}_axis_right")],
            'oe' => ['spherical' => $v("{$p}_spherical_left"), 'cylindrical' => $v("{$p}_cylindrical_left"), 'axis' => $v("{$p}_axis_left")],
        ];

        return [
            'main_complaint' => $v('main_complaint'),
            'hda'            => $v('hda'),
            'anamnesis'      => [
                'diabetic'                => $this->bool($v('diabetic')),
                'diabetic_family'         => $this->bool($v('diabetic_family')),
                'hypertensive'            => $this->bool($v('hypertensive')),
                'hypertensive_family'     => $this->bool($v('hypertensive_family')),
                'glaucomatous'            => $this->bool($v('glaucomatous')),
                'glaucomatous_family'     => $this->bool($v('glaucomatous_family')),
                'ocular_surgical_history' => $v('ocular_surgical_history'),
                'medications_in_use'      => $v('medications_in_use'),
                'other_history'           => $v('others_history'),
            ],
            'exam' => [
                'visual_acuity' => [
                    'scale'              => $name(VisualAcuityType::class, 'visual_acuity_type_id'),
                    'without_correction' => [
                        'od' => $name(VisualAcuityType::class, 'visual_acuity_without_correction_right_id'),
                        'oe' => $name(VisualAcuityType::class, 'visual_acuity_without_correction_left_id'),
                    ],
                    'with_correction' => [
                        'od' => $name(VisualAcuityType::class, 'visual_acuity_with_correction_right_id'),
                        'oe' => $name(VisualAcuityType::class, 'visual_acuity_with_correction_left_id'),
                    ],
                ],
                'ocular_motility'        => $v('ocular_motility'),
                'cover_test'             => $name(CoverTestType::class, 'cover_test_type_id'),
                'near_point_convergence' => $name(NearPointConvergence::class, 'near_point_convergence_id'),
                'color_vision'           => $name(ColorVisionType::class, 'color_vision_type_id'),
                'tonometry'              => [...$eyes('tonometer_right', 'tonometer_left'), 'time' => $v('tonometer_time')],
                'pachymetry'             => $eyes('pachymetry_right', 'pachymetry_left'),
                'gonioscopy'             => $eyes('gonioscopy_right', 'gonioscopy_left'),
                'refraction'             => ['dynamic' => $refrac('dynamic'), 'static' => $refrac('static')],
                'addition'               => $name(AdditionType::class, 'addition_type_id'),
                'lenses'                 => [
                    'distance'    => $this->lensNames($this->list($v('lens_away_ids')), $v('lens_away_id')),
                    'near'        => $this->lensNames($this->list($v('lens_near_ids')), $v('lens_near_id')),
                    'observation' => $v('observation_of_lenses'),
                ],
                'biomicroscopy'       => $eyes('biomicroscopy_right', 'biomicroscopy_left'),
                'fundoscopy'          => $eyes('fundoscopy_right', 'fundoscopy_left'),
                'general_observation' => $v('observation_general'),
            ],
            'diagnosis_cids'           => $this->list($v('diagnosis_cids')),
            'clinical_conduct'         => $v('clinical_conduct'),
            'follow_up_days'           => $v('follow_up_days'),
            'contact_lens_calculation' => $this->list($v('contact_lens_calculation')) ?: null,
        ];
    }

    private function exams(Patient $patient): array
    {
        return PatientExam::query()->with('examType')
            ->where('patient_id', $patient->id)
            ->orderByDesc('exam_performed_at')
            ->get()
            ->map(fn (PatientExam $e) => [
                'code'            => $e->code,
                'name'            => $e->name,
                'type'            => $e->examType?->name,
                'laterality'      => $e->laterality,
                'performed_at'    => $this->iso($e->exam_performed_at),
                'doctor'          => $this->doctorName($e->doctor_id),
                'diagnosis_cids'  => $e->diagnosis_cids ?? [],
                'observation'     => $e->observation,
                'source'          => $this->label($e->source),
                'external_origin' => $e->external_origin,
                'has_file'        => filled($e->archive),
            ])->all();
    }

    private function appointments(Patient $patient): array
    {
        return Schedule::query()->with(['covenant', 'visitType', 'situationLogs'])
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->where('patient_id', $patient->id)
            ->orderByDesc('date_time')
            ->get()
            ->map(fn (Schedule $s) => [
                'code'                => $s->code,
                'date_time'           => $this->iso($s->date_time),
                'doctor'              => $this->doctorName($s->doctor_id),
                'covenant'            => $s->covenant?->name,
                'visit_type'          => $s->visitType?->name,
                'attendance_type'     => $this->label($s->attendance_type),
                'specialty_area'      => $this->label($s->specialty_area),
                'situation'           => $this->label($s->situation),
                'confirmed_at'        => $this->iso($s->confirmed_at),
                'arrived_at'          => $this->iso($s->arrived_at),
                'patient_mood'        => $this->label($s->patient_mood),
                'notes'               => $s->notes,
                'cancellation_reason' => $s->cancellation_reason,
                'contact'             => ['telephone' => $s->telephone, 'cellphone' => $s->cellphone],
                'recurrence'          => ['type' => $s->recurrence_type, 'until' => $this->iso($s->recurrence_until)],
                'situation_history'   => $s->situationLogs->sortBy('created_at')->map(fn (ScheduleSituationLog $l) => [
                    'from'  => $this->label($l->from_situation),
                    'to'    => $this->label($l->to_situation),
                    'at'    => $this->iso($l->created_at),
                    'notes' => $l->notes,
                ])->values()->all(),
            ])->all();
    }

    private function waitingList(Patient $patient): array
    {
        return WaitingList::query()->with(['covenant', 'visitType'])
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->where('patient_id', $patient->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (WaitingList $w) => [
                'created_at'     => $this->iso($w->created_at),
                'doctor'         => $this->doctorName($w->doctor_id),
                'covenant'       => $w->covenant?->name,
                'visit_type'     => $w->visitType?->name,
                'preferred_from' => $this->iso($w->preferred_date_from),
                'preferred_to'   => $this->iso($w->preferred_date_until),
                'notes'          => $w->notes,
                'scheduled_at'   => $this->iso($w->scheduled_at),
                'active'         => (bool) $w->active,
                'contact'        => ['telephone' => $w->telephone, 'cellphone' => $w->cellphone],
            ])->all();
    }

    /** Mensagens de WhatsApp trocadas com o paciente (lembretes, confirmações, pesquisas). */
    private function messages(Patient $patient, Collection $scheduleIds): array
    {
        return WhatsAppMessage::query()
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->whereIn('schedule_id', $scheduleIds)
            ->orderBy('created_at')
            ->get()
            ->map(fn (WhatsAppMessage $m) => [
                'channel'      => 'whatsapp',
                'direction'    => $m->direction,
                'kind'         => $m->kind,
                'phone'        => $m->phone,
                'body'         => $m->body,
                'status'       => $m->status,
                'survey_score' => $m->survey_score,
                'sent_at'      => $this->iso($m->sent_at),
                'answered_at'  => $this->iso($m->answered_at),
            ])->all();
    }

    /** Chamadas do paciente no painel da sala de espera. */
    private function panelCalls(Patient $patient, Collection $scheduleIds): array
    {
        return PatientCall::query()
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->whereIn('schedule_id', $scheduleIds)
            ->orderBy('created_at')
            ->get()
            ->map(fn (PatientCall $c) => ['called_at' => $this->iso($c->created_at), 'doctor' => $c->doctor_name])
            ->all();
    }

    private function financial(Patient $patient): array
    {
        return [
            'insurance_claims' => BillingClaim::query()->with('covenant')
                ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
                ->where('patient_id', $patient->id)
                ->orderByDesc('attendance_date')
                ->get()
                ->map(fn (BillingClaim $c) => [
                    'code'                  => $c->code,
                    'covenant'              => $c->covenant?->name,
                    'guide_number'          => $c->guide_number,
                    'authorization_code'    => $c->authorization_code,
                    'attendance_date'       => $this->iso($c->attendance_date),
                    'doctor'                => $this->doctorName($c->doctor_id),
                    'tuss_code'             => $c->tuss_code,
                    'procedure_description' => $c->procedure_description,
                    'quantity'              => $c->quantity,
                    'unit_price'            => $c->unit_price,
                    'amount'                => $c->amount,
                    'glosa_amount'          => $c->glosa_amount,
                    'paid_amount'           => $c->paid_amount,
                    'paid_at'               => $this->iso($c->paid_at),
                    'status'                => $this->label($c->status),
                    'notes'                 => $c->notes,
                    'cancel_reason'         => $c->cancel_reason,
                    'cancelled_at'          => $this->iso($c->cancelled_at),
                ])->all(),
            'payments' => FinancialCashEntry::query()->with(['covenant', 'procedure'])
                ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
                ->where('patient_id', $patient->id)
                ->orderByDesc('entry_date')
                ->get()
                ->map(fn (FinancialCashEntry $e) => [
                    'code'           => $e->code,
                    'date'           => $this->iso($e->entry_date),
                    'description'    => $e->description,
                    'type'           => $this->label($e->type),
                    'nature'         => $this->label($e->nature),
                    'status'         => $this->label($e->status),
                    'amount'         => $e->amount,
                    'payment_method' => $this->label($e->payment_method),
                    'installments'   => $e->installments,
                    'split'          => ['cash' => $e->amount_cash, 'credit' => $e->amount_credit, 'debit' => $e->amount_debit],
                    'covenant'       => $e->covenant?->name,
                    'procedure'      => $e->procedure?->name,
                    'doctor'         => $this->doctorName($e->doctor_id),
                    'notes'          => $e->notes,
                ])->all(),
        ];
    }

    /** Guias TISS enviadas ao convênio, com itens e glosas (sem o XML/payload técnico). */
    private function tissGuides(Patient $patient): array
    {
        return TissGuide::query()->with(['operator', 'items.glosas'])
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->where('patient_id', $patient->id)
            ->orderByDesc('attendance_date')
            ->get()
            ->map(fn (TissGuide $g) => [
                'guide_type'            => $this->label($g->guide_type),
                'operator'              => $g->operator?->name,
                'guide_number_provider' => $g->guide_number_provider,
                'guide_number_operator' => $g->guide_number_operator,
                'authorization_number'  => $g->authorization_number,
                'status'                => $this->label($g->status),
                'attendance_date'       => $this->iso($g->attendance_date),
                'execution_date'        => $this->iso($g->execution_date),
                'doctor'                => $this->doctorName($g->doctor_id),
                'beneficiary'           => [
                    'card_number' => $g->beneficiary_card_number,
                    'name'        => $g->beneficiary_name,
                    'plan'        => $g->beneficiary_plan,
                ],
                'clinical_indication' => $g->clinical_indication,
                'total_amount'        => $g->total_amount,
                'denied_amount'       => $g->denied_amount,
                'paid_amount'         => $g->paid_amount,
                'items'               => $g->items->map(fn (TissGuideItem $i) => [
                    'tuss_code'            => $i->tuss_code,
                    'procedure_code'       => $i->procedure_code,
                    'description'          => $i->description,
                    'quantity'             => $i->quantity,
                    'unit_amount'          => $i->unit_amount,
                    'total_amount'         => $i->total_amount,
                    'execution_date'       => $this->iso($i->execution_date),
                    'authorization_number' => $i->authorization_number,
                    'status'               => $this->label($i->status),
                    'glosas'               => $i->glosas->map(fn (TissGlosa $gl) => [
                        'code'          => $gl->glosa_code,
                        'description'   => $gl->glosa_description,
                        'amount'        => $gl->amount,
                        'status'        => $this->label($gl->status),
                        'identified_at' => $this->iso($gl->identified_at),
                        'resolved_at'   => $this->iso($gl->resolved_at),
                    ])->values()->all(),
                ])->values()->all(),
            ])->all();
    }

    /**
     * Uso de IA sobre os dados do paciente (art. 18, VII e art. 20): com quem
     * foram compartilhados (provedores), o que foi enviado e o que voltou.
     * Prompt de sistema e guardrails ficam fora (segredo comercial, art. 19).
     */
    private function aiProcessing(Patient $patient): array
    {
        return AiRun::query()->with('providerCalls')
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->where('patient_id', $patient->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AiRun $run) => [
                'workflow'     => $run->workflow,
                'mode'         => $this->label($run->mode),
                'status'       => $this->label($run->status),
                'requested_at' => $this->iso($run->created_at),
                'approved_at'  => $this->iso($run->approved_at),
                'rejected_at'  => $this->iso($run->rejected_at),
                'shared_with'  => $run->providerCalls
                    ->map(fn ($call) => ['provider' => $call->provider, 'model' => $call->model])
                    ->unique(fn (array $p) => $p['provider'] . '|' . $p['model'])->values()->all(),
                'data_sent' => [
                    'question'   => $run->input_summary['user_prompt'] ?? null,
                    'context'    => $run->input_summary['context'] ?? [],
                    'exam_count' => count((array) ($run->input_summary['exam_ids'] ?? [])),
                ],
                'output'       => $run->final_output,
                'safety_notes' => $run->safety_notes ?? [],
            ])->all();
    }

    private function consents(Patient $patient): array
    {
        return $patient->consents()->get()->map(fn (PatientConsent $c) => [
            'type'              => $c->consent_type->label(),
            'legal_basis'       => $this->label($c->legal_basis),
            'status'            => $c->status,
            'document_version'  => $c->document_version,
            'channel'           => $c->channel,
            'granted_at'        => $this->iso($c->granted_at),
            'revoked_at'        => $this->iso($c->revoked_at),
            'revocation_reason' => $c->revocation_reason,
        ])->toArray();
    }

    /** Documentos liberados para o paciente no Portal. */
    private function documentShares(Patient $patient): array
    {
        return PatientDocumentShare::query()
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->where('patient_id', $patient->id)
            ->orderBy('granted_at')
            ->get()
            ->map(fn (PatientDocumentShare $s) => [
                'document_type'     => class_basename($s->shareable_type),
                'granted_at'        => $this->iso($s->granted_at),
                'revoked_at'        => $this->iso($s->revoked_at),
                'revocation_reason' => $s->revocation_reason,
            ])->all();
    }

    private function lgpdRequests(Patient $patient): array
    {
        return LgpdRequest::query()
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->where('patient_id', $patient->id)
            ->orderBy('requested_at')
            ->get()
            ->map(fn (LgpdRequest $r) => [
                'type'             => $this->label($r->request_type),
                'status'           => $this->label($r->status),
                'requester'        => ['name' => $r->requester_name, 'email' => $r->requester_email, 'document' => $r->requester_document],
                'description'      => $r->description,
                'requested_at'     => $this->iso($r->requested_at),
                'deadline_at'      => $this->iso($r->deadline_at),
                'responded_at'     => $this->iso($r->responded_at),
                'response'         => $r->response,
                'rejection_reason' => $r->rejection_reason,
            ])->all();
    }

    /** LGPD art. 9º — transparência sobre o tratamento (quantos acessos e para quê). */
    private function accessLogSummary(Patient $patient): array
    {
        return [
            'total_accesses'   => $patient->accessLogs()->count(),
            'last_accessed_at' => $this->iso($patient->accessLogs()->max('accessed_at')),
            'by_purpose'       => $patient->accessLogs()->toBase()->selectRaw('purpose, count(*) as total')
                ->groupBy('purpose')->pluck('total', 'purpose')->map(fn ($total) => (int) $total)->all(),
        ];
    }

    /** Declaração do que NÃO está no arquivo e por quê (LGPD art. 19, II). */
    private function exportScope(): array
    {
        $excluded = ['binary_files', 'internal_instructions', 'technical_records', 'professional_compensation', 'deleted_records', 'other_clinics'];

        return [
            'clinic_only'  => __('lgpd_export.scope.clinic_only'),
            'not_included' => array_map(
                fn (string $key) => ['category' => $key, 'reason' => __("lgpd_export.scope.not_included.{$key}")],
                $excluded,
            ),
        ];
    }

    // ── auxiliares ──────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $attributeSets atributos de prontuários e versões */
    private function loadClinicalNames(array $attributeSets): void
    {
        $columns = [
            VisualAcuityType::class     => ['visual_acuity_type_id', 'visual_acuity_without_correction_right_id', 'visual_acuity_without_correction_left_id', 'visual_acuity_with_correction_right_id', 'visual_acuity_with_correction_left_id'],
            CoverTestType::class        => ['cover_test_type_id'],
            NearPointConvergence::class => ['near_point_convergence_id'],
            ColorVisionType::class      => ['color_vision_type_id'],
            AdditionType::class         => ['addition_type_id'],
            Lense::class                => ['lens_away_id', 'lens_near_id'],
        ];

        foreach ($columns as $model => $keys) {
            $ids = [];

            foreach ($attributeSets as $a) {
                foreach ($keys as $key) {
                    $ids[] = $a[$key] ?? null;
                }

                if ($model === Lense::class) {
                    array_push($ids, ...$this->list($a['lens_away_ids'] ?? null), ...$this->list($a['lens_near_ids'] ?? null));
                }
            }
            $ids = array_values(array_unique(array_filter($ids, fn ($id) => filled($id))));

            // Item de catálogo desativado/excluído depois: o nome ainda vale.
            $this->names[$model] = $ids === [] ? [] : $model::withoutGlobalScopes()->whereIn('id', $ids)
                ->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all();
        }
    }

    private function name(string $model, mixed $id): ?string
    {
        return filled($id) ? ($this->names[$model][(string) $id] ?? null) : null;
    }

    private function doctorName(mixed $id): ?string
    {
        if (blank($id)) {
            return null;
        }

        $key = (string) $id;

        if (! array_key_exists($key, $this->names[Doctor::class] ?? [])) {
            $this->names[Doctor::class][$key] = Doctor::withoutGlobalScopes()->with('person')->find($id)?->person?->full_name;
        }

        return $this->names[Doctor::class][$key];
    }

    /** Características de lente (array novo; fallback para o id único legado). */
    private function lensNames(array $ids, mixed $legacyId): ?string
    {
        $ids   = $ids !== [] ? $ids : array_filter([$legacyId]);
        $names = array_filter(array_map(fn ($id) => $this->name(Lense::class, $id), $ids));

        return $names === [] ? null : implode(' + ', $names);
    }

    /** JSON (atributo cru) ou array (versão/cast) → array. */
    private function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    private function bool(mixed $value): ?bool
    {
        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    private function label(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum && method_exists($value, 'label') => $value->label(),
            $value instanceof BackedEnum                                   => $value->value,
            default                                                        => $value,
        };
    }

    private function iso(mixed $value): ?string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            filled($value)                      => (string) $value,
            default                             => null,
        };
    }
}
