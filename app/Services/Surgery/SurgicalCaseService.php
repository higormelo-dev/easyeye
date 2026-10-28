<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\{AuthorizationStatus, SurgeryEye, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\{Covenant, Doctor, EntityProduct, Patient, PatientExam, Procedure};
use App\Models\Surgery\{SurgeryFacility, SurgicalCase, SurgicalCaseExam, SurgicalCaseIolPlan, SurgicalCaseMaterial, SurgicalCaseProcedure, SurgicalTeamMember};
use App\Models\Surgery\SurgicalImplant;
use App\Models\SurgeryType;
use App\Services\ClinicalSafety\PatientClinicalSafetyService;
use App\Support\CurrentDoctor;
use App\Support\Surgery\CanonicalHash;
use BackedEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{Auth, DB};
use LogicException;

/**
 * Ciclo de vida do caso cirúrgico (ADR 0003): cadastro, edição por grupo de
 * campos, retrato de segurança clínica, cancelar/suspender/retomar e a
 * primitiva `changeStatus()` que os demais services (autorização, agenda,
 * cirurgia, alta) usam dentro da PRÓPRIA transação.
 *
 * Concorrência: toda mudança trava a linha do caso (`FOR UPDATE`), relê e
 * valida o estado ATUAL — nunca o da instância carregada pelo route binding.
 * `revision` sobe a cada escrita e é o token da tela (409 se ficou velho).
 *
 * Ordem de locks (ADR §5): advisory locks da janela → linha do caso → filhos
 * → estoque. Este service só toma a linha do caso e os filhos.
 */
class SurgicalCaseService
{
    /** Pedido (o que a operadora autoriza): só no rascunho ou depois de negado. */
    private const REQUEST_FIELDS = [
        'title', 'eye', 'surgery_type_id', 'covenant_id', 'covenant_card_number', 'regime', 'urgency',
        'cid_codes', 'clinical_indication', 'days_requested', 'needs_opme',
    ];

    /** Planejamento que muda a janela/local: bloqueado com a cirurgia agendada. */
    private const PLANNING_FIELDS = ['surgeon_doctor_id', 'surgery_facility_id', 'estimated_duration_minutes'];

    public function __construct(private readonly PatientClinicalSafetyService $safety)
    {
    }

    // ── Leitura sob trava ───────────────────────────────────────────────────

    /** Linha do caso travada (FOR UPDATE). Só dentro de transação. */
    public function lock(string $caseId): SurgicalCase
    {
        $this->assertInTransaction();

        return SurgicalCase::query()->whereKey($caseId)->lockForUpdate()->firstOrFail();
    }

    // ── Cadastro e edição ───────────────────────────────────────────────────

    /**
     * Cria o caso em rascunho. `$data` traz os campos do pedido e, opcionais,
     * as listas `procedures`, `materials`, `iol_plans`, `exams` e `team`.
     *
     * @param array<string, mixed> $data
     */
    public function create(string $entityId, array $data): SurgicalCase
    {
        return DB::transaction(function () use ($entityId, $data): SurgicalCase {
            $patient = $this->patient($entityId, (string) $data['patient_id']);
            $surgeon = $this->surgeon($entityId, (string) $data['surgeon_doctor_id']);

            $case = new SurgicalCase(Arr::only($data, (new SurgicalCase())->getFillable()));
            $case->forceFill([
                'entity_id'            => $entityId,
                'patient_id'           => $patient->getKey(),
                'surgeon_doctor_id'    => $surgeon->getKey(),
                'status'               => SurgicalCaseStatus::Draft,
                'authorization_status' => AuthorizationStatus::NotRequested,
                'status_history'       => [],
                'cid_codes'            => $this->normalizeCids($data['cid_codes'] ?? []),
                'revision'             => 1,
            ]);

            $this->assertReferences($entityId, $case, $patient);

            // Convênio do paciente é o padrão quando o pedido não diz outro.
            if (blank($case->covenant_id) && $patient->covenant_id !== null) {
                $case->covenant_id = $patient->covenant_id;
            }

            if (blank($case->covenant_card_number)) {
                $case->covenant_card_number = $patient->card_number;
            }

            $case->save();
            $this->appendHistory($case, null, SurgicalCaseStatus::Draft, null);
            $case->save();

            $this->syncChildren($case, $data, replaceRequest: true, includeClinical: true);
            $this->captureSafetySnapshot($case, $patient);

            return $case->refresh();
        });
    }

    /**
     * Edita o caso. Cada grupo de campos respeita o estado ATUAL (relido sob a
     * trava): alterar o que o estado trava é recusado; reenviar o mesmo valor
     * não é (a tela manda o formulário inteiro).
     *
     * @param array<string, mixed> $data
     */
    public function update(SurgicalCase $case, array $data, ?int $expectedRevision = null): SurgicalCase
    {
        return DB::transaction(function () use ($case, $data, $expectedRevision): SurgicalCase {
            $locked = $this->lock((string) $case->getKey());

            $this->assertRevision($locked, $expectedRevision);

            if (! $locked->status->allowsAnyChange()) {
                throw SurgeryRuleException::state('surgery.errors.case_closed');
            }

            $patient = $this->patient((string) $locked->entity_id, (string) $locked->patient_id);

            $incoming = Arr::only($data, array_merge(
                (new SurgicalCase())->getFillable(),
                ['cid_codes', 'surgeon_doctor_id', 'surgery_facility_id'],
            ));

            if (array_key_exists('cid_codes', $incoming)) {
                $incoming['cid_codes'] = $this->normalizeCids($incoming['cid_codes']);
            }

            $requestChange  = $this->changed($locked, Arr::only($incoming, self::REQUEST_FIELDS));
            $planningChange = $this->changed($locked, Arr::only($incoming, self::PLANNING_FIELDS));
            $replaceRequest = array_key_exists('procedures', $data) || array_key_exists('materials', $data);

            if (($requestChange !== [] || $replaceRequest) && ! $locked->status->allowsRequestChanges()) {
                throw SurgeryRuleException::state('surgery.errors.request_locked', ['status' => $locked->status->label()]);
            }

            if ($planningChange !== [] && $locked->status === SurgicalCaseStatus::Scheduled) {
                throw SurgeryRuleException::state('surgery.errors.planning_locked_while_scheduled');
            }

            // Médicos da equipe entram na disputa da janela (SurgeryScheduleService): com a cirurgia
            // AGENDADA o conjunto não muda aqui, senão um médico de outro caso ou com consulta no
            // horário viraria auxiliar sem conflito nem trava. Libere a vaga, ajuste e agende de novo.
            if ($locked->status === SurgicalCaseStatus::Scheduled
                && array_key_exists('team', $data)
                && $this->teamDoctorsChanged($locked, (array) $data['team'])) {
                throw SurgeryRuleException::state('surgery_schedule.errors.team_locked_while_scheduled');
            }

            // O paciente nunca troca: documento, autorização e estoque já o citam.
            unset($incoming['patient_id']);

            $locked->fill(Arr::except($incoming, ['surgeon_doctor_id', 'surgery_facility_id']));

            if (array_key_exists('surgeon_doctor_id', $incoming)) {
                $locked->surgeon_doctor_id = $this->surgeon((string) $locked->entity_id, (string) $incoming['surgeon_doctor_id'])->getKey();
            }

            if (array_key_exists('surgery_facility_id', $incoming)) {
                $locked->surgery_facility_id = $incoming['surgery_facility_id'] ?: null;
            }

            if (array_key_exists('cid_codes', $incoming)) {
                $locked->cid_codes = $incoming['cid_codes'];
            }

            $this->assertReferences((string) $locked->entity_id, $locked, $patient);

            $locked->revision = $locked->revision + 1;
            $locked->save();

            $this->syncChildren($locked, $data, replaceRequest: $replaceRequest, includeClinical: true);

            return $locked->refresh();
        });
    }

    /** Exclui um RASCUNHO (nunca visto pela operadora). Qualquer outro estado cancela. */
    public function destroyDraft(SurgicalCase $case): void
    {
        DB::transaction(function () use ($case): void {
            $locked = $this->lock((string) $case->getKey());

            if ($locked->status !== SurgicalCaseStatus::Draft || $locked->requested_at !== null) {
                throw SurgeryRuleException::state('surgery.errors.only_draft_can_be_deleted');
            }

            $locked->delete();
        });
    }

    // ── Retrato de segurança clínica ────────────────────────────────────────

    /**
     * Congela alergias, medicações ativas e alertas de risco ocular (IFIS,
     * anticoagulantes...) no caso. Repetido no pedido de autorização e na
     * conferência pré-cirúrgica: o que vale é o retrato mais recente.
     */
    public function captureSafetySnapshot(SurgicalCase $case, ?Patient $patient = null): SurgicalCase
    {
        $patient ??= $this->patient((string) $case->entity_id, (string) $case->patient_id);

        $summary = $this->safety->summary($patient, false);

        $case->forceFill([
            'clinical_safety_snapshot' => [
                ...$this->safety->snapshot($patient),
                'risk_alerts' => $summary['risk_alerts'] ?? [],
            ],
            'clinical_safety_snapshot_at' => now(),
        ])->save();

        return $case;
    }

    /** O cadastro vivo do paciente (alergias, medicações, riscos) difere do retrato gravado no caso? */
    public function safetySnapshotIsStale(SurgicalCase $case): bool
    {
        $stored = (array) ($case->clinical_safety_snapshot ?? []);

        if ($stored === []) {
            return true;
        }

        $patient = $this->patient((string) $case->entity_id, (string) $case->patient_id);
        $live    = [...$this->safety->snapshot($patient), 'risk_alerts' => $this->safety->summary($patient, false)['risk_alerts'] ?? []];

        return CanonicalHash::of($live) !== CanonicalHash::of($stored);
    }

    // ── Cancelar / suspender / retomar ──────────────────────────────────────

    public function cancel(SurgicalCase $case, string $reason, ?int $expectedRevision = null): SurgicalCase
    {
        return DB::transaction(function () use ($case, $reason, $expectedRevision): SurgicalCase {
            $locked = $this->lock((string) $case->getKey());

            $this->assertRevision($locked, $expectedRevision);

            if ($locked->status->wasPerformed()) {
                throw SurgeryRuleException::state('surgery.errors.performed_cannot_cancel');
            }

            $this->changeStatus($locked, SurgicalCaseStatus::Cancelled, $reason, [
                'cancelled_at'        => now(),
                'cancellation_reason' => mb_substr(trim($reason), 0, 500),
            ]);

            return $locked->refresh();
        });
    }

    public function suspend(SurgicalCase $case, string $reason, ?int $expectedRevision = null): SurgicalCase
    {
        return DB::transaction(function () use ($case, $reason, $expectedRevision): SurgicalCase {
            $locked = $this->lock((string) $case->getKey());

            $this->assertRevision($locked, $expectedRevision);

            $from = $locked->status;

            $this->changeStatus($locked, SurgicalCaseStatus::Suspended, $reason, [
                'status_before_suspension' => $from,
                'suspended_at'             => now(),
                'suspension_reason'        => mb_substr(trim($reason), 0, 500),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Retoma um caso suspenso no estado de onde saiu. Se estava AGENDADO, a
     * janela é liberada (outro caso pode ter ocupado a sala enquanto isso):
     * volta como autorizado e precisa ser reagendado — com checagem de conflito.
     */
    public function resume(SurgicalCase $case, ?int $expectedRevision = null): SurgicalCase
    {
        return DB::transaction(function () use ($case, $expectedRevision): SurgicalCase {
            $locked = $this->lock((string) $case->getKey());

            $this->assertRevision($locked, $expectedRevision);

            if ($locked->status !== SurgicalCaseStatus::Suspended) {
                throw SurgeryRuleException::state('surgery.errors.not_suspended');
            }

            $target = $locked->status_before_suspension ?? SurgicalCaseStatus::Draft;
            $attrs  = ['status_before_suspension' => null, 'suspended_at' => null, 'suspension_reason' => null];

            if ($target === SurgicalCaseStatus::Scheduled) {
                $target = $locked->authorization_status === AuthorizationStatus::PartiallyAuthorized
                    ? SurgicalCaseStatus::PartiallyAuthorized
                    : SurgicalCaseStatus::Authorized;

                $attrs += ['scheduled_start' => null, 'scheduled_end' => null, 'surgery_room_id' => null];
            }

            $this->changeStatus($locked, $target, null, $attrs, allowFromSuspended: true);

            return $locked->refresh();
        });
    }

    // ── Primitiva de transição ──────────────────────────────────────────────

    /**
     * Muda o estado do caso JÁ TRAVADO pelo chamador (`lock()` na mesma
     * transação): valida a transição contra o estado ATUAL, grava os campos
     * extras, anexa ao `status_history` e sobe a revisão.
     *
     * @param array<string, mixed> $attributes
     */
    public function changeStatus(
        SurgicalCase $locked,
        SurgicalCaseStatus $to,
        ?string $reason = null,
        array $attributes = [],
        bool $allowFromSuspended = false,
    ): void {
        $this->assertInTransaction();

        $from = $locked->status;

        $allowed = $from->canTransitionTo($to)
            || ($allowFromSuspended && $from === SurgicalCaseStatus::Suspended);

        // Implante vigente prende estoque e série: sair do Agendado (cancelar, suspender, liberar a
        // vaga) sem anulá-lo antes deixaria a baixa sem estorno e a série "em uso" para sempre.
        if ($from === SurgicalCaseStatus::Scheduled
            && $to !== SurgicalCaseStatus::Performed
            && SurgicalImplant::query()->where('surgical_case_id', $locked->getKey())->where('status', 'active')->exists()) {
            throw SurgeryRuleException::state('surgery_operation.errors.active_implants_block_transition');
        }

        if (! $allowed) {
            throw SurgeryRuleException::state('surgery.errors.invalid_transition', [
                'from' => $from->label(),
                'to'   => $to->label(),
            ]);
        }

        $locked->forceFill($attributes);
        $locked->status = $to;

        $this->appendHistory($locked, $from, $to, $reason);

        $locked->revision = $locked->revision + 1;
        $locked->save();
    }

    // ── Validações compartilhadas ───────────────────────────────────────────

    public function assertRevision(SurgicalCase $locked, ?int $expected): void
    {
        if ($expected !== null && (int) $locked->revision !== $expected) {
            throw SurgeryRuleException::state('surgery.errors.stale_revision');
        }
    }

    public function patient(string $entityId, string $patientId): Patient
    {
        $patient = Patient::query()->where('entity_id', $entityId)->whereKey($patientId)->first();

        if ($patient === null) {
            throw SurgeryRuleException::invalid('surgery.errors.patient_not_found');
        }

        return $patient;
    }

    public function surgeon(string $entityId, string $doctorId): Doctor
    {
        $doctor = Doctor::query()->ofEntity($entityId)->where('doctors.active', true)->whereKey($doctorId)->first();

        if ($doctor === null) {
            throw SurgeryRuleException::invalid('surgery.errors.surgeon_not_found');
        }

        return $doctor;
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /** @param array<int, array<string, mixed>> $incoming equipe pedida (linhas com doctor_id ou só nome) */
    private function teamDoctorsChanged(SurgicalCase $locked, array $incoming): bool
    {
        $current = $locked->team()->whereNotNull('doctor_id')->pluck('doctor_id')->map(fn ($id) => (string) $id)->unique()->sort()->values()->all();
        $wanted  = collect($incoming)->pluck('doctor_id')->filter(fn ($id) => filled($id))->map(fn ($id) => (string) $id)->unique()->sort()->values()->all();

        return $current !== $wanted;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Operação do caso cirúrgico exige transação aberta (linha travada).');
        }
    }

    /** Convênio, local e (se informado) tipo existem na clínica; datas e janela não mudam aqui. */
    private function assertReferences(string $entityId, SurgicalCase $case, Patient $patient): void
    {
        if ($case->covenant_id !== null) {
            $ok = Covenant::query()
                ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
                ->whereKey($case->covenant_id)
                ->exists();

            if (! $ok) {
                throw SurgeryRuleException::invalid('surgery.errors.covenant_not_found');
            }
        }

        if ($case->surgery_facility_id !== null) {
            $ok = SurgeryFacility::query()->where('entity_id', $entityId)->where('active', true)->whereKey($case->surgery_facility_id)->exists();

            if (! $ok) {
                throw SurgeryRuleException::invalid('surgery.errors.facility_not_found');
            }
        }

        if ($case->surgery_type_id !== null) {
            $ok = SurgeryType::query()
                ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
                ->whereKey($case->surgery_type_id)
                ->exists();

            if (! $ok) {
                throw SurgeryRuleException::invalid('surgery.errors.surgery_type_not_found');
            }
        }

        if ((string) $patient->entity_id !== $entityId) {
            throw SurgeryRuleException::invalid('surgery.errors.patient_not_found');
        }
    }

    /**
     * Quais campos de $incoming diferem do valor atual do caso.
     *
     * @param array<string, mixed> $incoming
     *
     * @return list<string>
     */
    private function changed(SurgicalCase $case, array $incoming): array
    {
        $changed = [];

        foreach ($incoming as $field => $value) {
            $current = $case->getAttribute($field);
            $current = $current instanceof BackedEnum ? $current->value : $current;
            $value   = $value instanceof BackedEnum ? $value->value : $value;

            if (is_array($current) || is_array($value)) {
                if (json_encode($current ?? []) !== json_encode($value ?? [])) {
                    $changed[] = $field;
                }

                continue;
            }

            if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Até 4 hipóteses diagnósticas (limite da guia TISS), sem repetição.
     *
     * @return list<array{code: string, description: string|null}>
     */
    private function normalizeCids(mixed $cids): array
    {
        $out = [];

        foreach ((array) $cids as $cid) {
            $code = strtoupper(trim((string) (is_array($cid) ? ($cid['code'] ?? '') : $cid)));

            if ($code === '' || isset($out[$code])) {
                continue;
            }

            $out[$code] = [
                'code'        => $code,
                'description' => is_array($cid) && filled($cid['description'] ?? null) ? mb_substr((string) $cid['description'], 0, 255) : null,
            ];
        }

        if (count($out) > 4) {
            throw SurgeryRuleException::invalid('surgery.errors.too_many_cids');
        }

        return array_values($out);
    }

    private function appendHistory(SurgicalCase $case, ?SurgicalCaseStatus $from, SurgicalCaseStatus $to, ?string $reason): void
    {
        $history   = (array) $case->status_history;
        $history[] = array_filter([
            'from'   => $from?->value,
            'to'     => $to->value,
            'at'     => now()->toIso8601String(),
            'by'     => Auth::id(),
            'reason' => $reason !== null ? mb_substr(trim($reason), 0, 500) : null,
        ], fn ($v) => $v !== null);

        $case->status_history = $history;
    }

    /**
     * Filhos do pedido. Cada lista só é tocada quando a chave vem em `$data`
     * (ausente = deixa como está); lista vazia limpa.
     *
     * @param array<string, mixed> $data
     */
    private function syncChildren(SurgicalCase $case, array $data, bool $replaceRequest, bool $includeClinical): void
    {
        $entityId = (string) $case->entity_id;

        if ($replaceRequest && array_key_exists('procedures', $data)) {
            $case->procedures()->delete();

            foreach (array_values((array) $data['procedures']) as $i => $row) {
                $procedureId = $row['procedure_id'] ?? null;

                // Procedimento do catálogo: da clínica ou global; id de outra clínica = recusado.
                if ($procedureId !== null && ! Procedure::query()->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))->whereKey($procedureId)->exists()) {
                    throw SurgeryRuleException::invalid('surgery.errors.procedure_not_found');
                }

                SurgicalCaseProcedure::query()->create([
                    'entity_id'        => $entityId,
                    'surgical_case_id' => $case->getKey(),
                    'procedure_id'     => $procedureId,
                    'tuss_code'        => filled($row['tuss_code'] ?? null) ? trim((string) $row['tuss_code']) : null,
                    'description'      => trim((string) $row['description']),
                    'quantity'         => max(1, (int) ($row['quantity'] ?? 1)),
                    'access_route'     => $row['access_route'] ?? null,
                    'position'         => $i,
                ]);
            }
        }

        if ($replaceRequest && array_key_exists('materials', $data)) {
            $case->materials()->delete();

            foreach (array_values((array) $data['materials']) as $i => $row) {
                $productId = $row['entity_product_id'] ?? null;

                if ($productId !== null && ! EntityProduct::query()->where('entity_id', $entityId)->whereKey($productId)->exists()) {
                    throw SurgeryRuleException::invalid('surgery.errors.product_not_found');
                }

                SurgicalCaseMaterial::query()->create([
                    'entity_id'               => $entityId,
                    'surgical_case_id'        => $case->getKey(),
                    'entity_product_id'       => $productId,
                    'description'             => trim((string) $row['description']),
                    'quantity'                => max(1, (int) ($row['quantity'] ?? 1)),
                    'manufacturer'            => $row['manufacturer'] ?? null,
                    'anvisa_registration'     => $row['anvisa_registration'] ?? null,
                    'manufacturer_ref'        => $row['manufacturer_ref'] ?? null,
                    'operating_authorization' => $row['operating_authorization'] ?? null,
                    'manufacturer_option'     => $row['manufacturer_option'] ?? null,
                    'unit_value'              => $row['unit_value'] ?? null,
                    'technical_justification' => $row['technical_justification'] ?? null,
                    'position'                => $i,
                ]);
            }
        }

        if (! $includeClinical) {
            return;
        }

        if (array_key_exists('iol_plans', $data)) {
            // Um plano por olho (índice único do caso + olho): repetir o olho estouraria no banco DEPOIS de
            // apagar os planos antigos. Quem não passa pelo formulário (a regra `distinct` dele) cai aqui.
            $eyes = array_map(fn ($row) => (string) ($row['eye'] ?? ''), (array) $data['iol_plans']);

            if (count($eyes) !== count(array_unique($eyes))) {
                throw SurgeryRuleException::invalid('surgery.errors.iol_plan_duplicate_eye');
            }

            $case->iolPlans()->delete();

            foreach ((array) $data['iol_plans'] as $row) {
                $eye = SurgeryEye::from((string) $row['eye']);

                if ($eye === SurgeryEye::Both) {
                    throw SurgeryRuleException::invalid('surgery.errors.iol_plan_one_eye');
                }

                // Lente planejada para o olho que NÃO é o operado (OD no caso, plano OE): recusado.
                if ($case->eye !== SurgeryEye::Both && $eye !== $case->eye) {
                    throw SurgeryRuleException::invalid('surgery.errors.iol_plan_eye_mismatch', ['eye' => $case->eye->short()]);
                }

                $productId = $row['entity_product_id'] ?? null;

                if ($productId !== null && ! EntityProduct::query()->where('entity_id', $entityId)->whereKey($productId)->exists()) {
                    throw SurgeryRuleException::invalid('surgery.errors.product_not_found');
                }

                SurgicalCaseIolPlan::query()->create([
                    'entity_id'            => $entityId,
                    'surgical_case_id'     => $case->getKey(),
                    'eye'                  => $eye,
                    'entity_product_id'    => $productId,
                    'model_label'          => trim((string) $row['model_label']),
                    'power'                => $row['power'],
                    'cylinder'             => $row['cylinder'] ?? null,
                    'axis'                 => $row['axis'] ?? null,
                    'formula'              => $row['formula'] ?? null,
                    'a_constant'           => $row['a_constant'] ?? null,
                    'target_refraction'    => $row['target_refraction'] ?? null,
                    'predicted_refraction' => $row['predicted_refraction'] ?? null,
                    // Quem planejou é quem está logado (nunca o que o cliente mandar).
                    'planned_by_doctor_id' => CurrentDoctor::issuer()?->getKey(),
                    'notes'                => $row['notes'] ?? null,
                ]);
            }
        }

        if (array_key_exists('exams', $data)) {
            $case->exams()->delete();

            foreach ((array) $data['exams'] as $row) {
                // PatientExam não tem entity_id: a posse é do paciente (já validado nesta clínica).
                $exam = PatientExam::query()
                    ->where('patient_id', $case->patient_id)
                    ->whereKey($row['patient_exam_id'])
                    ->first();

                if ($exam === null) {
                    throw SurgeryRuleException::invalid('surgery.errors.exam_not_found');
                }

                // Biometria é base da potência da LIO: a de outro olho não entra como biometria deste caso.
                $examEye = match ((int) $exam->laterality) {
                    1       => SurgeryEye::Right,
                    2       => SurgeryEye::Left,
                    default => null,
                };

                if (($row['role'] ?? 'other') === 'biometry' && $examEye !== null && $case->eye !== SurgeryEye::Both && $examEye !== $case->eye) {
                    throw SurgeryRuleException::invalid('surgery.errors.biometry_eye_mismatch', ['eye' => $case->eye->short()]);
                }

                SurgicalCaseExam::query()->create([
                    'entity_id'        => $entityId,
                    'surgical_case_id' => $case->getKey(),
                    'patient_exam_id'  => $exam->getKey(),
                    'role'             => in_array($row['role'] ?? 'other', SurgicalCaseExam::ROLES, true) ? $row['role'] : 'other',
                    'notes'            => $row['notes'] ?? null,
                ]);
            }
        }

        if (array_key_exists('team', $data)) {
            $case->team()->delete();

            foreach (array_values((array) $data['team']) as $i => $row) {
                $doctor = null;

                if (filled($row['doctor_id'] ?? null)) {
                    $doctor = $this->surgeon($entityId, (string) $row['doctor_id']);
                }

                SurgicalTeamMember::query()->create([
                    'entity_id'        => $entityId,
                    'surgical_case_id' => $case->getKey(),
                    'role'             => $row['role'],
                    'doctor_id'        => $doctor?->getKey(),
                    'name'             => trim((string) ($row['name'] ?? $doctor?->person?->full_name ?? '')),
                    'registry'         => $row['registry'] ?? ($doctor ? $doctor->record : null),
                    'position'         => $i,
                ]);
            }
        }
    }
}
