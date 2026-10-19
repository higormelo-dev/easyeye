<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType, ScheduleSituation};
use App\Models\{AuditLog, Doctor, Entity, FinancialCashEntry, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, Schedule, User};
use App\Services\PatientMergeService;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/**
 * Mesclar cadastro de paciente (PatientMergeService + PatientsController::merge):
 * tudo da ORIGEM vai para o DESTINO, a origem é excluída com merged_into_id.
 * Fail-closed: conflito de unicidade clínica e registro assinado/cirúrgico na
 * origem recusam o merge inteiro (422, nada movido).
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->adminUser = User::factory()->create();
    $this->adminEu   = createEntityUser($this->entity, $this->adminUser, ClientRule::Admin->value);

    $this->doctorUser = User::factory()->create();
    $this->doctorEu   = createEntityUser($this->entity, $this->doctorUser, ClientRule::Doctor->value);
    $this->doctor     = Doctor::query()->create([
        'entity_user_id' => $this->doctorEu->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $this->secretaryUser = User::factory()->create();
    $this->secretaryEu   = createEntityUser($this->entity, $this->secretaryUser, ClientRule::Secretary->value);

    // Mesmo nome + mesmo nascimento = duplicado real (candidato ao merge).
    $this->source = mergeTestPatient($this->entity);
    $this->target = mergeTestPatient($this->entity);
});

function mergeTestPatient(Entity $entity, string $name = 'MARIA SOUZA DE TESTE', ?string $birth = '1980-05-10'): Patient
{
    return Patient::factory()->create([
        'entity_id' => $entity->id,
        'active'    => true,
        'person_id' => People::factory()->create(['full_name' => $name, 'birth_date' => $birth])->id,
    ]);
}

function mergeAs($test, string $who, Patient $source, array $body)
{
    $user   = $test->{$who . 'User'};
    $member = $test->{$who . 'Eu'};

    return $test->actingAs($user)->withSession(panelSession($member))
        ->postJson(route('panel.patients.merge', $source), $body);
}

function mergeAdmin($test, Patient $source, Patient $target, array $extra = [])
{
    return mergeAs($test, 'admin', $source, ['target_id' => $target->id, 'confirm' => true] + $extra);
}

function mergeUnsignedRecord($test, Patient $patient, ?string $complaint = 'Consulta'): MedicalRecord
{
    return MedicalRecord::query()->create([
        'entity_id'      => $test->entity->id,
        'patient_id'     => $patient->id,
        'doctor_id'      => $test->doctor->id,
        'main_complaint' => $complaint,
    ]);
}

/** Linhas de agenda, prontuário, laudo e caixa do paciente — as tabelas representativas do merge. */
function mergeSeedRecords($test, Patient $patient, int $dayOffset = 1): array
{
    $schedule = Schedule::query()->create([
        'entity_id'  => $test->entity->id,
        'doctor_id'  => $test->doctor->id,
        'patient_id' => $patient->id,
        'full_name'  => $patient->person->full_name,
        'date_time'  => now()->addDays($dayOffset),
        'situation'  => ScheduleSituation::Scheduled->value,
        'active'     => true,
    ]);
    $record = mergeUnsignedRecord($test, $patient);
    $doc    = MedicalRecordDocumentation::query()->create([
        'medical_record_id' => $record->id,
        'patient_id'        => $patient->id,
        'doctor_id'         => $test->doctor->id,
        'type'              => DocumentationType::Report,
        'title'             => 'Laudo',
        'content'           => '<p>x</p>',
    ]);
    $cash = FinancialCashEntry::query()->forceCreate([
        'entity_id'   => $test->entity->id,
        'patient_id'  => $patient->id,
        'entry_date'  => now()->toDateString(),
        'description' => 'Consulta',
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => 100,
        'active'      => true,
    ]);

    return compact('schedule', 'record', 'doc', 'cash');
}

/** Total de linhas do paciente nas tabelas movíveis (menos o marcador derivado, que não se move quando o destino tem). */
function mergeRowCount(string $patientId): int
{
    return collect(PatientMergeService::MOVABLE_TABLES)->sum(fn (string $t): int => DB::table($t)->where('patient_id', $patientId)->count());
}

function mergeInsert(string $table, array $row): void
{
    $defaults = ['id' => (string) Str::uuid(), 'created_at' => now()];

    // patient_recall_blocks só tem created_at.
    if (Schema::hasColumn($table, 'updated_at')) {
        $defaults['updated_at'] = now();
    }

    DB::table($table)->insert($row + $defaults);
}

