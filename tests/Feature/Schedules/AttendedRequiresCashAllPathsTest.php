<?php

declare(strict_types=1);

/*
 * Regra "Atendido exige caixa" (Entity.requires_cash_to_complete) aplicada em
 * TODO caminho que grava schedules.situation no fluxo vivo.
 *
 * Antes a trava existia só no PATCH schedules/{id}/situation e no "Finalizar"
 * do prontuário. Estes caminhos marcavam Atendido sem lançamento no caixa:
 *   - POST schedules/bulk-update (ScheduleService::bulkUpdateSituation);
 *   - POST schedules / PUT schedules/{id} (ScheduleRequest aceitava
 *     `situation` e o controller gravava $request->validated() direto).
 * Agora a regra mora num lugar só — ScheduleService::changeSituation() — e
 * todo writer do fluxo passa por ele.
 *
 * Import de agendamentos (ScheduleImportService) fica FORA de propósito: é
 * carga de histórico/migração (consultas passadas já atendidas no sistema
 * anterior, sem caixa no EasyEye) e não é o fluxo vivo da recepção.
 */

use App\Enums\{CashEntryReferenceType, ClientRule, FinancialEntryStatus, FinancialEntryType, ImportStatus, ScheduleSituation};
use App\Exceptions\AttendanceRequiresCashEntryException;
use App\Models\{Entity, FinancialCashEntry, Schedule, ScheduleImport, ScheduleSituationLog, User};
use App\Services\{ScheduleImportService, ScheduleService};
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->entity = Entity::factory()->create([
        'is_client'                 => true,
        'active'                    => true,
        'requires_cash_to_complete' => true,
    ]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Admin->value);
    $this->doctor     = createDoctorForEntity($this->entity);

    // Outra clínica (tenant B), com a flag DESLIGADA.
    $this->otherEntity = Entity::factory()->create([
        'is_client'                 => true,
        'active'                    => true,
        'requires_cash_to_complete' => false,
    ]);
});

/** Agendamento "Em consulta" da clínica (pronto para ser concluído). */
function acrSchedule(Entity $entity, array $attrs = []): Schedule
{
    return createScheduleForEntity($entity, array_merge([
        'situation' => ScheduleSituation::InProgress->value,
    ], $attrs))['schedule'];
}

/** Lançamento de caixa vinculado ao agendamento (gravado direto, sem request). */
function acrCashEntry(Entity $owner, Schedule $schedule, array $extra = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id'      => $owner->id,
        'entry_date'     => now()->toDateString(),
        'description'    => 'Consulta particular',
        'type'           => FinancialEntryType::Income->value,
        'status'         => FinancialEntryStatus::Paid->value,
        'amount'         => 150,
        'active'         => true,
        'reference_type' => CashEntryReferenceType::Schedule->value,
        'reference_id'   => $schedule->id,
    ], $extra));
}

/** @param string[] $ids */
function acrBulk($test, array $ids, ScheduleSituation $situation): TestResponse
{
    return $test->actingAs($test->user)
        ->withSession(panelSession($test->entityUser))
        ->postJson(route('panel.schedules.bulk-update'), [
            'ids'       => $ids,
            'situation' => $situation->value,
        ]);
}

function acrStore($test, array $payload = []): TestResponse
{
    return $test->actingAs($test->user)
        ->withSession(panelSession($test->entityUser))
        ->postJson(route('panel.schedules.store'), array_merge([
            'doctor_id' => $test->doctor->id,
            'full_name' => 'Paciente Novo',
            'date_time' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'),
        ], $payload));
}

function acrUpdate($test, Schedule $schedule, array $payload = []): TestResponse
{
    return $test->actingAs($test->user)
        ->withSession(panelSession($test->entityUser))
        ->putJson(route('panel.schedules.update', $schedule), array_merge([
            'doctor_id' => $schedule->doctor_id,
            'full_name' => $schedule->full_name,
            'date_time' => $schedule->date_time->format('Y-m-d H:i:s'),
        ], $payload));
}

function acrLogCount(Schedule $schedule): int
{
    return ScheduleSituationLog::query()->where('schedule_id', $schedule->id)->count();
}

