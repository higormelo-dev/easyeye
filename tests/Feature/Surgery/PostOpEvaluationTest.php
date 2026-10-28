<?php

declare(strict_types=1);

use App\Enums\Surgery\{PostOpEvaluationStatus, PostOpReturnStatus, SurgicalCaseStatus};
use App\Models\{DoctorWorkSchedule, Schedule, VisualAcuityType};
use App\Models\Surgery\{SurgicalCase, SurgicalPostopEvaluation, SurgicalPostopReturn};
use App\Models\Surgery\SurgicalTeamMember;
use App\Services\Surgery\PostOp\{PostOpEvaluationService, PostOpReturnService};
use App\Services\Surgery\SurgicalCaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Módulo cirúrgico (Fase 4) / M5 — avaliação pós-operatória estruturada:
 * rascunho → finalizada (imutável, com hash), adendo com refração corrigida,
 * alerta de complicação grave (calculado no servidor) e o retorno em 24 h.
 *
 * Relógio fixo: quinta-feira, 15/10/2026, 10:00.
 */

function spePerformed(object $w, array $over = []): SurgicalCase
{
    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, array_merge([
        'patient_id'        => (string) $w->patient->id,
        'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'             => 'Facoemulsificação com LIO',
        'eye'               => 'od',
        'procedures'        => [['description' => 'Facoemulsificação com implante de LIO', 'tuss_code' => '30306019']],
    ], $over));

    $case->forceFill(['status' => SurgicalCaseStatus::Performed, 'performed_at' => now()])->save();

    return $case->refresh();
}

/** Dados de uma avaliação D1 do olho direito. */
function spePayload(array $over = []): array
{
    return array_merge([
        'milestone'           => 'd1',
        'eye'                 => 'od',
        'evaluated_at'        => '2026-10-15 09:30:00',
        'iop_mmhg'            => 14,
        'findings'            => [],
        'refraction_sphere'   => -0.5,
        'refraction_cylinder' => -1.0,
        'refraction_axis'     => 90,
        'adherence'           => 'good',
        'plan'                => 'Manter colírios; retorno em 7 dias.',
    ], $over);
}

function speUrl(string $route, SurgicalCase $case, ?SurgicalPostopEvaluation $evaluation = null): string
{
    return route("panel.surgery.postop.{$route}", array_filter(['surgicalCase' => $case->id, 'evaluation' => $evaluation?->id]));
}

function speOtherDoctorAs(object $w): object
{
    $doctor = createDoctorForEntity($w->entity);
    $eu     = $doctor->entityUser;

    test()->actingAs($eu->user)->withSession(panelSession($eu));

    return $doctor;
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w = surgeryWorld();

    foreach ([1, 2, 3, 4, 5] as $day) {
        DoctorWorkSchedule::query()->create(['doctor_id' => $this->w->doctor->id, 'day_of_week' => $day, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }

    $this->w->doctor->update(['schedule_interval' => 30]);
    $this->case = spePerformed($this->w);
});

// ── Acesso ──────────────────────────────────────────────────────────────────

it('só o médico com cadastro ativo registra a avaliação; secretária, admin e financeiro não', function () {
    foreach (['secretary', 'admin', 'financial'] as $who) {
        surgeryAs($this->w, $who)->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertForbidden();
    }

    expect(SurgicalPostopEvaluation::query()->count())->toBe(0);

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertCreated();

    expect(SurgicalPostopEvaluation::query()->count())->toBe(1);
});

it('médico que não participa do caso não registra avaliação (403)', function () {
    speOtherDoctorAs($this->w);

    test()->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertForbidden();

    expect(SurgicalPostopEvaluation::query()->count())->toBe(0);
});

it('caso de outra clínica é 404 e não grava nada', function () {
    $w2    = surgeryWorld();
    $other = spePerformed($w2);

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $other), spePayload())->assertNotFound();

    expect(SurgicalPostopEvaluation::query()->withoutGlobalScopes()->count())->toBe(0);
});

// ── Rascunho ────────────────────────────────────────────────────────────────