describe('lista de tabelas movíveis (drift contra o banco)', function () {
    it('MOVABLE_TABLES é exatamente o conjunto de tabelas com coluna patient_id', function () {
        $tables = collect(DB::select(
            "SELECT DISTINCT c.table_name FROM information_schema.columns c
             JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
             WHERE c.column_name = 'patient_id' AND c.table_schema = current_schema()",
        ))->pluck('table_name')->sort()->values()->all();

        $listed = collect(PatientMergeService::MOVABLE_TABLES)->sort()->values()->all();

        expect($tables)->toBe($listed);
    });

    it('os únicos que dependem de patient_id são só os que o merge pré-checa ou trata', function () {
        $rows = DB::select(
            'SELECT t.relname AS tbl, i.relname AS idx, ix.indisprimary AS is_pk
             FROM pg_index ix
             JOIN pg_class t ON t.oid = ix.indrelid
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
             WHERE ix.indisunique AND n.nspname = current_schema() AND a.attname = ?',
            ['patient_id'],
        );

        $found = collect($rows)->map(fn (object $r): string => $r->tbl . '.' . $r->idx)->sort()->values()->all();

        // Um índice único novo com patient_id precisa de pré-checagem em PatientMergeService::uniquenessConflicts().
        expect($found)->toBe([
            'intravitreal_courses.intravitreal_courses_one_open',
            'medical_record_drafts.medical_record_drafts_owner_context_unique',
            'patient_conditions.patient_conditions_one_open',
            'patient_exams.patient_exams_patient_code_unique',
            'patient_recall_blocks.patient_recall_blocks_one_active',
            'patient_recalls.patient_recalls_doctor_order_unique',
            'recall_dirty_patients.recall_dirty_patients_pkey',
        ]);
    });

    it('as tabelas cirúrgicas bloqueadas são um subconjunto das movíveis', function () {
        expect(array_diff(PatientMergeService::HASHED_SURGICAL_TABLES, PatientMergeService::MOVABLE_TABLES))->toBe([]);
    });
});

