<?php

declare(strict_types=1);

use App\Enums\Surgery\ChecklistAnswer;
use App\Enums\Surgery\{ChecklistPhase, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Surgery\{SurgicalCase, SurgicalChecklistEntry, SurgicalChecklistPhase};
use App\Services\Surgery\Guards\ChecklistTimeOutGuard;
use App\Services\Surgery\{SurgeryAuthorizationService, SurgeryChecklistService, SurgicalCaseService};
use App\Support\Surgery\CanonicalHash;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Módulo cirúrgico (Fase 4) / M2 — checklist de cirurgia segura (OMS): fases
 * Entrada / Pausa Cirúrgica / Saída, fechamento imutável com hash, texto
 * congelado no idioma de quem respondeu e o guard da Pausa Cirúrgica.
 */

/** Caso AGENDADO (estado que aceita o checklist), cirurgião próprio. */
function sckCase(object $w, array $over = [], SurgicalCaseStatus $status = SurgicalCaseStatus::Scheduled): SurgicalCase
{
    $surgeon = $over['surgeon'] ?? $w->doctor;
    unset($over['surgeon']);

    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, array_merge([
        'patient_id'        => (string) $w->patient->id,
        'surgeon_doctor_id' => (string) $surgeon->id,
        'title'             => 'Facoemulsificação',
        'eye'               => 'od',
        'anesthesia'        => 'topical',
        'procedures'        => [['description' => 'Facoemulsificação com LIO']],
    ], $over));

    app(SurgeryAuthorizationService::class)->request($case);
    app(SurgeryAuthorizationService::class)->record($case, ['type' => 'waived', 'note' => 'Particular']);

    $case->refresh()->forceFill([
        'status'          => $status,
        'scheduled_start' => '2026-10-19 09:00:00',
        'scheduled_end'   => '2026-10-19 10:00:00',
    ])->save();

    return $case->refresh();
}

/** Respostas "sim" para todos os itens da fase (ou o que o teste sobrescrever). @return list<array<string, mixed>> */
function sckAnswers(ChecklistPhase $phase, array $override = []): array
{
    return array_map(
        fn (array $item) => ['key' => $item['key'], 'answer' => $override[$item['key']]['answer'] ?? 'yes', 'note' => $override[$item['key']]['note'] ?? null],
        app(SurgeryChecklistService::class)->items($phase),
    );
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w = surgeryWorld();
});

// ── Catálogo ────────────────────────────────────────────────────────────────

it('todo item do catálogo tem texto nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach (ChecklistPhase::cases() as $phase) {
            expect(config("surgery_checklist.phases.{$phase->value}"))->not->toBeEmpty();

            foreach (config("surgery_checklist.phases.{$phase->value}") as $item) {
                $key = "surgery_schedule.checklist.items.{$item['key']}.text";
                expect(trans($key, [], $locale))->not->toBe($key)->and(trans($key, [], $locale))->toBeString();
            }

            expect($phase->label())->not->toStartWith('surgery_schedule.')->and($phase->when())->not->toStartWith('surgery_schedule.');
        }
    }
});

it('item crítico sempre exige resposta definitiva e a lista tem os itens oftalmológicos centrais', function () {
    $keys = collect(config('surgery_checklist.phases'))->flatten(1)->pluck('key');

    expect($keys)->toContain('eye_marked', 'consent_signed', 'allergies_reviewed', 'anticoagulant_aware', 'ifis_aware', 'anesthesia_confirmed', 'iol_checked', 'implant_recorded', 'recovery_plan', 'patient_procedure_eye');
});

// ── Aba ─────────────────────────────────────────────────────────────────────