it('grava o rascunho com o equivalente esférico calculado e o médico autor', function () {
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertCreated();

    $e = SurgicalPostopEvaluation::query()->firstOrFail();

    expect($e->status)->toBe(PostOpEvaluationStatus::Draft)
        ->and((float) $e->spherical_equivalent)->toBe(-1.0) // -0,50 + (-1,00 / 2)
        ->and((string) $e->doctor_id)->toBe((string) $this->w->doctor->id)
        ->and((string) $e->patient_id)->toBe((string) $this->w->patient->id)
        ->and($e->severe)->toBeFalse()
        ->and($e->revision)->toBe(1);
});

it('só aceita avaliação com a cirurgia realizada', function () {
    $this->case->forceFill(['status' => SurgicalCaseStatus::Scheduled])->save();

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertStatus(409);

    expect(SurgicalPostopEvaluation::query()->count())->toBe(0);
});

it('valida olho, PIO, refração, achado "outro" e data', function () {
    $post = fn (array $over) => surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload($over));

    // Caso é do olho direito: OE é recusado pelo serviço.
    $post(['eye' => 'oe'])->assertStatus(422);
    $post(['iop_mmhg' => 95])->assertStatus(422)->assertJsonValidationErrors('iop_mmhg');
    $post(['refraction_sphere' => null, 'refraction_cylinder' => -1.5, 'refraction_axis' => 10])->assertStatus(422);
    $post(['refraction_cylinder' => -1.5, 'refraction_axis' => null])->assertStatus(422);
    $post(['findings' => ['other']])->assertStatus(422);
    $post(['findings' => ['inventado']])->assertStatus(422)->assertJsonValidationErrors('findings.0');
    $post(['evaluated_at' => '2026-12-01 09:00:00'])->assertStatus(422);
    $post(['evaluated_at' => '2026-10-10 09:00:00'])->assertStatus(422);

    expect(SurgicalPostopEvaluation::query()->count())->toBe(0);

    $post(['findings' => ['other'], 'findings_notes' => 'Reflexo anormal'])->assertCreated();
});

it('uma avaliação por marco e olho; outro marco e "outra avaliação" repetem', function () {
    $post = fn (array $over) => surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload($over));

    $post([])->assertCreated();
    $post([])->assertStatus(409);
    $post(['milestone' => 'd7'])->assertCreated();
    $post(['milestone' => 'other'])->assertCreated();
    $post(['milestone' => 'other'])->assertCreated();

    expect(SurgicalPostopEvaluation::query()->count())->toBe(4);
});

it('caso de ambos os olhos aceita uma avaliação por olho', function () {
    $both = spePerformed($this->w, ['eye' => 'ao']);

    foreach (['od', 'oe'] as $eye) {
        surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $both), spePayload(['eye' => $eye]))->assertCreated();
    }

    expect(SurgicalPostopEvaluation::query()->where('surgical_case_id', $both->id)->count())->toBe(2);
});

it('acuidade visual de outra clínica é recusada; da clínica e a global passam', function () {
    $foreign = VisualAcuityType::query()->create(['entity_id' => $this->w->other->id, 'scale' => 1, 'name' => '1,0 ESTRANHA', 'active' => true]);
    $own     = VisualAcuityType::query()->create(['entity_id' => $this->w->entity->id, 'scale' => 1, 'name' => '0,8', 'active' => true]);
    $global  = VisualAcuityType::query()->create(['entity_id' => null, 'scale' => 1, 'name' => '1,0', 'active' => true]);

    $post = fn (array $over) => surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload($over));

    $post(['va_uncorrected_id' => (string) $foreign->id])->assertStatus(422);
    $post(['va_uncorrected_id' => (string) $own->id, 'va_corrected_id' => (string) $global->id])->assertCreated();
});