describe('merge (caminho feliz)', function () {
    it('move os registros, renumera exame colidido, exclui a origem e audita os dois cadastros', function () {
        $sourceRows = mergeSeedRecords($this, $this->source);
        $targetRows = mergeSeedRecords($this, $this->target, 2);

        // Origem: 0001 (colide), 0002, 0003 (não colide, mas o novo número não pode cair em cima dele).
        $s1 = PatientExam::factory()->create(['patient_id' => $this->source->id, 'code' => 'EXM-0000000001']);
        $s2 = PatientExam::factory()->create(['patient_id' => $this->source->id, 'code' => 'EXM-0000000002']);
        $s3 = PatientExam::factory()->create(['patient_id' => $this->source->id, 'code' => 'EXM-0000000003']);
        PatientExam::factory()->create(['patient_id' => $this->target->id, 'code' => 'EXM-0000000001']);
        PatientExam::factory()->create(['patient_id' => $this->target->id, 'code' => 'EXM-0000000002']);

        $targetBefore   = mergeRowCount((string) $this->target->id);
        $sourceBefore   = mergeRowCount((string) $this->source->id);
        $dirtyOnSource  = DB::table('recall_dirty_patients')->where('patient_id', $this->source->id)->count();
        $targetHasDirty = DB::table('recall_dirty_patients')->where('patient_id', $this->target->id)->exists();

        $response = mergeAdmin($this, $this->source, $this->target)->assertOk();

        $moved = $sourceBefore - ($targetHasDirty ? $dirtyOnSource : 0);

        expect($response->json('source'))->toBe(['id' => (string) $this->source->id, 'code' => $this->source->code])
            ->and($response->json('target'))->toBe(['id' => (string) $this->target->id, 'code' => $this->target->code])
            ->and($response->json('moved'))->toBe($moved)
            ->and($response->json('renumbered'))->toBe(2)
            ->and($response->json('message'))->toContain((string) $moved)
            // Tudo da origem foi para o destino.
            ->and(mergeRowCount((string) $this->source->id))->toBe(0)
            ->and(mergeRowCount((string) $this->target->id))->toBeGreaterThanOrEqual($targetBefore + $moved - 1);

        foreach ([$sourceRows, $targetRows] as $rows) {
            expect($rows['schedule']->fresh()->patient_id)->toBe((string) $this->target->id)
                ->and($rows['record']->fresh()->patient_id)->toBe((string) $this->target->id)
                ->and($rows['doc']->fresh()->patient_id)->toBe((string) $this->target->id)
                ->and($rows['cash']->fresh()->patient_id)->toBe((string) $this->target->id);
        }

        // Exames: códigos únicos no destino; o que não colidia manteve o código e o colidido foi para o próximo livre (0004).
        $codes = PatientExam::query()->where('patient_id', $this->target->id)->orderBy('code')->pluck('code')->all();

        expect($codes)->toBe(['EXM-0000000001', 'EXM-0000000002', 'EXM-0000000003', 'EXM-0000000004', 'EXM-0000000005'])
            ->and($s1->fresh()->code)->toBe('EXM-0000000004')
            ->and($s2->fresh()->code)->toBe('EXM-0000000005')
            ->and($s3->fresh()->code)->toBe('EXM-0000000003');
    });

    it('a origem fica excluída e aponta para o destino; o destino segue ativo', function () {
        mergeAdmin($this, $this->source, $this->target)->assertOk();

        $source = Patient::query()->withTrashed()->findOrFail($this->source->id);

        expect($source->trashed())->toBeTrue()
            ->and($source->merged_into_id)->toBe((string) $this->target->id)
            ->and($source->mergedInto->code)->toBe($this->target->code)
            ->and($this->target->fresh()->trashed())->toBeFalse()
            ->and($this->target->fresh()->merged_into_id)->toBeNull();
    });

    it('audita: updated (merged_into_id) e deleted na origem, merged no destino, updated do código renumerado', function () {
        $exam = PatientExam::factory()->create(['patient_id' => $this->source->id, 'code' => 'EXM-0000000001']);
        PatientExam::factory()->create(['patient_id' => $this->target->id, 'code' => 'EXM-0000000001']);
        AuditLog::query()->delete();

        mergeAdmin($this, $this->source, $this->target)->assertOk();

        $logs = fn (string $type, string $id) => AuditLog::query()->where('auditable_type', $type)->where('auditable_id', $id)->get();

        $sourceEvents = $logs(Patient::class, (string) $this->source->id);
        $merged       = $logs(Patient::class, (string) $this->target->id)->firstWhere('event', 'merged');
        $examUpdate   = $logs(PatientExam::class, (string) $exam->id)->firstWhere('event', 'updated');

        expect($sourceEvents->pluck('event')->all())->toContain('updated', 'deleted')
            ->and($sourceEvents->firstWhere('event', 'updated')->new_values)->toBe(['merged_into_id' => (string) $this->target->id])
            ->and($merged)->not->toBeNull()
            ->and($merged->new_values)->toBe([
                'source_id'   => (string) $this->source->id,
                'source_code' => $this->source->code,
                'moved'       => $merged->new_values['moved'],
                'renumbered'  => 1,
            ])
            ->and($merged->user_id)->toBe($this->adminUser->id)
            ->and($examUpdate->old_values)->toBe(['code' => 'EXM-0000000001'])
            ->and($examUpdate->new_values)->toBe(['code' => 'EXM-0000000002']);
    });

    it('marca o destino para reavaliação do recall e não deixa marcador da origem', function () {
        mergeAdmin($this, $this->source, $this->target)->assertOk();

        expect(DB::table('recall_dirty_patients')->where('patient_id', $this->source->id)->count())->toBe(0)
            ->and(DB::table('recall_dirty_patients')->where('patient_id', $this->target->id)->value('reason'))->toBe('patient_merge');
    });

    it('recall_dirty_patients nos dois: sobra uma linha só, a do destino (sem violar a PK)', function () {
        DB::table('recall_dirty_patients')->updateOrInsert(
            ['entity_id' => $this->entity->id, 'patient_id' => $this->source->id],
            ['reason' => 'exam', 'marked_at' => now()],
        );
        DB::table('recall_dirty_patients')->updateOrInsert(
            ['entity_id' => $this->entity->id, 'patient_id' => $this->target->id],
            ['reason' => 'exam', 'marked_at' => now()],
        );

        mergeAdmin($this, $this->source, $this->target)->assertOk();

        expect(DB::table('recall_dirty_patients')->whereIn('patient_id', [$this->source->id, $this->target->id])->count())->toBe(1);
    });

    it('sem colisão de código, ninguém é renumerado', function () {
        $exam = PatientExam::factory()->create(['patient_id' => $this->source->id, 'code' => 'EXM-0000000007']);
        PatientExam::factory()->create(['patient_id' => $this->target->id, 'code' => 'EXM-0000000001']);

        $response = mergeAdmin($this, $this->source, $this->target)->assertOk();

        expect($response->json('renumbered'))->toBe(0)
            ->and($exam->fresh()->code)->toBe('EXM-0000000007')
            ->and($exam->fresh()->patient_id)->toBe((string) $this->target->id);
    });

    it('o exame movido aparece no Histórico do destino (código novo, evento merged)', function () {
        $exam = PatientExam::factory()->create(['patient_id' => $this->source->id, 'code' => 'EXM-0000000001']);
        PatientExam::factory()->create(['patient_id' => $this->target->id, 'code' => 'EXM-0000000001']);

        mergeAdmin($this, $this->source, $this->target)->assertOk();

        $entries = collect($this->actingAs($this->adminUser)->withSession(panelSession($this->adminEu))
            ->getJson(route('panel.eye-images.patients.audit', ['patient' => $this->target]))->assertOk()->json('entries'));

        $code = $entries->first(fn (array $e) => $e['target'] === 'exam' && $e['event'] === 'updated');

        expect($entries->pluck('event')->all())->toContain('merged')
            ->and($code['target_code'])->toBe($exam->fresh()->code)
            ->and($code['changes'][0])->toMatchArray(['field' => 'code', 'old' => 'EXM-0000000001', 'redacted' => false]);
    });
});

