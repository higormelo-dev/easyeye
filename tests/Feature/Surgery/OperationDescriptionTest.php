<?php

declare(strict_types=1);

require_once __DIR__ . '/OperationFixtures.php';

use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\Surgery\SurgicalCase;
use App\Models\Surgery\{SurgicalDescription, SurgicalDescriptionAddendum};
use App\Services\Surgery\SurgeryDescriptionService;
use App\Support\Surgery\CanonicalHash;
use Carbon\Carbon;

/*
 * Fase 4 / M4 — descrição cirúrgica: rascunho do cirurgião → finalizada com
 * hash (imutável) → só adendo, encadeado por hash. A alta exige a finalizada.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w = surgeryWorld();
});

function sopDraft(array $over = []): array
{
    return array_merge([
        'performed_start_at'  => '2026-10-15T08:10:00-03:00',
        'performed_end_at'    => '2026-10-15T08:40:00-03:00',
        'anesthesia_used'     => 'topical',
        'findings'            => 'Catarata nuclear 3+, pupila bem dilatada.',
        'technique_performed' => 'Facoemulsificação por córnea clara, capsulorrexe contínua, LIO no saco.',
        'no_complications'    => true,
    ], $over);
}

function sopSave(object $t, $case, array $body, string $who = 'doctor')
{
    return surgeryAs($t->w, $who)->putJson(route('panel.surgery.operation.description.save', $case), $body);
}

function sopFinalize(object $t, $case, array $body = [], string $who = 'doctor')
{
    return surgeryAs($t->w, $who)->postJson(route('panel.surgery.operation.description.finalize', $case), $body);
}

function sopPerformed(object $t, array $create = []): SurgicalCase
{
    return sopCase($t->w, $create, ['status' => SurgicalCaseStatus::Performed, 'performed_at' => now()]);
}

it('o cirurgião salva o rascunho (cria na primeira vez) e a revisão sobe', function () {
    $case = sopCase($this->w);

    $first = sopSave($this, $case, sopDraft())->assertOk();
    $again = sopSave($this, $case, sopDraft(['findings' => 'Achados revistos']))->assertOk();

    $description = SurgicalDescription::query()->where('surgical_case_id', $case->id)->firstOrFail();

    expect($description->findings)->toBe('Achados revistos')
        ->and($again->json('revision'))->toBeGreaterThan($first->json('revision'))
        ->and($description->isFinalized())->toBeFalse()
        ->and((string) $description->patient_id)->toBe((string) $this->w->patient->id)
        ->and((string) $description->surgeon_doctor_id)->toBe((string) $this->w->doctor->id)
        ->and(SurgicalDescription::query()->count())->toBe(1);
});

it('sem complicações marcado, o texto de intercorrências é descartado', function () {
    $case = sopCase($this->w);

    sopSave($this, $case, sopDraft(['no_complications' => true, 'complications' => 'texto que não vale']))->assertOk();

    expect(SurgicalDescription::query()->firstOrFail()->complications)->toBeNull();
});

it('secretária, financeiro e outro médico não escrevem a descrição', function () {
    $case = sopCase($this->w);

    sopSave($this, $case, sopDraft(), 'secretary')->assertForbidden();
    sopSave($this, $case, sopDraft(), 'financial')->assertForbidden();
    sopOtherDoctor($this->w)->putJson(route('panel.surgery.operation.description.save', $case), sopDraft())->assertForbidden();

    expect(SurgicalDescription::query()->count())->toBe(0);
});

it('auxiliar da equipe não escreve a descrição (é ato do cirurgião)', function () {
    $case      = sopCase($this->w);
    $assistant = createDoctorForEntity($this->w->entity);
    $case->team()->create(['entity_id' => $this->w->entity->id, 'role' => 'assistant', 'doctor_id' => $assistant->id, 'name' => 'AUX']);

    test()->actingAs($assistant->entityUser->user)->withSession(panelSession($assistant->entityUser))
        ->putJson(route('panel.surgery.operation.description.save', $case), sopDraft())->assertForbidden();
});

it('valida horários: fim depois do início e nada no futuro', function () {
    $case = sopCase($this->w);

    sopSave($this, $case, sopDraft(['performed_end_at' => '2026-10-15T08:00:00-03:00']))->assertStatus(422)->assertJsonValidationErrors('performed_end_at');
    sopSave($this, $case, sopDraft(['performed_end_at' => '2026-10-16T08:00:00-03:00']))->assertStatus(422);
    sopSave($this, $case, sopDraft(['anesthesia_used' => 'inventada']))->assertStatus(422);
});

it('estado do caso: só agendada ou realizada; revisão velha da descrição é 409', function () {
    $draftCase = sopCase($this->w, state: ['status' => SurgicalCaseStatus::Authorized]);
    sopSave($this, $draftCase, sopDraft())->assertStatus(409);

    $case = sopCase($this->w, ['title' => 'B']);
    $rev  = sopSave($this, $case, sopDraft())->json('revision');

    sopSave($this, $case, sopDraft())->assertOk();
    sopSave($this, $case, sopDraft(['expected_revision' => $rev]))->assertStatus(409);
});

it('finalizar exige a cirurgia realizada e lista o que falta', function () {
    $case = sopCase($this->w);
    sopSave($this, $case, sopDraft())->assertOk();

    sopFinalize($this, $case)->assertStatus(409); // ainda agendada

    $performed = sopPerformed($this, ['title' => 'Realizada']);
    sopSave($this, $performed, ['findings' => 'Só achados'])->assertOk();

    $response = sopFinalize($this, $performed)->assertStatus(422)->assertJsonValidationErrors('description');
    $messages = collect($response->json('errors.description'));

    expect($messages)->toContain(__('surgery_operation.validation.times_required'))
        ->and($messages)->toContain(__('surgery_operation.validation.technique_required'))
        ->and($messages)->toContain(__('surgery_operation.validation.anesthesia_required'))
        ->and($messages)->toContain(__('surgery_operation.validation.complications_required'));

    expect(SurgicalDescription::query()->where('surgical_case_id', $performed->id)->firstOrFail()->isFinalized())->toBeFalse();
});

it('sem descrição não há o que finalizar', function () {
    sopFinalize($this, sopPerformed($this))->assertStatus(409);
});

it('finaliza: congela o cabeçalho, grava o hash do conteúdo e fecha a edição', function () {
    $case            = sopPerformed($this);
    [$product, $lot] = sopIol($this->w);
    sopStore($this, $case, sopImplantPayload($product, $lot))->assertCreated();

    sopSave($this, $case, sopDraft())->assertOk();
    sopFinalize($this, $case)->assertOk();

    $description = SurgicalDescription::query()->where('surgical_case_id', $case->id)->firstOrFail();

    expect($description->isFinalized())->toBeTrue()
        ->and($description->finalized_at)->not->toBeNull()
        ->and((string) $description->finalized_by_doctor_id)->toBe((string) $this->w->doctor->id)
        ->and($description->content_hash)->toBe(CanonicalHash::of(['v' => CanonicalHash::VERSION, ...$description->hashableContent()]))
        ->and($description->header_snapshot['surgeon']['name'])->toBe('DR. CIRURGIAO')
        ->and($description->header_snapshot['patient']['name'])->toBe('PACIENTE CIRURGICO')
        ->and($description->header_snapshot['implants'])->toHaveCount(1)
        ->and($description->header_snapshot['implants'][0]['serial_number'])->toBe('SN-0001');

    // Depois de finalizada: nem pelo HTTP, nem pelo model.
    sopSave($this, $case, sopDraft(['findings' => 'adulterado']))->assertStatus(409);
    sopFinalize($this, $case)->assertStatus(409);

    expect(fn () => $description->update(['findings' => 'adulterado']))->toThrow(LogicException::class);
    expect(fn () => $description->forceFill(['content_hash' => str_repeat('0', 64)])->save())->toThrow(LogicException::class);
    expect(fn () => $description->delete())->toThrow(LogicException::class);
    expect($description->fresh()->findings)->toBe('Catarata nuclear 3+, pupila bem dilatada.');
});

it('o hash muda se qualquer parte do conteúdo mudar', function () {
    $a = sopPerformed($this);
    $b = sopPerformed($this, ['title' => 'B']);

    sopSave($this, $a, sopDraft())->assertOk();
    sopSave($this, $b, sopDraft(['findings' => 'Outro achado']))->assertOk();
    sopFinalize($this, $a)->assertOk();
    sopFinalize($this, $b)->assertOk();

    $hashes = SurgicalDescription::query()->pluck('content_hash')->all();

    expect($hashes)->toHaveCount(2)->and($hashes[0])->not->toBe($hashes[1]);
});

it('adendo: só em descrição finalizada, com texto e motivo, imutável e encadeado', function () {
    $case = sopPerformed($this);
    $url  = route('panel.surgery.operation.description.addenda', $case);

    surgeryAs($this->w, 'doctor')->postJson($url, ['text' => 'Complemento sobre o achado', 'reason' => 'Esquecimento'])->assertStatus(409); // sem descrição

    sopSave($this, $case, sopDraft())->assertOk();
    surgeryAs($this->w, 'doctor')->postJson($url, ['text' => 'Complemento sobre o achado', 'reason' => 'Esquecimento'])->assertStatus(409); // rascunho

    sopFinalize($this, $case)->assertOk();

    surgeryAs($this->w, 'doctor')->postJson($url, ['text' => 'curto', 'reason' => 'x'])->assertStatus(422);
    surgeryAs($this->w, 'secretary')->postJson($url, ['text' => 'Complemento sobre o achado', 'reason' => 'Esquecimento'])->assertForbidden();

    surgeryAs($this->w, 'doctor')->postJson($url, ['text' => 'Complemento sobre o achado', 'reason' => 'Esquecimento'])->assertCreated();
    surgeryAs($this->w, 'doctor')->postJson($url, ['text' => 'Segundo complemento, com mais detalhes', 'reason' => 'Revisão'])->assertCreated();

    $addenda = SurgicalDescriptionAddendum::query()->orderBy('created_at')->orderBy('id')->get();

    expect($addenda)->toHaveCount(2)
        ->and($addenda[0]->doctor_name)->toBe('DR. CIRURGIAO')
        ->and($addenda[0]->doctor_registry)->toBe('12345')
        ->and($addenda[0]->content_hash)->not->toBe($addenda[1]->content_hash)
        ->and(fn () => $addenda[0]->update(['text' => 'adulterado']))->toThrow(LogicException::class)
        ->and(fn () => $addenda[0]->delete())->toThrow(LogicException::class);
});

it('a alta consulta a descrição finalizada pelo serviço', function () {
    $case = sopPerformed($this);

    expect(app(SurgeryDescriptionService::class)->isFinalized($case))->toBeFalse();

    sopSave($this, $case, sopDraft())->assertOk();
    expect(app(SurgeryDescriptionService::class)->isFinalized($case))->toBeFalse();

    sopFinalize($this, $case)->assertOk();
    expect(app(SurgeryDescriptionService::class)->isFinalized($case))->toBeTrue();
});

it('PDF: rascunho e finalizada saem em PDF; caso de outra clínica é 404; sem descrição é 404', function () {
    $case = sopPerformed($this);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.operation.description.pdf', $case))->assertNotFound();

    sopSave($this, $case, sopDraft())->assertOk();
    $draft = surgeryAs($this->w, 'doctor')->get(route('panel.surgery.operation.description.pdf', $case))->assertOk();
    expect($draft->headers->get('Content-Type'))->toContain('application/pdf');

    sopFinalize($this, $case)->assertOk();
    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.operation.description.pdf', $case))->assertOk();

    $w2 = surgeryWorld();
    surgeryAs($w2, 'doctor')->get(route('panel.surgery.operation.description.pdf', $case))->assertNotFound();
    surgeryAs($this->w, 'financial')->get(route('panel.surgery.operation.description.pdf', $case))->assertForbidden();
});

it('a seção entrega o rascunho com os problemas e a finalizada com adendos', function () {
    $case = sopPerformed($this);
    sopSave($this, $case, ['findings' => 'Só achados'])->assertOk();

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page
            ->where('sections.operation.description.finalized', false)
            ->where('sections.operation.description.findings', 'Só achados')
            ->has('sections.operation.description.problems', 4)
            ->where('sections.operation.state.can_describe', true)
            ->where('sections.operation.state.can_finalize', true));
});