// ── Regra central (ScheduleService::changeSituation) ────────────────────────

describe('changeSituation é o ponto único da regra', function () {
    it('recusa Atendido sem caixa: exceção de domínio, nada gravado', function () {
        $schedule = acrSchedule($this->entity);

        expect(fn () => app(ScheduleService::class)->changeSituation($schedule, ScheduleSituation::Attended, null))
            ->toThrow(AttendanceRequiresCashEntryException::class);

        expect($schedule->fresh()->situation)->toBe(ScheduleSituation::InProgress)
            ->and(acrLogCount($schedule))->toBe(0);
    });

    it('libera Atendido com lançamento ativo da própria clínica', function () {
        $schedule = acrSchedule($this->entity);
        acrCashEntry($this->entity, $schedule);

        expect(app(ScheduleService::class)->changeSituation($schedule, ScheduleSituation::Attended, null))->toBeTrue()
            ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
    });

    it('clínica com a flag desligada conclui sem caixa', function () {
        $schedule = acrSchedule($this->otherEntity);

        expect(app(ScheduleService::class)->changeSituation($schedule, ScheduleSituation::Attended, null))->toBeTrue()
            ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
    });

    it('outras situações não dependem de caixa', function () {
        $schedule = acrSchedule($this->entity);

        expect(app(ScheduleService::class)->changeSituation($schedule, ScheduleSituation::NoShow, null))->toBeTrue()
            ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::NoShow);
    });
});

// ── Bulk (POST schedules/bulk-update) ───────────────────────────────────────

describe('bulk-update para Atendido', function () {
    it('ignora a linha sem caixa com mensagem traduzida por linha e conclui a que tem caixa', function () {
        $paid   = acrSchedule($this->entity);
        $unpaid = acrSchedule($this->entity);
        acrCashEntry($this->entity, $paid);

        $response = acrBulk($this, [$paid->id, $unpaid->id], ScheduleSituation::Attended)
            ->assertOk()
            ->assertJson(['updated' => 1, 'skipped' => 1])
            ->assertJsonPath('blocked.0.id', $unpaid->id)
            ->assertJsonPath('blocked.0.code', $unpaid->code)
            ->assertJsonPath('blocked.0.message', __('schedules.bulk_row_cash_entry_required', ['code' => $unpaid->code]));

        expect($response->json('blocked'))->toHaveCount(1)
            ->and($response->json('message'))->toContain(__('schedules.bulk_skipped_cash', ['blocked' => 1]))
            ->and($paid->fresh()->situation)->toBe(ScheduleSituation::Attended)
            ->and($unpaid->fresh()->situation)->toBe(ScheduleSituation::InProgress)
            ->and(acrLogCount($unpaid))->toBe(0);
    });

    it('lançamento CANCELADO não libera a conclusão', function () {
        $schedule = acrSchedule($this->entity);
        acrCashEntry($this->entity, $schedule, ['status' => FinancialEntryStatus::Cancelled->value]);

        acrBulk($this, [$schedule->id], ScheduleSituation::Attended)
            ->assertOk()
            ->assertJson(['updated' => 0, 'skipped' => 1])
            ->assertJsonCount(1, 'blocked');

        expect($schedule->fresh()->situation)->toBe(ScheduleSituation::InProgress);
    });

    it('[tenant] lançamento de OUTRA clínica apontando para o agendamento não libera a conclusão', function () {
        $schedule = acrSchedule($this->entity);
        acrCashEntry($this->otherEntity, $schedule); // linha forjada/legada cross-tenant

        acrBulk($this, [$schedule->id], ScheduleSituation::Attended)
            ->assertOk()
            ->assertJson(['updated' => 0, 'skipped' => 1])
            ->assertJsonCount(1, 'blocked');

        expect($schedule->fresh()->situation)->toBe(ScheduleSituation::InProgress);
    });

    it('[tenant] id de agendamento de outra clínica é ignorado e não é alterado', function () {
        $foreign = acrSchedule($this->otherEntity);

        acrBulk($this, [$foreign->id], ScheduleSituation::Attended)
            ->assertOk()
            ->assertJson(['updated' => 0, 'skipped' => 1])
            ->assertJsonCount(0, 'blocked');

        expect($foreign->fresh()->situation)->toBe(ScheduleSituation::InProgress);
    });

    it('clínica com a flag desligada conclui em massa sem caixa', function () {
        $this->entity->update(['requires_cash_to_complete' => false]);
        $a = acrSchedule($this->entity);
        $b = acrSchedule($this->entity);

        acrBulk($this, [$a->id, $b->id], ScheduleSituation::Attended)
            ->assertOk()
            ->assertJson(['updated' => 2, 'skipped' => 0])
            ->assertJsonCount(0, 'blocked');

        expect($a->fresh()->situation)->toBe(ScheduleSituation::Attended)
            ->and($b->fresh()->situation)->toBe(ScheduleSituation::Attended);
    });

    it('outras situações em massa seguem sem exigir caixa (contrato de bulk inalterado)', function () {
        $a = acrSchedule($this->entity);
        $b = acrSchedule($this->entity, ['situation' => ScheduleSituation::NoShow->value]);

        acrBulk($this, [$a->id, $b->id], ScheduleSituation::NoShow)
            ->assertOk()
            ->assertJson(['updated' => 1, 'skipped' => 1])
            ->assertJsonCount(0, 'blocked');

        expect($a->fresh()->situation)->toBe(ScheduleSituation::NoShow)
            ->and(acrLogCount($a))->toBe(1);
    });
});