describe('conflitos de unicidade (422, nada movido)', function () {
    function mergeAssertNothingMoved($test, array $rows): void
    {
        expect($test->source->fresh()->trashed())->toBeFalse()
            ->and($test->source->fresh()->merged_into_id)->toBeNull()
            ->and($rows['schedule']->fresh()->patient_id)->toBe((string) $test->source->id)
            ->and($rows['record']->fresh()->patient_id)->toBe((string) $test->source->id);
    }

    it('condição crônica aberta igual (protocolo + olho) nos dois', function () {
        $rows = mergeSeedRecords($this, $this->source);

        foreach ([$this->source, $this->target] as $p) {
            mergeInsert('patient_conditions', [
                'entity_id' => $this->entity->id, 'patient_id' => $p->id, 'protocol_key' => 'glaucoma', 'eye' => 'right', 'status' => 'active', 'origin' => 'manual',
            ]);
        }

        $response = mergeAdmin($this, $this->source, $this->target)->assertStatus(422);

        expect($response->json('errors.target_id.0'))->toContain('condição crônica')->toContain('glaucoma (OD)');
        mergeAssertNothingMoved($this, $rows);
    });

    it('condição encerrada em um dos lados não conflita', function () {
        mergeInsert('patient_conditions', [
            'entity_id' => $this->entity->id, 'patient_id' => $this->source->id, 'protocol_key' => 'glaucoma', 'eye' => 'right', 'status' => 'discharged', 'origin' => 'manual',
        ]);
        mergeInsert('patient_conditions', [
            'entity_id' => $this->entity->id, 'patient_id' => $this->target->id, 'protocol_key' => 'glaucoma', 'eye' => 'right', 'status' => 'active', 'origin' => 'manual',
        ]);

        mergeAdmin($this, $this->source, $this->target)->assertOk();
    });

    it('retorno por ordem médica nos dois', function () {
        $rows = mergeSeedRecords($this, $this->source);

        foreach ([$this->source, $this->target] as $p) {
            mergeInsert('patient_recalls', [
                'entity_id' => $this->entity->id, 'patient_id' => $p->id, 'source' => 'doctor_order', 'task_key' => 'return', 'kind' => 'doctor_order',
            ]);
        }

        $response = mergeAdmin($this, $this->source, $this->target)->assertStatus(422);

        expect($response->json('errors.target_id.0'))->toContain('retorno por ordem médica');
        mergeAssertNothingMoved($this, $rows);
    });

    it('retorno por ordem médica só na origem se move normalmente', function () {
        mergeInsert('patient_recalls', [
            'entity_id' => $this->entity->id, 'patient_id' => $this->source->id, 'source' => 'doctor_order', 'task_key' => 'return', 'kind' => 'doctor_order',
        ]);

        mergeAdmin($this, $this->source, $this->target)->assertOk();

        expect(DB::table('patient_recalls')->where('patient_id', $this->target->id)->count())->toBe(1);
    });

    it('bloqueio de contato ativo com o mesmo motivo nos dois (o motivo vem traduzido)', function () {
        $rows = mergeSeedRecords($this, $this->source);

        foreach ([$this->source, $this->target] as $p) {
            mergeInsert('patient_recall_blocks', [
                'entity_id' => $this->entity->id, 'patient_id' => $p->id, 'reason' => 'do_not_contact',
            ]);
        }

        $response = mergeAdmin($this, $this->source, $this->target)->assertStatus(422);

        expect($response->json('errors.target_id.0'))->toContain('bloqueio de contato')->toContain('Pediu para não ser contatado');
        mergeAssertNothingMoved($this, $rows);
    });

    it('bloqueio liberado não conflita', function () {
        mergeInsert('patient_recall_blocks', [
            'entity_id' => $this->entity->id, 'patient_id' => $this->source->id, 'reason' => 'moved', 'released_at' => now(),
        ]);
        mergeInsert('patient_recall_blocks', [
            'entity_id' => $this->entity->id, 'patient_id' => $this->target->id, 'reason' => 'moved',
        ]);

        mergeAdmin($this, $this->source, $this->target)->assertOk();
    });

    it('tratamento intravítreo em andamento no mesmo olho nos dois', function () {
        $rows = mergeSeedRecords($this, $this->source);

        foreach ([[$this->source, 'active'], [$this->target, 'paused']] as [$p, $status]) {
            mergeInsert('intravitreal_courses', [
                'entity_id' => $this->entity->id, 'patient_id' => $p->id, 'eye' => 'left', 'drug_key' => 'x', 'drug_label' => 'X',
                'regimen'   => 'prn', 'status' => $status, 'started_on' => now()->toDateString(),
            ]);
        }

        $response = mergeAdmin($this, $this->source, $this->target)->assertStatus(422);

        expect($response->json('errors.target_id.0'))->toContain('intravítreo')->toContain('(OE)');
        mergeAssertNothingMoved($this, $rows);
    });

    it('curso intravítreo concluído ou em olho diferente não conflita', function () {
        mergeInsert('intravitreal_courses', [
            'entity_id' => $this->entity->id, 'patient_id' => $this->source->id, 'eye' => 'left', 'drug_key' => 'x', 'drug_label' => 'X',
            'regimen'   => 'prn', 'status' => 'completed', 'started_on' => now()->toDateString(),
        ]);
        mergeInsert('intravitreal_courses', [
            'entity_id' => $this->entity->id, 'patient_id' => $this->target->id, 'eye' => 'left', 'drug_key' => 'x', 'drug_label' => 'X',
            'regimen'   => 'prn', 'status' => 'active', 'started_on' => now()->toDateString(),
        ]);

        mergeAdmin($this, $this->source, $this->target)->assertOk();
    });

    it('rascunho de prontuário do mesmo usuário na mesma tela: a mensagem diz o context_key', function () {
        $rows = mergeSeedRecords($this, $this->source);

        foreach ([$this->source, $this->target] as $p) {
            mergeInsert('medical_record_drafts', [
                'entity_id' => $this->entity->id, 'user_id' => $this->doctorUser->id, 'patient_id' => $p->id, 'context_key' => 'new', 'payload' => '{}',
            ]);
        }

        $response = mergeAdmin($this, $this->source, $this->target)->assertStatus(422);

        expect($response->json('errors.target_id.0'))->toContain('rascunho de prontuário')->toContain('(new)');
        mergeAssertNothingMoved($this, $rows);
    });

    it('rascunho de usuários diferentes ou de telas diferentes se move junto', function () {
        mergeInsert('medical_record_drafts', [
            'entity_id' => $this->entity->id, 'user_id' => $this->doctorUser->id, 'patient_id' => $this->source->id, 'context_key' => 'new', 'payload' => '{}',
        ]);
        mergeInsert('medical_record_drafts', [
            'entity_id' => $this->entity->id, 'user_id' => $this->adminUser->id, 'patient_id' => $this->target->id, 'context_key' => 'new', 'payload' => '{}',
        ]);

        mergeAdmin($this, $this->source, $this->target)->assertOk();

        expect(DB::table('medical_record_drafts')->where('patient_id', $this->target->id)->count())->toBe(2);
    });

    it('vários conflitos juntos aparecem todos numa mensagem só', function () {
        foreach ([$this->source, $this->target] as $p) {
            mergeInsert('patient_recalls', [
                'entity_id' => $this->entity->id, 'patient_id' => $p->id, 'source' => 'doctor_order', 'task_key' => 'return', 'kind' => 'doctor_order',
            ]);
            mergeInsert('patient_recall_blocks', ['entity_id' => $this->entity->id, 'patient_id' => $p->id, 'reason' => 'other']);
        }

        $message = mergeAdmin($this, $this->source, $this->target)->assertStatus(422)->json('errors.target_id.0');

        expect($message)->toContain('retorno por ordem médica')->toContain('bloqueio de contato');
    });
});