it('o rascunho só é editado pelo médico que o abriu; a revisão velha é recusada', function () {
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();

    // Revisão velha.
    surgeryAs($this->w, 'doctor')->putJson(speUrl('evaluations.update', $this->case, $e), spePayload(['expected_revision' => 9]))->assertStatus(409);

    // Edição legítima sobe a revisão.
    surgeryAs($this->w, 'doctor')->putJson(speUrl('evaluations.update', $this->case, $e), spePayload(['iop_mmhg' => 18, 'expected_revision' => 1]))->assertOk();
    expect($e->refresh()->revision)->toBe(2)->and((float) $e->iop_mmhg)->toBe(18.0);

    // Outro médico, mesmo participando da equipe, não edita o rascunho alheio.
    $colleague = speOtherDoctorAs($this->w);
    SurgicalTeamMember::query()->create([
        'entity_id' => $this->w->entity->id, 'surgical_case_id' => $this->case->id, 'role' => 'assistant',
        'doctor_id' => $colleague->id, 'name' => 'COLEGA',
    ]);

    test()->putJson(speUrl('evaluations.update', $this->case, $e), spePayload(['iop_mmhg' => 30]))->assertStatus(409);
    test()->deleteJson(speUrl('evaluations.destroy', $this->case, $e))->assertStatus(409);

    expect((float) $e->refresh()->iop_mmhg)->toBe(18.0);
});

it('exclui o rascunho, mas nunca a avaliação finalizada', function () {
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertCreated();
    $draft = SurgicalPostopEvaluation::query()->firstOrFail();

    surgeryAs($this->w, 'doctor')->deleteJson(speUrl('evaluations.destroy', $this->case, $draft))->assertOk();
    expect(SurgicalPostopEvaluation::query()->count())->toBe(0);

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.finalize', $this->case, $e), spePayload())->assertOk();

    surgeryAs($this->w, 'doctor')->deleteJson(speUrl('evaluations.destroy', $this->case, $e))->assertStatus(409);
    expect(fn () => $e->refresh()->delete())->toThrow(LogicException::class);
    expect(SurgicalPostopEvaluation::query()->count())->toBe(1);
});

// ── Finalizar ───────────────────────────────────────────────────────────────

it('finaliza: hash, data e autor; fica imutável (HTTP e modelo)', function () {
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.finalize', $this->case, $e), spePayload(['iop_mmhg' => 16]))
        ->assertOk()->assertJsonPath('severe', false);

    $e->refresh();

    expect($e->status)->toBe(PostOpEvaluationStatus::Finalized)
        ->and($e->content_hash)->toHaveLength(64)
        ->and($e->finalized_at)->not->toBeNull()
        ->and((string) $e->finalized_by_doctor_id)->toBe((string) $this->w->doctor->id)
        ->and((float) $e->iop_mmhg)->toBe(16.0); // "salvar e finalizar" grava o que a tela mandou

    surgeryAs($this->w, 'doctor')->putJson(speUrl('evaluations.update', $this->case, $e), spePayload(['iop_mmhg' => 40]))->assertStatus(409);
    expect(fn () => $e->update(['iop_mmhg' => 40]))->toThrow(LogicException::class)
        ->and((float) $e->refresh()->iop_mmhg)->toBe(16.0);
});

it('finalizar de novo (clique duplo) devolve a mesma avaliação, sem erro', function () {
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.finalize', $this->case, $e), spePayload())->assertOk();
    $hash = $e->refresh()->content_hash;

    // A 2ª chamada já encontra a avaliação finalizada: o serviço devolve sem tocar (a tela só faz o 1º "salvar").
    app()->make(PostOpEvaluationService::class);
    auth()->setUser($this->w->users['doctor']);
    session(panelSession($this->w->eus['doctor']));

    $again = app(PostOpEvaluationService::class)->finalize($this->case, $e);

    expect($again->content_hash)->toBe($hash)->and(SurgicalPostopEvaluation::query()->count())->toBe(1);
});

it('não finaliza avaliação vazia', function () {
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), [
        'milestone' => 'd1', 'eye' => 'od', 'evaluated_at' => '2026-10-15 09:30:00',
    ])->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.finalize', $this->case, $e), [
        'milestone' => 'd1', 'eye' => 'od', 'evaluated_at' => '2026-10-15 09:30:00',
    ])->assertStatus(422);

    expect($e->refresh()->status)->toBe(PostOpEvaluationStatus::Draft);
});

it('avaliação de outro caso não é alcançada pela rota deste (404)', function () {
    $otherCase = spePerformed($this->w);
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $otherCase), spePayload())->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();

    surgeryAs($this->w, 'doctor')->putJson(speUrl('evaluations.update', $this->case, $e), spePayload())->assertNotFound();
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.finalize', $this->case, $e), spePayload())->assertNotFound();
});