it('a aba só aparece com a cirurgia agendada, realizada ou com alta', function () {
    $case = sckCase($this->w, [], SurgicalCaseStatus::Authorized);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page->where('tabs', fn ($tabs) => ! collect($tabs)->pluck('key')->contains('checklist')));

    foreach ([SurgicalCaseStatus::Scheduled, SurgicalCaseStatus::Performed, SurgicalCaseStatus::Discharged] as $status) {
        $case->forceFill(['status' => $status])->save();

        surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $case))
            ->assertInertia(fn ($page) => $page->where('tabs', fn ($tabs) => collect($tabs)->pluck('key')->contains('checklist')));
    }
});

it('entrega as 3 fases, sugere "não se aplica" sem impor e mostra o retrato de segurança', function () {
    $case = sckCase($this->w);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page
            ->where('sections.checklist.can_answer', true)
            ->has('sections.checklist.phases', 3)
            ->where('sections.checklist.phases.0.phase', 'sign_in')
            ->where('sections.checklist.phases.0.state', 'not_started')
            ->where('sections.checklist.phases.0.total', 9)
            ->where('sections.checklist.phases.1.phase', 'time_out')
            ->where('sections.checklist.phases.2.phase', 'sign_out')
            ->where('sections.checklist.catalog_version', config('surgery_checklist.version'))
            ->where('sections.checklist.time_out_satisfied', false)
            ->has('sections.checklist.safety')
            ->where('sections.checklist.phases.0.items', fn ($items) => (bool) collect($items)->firstWhere('key', 'fasting_confirmed')['suggested_na'])
            ->where('sections.checklist.phases.1.items', fn ($items) => (bool) collect($items)->firstWhere('key', 'iol_checked')['suggested_na']));
});

// ── Responder e fechar ──────────────────────────────────────────────────────

it('salva respostas parcialmente: a fase abre e o texto fica congelado no idioma de quem respondeu', function () {
    $case = sckCase($this->w);

    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.checklist.save', [$case, 'sign_in']), ['answers' => [
            ['key' => 'identity_confirmed', 'answer' => 'yes'],
            ['key' => 'eye_marked', 'answer' => 'no', 'note' => 'Marcação apagada, refeita'],
        ]])
        ->assertOk();

    $phase = SurgicalChecklistPhase::query()->where('surgical_case_id', $case->id)->firstOrFail();

    expect($phase->phase)->toBe(ChecklistPhase::SignIn)
        ->and($phase->isClosed())->toBeFalse()
        ->and($phase->catalog_version)->toBe(config('surgery_checklist.version'))
        ->and($phase->entries)->toHaveCount(2);

    $entry = $phase->entries->firstWhere('item_key', 'identity_confirmed');

    expect($entry->item_text)->toBe(trans('surgery_schedule.checklist.items.identity_confirmed.text'))
        ->and($entry->critical)->toBeTrue()
        ->and($phase->entries->firstWhere('item_key', 'eye_marked')->note)->toBe('Marcação apagada, refeita');

    // Regravar a mesma pergunta troca a resposta (não duplica).
    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.checklist.save', [$case, 'sign_in']), ['answers' => [['key' => 'eye_marked', 'answer' => 'yes']]])
        ->assertOk();

    expect(SurgicalChecklistEntry::query()->where('surgical_case_id', $case->id)->count())->toBe(2)
        ->and(SurgicalChecklistEntry::query()->where('item_key', 'eye_marked')->value('answer'))->toBe(ChecklistAnswer::Yes);
});

it('recusa item desconhecido, resposta inválida e "não se aplica" onde o catálogo não permite', function () {
    $case = sckCase($this->w);
    $url  = route('panel.surgery.checklist.save', [$case, 'sign_in']);

    surgeryAs($this->w, 'secretary')->putJson($url, ['answers' => [['key' => 'inexistente', 'answer' => 'yes']]])->assertStatus(422);
    surgeryAs($this->w, 'secretary')->putJson($url, ['answers' => [['key' => 'identity_confirmed', 'answer' => 'talvez']]])->assertStatus(422);
    surgeryAs($this->w, 'secretary')->putJson($url, ['answers' => [['key' => 'identity_confirmed', 'answer' => 'na']]])->assertStatus(422);
    surgeryAs($this->w, 'secretary')->putJson($url, ['answers' => [['key' => 'fasting_confirmed', 'answer' => 'na']]])->assertOk();

    expect(SurgicalChecklistEntry::query()->count())->toBe(1);
});