describe('registro assinado ou cirúrgico (hash cobre o patient_id)', function () {
    function mergeSignedRecord($test, Patient $patient): MedicalRecord
    {
        $record = mergeUnsignedRecord($test, $patient, 'Consulta assinada');
        $record->sign($test->doctorEu);

        return $record->fresh();
    }

    function mergeSurgicalCase($test, Patient $patient): string
    {
        $id = (string) Str::uuid();

        DB::table('surgical_cases')->insert([
            'id'                => $id, 'entity_id' => $test->entity->id, 'code' => 'CIR-' . Str::random(6), 'patient_id' => $patient->id,
            'surgeon_doctor_id' => $test->doctor->id, 'title' => 'Facectomia', 'eye' => 'OD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    it('origem com prontuário assinado: 422, nada movido, e a mensagem manda mesclar a partir do outro cadastro', function () {
        $signed = mergeSignedRecord($this, $this->source);
        $rows   = mergeSeedRecords($this, $this->source);

        $response = mergeAdmin($this, $this->source, $this->target)->assertStatus(422);

        expect($response->json('errors.target_id.0'))->toContain('prontuário(s) assinado(s)')->toContain('outro cadastro')
            ->and($signed->fresh()->patient_id)->toBe((string) $this->source->id)
            ->and($signed->fresh()->verifyIntegrity())->toBeTrue()
            ->and($this->source->fresh()->merged_into_id)->toBeNull()
            ->and($rows['schedule']->fresh()->patient_id)->toBe((string) $this->source->id);
    });

    it('invertendo os papéis: quem tem o assinado é o destino — 200 e o assinado fica onde está com a assinatura íntegra', function () {
        $signed = mergeSignedRecord($this, $this->source);
        $rows   = mergeSeedRecords($this, $this->target);

        mergeAdmin($this, $this->target, $this->source)->assertOk();

        $signedAfter = $signed->fresh();

        expect($signedAfter->patient_id)->toBe((string) $this->source->id)
            ->and($signedAfter->is_locked)->toBeTrue()
            ->and($signedAfter->verifyIntegrity())->toBeTrue()
            // o que NÃO era assinado veio para o destino
            ->and($rows['schedule']->fresh()->patient_id)->toBe((string) $this->source->id)
            ->and($rows['record']->fresh()->patient_id)->toBe((string) $this->source->id)
            ->and($this->target->fresh()->trashed())->toBeTrue();
    });

    it('assinado dos dois lados: não mescla em nenhum sentido nesta versão', function () {
        $a = mergeSignedRecord($this, $this->source);
        $b = mergeSignedRecord($this, $this->target);

        mergeAdmin($this, $this->source, $this->target)->assertStatus(422);
        mergeAdmin($this, $this->target, $this->source)->assertStatus(422);

        expect($a->fresh()->patient_id)->toBe((string) $this->source->id)
            ->and($b->fresh()->patient_id)->toBe((string) $this->target->id)
            ->and($a->fresh()->verifyIntegrity())->toBeTrue()
            ->and($b->fresh()->verifyIntegrity())->toBeTrue();
    });

    it('assinado só no destino não bloqueia (nada do destino é movido)', function () {
        $signed = mergeSignedRecord($this, $this->target);

        mergeAdmin($this, $this->source, $this->target)->assertOk();

        expect($signed->fresh()->verifyIntegrity())->toBeTrue()
            ->and($signed->fresh()->patient_id)->toBe((string) $this->target->id);
    });

    it('origem com caso cirúrgico, em qualquer status: 422 e o caso não é movido', function () {
        $caseId = mergeSurgicalCase($this, $this->source);
        DB::table('surgical_cases')->where('id', $caseId)->update(['status' => 'cancelled']);

        $response = mergeAdmin($this, $this->source, $this->target)->assertStatus(422);

        expect($response->json('errors.target_id.0'))->toContain('caso cirúrgico')->toContain('outro cadastro')
            ->and(DB::table('surgical_cases')->where('id', $caseId)->value('patient_id'))->toBe((string) $this->source->id);
    });

    it('caso cirúrgico só no destino não bloqueia', function () {
        $caseId = mergeSurgicalCase($this, $this->target);

        mergeAdmin($this, $this->source, $this->target)->assertOk();

        expect(DB::table('surgical_cases')->where('id', $caseId)->value('patient_id'))->toBe((string) $this->target->id);
    });

    it('caso cirúrgico excluído (soft delete) da origem também bloqueia', function () {
        $caseId = mergeSurgicalCase($this, $this->source);
        DB::table('surgical_cases')->where('id', $caseId)->update(['deleted_at' => now()]);

        mergeAdmin($this, $this->source, $this->target)->assertStatus(422);
    });

    it('assinado e conflito de unicidade juntos aparecem na mesma mensagem', function () {
        mergeSignedRecord($this, $this->source);

        foreach ([$this->source, $this->target] as $p) {
            mergeInsert('patient_recall_blocks', ['entity_id' => $this->entity->id, 'patient_id' => $p->id, 'reason' => 'other']);
        }

        $message = mergeAdmin($this, $this->source, $this->target)->assertStatus(422)->json('errors.target_id.0');

        expect($message)->toContain('assinado(s)')->toContain('bloqueio de contato');
    });
});

describe('alvo e permissão', function () {
    it('só administrador mescla: secretária e médico recebem 403 e nada muda', function () {
        foreach (['secretary', 'doctor'] as $who) {
            mergeAs($this, $who, $this->source, ['target_id' => $this->target->id, 'confirm' => true])->assertForbidden();
        }

        expect($this->source->fresh()->trashed())->toBeFalse();
    });

    it('visitante não mescla', function () {
        $this->postJson(route('panel.patients.merge', $this->source), ['target_id' => $this->target->id, 'confirm' => true])->assertUnauthorized();
    });

    it('destino de outra clínica: 404; origem de outra clínica: 404', function () {
        $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherPatient = mergeTestPatient($otherEntity);

        mergeAdmin($this, $this->source, $otherPatient)->assertNotFound();
        mergeAdmin($this, $otherPatient, $this->target)->assertNotFound();

        expect($otherPatient->fresh()->trashed())->toBeFalse()->and($this->source->fresh()->trashed())->toBeFalse();
    });

    it('destino inexistente: 404', function () {
        mergeAs($this, 'admin', $this->source, ['target_id' => (string) Str::uuid(), 'confirm' => true])->assertNotFound();
    });

    it('destino fora da lista de candidatos (nome diferente) é recusado — não se mescla por UUID adivinhado', function () {
        $stranger = mergeTestPatient($this->entity, 'JOAO PEREIRA OUTRO NOME', '1975-01-01');

        $response = mergeAdmin($this, $this->source, $stranger)->assertStatus(422);

        expect($response->json('errors.target_id'))->not->toBeEmpty()
            ->and($this->source->fresh()->trashed())->toBeFalse()
            ->and($stranger->fresh()->trashed())->toBeFalse();
    });

    it('homônimo (mesmo nome, nascimento diferente) é candidato e pode ser mesclado', function () {
        $homonym = mergeTestPatient($this->entity, 'MARIA SOUZA DE TESTE', '1990-01-01');

        mergeAdmin($this, $this->source, $homonym)->assertOk();
    });

    it('mesmo cadastro como origem e destino: 422', function () {
        mergeAdmin($this, $this->source, $this->source)->assertStatus(422);

        expect($this->source->fresh()->trashed())->toBeFalse();
    });

    it('destino excluído: 422', function () {
        $this->target->delete();

        mergeAdmin($this, $this->source, $this->target)->assertStatus(422);

        expect($this->source->fresh()->trashed())->toBeFalse();
    });

    it('destino já mesclado em outro: 422', function () {
        $third = mergeTestPatient($this->entity);
        mergeAdmin($this, $this->target, $third)->assertOk();

        mergeAdmin($this, $this->source, $this->target)->assertStatus(422);
    });

    it('origem já mesclada: a segunda tentativa dá 404 (cadastro excluído) e nada se repete', function () {
        mergeAdmin($this, $this->source, $this->target)->assertOk();

        mergeAdmin($this, $this->source, $this->target)->assertNotFound();
    });

    it('confirmação ausente ou falsa: 422 e nada muda', function () {
        mergeAs($this, 'admin', $this->source, ['target_id' => $this->target->id])->assertStatus(422)->assertJsonValidationErrors('confirm');
        mergeAs($this, 'admin', $this->source, ['target_id' => $this->target->id, 'confirm' => false])->assertStatus(422);

        expect($this->source->fresh()->trashed())->toBeFalse();
    });

    it('target_id ausente ou que não é uuid: 422', function () {
        mergeAs($this, 'admin', $this->source, ['confirm' => true])->assertStatus(422)->assertJsonValidationErrors('target_id');
        mergeAs($this, 'admin', $this->source, ['target_id' => 'abc', 'confirm' => true])->assertStatus(422)->assertJsonValidationErrors('target_id');
    });
});

describe('candidatos (GET merge-candidates)', function () {
    function mergeCandidatesFor($test, string $who, Patient $patient)
    {
        return $test->actingAs($test->{$who . 'User'})->withSession(panelSession($test->{$who . 'Eu'}))
            ->getJson(route('panel.patients.merge-candidates', $patient));
    }

    it('devolve duplicado e homônimo com id, código, nome e nascimento; duplicados primeiro; sem o próprio', function () {
        $homonym   = mergeTestPatient($this->entity, 'MARIA SOUZA DE TESTE', '1990-01-01');
        $noBirth   = mergeTestPatient($this->entity, 'MARIA SOUZA DE TESTE', null);
        $unrelated = mergeTestPatient($this->entity, 'OUTRA PESSOA QUALQUER', '1980-05-10');

        $data = collect(mergeCandidatesFor($this, 'admin', $this->source)->assertOk()->json('data'));

        expect($data->pluck('id')->all())->toContain((string) $this->target->id, (string) $homonym->id, (string) $noBirth->id)
            ->and($data->pluck('id')->all())->not->toContain((string) $this->source->id)
            ->and($data->pluck('id')->all())->not->toContain((string) $unrelated->id)
            ->and($data->first()['id'])->toBe((string) $this->target->id)
            ->and($data->first())->toMatchArray([
                'code'       => $this->target->code,
                'full_name'  => 'MARIA SOUZA DE TESTE',
                'birth_date' => '1980-05-10',
                'kind'       => 'duplicate',
            ])
            ->and($data->first()['age'])->toBeInt()
            ->and($data->firstWhere('id', (string) $homonym->id)['kind'])->toBe('homonym')
            ->and($data->firstWhere('id', (string) $noBirth->id)['birth_date'])->toBeNull()
            ->and($data->firstWhere('id', (string) $noBirth->id)['age'])->toBeNull();
    });

    it('ignora excluídos, já mesclados e outras clínicas', function () {
        $deleted = mergeTestPatient($this->entity);
        $deleted->delete();

        $mergedAway = mergeTestPatient($this->entity);
        mergeAdmin($this, $mergedAway, $this->target)->assertOk();

        $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherClinic = mergeTestPatient($otherEntity);

        $ids = collect(mergeCandidatesFor($this, 'admin', $this->source)->assertOk()->json('data'))->pluck('id')->all();

        expect($ids)->toBe([(string) $this->target->id])
            ->and($ids)->not->toContain((string) $deleted->id)
            ->and($ids)->not->toContain((string) $mergedAway->id)
            ->and($ids)->not->toContain((string) $otherClinic->id);
    });

    it('sem nenhum parecido: lista vazia', function () {
        $alone = mergeTestPatient($this->entity, 'NOME UNICO SEM PAR', '2001-02-03');

        expect(mergeCandidatesFor($this, 'admin', $alone)->assertOk()->json('data'))->toBe([]);
    });

    it('só admin consulta; paciente de outra clínica dá 404', function () {
        mergeCandidatesFor($this, 'secretary', $this->source)->assertForbidden();
        mergeCandidatesFor($this, 'doctor', $this->source)->assertForbidden();

        $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);

        mergeCandidatesFor($this, 'admin', mergeTestPatient($otherEntity))->assertNotFound();
    });
});