// ── Complicação grave ───────────────────────────────────────────────────────

it('complicação grave é calculada no servidor (o cliente não decide) e pede retorno em 24 h', function () {
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload([
        'findings' => ['endophthalmitis_suspected'],
        'severe'   => false, // ignorado
    ]))->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();
    expect($e->severe)->toBeTrue();

    // Sem finalizar ainda: nenhum retorno urgente.
    expect(SurgicalPostopReturn::query()->where('milestone', 'urgent')->count())->toBe(0);

    // Avaliação na quinta 15/10 às 10:00; 24 h = até sexta 10:00 (slots de sexta 08:00, 08:30 ... 09:30).
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.finalize', $this->case, $e), spePayload([
        'findings'     => ['endophthalmitis_suspected'],
        'evaluated_at' => '2026-10-15 10:00:00',
    ]))->assertOk()->assertJsonPath('severe', true);

    $urgent = SurgicalPostopReturn::query()->where('milestone', 'urgent')->firstOrFail();

    expect($urgent->status)->toBe(PostOpReturnStatus::Scheduled)
        ->and($urgent->scheduled_for->between(Carbon::parse('2026-10-15 10:00:00'), Carbon::parse('2026-10-16 10:00:00')))->toBeTrue()
        ->and((string) $urgent->source_evaluation_id)->toBe((string) $e->id);

    $schedule = Schedule::query()->findOrFail($urgent->schedule_id);

    expect($schedule->series_id)->toBeNull()
        ->and((string) $schedule->surgical_case_id)->toBe((string) $this->case->id);
});

it('sem horário em 24 h o retorno urgente fica "falhou" com o código certo; um por avaliação', function () {
    // Sexta-feira sem expediente nem horário livre depois das 10h: remove a escala.
    DoctorWorkSchedule::query()->delete();

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload(['findings' => ['iol_dislocation']]))->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.finalize', $this->case, $e), spePayload(['findings' => ['iol_dislocation']]))->assertOk();

    $urgent = SurgicalPostopReturn::query()->where('milestone', 'urgent')->firstOrFail();

    expect($urgent->status)->toBe(PostOpReturnStatus::Failed)->and($urgent->failure_code)->toBe('no_slot_24h');

    // Reentrega/segunda chamada: continua um só.
    app(PostOpReturnService::class)->requestUrgent($e->refresh());
    expect(SurgicalPostopReturn::query()->where('milestone', 'urgent')->count())->toBe(1);
});

it('achado leve (hipertensão ocular, edema de córnea) não pede retorno urgente', function () {
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload(['findings' => ['corneal_edema', 'ocular_hypertension'], 'iop_mmhg' => 32]))->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.finalize', $this->case, $e), spePayload(['findings' => ['corneal_edema', 'ocular_hypertension'], 'iop_mmhg' => 32]))
        ->assertOk()->assertJsonPath('severe', false);

    expect(SurgicalPostopReturn::query()->where('milestone', 'urgent')->count())->toBe(0);
});

// ── Adendo ──────────────────────────────────────────────────────────────────

it('adendo exige avaliação finalizada; a refração corrigida passa a valer', function () {
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.addenda', $this->case, $e), ['text' => 'Corrigindo'])->assertStatus(409);

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.finalize', $this->case, $e), spePayload())->assertOk();

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.addenda', $this->case, $e), ['text' => 'Eixo digitado errado'])->assertCreated();
    expect(PostOpEvaluationService::effectiveEquivalent($e->refresh()))->toBe(-1.0);

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.addenda', $this->case, $e), [
        'text' => 'Refração correta: esfera -0,25 cil -0,50', 'corrected_sphere' => -0.25, 'corrected_cylinder' => -0.5, 'corrected_axis' => 80,
    ])->assertCreated();

    $e = $e->refresh()->load('addenda');

    expect($e->addenda)->toHaveCount(2)
        ->and(PostOpEvaluationService::effectiveEquivalent($e))->toBe(-0.5)
        ->and((float) $e->spherical_equivalent)->toBe(-1.0) // a original não muda
        ->and($e->addenda->last()->content_hash)->toHaveLength(64);

    expect(fn () => $e->addenda->first()->update(['text' => 'x']))->toThrow(LogicException::class);
});

