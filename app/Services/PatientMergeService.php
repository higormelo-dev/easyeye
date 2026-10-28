<?php

declare(strict_types=1);

namespace App\Services;

use App\Domains\Recall\Services\RecallOutbox;
use App\Models\{Patient, PatientExam, People};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Lang};
use Illuminate\Validation\ValidationException;

/**
 * Mesclar cadastro de paciente (dedup, na MESMA clínica): tudo que pertence ao
 * cadastro de ORIGEM passa para o de DESTINO e a origem é excluída
 * (soft delete) com `merged_into_id` apontando para o destino. Sem desfazer
 * nesta versão. `People` (identidade global) e as contas do Portal do Paciente
 * não são tocados.
 *
 * Tudo roda numa transação, com as duas linhas de `patients` travadas em ordem
 * estável por id (dois merges cruzados não dão deadlock). Fail-closed: qualquer
 * conflito de unicidade clínica ou registro assinado/emitido na origem recusa o
 * merge inteiro (422) e nada é movido.
 *
 * Por que o registro assinado/emitido bloqueia: o hash de integridade desses
 * registros cobre o `patient_id` (Signable::clinicalContent no prontuário;
 * `content_hash` do implante, da descrição, do pós-operatório e do documento
 * emitido na cirurgia). Mudar o `patient_id` deles faria a assinatura parecer
 * adulterada. Quem guarda esses registros precisa ser o DESTINO: nada dele é
 * movido, então o hash segue válido.
 */
final class PatientMergeService
{
    /**
     * Toda tabela com coluna `patient_id` (conferido por teste contra o
     * information_schema: tabela nova sem entrada aqui reprova o teste).
     *
     * @var list<string>
     */
    public const array MOVABLE_TABLES = [
        'schedules', 'patient_exams', 'medical_records', 'waiting_list', 'patient_consents',
        'data_access_logs', 'lgpd_requests', 'medical_record_documentations', 'medical_record_files',
        'billing_claims', 'tiss_guides', 'ai_runs', 'financial_cash_entries', 'medical_record_evolutions',
        'patient_document_shares', 'medical_record_procedures', 'doctor_payout_items', 'urgent_care_entries',
        'medical_record_drafts', 'patient_allergies', 'patient_medications', 'eye_image_report_drafts',
        'patient_conditions', 'patient_recalls', 'patient_recall_events', 'patient_recall_blocks',
        'recall_dirty_patients', 'recall_invites', 'whatsapp_messages', 'intravitreal_courses',
        'intravitreal_applications', 'care_guidance_issues', 'surgical_cases', 'surgery_document_issues',
        'surgical_implants', 'surgical_descriptions', 'surgical_description_addenda', 'surgical_postop_evaluations',
    ];

    /**
     * Tabelas cirúrgicas: o hash de cada registro emitido tem o paciente dentro.
     * Qualquer linha da origem aqui (em qualquer status) bloqueia o merge.
     *
     * @var list<string>
     */
    public const array HASHED_SURGICAL_TABLES = [
        'surgical_cases', 'surgery_document_issues', 'surgical_implants', 'surgical_descriptions',
        'surgical_description_addenda', 'surgical_postop_evaluations',
    ];

    private const string EXAM_CODE_PREFIX = 'EXM';

    public function __construct(private readonly PatientDuplicateFinder $duplicates)
    {
    }