describe('restaurar e telas', function () {
    it('restaurar a origem mesclada: 422 com o código do destino, e continua excluída', function () {
        mergeAdmin($this, $this->source, $this->target)->assertOk();
        // Sem outro cadastro ativo de mesmo nome, a checagem de duplicado não barraria: quem barra é o merged_into_id.
        $this->target->delete();

        $response = $this->actingAs($this->adminUser)->withSession(panelSession($this->adminEu))
            ->patchJson(route('panel.patients.restore', $this->source->id))
            ->assertStatus(422);

        expect($response->json('message'))->toContain('mesclado')->toContain($this->target->code)
            ->and(Patient::query()->withTrashed()->find($this->source->id)->trashed())->toBeTrue();
    });

    it('restaurar um paciente excluído comum (não mesclado) continua funcionando', function () {
        $solo = mergeTestPatient($this->entity, 'PACIENTE SEM PAR DE NOME', '1999-09-09');
        $solo->delete();

        $this->actingAs($this->adminUser)->withSession(panelSession($this->adminEu))
            ->patchJson(route('panel.patients.restore', $solo->id))
            ->assertOk();

        expect(Patient::query()->find($solo->id))->not->toBeNull();
    });

    it('a lista de pacientes traz can_merge só para admin e só para cadastro não excluído', function () {
        $deleted = mergeTestPatient($this->entity, 'EXCLUIDO SEM PAR', '1970-01-01');
        $deleted->delete();

        $rowsFor = function (string $who) {
            $rows = [];

            $this->actingAs($this->{$who . 'User'})->withSession(panelSession($this->{$who . 'Eu'}))
                ->get(route('panel.patients.index', ['search' => '']))
                ->assertOk()
                ->assertInertia(function ($page) use (&$rows) {
                    $page->where('patients.data', function ($data) use (&$rows) {
                        $rows = collect($data)->keyBy('id')->all();

                        return true;
                    });
                });

            return $rows;
        };

        $admin     = $rowsFor('admin');
        $secretary = $rowsFor('secretary');

        expect($admin[(string) $this->source->id]['can_merge'])->toBeTrue()
            ->and($admin[(string) $deleted->id]['can_merge'])->toBeFalse()
            ->and($secretary[(string) $this->source->id]['can_merge'])->toBeFalse();
    });

    it('a gaveta (show) devolve merged_into_code do cadastro mesclado e null nos demais', function () {
        mergeAdmin($this, $this->source, $this->target)->assertOk();

        $show = fn (Patient $p) => $this->actingAs($this->adminUser)->withSession(panelSession($this->adminEu))
            ->getJson(route('panel.patients.show', $p->id))->assertOk()->json('data.merged_into_code');

        expect($show($this->source))->toBe($this->target->code)
            ->and($show($this->target))->toBeNull();
    });
});