it('fechar exige todos os itens respondidos e lista os que faltam', function () {
    $case = sckCase($this->w);

    $response = surgeryAs($this->w, 'doctor')
        ->postJson(route('panel.surgery.checklist.close', [$case, 'sign_in']), ['answers' => [['key' => 'identity_confirmed', 'answer' => 'yes']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('checklist');

    expect($response->json('errors.checklist'))->toHaveCount(8);
    expect(SurgicalChecklistPhase::query()->whereNotNull('closed_at')->count())->toBe(0);
});

it('item crítico "não" só fecha com nota; item não crítico "não" fecha sem nota', function () {
    $case = sckCase($this->w);
    $url  = route('panel.surgery.checklist.close', [$case, 'sign_in']);

    $answers = sckAnswers(ChecklistPhase::SignIn, [
        'eye_marked'     => ['answer' => 'no'],            // crítico sem nota
        'vitals_checked' => ['answer' => 'no'],           // não crítico
    ]);

    surgeryAs($this->w, 'doctor')->postJson($url, ['answers' => $answers])
        ->assertStatus(422)
        ->assertJsonPath('errors.checklist.0', __('surgery_schedule.checklist.errors.critical_needs_note', ['item' => trans('surgery_schedule.checklist.items.eye_marked.text')]));

    $answers = sckAnswers(ChecklistPhase::SignIn, [
        'eye_marked'     => ['answer' => 'no', 'note' => 'Refeita a marcação com o paciente'],
        'vitals_checked' => ['answer' => 'no'],
    ]);

    surgeryAs($this->w, 'doctor')->postJson($url, ['answers' => $answers])->assertOk();

    expect(SurgicalChecklistPhase::query()->where('phase', 'sign_in')->firstOrFail()->isClosed())->toBeTrue();
});

it('fecha a fase: hash do conteúdo, quem fechou e catálogo gravados', function () {
    $case = sckCase($this->w);

    surgeryAs($this->w, 'doctor')
        ->postJson(route('panel.surgery.checklist.close', [$case, 'time_out']), ['answers' => sckAnswers(ChecklistPhase::TimeOut)])
        ->assertOk();

    $phase = SurgicalChecklistPhase::query()->with('entries')->where('phase', 'time_out')->firstOrFail();

    $expected = CanonicalHash::of([
        'v'       => CanonicalHash::VERSION,
        'catalog' => $phase->catalog_version,
        'case'    => (string) $case->id,
        'phase'   => 'time_out',
        'locale'  => $phase->locale,
        'entries' => array_map(function (array $item) use ($phase) {
            $entry = $phase->entries->firstWhere('item_key', $item['key']);

            return ['key' => $item['key'], 'text' => $entry->item_text, 'critical' => (bool) $entry->critical, 'answer' => $entry->answer->value, 'note' => $entry->note];
        }, app(SurgeryChecklistService::class)->items(ChecklistPhase::TimeOut)),
    ]);

    expect($phase->isClosed())->toBeTrue()
        ->and($phase->content_hash)->toBe($expected)->and($phase->content_hash)->toHaveLength(64)
        ->and((string) $phase->closed_by)->toBe((string) $this->w->users['doctor']->id)
        ->and((string) $phase->closed_by_doctor_id)->toBe((string) $this->w->doctor->id)
        ->and($phase->closed_at)->not->toBeNull();
});

it('fase fechada é imutável: nova resposta é 409 e os models recusam alterar e apagar', function () {
    $case = sckCase($this->w);

    surgeryAs($this->w, 'doctor')
        ->postJson(route('panel.surgery.checklist.close', [$case, 'sign_out']), ['answers' => sckAnswers(ChecklistPhase::SignOut)])
        ->assertOk();

    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.checklist.save', [$case, 'sign_out']), ['answers' => [['key' => 'recovery_plan', 'answer' => 'no']]])
        ->assertStatus(409);

    surgeryAs($this->w, 'doctor')
        ->postJson(route('panel.surgery.checklist.close', [$case, 'sign_out']), ['answers' => sckAnswers(ChecklistPhase::SignOut)])
        ->assertStatus(409);

    $phase = SurgicalChecklistPhase::query()->firstOrFail();
    $entry = SurgicalChecklistEntry::query()->firstOrFail();
    $hash  = $phase->content_hash;

    expect(fn () => $phase->update(['content_hash' => str_repeat('a', 64)]))->toThrow(LogicException::class);
    expect(fn () => $phase->update(['override_reason' => 'tardio']))->toThrow(LogicException::class);
    expect(fn () => $entry->update(['answer' => 'no']))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);
    expect(fn () => $phase->delete())->toThrow(LogicException::class);
    expect(fn () => SurgicalChecklistEntry::query()->create([
        'entity_id' => $case->entity_id, 'surgical_case_id' => $case->id, 'surgical_checklist_phase_id' => $phase->id,
        'phase'     => 'sign_out', 'item_key' => 'novo', 'item_text' => 'x', 'critical' => false, 'answer' => 'yes', 'answered_at' => now(),
    ]))->toThrow(LogicException::class);

    expect($phase->fresh()->content_hash)->toBe($hash);
});

it('o texto conferido fica congelado: trocar o idioma depois não muda a fase fechada', function () {
    $case = sckCase($this->w);

    // Pelo serviço (a requisição HTTP reaplica o idioma do usuário via SetLocale).
    $this->actingAs($this->w->users['secretary'])->withSession(panelSession($this->w->eus['secretary']));
    app()->setLocale('en');
    app(SurgeryChecklistService::class)->close($case, ChecklistPhase::SignOut, sckAnswers(ChecklistPhase::SignOut));

    app()->setLocale('pt_BR');

    $overview = app(SurgeryChecklistService::class)->overview($case->refresh());
    $signOut  = collect($overview['phases'])->firstWhere('phase', 'sign_out');
    $item     = collect($signOut['items'])->firstWhere('key', 'recovery_plan');

    expect($item['text'])->toBe(trans('surgery_schedule.checklist.items.recovery_plan.text', [], 'en'))
        ->and($item['text'])->not->toBe(trans('surgery_schedule.checklist.items.recovery_plan.text', [], 'pt_BR'))
        ->and(SurgicalChecklistPhase::query()->value('locale'))->toBe('en');
});

// ── Estado do caso, perfis e isolamento ─────────────────────────────────────

it('só trabalha o checklist com a cirurgia agendada ou realizada', function () {
    foreach ([SurgicalCaseStatus::Authorized, SurgicalCaseStatus::Cancelled, SurgicalCaseStatus::Suspended, SurgicalCaseStatus::Discharged] as $status) {
        $case = sckCase($this->w, [], $status);

        surgeryAs($this->w, 'secretary')
            ->putJson(route('panel.surgery.checklist.save', [$case, 'sign_in']), ['answers' => [['key' => 'identity_confirmed', 'answer' => 'yes']]])
            ->assertStatus(409);
    }

    $performed = sckCase($this->w, [], SurgicalCaseStatus::Performed);

    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.checklist.save', [$performed, 'sign_out']), ['answers' => [['key' => 'recovery_plan', 'answer' => 'yes']]])
        ->assertOk();

    expect(SurgicalChecklistPhase::query()->count())->toBe(1);
});

it('financeiro não responde; médico fora do caso não responde; outra clínica é 404; fase inválida é 404', function () {
    $case = sckCase($this->w, ['surgeon' => createDoctorForEntity($this->w->entity)]);
    $body = ['answers' => [['key' => 'identity_confirmed', 'answer' => 'yes']]];

    surgeryAs($this->w, 'financial')->putJson(route('panel.surgery.checklist.save', [$case, 'sign_in']), $body)->assertForbidden();
    surgeryAs($this->w, 'doctor')->putJson(route('panel.surgery.checklist.save', [$case, 'sign_in']), $body)->assertForbidden();

    $foreign = surgeryWorld();
    surgeryAs($foreign, 'admin')->putJson(route('panel.surgery.checklist.save', [$case, 'sign_in']), $body)->assertNotFound();
    surgeryAs($foreign, 'admin')->postJson(route('panel.surgery.checklist.close', [$case, 'sign_in']), $body)->assertNotFound();

    surgeryAs($this->w, 'secretary')->putJson("/panel/surgery/{$case->id}/checklist/quarta_fase", $body)->assertNotFound();

    expect(SurgicalChecklistEntry::query()->count())->toBe(0);
});

it('respostas sem lista ou com lixo no corpo não passam', function () {
    $case = sckCase($this->w);

    surgeryAs($this->w, 'secretary')->putJson(route('panel.surgery.checklist.save', [$case, 'sign_in']), [])->assertStatus(422);
    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.checklist.save', [$case, 'sign_in']), ['answers' => [['key' => 'identity_confirmed', 'answer' => 'yes', 'note' => str_repeat('x', 1001)]]])
        ->assertStatus(422);
});

// ── Guard da Pausa Cirúrgica ────────────────────────────────────────────────

it('o guard está registrado e aceita a Pausa Cirúrgica fechada', function () {
    expect(config('surgery.perform_guards'))->toContain(ChecklistTimeOutGuard::class);

    $case = sckCase($this->w);
    app(SurgeryChecklistService::class);
    $this->actingAs($this->w->users['secretary'])->withSession(panelSession($this->w->eus['secretary']));

    app(SurgeryChecklistService::class)->close($case, ChecklistPhase::TimeOut, sckAnswers(ChecklistPhase::TimeOut));

    DB::transaction(function () use ($case) {
        app(ChecklistTimeOutGuard::class)->assert($case->refresh(), [], $this->w->doctor);
    });

    expect(app(SurgeryChecklistService::class)->isTimeOutClosed($case))->toBeTrue()
        ->and(SurgicalChecklistPhase::query()->whereNotNull('override_reason')->count())->toBe(0);
});

it('sem Pausa Cirúrgica fechada e sem exceção: recusa e não grava nada', function () {
    $case = sckCase($this->w);

    $call = fn () => DB::transaction(fn () => app(ChecklistTimeOutGuard::class)->assert($case->refresh(), [], $this->w->doctor));

    expect($call)->toThrow(SurgeryRuleException::class, __('surgery_schedule.checklist.errors.time_out_required'));
    expect(fn () => DB::transaction(fn () => app(ChecklistTimeOutGuard::class)->assert($case->refresh(), ['time_out_override_reason' => '   '], $this->w->doctor)))
        ->toThrow(SurgeryRuleException::class);

    expect(SurgicalChecklistPhase::query()->count())->toBe(0);
});

it('só o cirurgião do caso faz a exceção, com justificativa de 10+ caracteres', function () {
    $case  = sckCase($this->w);              // cirurgião = $w->doctor
    $other = createDoctorForEntity($this->w->entity);

    // Outro médico: recusado, nada gravado.
    try {
        DB::transaction(fn () => app(ChecklistTimeOutGuard::class)->assert($case->refresh(), ['time_out_override_reason' => 'Urgência do paciente na sala'], $other));
        $this->fail('Outro médico não pode fazer a exceção.');
    } catch (SurgeryRuleException $e) {
        expect($e->status)->toBe(409)->and($e->getMessage())->toBe(__('surgery_schedule.checklist.errors.only_surgeon_overrides'));
    }

    // Justificativa curta: 422.
    try {
        DB::transaction(fn () => app(ChecklistTimeOutGuard::class)->assert($case->refresh(), ['time_out_override_reason' => 'curto'], $this->w->doctor));
        $this->fail('Justificativa curta deveria falhar.');
    } catch (SurgeryRuleException $e) {
        expect($e->status)->toBe(422);
    }

    expect(SurgicalChecklistPhase::query()->count())->toBe(0);

    // Cirurgião com justificativa válida: passa e grava na fase.
    DB::transaction(fn () => app(ChecklistTimeOutGuard::class)->assert($case->refresh(), ['time_out_override_reason' => '  Urgência: paciente com dor intensa e jejum cumprido  '], $this->w->doctor));

    $phase = SurgicalChecklistPhase::query()->firstOrFail();

    expect($phase->phase)->toBe(ChecklistPhase::TimeOut)
        ->and($phase->isClosed())->toBeFalse()
        ->and($phase->override_reason)->toBe('Urgência: paciente com dor intensa e jejum cumprido')
        ->and((string) $phase->override_doctor_id)->toBe((string) $this->w->doctor->id)
        ->and($phase->override_at)->not->toBeNull()
        ->and(app(SurgeryChecklistService::class)->timeOutSatisfied($case))->toBeTrue()
        ->and(app(SurgeryChecklistService::class)->isTimeOutClosed($case))->toBeFalse();

    // Repetir é idempotente (uma só linha da fase).
    DB::transaction(fn () => app(ChecklistTimeOutGuard::class)->assert($case->refresh(), ['time_out_override_reason' => 'Mesma justificativa registrada de novo'], $this->w->doctor));

    expect(SurgicalChecklistPhase::query()->count())->toBe(1);
});

it('a exceção entra na tela do caso como aviso e não fecha a fase', function () {
    $case = sckCase($this->w);
    DB::transaction(fn () => app(ChecklistTimeOutGuard::class)->assert($case->refresh(), ['time_out_override_reason' => 'Urgência: paciente com dor intensa'], $this->w->doctor));

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page
            ->where('sections.checklist.time_out_satisfied', true)
            ->where('sections.checklist.phases.1.state', 'open')
            ->where('sections.checklist.phases.1.override.reason', 'Urgência: paciente com dor intensa')
            ->where('sections.checklist.phases.1.override.doctor', 'DR. CIRURGIAO'));
});

it('a exceção some se a transação de quem registra a cirurgia der rollback', function () {
    $case = sckCase($this->w);

    try {
        DB::transaction(function () use ($case) {
            app(ChecklistTimeOutGuard::class)->assert($case->refresh(), ['time_out_override_reason' => 'Urgência: paciente com dor intensa'], $this->w->doctor);

            throw new RuntimeException('outro guard recusou');
        });
    } catch (RuntimeException) {
    }

    expect(SurgicalChecklistPhase::query()->count())->toBe(0);
});

it('Entrada e Saída não são exigidas para realizar; só a Pausa conta', function () {
    $case = sckCase($this->w);
    $this->actingAs($this->w->users['secretary'])->withSession(panelSession($this->w->eus['secretary']));

    app(SurgeryChecklistService::class)->close($case, ChecklistPhase::SignIn, sckAnswers(ChecklistPhase::SignIn));
    app(SurgeryChecklistService::class)->close($case, ChecklistPhase::SignOut, sckAnswers(ChecklistPhase::SignOut));

    expect(fn () => DB::transaction(fn () => app(ChecklistTimeOutGuard::class)->assert($case->refresh(), [], $this->w->doctor)))
        ->toThrow(SurgeryRuleException::class);
});

it('dois fechamentos seguidos da mesma fase: o segundo vê a fase fechada pelo primeiro', function () {
    $case = sckCase($this->w);
    $this->actingAs($this->w->users['secretary'])->withSession(panelSession($this->w->eus['secretary']));

    $service = app(SurgeryChecklistService::class);
    $service->close($case, ChecklistPhase::TimeOut, sckAnswers(ChecklistPhase::TimeOut));
    $hash = SurgicalChecklistPhase::query()->value('content_hash');

    expect(fn () => $service->close($case, ChecklistPhase::TimeOut, sckAnswers(ChecklistPhase::TimeOut, ['team_introduced' => ['answer' => 'no']])))
        ->toThrow(SurgeryRuleException::class);

    expect(SurgicalChecklistPhase::query()->value('content_hash'))->toBe($hash)
        ->and(SurgicalChecklistPhase::query()->count())->toBe(1);
});

// ── Revisão adversarial: fechar é ato do médico da equipe ───────────────────

it('fechar a fase é do médico da equipe: secretária e médico fora do caso levam 403, nada fecha', function () {
    $case = sckCase($this->w);
    $url  = route('panel.surgery.checklist.close', [$case, 'sign_out']);
    $body = ['answers' => sckAnswers(ChecklistPhase::SignOut)];

    surgeryAs($this->w, 'secretary')->postJson($url, $body)->assertForbidden();
    surgeryAs($this->w, 'admin')->postJson($url, $body)->assertForbidden();

    $other = sckCase($this->w, ['surgeon' => createDoctorForEntity($this->w->entity)]);
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.checklist.close', [$other, 'sign_out']), $body)->assertForbidden();

    expect(SurgicalChecklistPhase::query()->whereNotNull('closed_at')->count())->toBe(0);

    surgeryAs($this->w, 'doctor')->postJson($url, $body)->assertOk();

    expect((string) SurgicalChecklistPhase::query()->value('closed_by_doctor_id'))->toBe((string) $this->w->doctor->id);
});

it('item crítico "não" precisa de nota de 10+ caracteres para fechar', function () {
    $case = sckCase($this->w);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.checklist.close', [$case, 'sign_in']), ['answers' => sckAnswers(ChecklistPhase::SignIn, ['eye_marked' => ['answer' => 'no', 'note' => 'curta']])])
        ->assertStatus(422);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.checklist.close', [$case, 'sign_in']), ['answers' => sckAnswers(ChecklistPhase::SignIn, ['eye_marked' => ['answer' => 'no', 'note' => 'Marcação refeita com o paciente']])])
        ->assertOk();
});

it('Pausa fechada com item crítico "não" NÃO satisfaz: só a exceção do cirurgião libera', function () {
    $case = sckCase($this->w);
    $this->actingAs($this->w->users['doctor'])->withSession(panelSession($this->w->eus['doctor']));

    $service = app(SurgeryChecklistService::class);
    $service->close($case, ChecklistPhase::TimeOut, sckAnswers(ChecklistPhase::TimeOut, ['equipment_ready' => ['answer' => 'no', 'note' => 'Faco com defeito, trocado']]));

    expect($service->isTimeOutClosed($case))->toBeTrue()
        ->and($service->isTimeOutCriticalNo($case))->toBeTrue()
        ->and($service->isTimeOutCurrent($case))->toBeFalse()
        ->and($service->timeOutSatisfied($case))->toBeFalse();

    expect(fn () => DB::transaction(fn () => app(ChecklistTimeOutGuard::class)->assert($case->refresh(), [], $this->w->doctor)))
        ->toThrow(SurgeryRuleException::class, __('surgery_schedule.checklist.errors.time_out_critical_no'));

    DB::transaction(fn () => app(ChecklistTimeOutGuard::class)->assert($case->refresh(), ['time_out_override_reason' => 'Equipamento trocado e testado antes da incisão'], $this->w->doctor));

    expect($service->timeOutSatisfied($case))->toBeTrue()
        ->and(SurgicalChecklistPhase::query()->value('override_reason'))->toContain('Equipamento trocado');
});

// ── Pausa amarrada ao plano e à janela ──────────────────────────────────────

it('mudar a LIO, a equipe ou a janela depois da Pausa fechada invalida a Pausa; a exceção cobre só o plano de agora', function () {
    $case = sckCase($this->w);
    $this->actingAs($this->w->users['doctor'])->withSession(panelSession($this->w->eus['doctor']));

    $service = app(SurgeryChecklistService::class);
    $service->close($case, ChecklistPhase::TimeOut, sckAnswers(ChecklistPhase::TimeOut));

    $guard = fn (array $input = []) => DB::transaction(fn () => app(ChecklistTimeOutGuard::class)->assert($case->refresh(), $input, $this->w->doctor));

    $guard();                                                  // plano igual ao do fechamento: passa
    expect($service->isTimeOutStale($case))->toBeFalse();

    // LIO planejada muda depois da Pausa.
    app(SurgicalCaseService::class)->update($case, ['iol_plans' => [['eye' => 'od', 'model_label' => 'LIO Y', 'power' => 20.0]]]);

    expect($service->isTimeOutClosed($case))->toBeTrue()->and($service->isTimeOutStale($case->refresh()))->toBeTrue();
    expect(fn () => $guard())->toThrow(SurgeryRuleException::class, __('surgery_schedule.checklist.errors.time_out_stale'));

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page->where('sections.checklist.time_out_stale', true)->where('sections.checklist.time_out_satisfied', false));

    // Exceção do cirurgião na fase JÁ FECHADA: grava só o registro da exceção.
    $guard(['time_out_override_reason' => 'LIO trocada por indicação do cirurgião na sala']);
    $phase = SurgicalChecklistPhase::query()->firstOrFail();

    expect($phase->isClosed())->toBeTrue()->and($phase->override_fingerprint)->not->toBeNull()
        ->and($service->timeOutSatisfied($case->refresh()))->toBeTrue();

    // Mudou DE NOVO: a exceção antiga não vale mais.
    app(SurgicalCaseService::class)->update($case, ['iol_plans' => [['eye' => 'od', 'model_label' => 'LIO Z', 'power' => 19.5]]]);

    expect($service->timeOutSatisfied($case->refresh()))->toBeFalse();
});