    /**
     * Cadastros que PODEM receber a mesclagem: mesma clínica, não excluídos e
     * não mesclados, com o mesmo nome (duplicado = mesmo nascimento; homônimo =
     * nascimento diferente ou ausente). Duplicados primeiro, depois por código.
     *
     * @return list<array{id: string, code: string, full_name: ?string, birth_date: ?string, age: ?int, kind: string}>
     */
    public function candidates(Patient $source): array
    {
        return $this->candidateRows($source)
            ->map(fn (array $row): array => [
                'id'         => (string) $row['patient']->id,
                'code'       => (string) $row['patient']->code,
                'full_name'  => $row['patient']->person?->full_name,
                'birth_date' => $row['patient']->person?->birth_date?->format('Y-m-d'),
                'age'        => $row['patient']->person?->birth_date?->age,
                'kind'       => $row['kind'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{moved: int, renumbered: int}
     *
     * @throws ValidationException 422 (campo target_id) — alvo inválido ou conflito; nada é movido
     */
    public function merge(Patient $source, Patient $target): array
    {
        return DB::transaction(function () use ($source, $target): array {
            // Ordem estável por id: dois merges cruzados (A→B e B→A) travam na mesma ordem.
            $locked = Patient::query()->withTrashed()
                ->whereIn('id', [$source->id, $target->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Patient $p): string => (string) $p->id);

            $freshSource = $locked->get((string) $source->id);
            $freshTarget = $locked->get((string) $target->id);

            $this->assertMergeable($freshSource, $freshTarget);

            $conflicts = array_merge($this->immutableRecordConflicts($freshSource), $this->uniquenessConflicts($freshSource, $freshTarget));

            if ($conflicts !== []) {
                throw ValidationException::withMessages([
                    'target_id' => [__('validation.custom.patient.merge_conflict', ['items' => implode('; ', $conflicts)])],
                ]);
            }

            // Marcador derivado (PK entity_id + patient_id): se o destino já tem, a linha da origem é descartável.
            if (DB::table('recall_dirty_patients')->where('patient_id', $freshTarget->id)->where('entity_id', $freshTarget->entity_id)->exists()) {
                DB::table('recall_dirty_patients')->where('patient_id', $freshSource->id)->delete();
            }

            $renumbered = $this->renumberCollidingExams($freshSource, $freshTarget);
            $moved      = $this->moveRecords($freshSource, $freshTarget);

            // Trilha: `updated` (merged_into_id) e `deleted` da origem pelo Auditable, `merged` explícito no destino.
            $freshSource->forceFill(['merged_into_id' => $freshTarget->id])->save();
            $freshSource->delete();

            // O observador do recall marcou a origem ao salvar/excluir: cadastro encerrado não vai para a reavaliação.
            DB::table('recall_dirty_patients')->where('patient_id', $freshSource->id)->delete();

            app(AuditService::class)->log('merged', $freshTarget, null, [
                'source_id'   => (string) $freshSource->id,
                'source_code' => (string) $freshSource->code,
                'moved'       => $moved,
                'renumbered'  => $renumbered,
            ]);

            // O UPDATE em massa não dispara o observador do recall: o destino ganhou atos, o retorno precisa ser reavaliado.
            RecallOutbox::mark((string) $freshTarget->entity_id, (string) $freshTarget->id, 'patient_merge');

            return ['moved' => $moved, 'renumbered' => $renumbered];
        });
    }

    /**
     * @return Collection<int, array{patient: Patient, kind: string}>
     */
    private function candidateRows(Patient $source): Collection
    {
        $entityId = (string) $source->entity_id;
        $person   = $source->person ?? People::query()->withTrashed()->find($source->person_id);

        if ($person === null) {
            return collect();
        }

        $found = $this->duplicates->find($entityId, (string) $person->full_name, $person->birth_date?->toDateString(), (string) $person->id);

        $kinds = [];

        foreach ($found['homonyms'] as $code) {
            $kinds[$code] = 'homonym';
        }

        // Duplicado vence se o mesmo código aparecer nas duas listas.
        foreach ($found['duplicates'] as $code) {
            $kinds[$code] = 'duplicate';
        }

        if ($kinds === []) {
            return collect();
        }

        return Patient::query()
            ->where('entity_id', $entityId)
            ->whereNull('merged_into_id')
            ->whereKeyNot($source->id)
            ->whereIn('code', array_keys($kinds))
            ->with('person')
            ->get()
            ->map(fn (Patient $p): array => ['patient' => $p, 'kind' => $kinds[$p->code]])
            ->sortBy([
                fn (array $a, array $b): int => ($a['kind'] === 'duplicate' ? 0 : 1) <=> ($b['kind'] === 'duplicate' ? 0 : 1),
                fn (array $a, array $b): int => strcmp((string) $a['patient']->code, (string) $b['patient']->code),
            ])
            ->values();
    }

    /** Alvo e origem têm de ser dois cadastros vivos da mesma clínica, ligados pela regra de candidatos. */
    private function assertMergeable(?Patient $source, ?Patient $target): void
    {
        $valid = $source !== null && $target !== null
            && (string) $source->id !== (string) $target->id
            && (string) $source->entity_id === (string) $target->entity_id
            && ! $source->trashed() && ! $target->trashed()
            && $source->merged_into_id === null && $target->merged_into_id === null
            && $this->candidateRows($source)->contains(fn (array $row): bool => (string) $row['patient']->id === (string) $target->id);

        if (! $valid) {
            throw ValidationException::withMessages(['target_id' => [__('validation.custom.patient.merge_target_invalid')]]);
        }
    }

    /**
     * Registros da origem cujo hash de integridade cobre o patient_id: assinados/travados e tudo da cirurgia.
     * Um método só: é aqui que a política muda.
     *
     * @return list<string> motivos já traduzidos
     */
    private function immutableRecordConflicts(Patient $source): array
    {
        $conflicts = [];

        $signed = DB::table('medical_records')
            ->where('patient_id', $source->id)
            ->where(fn ($q) => $q->where('is_locked', true)->orWhereNotNull('signed_at'))
            ->count();

        if ($signed > 0) {
            $conflicts[] = __('validation.custom.patient.merge_conflict_signed', ['count' => $signed]);
        }

        foreach (self::HASHED_SURGICAL_TABLES as $table) {
            if (DB::table($table)->where('patient_id', $source->id)->exists()) {
                $conflicts[] = __('validation.custom.patient.merge_conflict_surgery');

                break;
            }
        }

        return $conflicts;
    }

    /**
     * Índices únicos que dependem de patient_id: o que colidiria depois de mover. Cada motivo diz a área presa.
     *
     * @return list<string>
     */
    private function uniquenessConflicts(Patient $source, Patient $target): array
    {
        $conflicts = [];

        $conditions = DB::table('patient_conditions as s')
            ->join('patient_conditions as t', fn ($j) => $j
                ->on('t.entity_id', '=', 's.entity_id')->on('t.protocol_key', '=', 's.protocol_key')->on('t.eye', '=', 's.eye'))
            ->where('s.patient_id', $source->id)->where('t.patient_id', $target->id)
            ->whereIn('s.status', ['suggested', 'active'])->whereIn('t.status', ['suggested', 'active'])
            ->orderBy('s.protocol_key')->orderBy('s.eye')
            ->get(['s.protocol_key', 's.eye'])
            ->map(fn (object $r): string => $r->protocol_key . ' (' . $this->eyeLabel((string) $r->eye) . ')');

        if ($conditions->isNotEmpty()) {
            $conflicts[] = __('validation.custom.patient.merge_conflict_conditions', ['detail' => $conditions->implode(', ')]);
        }

        $doctorOrder = fn (string $patientId): bool => DB::table('patient_recalls')
            ->where('patient_id', $patientId)->where('source', 'doctor_order')->exists();

        if ($doctorOrder((string) $source->id) && $doctorOrder((string) $target->id)) {
            $conflicts[] = __('validation.custom.patient.merge_conflict_recalls');
        }

        $blocks = DB::table('patient_recall_blocks as s')
            ->join('patient_recall_blocks as t', fn ($j) => $j
                ->on('t.entity_id', '=', 's.entity_id')->on('t.reason', '=', 's.reason'))
            ->where('s.patient_id', $source->id)->where('t.patient_id', $target->id)
            ->whereNull('s.released_at')->whereNull('t.released_at')
            ->orderBy('s.reason')
            ->pluck('s.reason')
            ->map(fn (string $reason): string => $this->blockReasonLabel($reason));

        if ($blocks->isNotEmpty()) {
            $conflicts[] = __('validation.custom.patient.merge_conflict_blocks', ['detail' => $blocks->implode(', ')]);
        }

        $courses = DB::table('intravitreal_courses as s')
            ->join('intravitreal_courses as t', 't.eye', '=', 's.eye')
            ->where('s.patient_id', $source->id)->where('t.patient_id', $target->id)
            ->whereIn('s.status', ['active', 'paused'])->whereIn('t.status', ['active', 'paused'])
            ->whereNull('s.deleted_at')->whereNull('t.deleted_at')
            ->orderBy('s.eye')
            ->pluck('s.eye')
            ->map(fn (string $eye): string => $this->eyeLabel($eye));

        if ($courses->isNotEmpty()) {
            $conflicts[] = __('validation.custom.patient.merge_conflict_intravitreal', ['detail' => $courses->implode(', ')]);
        }

        $drafts = DB::table('medical_record_drafts as s')
            ->join('medical_record_drafts as t', fn ($j) => $j
                ->on('t.entity_id', '=', 's.entity_id')->on('t.user_id', '=', 's.user_id')->on('t.context_key', '=', 's.context_key'))
            ->where('s.patient_id', $source->id)->where('t.patient_id', $target->id)
            ->orderBy('s.context_key')
            ->pluck('s.context_key');

        if ($drafts->isNotEmpty()) {
            $conflicts[] = __('validation.custom.patient.merge_conflict_drafts', ['detail' => $drafts->implode(', ')]);
        }

        return $conflicts;
    }

    /**
     * Exame da origem com código que o destino já usa (índice único por paciente: patient_id + code) ganha o
     * próximo número livre do destino ANTES de mover. O novo número também não pode bater com outro exame da origem
     * que mantém o código. Cada troca é auditada (código antigo → novo).
     */
    private function renumberCollidingExams(Patient $source, Patient $target): int
    {
        $targetCodes = DB::table('patient_exams')->where('patient_id', $target->id)->pluck('code')->filter(fn ($code) => is_string($code))->all();
        $sourceExams = DB::table('patient_exams')->where('patient_id', $source->id)->orderBy('code')->get(['id', 'code']);

        $inTarget = array_flip($targetCodes);
        $taken    = array_flip([...$targetCodes, ...$sourceExams->pluck('code')->filter(fn ($code) => is_string($code))->all()]);
        $next     = 0;

        foreach (array_keys($taken) as $code) {
            if (preg_match('/^' . self::EXAM_CODE_PREFIX . '-(\d+)$/', (string) $code, $m) === 1) {
                $next = max($next, (int) $m[1]);
            }
        }

        $renumbered = 0;

        foreach ($sourceExams as $row) {
            if (! is_string($row->code) || ! isset($inTarget[$row->code])) {
                continue;
            }

            do {
                $newCode = sprintf('%s-%010d', self::EXAM_CODE_PREFIX, ++$next);
            } while (isset($taken[$newCode]));

            $taken[$newCode] = true;

            $exam = PatientExam::query()->findOrFail($row->id);
            $exam->forceFill(['code' => $newCode])->saveQuietly();
            app(AuditService::class)->log('updated', $exam, ['code' => $row->code], ['code' => $newCode]);

            $renumbered++;
        }

        return $renumbered;
    }

    private function moveRecords(Patient $source, Patient $target): int
    {
        $moved = 0;

        foreach (self::MOVABLE_TABLES as $table) {
            $moved += DB::table($table)->where('patient_id', $source->id)->update(['patient_id' => $target->id]);
        }

        return $moved;
    }

    private function eyeLabel(string $eye): string
    {
        return match ($eye) {
            'right' => 'OD',
            'left'  => 'OE',
            default => 'AO',
        };
    }

    private function blockReasonLabel(string $reason): string
    {
        $key = 'recall.block_reason.' . $reason;

        return Lang::has($key) ? (string) __($key) : $reason;
    }
}