// ── Criação (POST schedules) ────────────────────────────────────────────────

describe('store com situation', function () {
    it('recusa criar já Atendido quando a clínica exige caixa (422) e não cria nada', function () {
        acrStore($this, ['situation' => ScheduleSituation::Attended->value])
            ->assertStatus(422)
            ->assertJson(['requires_cash_entry' => true])
            ->assertJsonPath('errors.situation.0', __('schedules.cash_entry_required'));

        expect(Schedule::query()->where('entity_id', $this->entity->id)->count())->toBe(0);
    });

    it('flag desligada: cria Atendido passando pelo fluxo (histórico de situação gravado)', function () {
        $this->entity->update(['requires_cash_to_complete' => false]);

        $id = acrStore($this, ['situation' => ScheduleSituation::Attended->value])
            ->assertCreated()
            ->json('data.id');

        $schedule = Schedule::query()->findOrFail($id);
        $log      = ScheduleSituationLog::query()->where('schedule_id', $id)->sole();

        expect($schedule->situation)->toBe(ScheduleSituation::Attended)
            ->and($log->from_situation)->toBe(ScheduleSituation::Scheduled)
            ->and($log->to_situation)->toBe(ScheduleSituation::Attended)
            ->and($log->entity_user_id)->toBe($this->entityUser->id);
    });

    it('situation pedida passa pelo fluxo: Aguardando grava a chegada', function () {
        $id = acrStore($this, ['situation' => ScheduleSituation::Waiting->value])
            ->assertCreated()
            ->json('data.id');

        $schedule = Schedule::query()->findOrFail($id);

        expect($schedule->situation)->toBe(ScheduleSituation::Waiting)
            ->and($schedule->arrived_at)->not->toBeNull()
            ->and(acrLogCount($schedule))->toBe(1);
    });

    it('sem situation nasce Agendado e sem histórico (contrato da tela inalterado)', function () {
        $id = acrStore($this)->assertCreated()->json('data.id');

        $schedule = Schedule::query()->findOrFail($id);

        expect($schedule->situation)->toBe(ScheduleSituation::Scheduled)
            ->and(acrLogCount($schedule))->toBe(0);
    });

    it('situation inválida vira erro de validação (antes estourava 500 no cast do enum)', function () {
        acrStore($this, ['situation' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors('situation');

        expect(Schedule::query()->where('entity_id', $this->entity->id)->count())->toBe(0);
    });
});

// ── Edição (PUT schedules/{id}) ─────────────────────────────────────────────

describe('update com situation', function () {
    it('recusa Atendido sem caixa (422) e não grava NENHUM campo do formulário', function () {
        $schedule = acrSchedule($this->entity, ['notes' => 'original']);

        acrUpdate($this, $schedule, [
            'situation' => ScheduleSituation::Attended->value,
            'notes'     => 'alterada junto',
        ])
            ->assertStatus(422)
            ->assertJson(['requires_cash_entry' => true])
            ->assertJsonPath('errors.situation.0', __('schedules.cash_entry_required'));

        $fresh = $schedule->fresh();

        expect($fresh->situation)->toBe(ScheduleSituation::InProgress)
            ->and($fresh->notes)->toBe('original')
            ->and(acrLogCount($schedule))->toBe(0);
    });

    it('com caixa lançado conclui passando pelo fluxo (histórico gravado)', function () {
        $schedule = acrSchedule($this->entity);
        acrCashEntry($this->entity, $schedule);

        acrUpdate($this, $schedule, ['situation' => ScheduleSituation::Attended->value])->assertOk();

        expect($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended)
            ->and(acrLogCount($schedule))->toBe(1);
    });

    it('sem situation salva os demais campos e mantém a situação', function () {
        $schedule = acrSchedule($this->entity, ['situation' => ScheduleSituation::Waiting->value]);

        acrUpdate($this, $schedule, ['notes' => 'nova observação'])->assertOk();

        $fresh = $schedule->fresh();

        expect($fresh->situation)->toBe(ScheduleSituation::Waiting)
            ->and($fresh->notes)->toBe('nova observação')
            ->and(acrLogCount($schedule))->toBe(0);
    });

    it('mesma situação atual é no-op (sem histórico)', function () {
        $schedule = acrSchedule($this->entity);

        acrUpdate($this, $schedule, ['situation' => ScheduleSituation::InProgress->value])->assertOk();

        expect(acrLogCount($schedule))->toBe(0);
    });

    it('mover para horário ocupado por OUTRO agendamento do médico é 422 em date_time (antes: 500 no bind do model)', function () {
        $schedule = acrSchedule($this->entity, ['date_time' => now()->addDays(2)->setTime(9, 0)]);
        $taken    = now()->addDays(2)->setTime(11, 0);
        acrSchedule($this->entity, ['doctor_id' => $schedule->doctor_id, 'date_time' => $taken]);

        acrUpdate($this, $schedule, ['date_time' => $taken->format('Y-m-d H:i:s')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_time');

        expect($schedule->fresh()->date_time->format('H:i'))->toBe('09:00');
    });
});

// ── PATCH schedules/{id}/situation (contrato preservado) ────────────────────

describe('PATCH situation', function () {
    it('mantém 422 com requires_cash_entry quando falta caixa', function () {
        $schedule = acrSchedule($this->entity);

        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->patchJson(route('panel.schedules.situation', $schedule), ['situation' => ScheduleSituation::Attended->value])
            ->assertStatus(422)
            ->assertJson([
                'message'             => __('schedules.cash_entry_required'),
                'requires_cash_entry' => true,
            ]);

        expect($schedule->fresh()->situation)->toBe(ScheduleSituation::InProgress);
    });
});

// ── Import (decisão: histórico fica fora da regra) ──────────────────────────

describe('import de agendamentos', function () {
    it('importa consulta passada como Atendido mesmo com a clínica exigindo caixa', function () {
        Storage::fake('private');

        $this->doctor->forceFill(['import_code' => 'DOC-ACR'])->save();

        $path = "imports/schedules/{$this->entity->id}/acr.csv";
        Storage::disk('private')->put($path, "\xEF\xBB\xBF"
            . "codigo_importacao_medico;nome_paciente;data_hora;situacao;codigo_importacao\n"
            . 'DOC-ACR;Paciente Historico;' . now()->subMonth()->format('d/m/Y') . " 09:00;Atendido;ACR-1\n");

        $import = ScheduleImport::create([
            'entity_id'     => $this->entity->id,
            'user_id'       => $this->user->id,
            'status'        => ImportStatus::Pending,
            'file_path'     => $path,
            'original_name' => 'acr.csv',
        ]);

        app(ScheduleImportService::class)->process($import);

        expect($import->fresh()->imported_rows)->toBe(1)
            ->and(Schedule::query()->where('import_code', 'ACR-1')->sole()->situation)->toBe(ScheduleSituation::Attended);
    });
});