it('trocar a janela (reagendar) depois da Pausa também a invalida', function () {
    $case = sckCase($this->w);
    $this->actingAs($this->w->users['doctor'])->withSession(panelSession($this->w->eus['doctor']));

    app(SurgeryChecklistService::class)->close($case, ChecklistPhase::TimeOut, sckAnswers(ChecklistPhase::TimeOut));
    $case->refresh()->forceFill(['scheduled_start' => '2026-10-19 11:00:00', 'scheduled_end' => '2026-10-19 12:00:00'])->save();

    expect(app(SurgeryChecklistService::class)->isTimeOutStale($case))->toBeTrue();
});

// ── Retrato de segurança vivo ───────────────────────────────────────────────

it('abrir a Entrada renova o retrato de segurança e o alerta aparece quando o cadastro muda', function () {
    $case = sckCase($this->w);
    $case->forceFill(['clinical_safety_snapshot' => ['allergies_snapshot' => ['state' => 'unknown', 'items' => []], 'medications_snapshot' => [], 'risk_alerts' => []], 'clinical_safety_snapshot_at' => '2026-07-01 08:00:00'])->save();

    $this->actingAs($this->w->users['secretary'])->withSession(panelSession($this->w->eus['secretary']));
    app(SurgeryChecklistService::class)->save($case, ChecklistPhase::SignIn, [['key' => 'identity_confirmed', 'answer' => 'yes']]);

    expect($case->refresh()->clinical_safety_snapshot_at->format('Y-m-d'))->toBe('2026-10-15');

    // Cadastro vivo muda depois do retrato: alerta destacado na aba.
    $this->w->patient->update(['allergies_denied_at' => now()]);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page->where('sections.checklist.safety.live_differs', true)->where('sections.checklist.safety.taken_at', fn ($v) => str_starts_with($v, '2026-10-15')));

    app(SurgicalCaseService::class)->captureSafetySnapshot($case->refresh());

    expect(app(SurgicalCaseService::class)->safetySnapshotIsStale($case->refresh()))->toBeFalse();
});