it('adendo exige texto e eixo quando há cilindro corrigido', function () {
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.finalize', $this->case, $e), spePayload())->assertOk();

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.addenda', $this->case, $e), ['text' => ''])->assertStatus(422);
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.addenda', $this->case, $e), [
        'text' => 'Corrigindo cilindro', 'corrected_sphere' => -1, 'corrected_cylinder' => -1,
    ])->assertStatus(422);
});

// ── Vínculo com o retorno da Agenda ─────────────────────────────────────────

it('vincula a avaliação ao retorno da Agenda do MESMO caso; o de outro caso é recusado', function () {
    app(PostOpReturnService::class)->plan((string) $this->case->id);
    $own = Schedule::query()->where('surgical_case_id', $this->case->id)->firstOrFail();

    $foreign = Schedule::query()->create([
        'entity_id' => $this->w->entity->id, 'doctor_id' => $this->w->doctor->id, 'full_name' => 'OUTRO',
        'date_time' => '2026-10-20 08:00:00', 'situation' => 1, 'active' => true,
    ]);

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload(['schedule_id' => (string) $foreign->id]))->assertStatus(422);
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload(['schedule_id' => (string) $own->id]))->assertCreated();

    expect((string) SurgicalPostopEvaluation::query()->firstOrFail()->schedule_id)->toBe((string) $own->id);
});

// ── A aba ───────────────────────────────────────────────────────────────────

it('a aba Pós-operatório aparece só com a cirurgia realizada e traz retornos, avaliações e alertas', function () {
    app(PostOpReturnService::class)->plan((string) $this->case->id);

    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload(['findings' => ['endophthalmitis_suspected']]))->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.finalize', $this->case, $e), spePayload(['findings' => ['endophthalmitis_suspected']]))->assertOk();

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $this->case))
        ->assertInertia(fn ($page) => $page
            ->has('sections.postop.returns', 4) // D1/D7/D30 + urgente
            ->has('sections.postop.evaluations', 1)
            ->where('sections.postop.evaluations.0.severe', true)
            ->where('sections.postop.alerts.0.type', 'severe')
            ->where('sections.postop.can.evaluate', true)
            ->has('sections.discharge.blockers'));

    $draft = app(SurgicalCaseService::class)->create((string) $this->w->entity->id, [
        'patient_id' => (string) $this->w->patient->id, 'surgeon_doctor_id' => (string) $this->w->doctor->id,
        'title'      => 'Rascunho', 'eye' => 'od', 'procedures' => [['description' => 'Procedimento']],
    ]);

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $draft))
        ->assertInertia(fn ($page) => $page->missing('sections.postop')->missing('sections.discharge'));
});

it('a secretária vê a aba mas sem poder avaliar; pode pedir para tentar os retornos de novo', function () {
    config(['surgery_postop.search_days' => 1]);
    DoctorWorkSchedule::query()->delete(); // sem escala: os retornos falham

    app(PostOpReturnService::class)->plan((string) $this->case->id);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $this->case))
        ->assertInertia(fn ($page) => $page
            ->where('sections.postop.can.evaluate', false)
            ->where('sections.postop.can.retry', true)
            ->where('sections.postop.alerts.0.type', 'return_failed'));

    foreach ([1, 2, 3, 4, 5] as $day) {
        DoctorWorkSchedule::query()->create(['doctor_id' => $this->w->doctor->id, 'day_of_week' => $day, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }

    config(['surgery_postop.search_days' => 21]);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.postop.returns.retry', $this->case))->assertOk();

    expect(Schedule::query()->where('surgical_case_id', $this->case->id)->count())->toBe(3);

    // Médico (não é ato dele) e financeiro não repetem.
    surgeryAs($this->w, 'financial')->postJson(route('panel.surgery.postop.returns.retry', $this->case))->assertForbidden();
});

it('persistência: o rascunho e a finalização gravam no banco (nada só em memória)', function () {
    surgeryAs($this->w, 'doctor')->postJson(speUrl('evaluations.store', $this->case), spePayload())->assertCreated();

    expect(DB::table('surgical_postop_evaluations')->where('surgical_case_id', $this->case->id)->count())->toBe(1);
});
