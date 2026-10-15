<?php

declare(strict_types=1);

namespace App\Services\Lgpd;

use App\Domains\AI\Models\AiRun;
use App\Domains\CareGuidance\Models\CareGuidanceIssue;
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Models\{PatientCondition, PatientRecall, PatientRecallBlock, PatientRecallEvent, RecallInvite};
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
    PatientAllergy,
    PatientCall,
    PatientConsent,
    PatientDocumentShare,
    PatientExam,
    PatientMedication,
    People,
    RecordVersion,
    Schedule,
    ScheduleSituationLog,
    UrgentCareEntry,
    User,
    VisualAcuityType,
    WaitingList
};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use App\Services\ContactLensFormatter;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\ContactLensUse;
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
    public const FORMAT_VERSION = 6; // 6: cirurgias — pedido, autorização, documentos, implante, pós-operatório (Fase 4); 5: retornos/recall e convites por WhatsApp, curso de injeções intravítreas, orientações pós-procedimento e pronto atendimento (Fase 6); 4: alergias, medicações em uso, retrato na consulta e uso de LC (Fase 2)

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
            'clinical_safety'    => $this->clinicalSafety($patient),
            'portal_account'     => $this->portalAccount($patient->person),
            'medical_records'    => $this->medicalRecords($patient),
            'exams'              => $this->exams($patient),
            'appointments'       => $this->appointments($patient),
            'waiting_list'       => $this->waitingList($patient),
            'urgent_care'        => $this->urgentCare($patient),
            'recall'             => $this->recall($patient),
            'intravitreal'       => $this->intravitreal($patient),
            'care_guidance'      => $this->careGuidance($patient),
            'messages'           => $this->messages($patient, $scheduleIds),
            'whatsapp_opt_outs'  => $this->whatsappOptOuts($patient, $scheduleIds),
            'panel_calls'        => $this->panelCalls($patient, $scheduleIds),
            'financial'          => $this->financial($patient),
            'tiss_guides'        => $this->tissGuides($patient),
            'surgery'            => app(SurgeryDataExporter::class)->export($patient),
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

    /**
     * Alergias (inclusive descartadas — histórico), "nega alergias" e
     * medicações em uso (inclusive suspensas/descartadas) da clínica.
     */
    private function clinicalSafety(Patient $patient): array
    {
        $users = fn (array $ids) => User::query()->whereIn('id', array_filter($ids))->pluck('name', 'id');

        $allergies = PatientAllergy::query()->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)->where('patient_id', $patient->id)
            ->whereNull('deleted_at')->orderBy('created_at')->get();
        $medications = PatientMedication::query()->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)->where('patient_id', $patient->id)
            ->whereNull('deleted_at')->orderBy('created_at')->get();

        $names = $users([
            $patient->allergies_denied_by,
            ...$allergies->flatMap(fn (PatientAllergy $a) => [$a->created_by, $a->confirmed_by, $a->discarded_by])->all(),
            ...$medications->flatMap(fn (PatientMedication $m) => [$m->created_by, $m->confirmed_by, $m->discarded_by])->all(),
        ]);
        $who = fn (mixed $id) => filled($id) ? ($names[(string) $id] ?? null) : null;

        return [
            'allergies_denied' => $patient->allergies_denied_at ? [
                'at' => $this->iso($patient->allergies_denied_at),
                'by' => $who($patient->allergies_denied_by),
            ] : null,
            'allergies' => $allergies->map(fn (PatientAllergy $a) => [
                'category'          => $this->label($a->category),
                'substance'         => $a->substance,
                'active_ingredient' => $a->active_ingredient,
                'reaction'          => $a->reaction,
                'severity'          => $this->label($a->severity),
                'status'            => $this->label($a->status),
                'notes'             => $a->notes,
                'registered_at'     => $this->iso($a->created_at),
                'registered_by'     => $who($a->created_by),
                'confirmed_at'      => $this->iso($a->confirmed_at),
                'confirmed_by'      => $who($a->confirmed_by),
                'discarded_at'      => $this->iso($a->discarded_at),
                'discarded_by'      => $who($a->discarded_by),
                'discard_reason'    => $a->discard_reason,
            ])->values()->all(),
            'medications' => $medications->map(fn (PatientMedication $m) => [
                'name'              => $m->name,
                'active_ingredient' => $m->active_ingredient,
                'posology'          => $m->posology,
                'started_on'        => $m->started_on?->toDateString(),
                'ended_on'          => $m->ended_on?->toDateString(),
                'status'            => $this->label($m->status),
                'source'            => $this->label($m->source),
                'notes'             => $m->notes,
                'registered_at'     => $this->iso($m->created_at),
                'registered_by'     => $who($m->created_by),
                'confirmed_at'      => $this->iso($m->confirmed_at),
                'confirmed_by'      => $who($m->confirmed_by),
                'discarded_at'      => $this->iso($m->discarded_at),
            ])->values()->all(),
        ];
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
        $files      = $byRecord(MedicalRecordFile::class);
        $evolutions = $byRecord(MedicalRecordEvolution::class);
        $procedures = $byRecord(MedicalRecordProcedure::class, ['procedure']);
        $versions   = RecordVersion::query()->with('user')
            ->where('versionable_type', MedicalRecord::class)->whereIn('versionable_id', $ids)
            ->orderBy('version')->get()->groupBy('versionable_id');

        // Documentação: NUNCA via get()/cursor() sobre as linhas inteiras. O
        // metadata de um laudo com imagem embutida chega a ~27 MiB em base64 e
        // o PDO_PGSQL (libpq) lê o resultado INTEIRO de uma consulta pra
        // memória nativa do processo no execute() — cursor() só entrega as
        // linhas uma a uma DEPOIS disso, então o histórico todo ficava
        // residente antes de qualquer redação (invisível pro memory_limit e
        // pro memory_get_peak_usage(), fatal pro contêiner; achado via revisão
        // adversarial Codex). Aqui, uma consulta leve pelos ids e UMA linha por
        // consulta (chave primária): o que fica residente é um laudo por vez.
        $documentations = $this->documentationsByRecord($ids, $patient);

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
            'documentations' => $documentations[$r->id] ?? [],
            'files'          => ($files[$r->id] ?? collect())->map(fn (MedicalRecordFile $f) => [
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
     * Documentações do paciente já na forma exportada (e redigida), por
     * prontuário e em ordem cronológica. Só o array pequeno de cada uma fica
     * retido — o model cru, com o metadata inteiro, é solto a cada volta.
     *
     * Ids primeiro (linhas minúsculas, sem metadata), depois UMA consulta por
     * chave primária por documentação. Não paginar por `id > ?` (lazyById):
     * numa tabela compartilhada entre clínicas o planejador pode varrer o
     * índice da chave primária inteiro a cada página.
     *
     * @param Collection<int, string> $recordIds
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function documentationsByRecord(Collection $recordIds, Patient $patient): array
    {
        $refs = MedicalRecordDocumentation::query()
            ->whereIn('medical_record_id', $recordIds)->where('patient_id', $patient->id)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'medical_record_id']);

        $byRecord = [];

        foreach ($refs as $ref) {
            $documentation = MedicalRecordDocumentation::query()->find($ref->id);

            if ($documentation === null) { // removida entre as duas leituras
                continue;
            }

            $byRecord[$ref->medical_record_id][] = $this->documentationData($documentation);

            unset($documentation);
        }

        return $byRecord;
    }

    /**
     * Forma exportada de UMA documentação (já redigida) — chamada uma vez por
     * documentação em documentationsByRecord(), nunca sobre o conjunto.
     *
     * @return array<string, mixed>
     */
    private function documentationData(MedicalRecordDocumentation $d): array
    {
        return [
            'type'  => $d->getTypeLabel(),
            'title' => $d->title,
            // contentWithoutDateHeader() (NUNCA contentForRender()): este
            // último injeta de volta o byte da imagem inline embutida
            // (metadata.inline_images) no HTML — ignorando por completo a
            // redação de redactImageReportBytes() abaixo (achado via
            // revisão adversarial Codex). O export mantém só a referência
            // (data-exam-image-id), igual ao resto do metadata redigido.
            'content'           => $d->contentWithoutDateHeader(),
            'generated_with_ai' => filled($d->ai_run_id),
            // Receita emitida apesar de alergia ativa: motivo e conflitos.
            'allergy_override' => is_array($d->safety_override) ? [
                'reason'    => $d->safety_override['reason'] ?? null,
                'conflicts' => $d->safety_override['conflicts'] ?? [],
                'at'        => $d->safety_override['at'] ?? null,
            ] : null,
            'created_at' => $this->iso($d->created_at),
            // Fase 2 / Bloco B: data impressa (data alternativa) e dados
            // estruturados (atestado, CID autorizado, itens do pedido).
            'document_date' => $d->document_date?->toDateString(),
            // Bytes do laudo de imagem embutido nunca entram aqui (até
            // ~27 MiB em base64 por laudo esgotava o memory_limit com
            // poucos laudos no histórico) — mesma regra já aplicada a
            // foto/vídeo de exame nesta exportação (ver exams(), só
            // has_file, nunca os bytes). Resto do metadata intacto.
            'details' => MedicalRecordDocumentation::redactImageReportBytes($d->metadata),
        ];
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
            'diagnosis_cids'   => $this->list($v('diagnosis_cids')),
            'clinical_conduct' => $v('clinical_conduct'),
            'follow_up_days'   => $v('follow_up_days'),
            // Retrato no momento da consulta (Fase 2): alergias e medicações
            // ativas; uso de lente de contato da anamnese.
            'allergies_snapshot'       => $this->list($v('allergies_snapshot')) ?: null,
            'medications_snapshot'     => $v('medications_snapshot') === null ? null : $this->list($v('medications_snapshot')),
            'contact_lens_use'         => $this->list($v('contact_lens_use')) ?: null,
            'contact_lens_use_summary' => ContactLensUse::text($this->list($v('contact_lens_use')) ?: null),
            'contact_lens_calculation' => $this->list($v('contact_lens_calculation')) ?: null,
            // Em texto: v2 = potência de LC sugerida por olho (e o tipo); v1 = formato antigo.
            'contact_lens_summary' => app(ContactLensFormatter::class)->text($this->list($v('contact_lens_calculation')) ?: null),
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

    /** Pronto atendimento: a chegada espontânea, o motivo (dado de saúde) e o desfecho. */
    private function urgentCare(Patient $patient): array
    {
        return UrgentCareEntry::query()
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->where('patient_id', $patient->id)
            ->orderBy('arrived_at')
            ->get()
            ->map(fn (UrgentCareEntry $e) => [
                'reason'         => $this->label($e->reason),
                'reason_details' => $e->reason_details,
                'priority'       => $this->label($e->priority),
                'status'         => $this->label($e->status),
                'arrived_at'     => $this->iso($e->arrived_at),
                'called_at'      => $this->iso($e->called_at),
                'attended_at'    => $this->iso($e->attended_at),
                'attended_by'    => $this->doctorName($e->attended_by_doctor_id),
                'closed_at'      => $this->iso($e->closed_at),
                'closed_reason'  => $e->closed_reason,
            ])->all();
    }

    /**
     * Retornos e recall (Fase 6): as condições acompanhadas, o que falta para o paciente (com o que a clínica fez —
     * contato, adiamento, "feito fora"), o histórico de cada retorno, os bloqueios de contato e os convites por
     * WhatsApp (a mensagem em si está em `messages`).
     */
    private function recall(Patient $patient): array
    {
        $scope = fn ($q) => $q->where('entity_id', $patient->entity_id)->where('patient_id', $patient->id);

        $conditions = PatientCondition::query()->withoutGlobalScopes()->where($scope)->orderBy('created_at')->get();
        $recalls    = PatientRecall::query()->withoutGlobalScopes()->where($scope)->orderBy('due_at')->get();
        $events     = PatientRecallEvent::query()->withoutGlobalScopes()->where($scope)->orderBy('created_at')->get()->groupBy('recall_id');
        $invites    = RecallInvite::query()->withoutGlobalScopes()->where($scope)->with('recalls:id')->orderBy('created_at')->get();
        $blocks     = PatientRecallBlock::query()->withoutGlobalScopes()->where($scope)->orderBy('created_at')->get();

        return [
            'conditions' => $conditions->map(fn (PatientCondition $c) => [
                'protocol'         => $c->protocol_key,
                'eye'              => $this->label($c->eye),
                'status'           => $this->label($c->status),
                'origin'           => $this->label($c->origin),
                'started_on'       => $this->iso($c->started_on),
                'evidence'         => $c->evidence,
                'confirmed_at'     => $this->iso($c->confirmed_at),
                'discharged_at'    => $this->iso($c->discharged_at),
                'discharge_reason' => $c->discharge_reason,
                'rejected_at'      => $this->iso($c->rejected_at),
            ])->all(),
            'returns' => $recalls->map(fn (PatientRecall $r) => [
                'source'               => $r->source,
                'protocol'             => $r->protocol_key,
                'task'                 => $r->task_key,
                'kind'                 => $r->kind,
                'exam'                 => $r->exam_key,
                'eye'                  => $this->label($r->eye),
                'last_done_at'         => $this->iso($r->last_done_at),
                'due_at'               => $this->iso($r->due_at),
                'situation'            => $this->label($r->lifecycle),
                'closed_reason'        => $r->closed_reason,
                'snoozed_until'        => $this->iso($r->snoozed_until),
                'dismissed_reason'     => $r->dismissed_reason,
                'last_contact_at'      => $this->iso($r->last_contact_at),
                'last_contact_outcome' => $r->last_contact_outcome,
                'contact_count'        => $r->contact_count,
                'done_elsewhere_at'    => $this->iso($r->external_done_at),
                'history'              => ($events[$r->id] ?? collect())->map(fn (PatientRecallEvent $e) => [
                    'event' => $e->event,
                    'by'    => $e->actor_label,
                    'meta'  => $e->meta,
                    'at'    => $this->iso($e->created_at),
                ])->values()->all(),
            ])->all(),
            'contact_blocks' => $blocks->map(fn (PatientRecallBlock $b) => [
                'reason'      => $this->label($b->reason),
                'note'        => $b->note,
                'created_at'  => $this->iso($b->created_at),
                'released_at' => $this->iso($b->released_at),
            ])->all(),
            'whatsapp_invites' => $invites->map(fn (RecallInvite $i) => [
                'origin'       => $this->label($i->origin),
                'day'          => explode(':', (string) $i->wave_key)[0] ?? null,
                'response'     => $this->label($i->response),
                'responded_at' => $this->iso($i->responded_at),
                'sent_at'      => $this->iso($i->created_at),
                'returns'      => $i->recalls->count(),
            ])->all(),
        ];
    }

    /** Tratamento com injeções intravítreas (Fase 6): um curso por olho, com cada aplicação (lote, autorização, intervalo). */
    private function intravitreal(Patient $patient): array
    {
        $applications = IntravitrealApplication::query()->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)->where('patient_id', $patient->id)
            ->orderBy('sequence_no')->get()->groupBy('course_id');

        return IntravitrealCourse::query()->withoutGlobalScopes()->withTrashed()
            ->where('entity_id', $patient->entity_id)->where('patient_id', $patient->id)
            ->orderBy('started_on')->get()
            ->map(fn (IntravitrealCourse $c) => [
                'eye'            => $c->eye,
                'drug'           => $c->drug_label,
                'indication'     => $c->indication,
                'regimen'        => $this->label($c->regimen),
                'status'         => $this->label($c->status),
                'started_on'     => $this->iso($c->started_on),
                'ended_on'       => $this->iso($c->ended_on),
                'end_reason'     => $c->end_reason,
                'interval_weeks' => $c->current_interval_weeks,
                'next_due_on'    => $this->iso($c->next_due_on),
                'responsible'    => $this->doctorName($c->responsible_doctor_id),
                'applications'   => ($applications[$c->id] ?? collect())->map(fn (IntravitrealApplication $a) => [
                    'number'                    => $a->sequence_no,
                    'phase'                     => $a->phase,
                    'status'                    => $this->label($a->status),
                    'planned_for'               => $this->iso($a->planned_for),
                    'performed_at'              => $this->iso($a->performed_at),
                    'performed_by'              => $this->doctorName($a->performed_by_doctor_id),
                    'drug'                      => $a->drug_label,
                    'lot_number'                => $a->lot_number,
                    'lot_expiry'                => $this->iso($a->lot_expiry),
                    'authorization_required'    => (bool) $a->authorization_required,
                    'authorization_status'      => $this->label($a->authorization_status),
                    'authorization_number'      => $a->authorization_number,
                    'authorization_valid_until' => $this->iso($a->authorization_valid_until),
                    'authorization_notes'       => $a->authorization_notes,
                    'decided_interval_weeks'    => $a->decided_interval_weeks,
                    'notes'                     => $a->notes,
                ])->values()->all(),
            ])->all();
    }

    /**
     * Orientações pós-procedimento entregues (Fase 6): o retrato congelado — título, texto, local, colírios e os PDFs
     * (só o nome, o tamanho e a impressão digital: o arquivo em si não vai no JSON).
     */
    private function careGuidance(Patient $patient): array
    {
        return CareGuidanceIssue::query()->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)->where('patient_id', $patient->id)
            ->orderBy('issued_at')->get()
            ->map(fn (CareGuidanceIssue $i) => [
                'title'       => $i->title,
                'issued_at'   => $this->iso($i->issued_at),
                'doctor'      => $this->doctorName($i->doctor_id),
                'eye'         => $i->eye,
                'location'    => $i->location_label,
                'text'        => $i->body,
                'eye_drops'   => $i->eye_drops ?? [],
                'attachments' => collect($i->attachments ?? [])->map(fn (array $f) => [
                    'name'       => $f['name'] ?? null,
                    'size_bytes' => $f['size_bytes'] ?? null,
                    'sha256'     => $f['sha256'] ?? null,
                ])->values()->all(),
                'content_hash' => $i->content_hash,
            ])->all();
    }

    /** Mensagens de WhatsApp trocadas com o paciente (lembretes, confirmações, pesquisas, convites de retorno). */
    private function messages(Patient $patient, Collection $scheduleIds): array
    {
        return WhatsAppMessage::query()
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            // Lembretes/confirmações/pesquisas vêm do agendamento; o convite de retorno (Fase 6) não tem agendamento:
            // ele é do paciente (`patient_id`), e a resposta do paciente ao convite também.
            ->where(fn ($q) => $q->whereIn('schedule_id', $scheduleIds)->orWhere('patient_id', $patient->id))
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
                'delivered_at' => $this->iso($m->delivered_at),
                'read_at'      => $this->iso($m->read_at),
                'answered_at'  => $this->iso($m->answered_at),
            ])->all();
    }

    /**
     * Descadastros do WhatsApp (respondeu SAIR ou o provedor recusou) dos
     * celulares do paciente — do cadastro e dos agendamentos — no número da
     * clínica e no do EasyEye (o que envia para ela). SAIR no número do
     * EasyEye vale para todas as clínicas que usam esse número.
     */
    private function whatsappOptOuts(Patient $patient, Collection $scheduleIds): array
    {
        $phones = Schedule::withTrashed()->whereIn('id', $scheduleIds)->pluck('cellphone')
            ->push($patient->person?->cellphone)
            ->map(fn ($raw) => WhatsAppService::normalizePhone($raw))
            ->filter()
            ->unique()
            ->values();

        if ($phones->isEmpty()) {
            return [];
        }

        $senders = WhatsAppSetting::query()
            ->where(fn ($q) => $q->where('entity_id', $patient->entity_id)->orWhereNull('entity_id'))
            ->get(['id', 'entity_id'])
            ->keyBy('id');

        return WhatsAppOptOut::query()
            ->whereIn('whatsapp_setting_id', $senders->keys())
            ->whereIn('phone', $phones)
            ->orderBy('opted_out_at')
            ->get()
            ->map(fn (WhatsAppOptOut $o) => [
                'channel'      => 'whatsapp',
                'sender'       => $senders->get($o->whatsapp_setting_id)?->entity_id === null ? 'easyeye' : 'clinic',
                'phone'        => $o->phone,
                'source'       => $o->source,
                'opted_out_at' => $this->iso($o->opted_out_at),
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
