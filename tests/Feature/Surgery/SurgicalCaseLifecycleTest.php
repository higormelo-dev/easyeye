<?php

declare(strict_types=1);

use App\Enums\Surgery\{AuthorizationEventType, AuthorizationStatus, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\{DataAccessLog, EntityProduct, ExamType, Patient, PatientExam, Procedure};
use App\Models\Surgery\{SurgicalAuthorizationEvent, SurgicalCase};
use App\Services\Surgery\{SurgeryAuthorizationService, SurgicalCaseService};
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Módulo cirúrgico (Fase 4) / M0–M1 — ciclo de vida do caso: pedido,
 * autorização (log imutável), cancelar/suspender/retomar, isolamento entre
 * clínicas, perfis e concorrência de revisão.
 */

function sclPayload(object $w, array $over = []): array
{
    return array_merge([
        'patient_id'        => (string) $w->patient->id,
        'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'             => 'Facoemulsificação com LIO',
        'eye'               => 'od',
        'regime'            => 'ambulatory',
        'urgency'           => 'elective',
        'days_requested'    => 1,
        'procedures'        => [
            ['description' => 'Facoemulsificação com implante de LIO', 'tuss_code' => '30306019', 'quantity' => 1],
        ],
    ], $over);
}

function sclCase(object $w, array $over = []): SurgicalCase
{
    return app(SurgicalCaseService::class)->create((string) $w->entity->id, sclPayload($w, $over));
}

/** Leva o caso a "aguardando autorização" pelo serviço (precisa do pedido completo). */
function sclRequested(object $w, array $over = []): SurgicalCase
{
    $case = sclCase($w, $over);

    return app(SurgeryAuthorizationService::class)->request($case);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w = surgeryWorld();
});

// ── Acesso ──────────────────────────────────────────────────────────────────

it('nega a lista a clínica sem o recurso has_surgery', function () {
    $w = surgeryWorld(withModule: false);

    surgeryAs($w, 'admin')->getJson(route('panel.surgery.index'))->assertForbidden();
});

it('financeiro não abre a lista; admin, médico e secretária abrem', function () {
    surgeryAs($this->w, 'financial')->get(route('panel.surgery.index'))->assertForbidden();

    foreach (['admin', 'doctor', 'secretary'] as $who) {
        surgeryAs($this->w, $who)->get(route('panel.surgery.index'))->assertOk();
    }
});

// ── Cadastro ────────────────────────────────────────────────────────────────

it('a secretária cria o caso em rascunho, sem gravar campos de ato médico', function () {
    $payload = sclPayload($this->w, [
        'technique' => 'Técnica reservada ao médico',
        'iol_plans' => [['eye' => 'od', 'model_label' => 'LIO X', 'power' => 21.5]],
    ]);

    $response = surgeryAs($this->w, 'secretary')->post(route('panel.surgery.store'), $payload);

    $case = SurgicalCase::query()->firstOrFail();

    $response->assertRedirect(route('panel.surgery.show', $case));
    expect($case->status)->toBe(SurgicalCaseStatus::Draft)
        ->and($case->authorization_status)->toBe(AuthorizationStatus::NotRequested)
        ->and($case->code)->toStartWith('CIR-')
        ->and($case->revision)->toBe(1)
        ->and($case->technique)->toBeNull()
        ->and($case->iolPlans()->count())->toBe(0)
        ->and($case->procedures()->count())->toBe(1)
        ->and($case->status_history)->toHaveCount(1)
        ->and($case->clinical_safety_snapshot_at)->not->toBeNull();
});

it('o médico grava técnica e LIO planejada', function () {
    $payload = sclPayload($this->w, [
        'technique' => 'Facoemulsificação por córnea clara',
        'iol_plans' => [['eye' => 'od', 'model_label' => 'LIO X', 'power' => 21.5, 'predicted_refraction' => -0.25]],
    ]);

    surgeryAs($this->w, 'doctor')->post(route('panel.surgery.store'), $payload)->assertRedirect();

    $case = SurgicalCase::query()->firstOrFail();

    expect($case->technique)->toBe('Facoemulsificação por córnea clara')
        ->and($case->iolPlans)->toHaveCount(1)
        ->and((float) $case->iolPlans->first()->power)->toBe(21.5);
});

it('usa o convênio e a carteirinha do paciente quando o pedido não diz outros', function () {
    $this->w->patient->update(['covenant_id' => $this->w->insurer->id, 'card_number' => '998877']);

    $case = sclCase($this->w);

    expect((string) $case->covenant_id)->toBe((string) $this->w->insurer->id)
        ->and($case->covenant_card_number)->toBe('998877');
});

it('recusa paciente de outra clínica e não grava nada', function () {
    $foreign = Patient::factory()->create(['entity_id' => $this->w->other->id, 'active' => true]);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.store'), sclPayload($this->w, ['patient_id' => (string) $foreign->id]))
        ->assertStatus(422);

    expect(SurgicalCase::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('recusa cirurgião de outra clínica', function () {
    $foreign = createDoctorForEntity($this->w->other);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.store'), sclPayload($this->w, ['surgeon_doctor_id' => (string) $foreign->id]))
        ->assertStatus(422);
});

it('limita os diagnósticos da guia a quatro', function () {
    $cids = array_map(fn ($c) => ['code' => $c], ['H25.0', 'H25.1', 'H25.2', 'H25.8', 'H26.0']);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.store'), sclPayload($this->w, ['cid_codes' => $cids]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('cid_codes');
});

// ── Edição e revisão ────────────────────────────────────────────────────────

it('edita o rascunho e sobe a revisão', function () {
    $case = sclCase($this->w);

    surgeryAs($this->w, 'secretary')
        ->put(route('panel.surgery.update', $case), ['title' => 'Facoemulsificação OD', 'expected_revision' => 1])
        ->assertRedirect();

    $case->refresh();

    expect($case->title)->toBe('Facoemulsificação OD')->and($case->revision)->toBe(2);
});

it('recusa edição com a revisão velha (409) sem gravar', function () {
    $case = sclCase($this->w);

    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.update', $case), ['title' => 'Outro título', 'expected_revision' => 7])
        ->assertStatus(409);

    expect($case->refresh()->title)->toBe('Facoemulsificação com LIO');
});

it('não deixa trocar o pedido depois de enviado, mas aceita reenviar o mesmo valor', function () {
    $case = sclRequested($this->w);

    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.update', $case), ['title' => 'Título novo'])
        ->assertStatus(409);

    surgeryAs($this->w, 'secretary')
        ->put(route('panel.surgery.update', $case), ['title' => $case->title, 'notes' => 'observação livre'])
        ->assertRedirect();

    expect($case->refresh()->notes)->toBe('observação livre');
});

it('não troca o paciente do caso', function () {
    $case  = sclCase($this->w);
    $other = Patient::factory()->create(['entity_id' => $this->w->entity->id, 'active' => true]);

    surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.update', $case), ['patient_id' => (string) $other->id])
        ->assertStatus(422);

    expect((string) $case->refresh()->patient_id)->toBe((string) $this->w->patient->id);
});

// ── Autorização ─────────────────────────────────────────────────────────────

it('lista tudo que falta antes de enviar o pedido', function () {
    $case = sclCase($this->w, [
        'procedures'  => [['description' => 'Procedimento sem TUSS']],
        'covenant_id' => (string) $this->w->insurer->id,
        'regime'      => 'day_hospital',
        'needs_opme'  => true,
    ]);

    surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.authorization.request', $case))
        ->assertStatus(422)
        ->assertJsonValidationErrors('request');

    $problems = app(SurgeryAuthorizationService::class)->requestProblems($case);

    expect($problems)->toContain(__('surgery.validation.tuss_required_with_covenant'))
        ->and($problems)->toContain(__('surgery.validation.facility_required_for_regime'))
        ->and($problems)->toContain(__('surgery.validation.cid_required_for_regime'))
        ->and($problems)->toContain(__('surgery.validation.materials_required_with_opme'));

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Draft);
});

it('envia o pedido: aguardando, evento no log e retrato de segurança atualizado', function () {
    $case = sclCase($this->w);

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.authorization.request', $case))->assertRedirect();

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and($case->authorization_status)->toBe(AuthorizationStatus::Pending)
        ->and($case->requested_at)->not->toBeNull()
        ->and($case->authorizationEvents->pluck('type')->all())->toBe([AuthorizationEventType::Requested]);
});

it('autoriza: número, senha e validade ficam no caso e no evento; itens viram autorizados', function () {
    $case = sclRequested($this->w);

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.authorization.record', $case), [
        'type'                   => 'authorized',
        'authorization_number'   => 'AUT-123',
        'authorization_password' => 'S3NH4',
        'valid_until'            => '2026-11-30',
        'channel'                => 'portal',
    ])->assertRedirect();

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and($case->authorization_status)->toBe(AuthorizationStatus::Authorized)
        ->and($case->authorization_number)->toBe('AUT-123')
        ->and($case->authorization_valid_until->toDateString())->toBe('2026-11-30')
        ->and($case->procedures->first()->authorized_quantity)->toBe(1);
});

it('autorização parcial exige a lista de itens e ao menos um item abaixo do pedido', function () {
    $case = sclRequested($this->w, [
        'needs_opme' => true,
        'materials'  => [['description' => 'Anel de tensão capsular', 'quantity' => 2, 'technical_justification' => 'Subluxação do cristalino']],
    ]);
    $material = $case->materials->first();

    // Tudo liberado não é parcial.
    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.authorization.record', $case), [
        'type'                 => 'partially_authorized',
        'authorization_number' => 'P-1',
        'authorized_items'     => [
            ['kind' => 'procedure', 'id' => (string) $case->procedures->first()->id, 'quantity' => 1],
            ['kind' => 'material', 'id' => (string) $material->id, 'quantity' => 2],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('authorized_items');

    // Parcial de verdade: 1 de 2 anéis.
    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.authorization.record', $case), [
        'type'                 => 'partially_authorized',
        'authorization_number' => 'P-1',
        'authorized_items'     => [
            ['kind' => 'procedure', 'id' => (string) $case->procedures->first()->id, 'quantity' => 1],
            ['kind' => 'material', 'id' => (string) $material->id, 'quantity' => 1],
        ],
    ])->assertRedirect();

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::PartiallyAuthorized)
        ->and($case->materials->first()->authorized_quantity)->toBe(1);
});

it('negada exige motivo; a negativa pode virar recurso e novo pedido', function () {
    $case = sclRequested($this->w);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.authorization.record', $case), ['type' => 'denied'])
        ->assertStatus(422)->assertJsonValidationErrors('denial_reason');

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.authorization.record', $case), [
        'type' => 'denied', 'denial_reason' => 'Fora da cobertura contratual',
    ])->assertRedirect();

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Denied);

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.authorization.request', $case))->assertRedirect();

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and($case->authorizationEvents->pluck('type')->map->value->all())
        ->toBe(['requested', 'denied', 'appealed']);
});

it('dispensa a autorização (particular) com motivo e deixa o caso pronto para agendar', function () {
    $case = sclCase($this->w);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.authorization.record', $case), ['type' => 'waived'])
        ->assertStatus(422)->assertJsonValidationErrors('note');

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.authorization.record', $case), [
        'type' => 'waived', 'note' => 'Cirurgia particular',
    ])->assertRedirect();

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and($case->authorization_status)->toBe(AuthorizationStatus::Waived)
        ->and($case->authorization_status->allowsScheduling())->toBeTrue();
});

it('resposta da operadora só vale para pedido aguardando', function () {
    $case = sclCase($this->w);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.authorization.record', $case), [
        'type' => 'authorized', 'authorization_number' => 'X-1',
    ])->assertStatus(409);

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Draft);
});

it('o log de autorização é imutável', function () {
    $case  = sclRequested($this->w);
    $event = $case->authorizationEvents()->firstOrFail();

    expect(fn () => $event->update(['note' => 'adulterado']))->toThrow(LogicException::class);
    expect(fn () => $event->delete())->toThrow(LogicException::class);
    expect(SurgicalAuthorizationEvent::query()->count())->toBe(1);
});

it('retirar o pedido volta ao rascunho e permite corrigir', function () {
    $case = sclRequested($this->w);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.authorization.withdraw', $case), [])
        ->assertStatus(422)->assertJsonValidationErrors('reason');

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.authorization.withdraw', $case), ['reason' => 'Faltou o CID'])
        ->assertRedirect();

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Draft)
        ->and($case->authorization_status)->toBe(AuthorizationStatus::NotRequested);
});

it('job diário vence a autorização: autorizada volta a aguardar; agendada continua agendada', function () {
    $authorized = sclRequested($this->w);
    app(SurgeryAuthorizationService::class)->record($authorized, [
        'type' => 'authorized', 'authorization_number' => 'A-1', 'valid_until' => '2026-10-20',
    ]);

    $scheduled = sclRequested($this->w, ['title' => 'Outra']);
    app(SurgeryAuthorizationService::class)->record($scheduled, [
        'type' => 'authorized', 'authorization_number' => 'A-2', 'valid_until' => '2026-10-20',
    ]);
    $scheduled->forceFill(['status' => SurgicalCaseStatus::Scheduled])->save();

    $this->travelTo(Carbon::parse('2026-10-21 06:00:00'));

    expect(app(SurgeryAuthorizationService::class)->expireDue())->toBe(2);

    expect($authorized->refresh()->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and($authorized->authorization_status)->toBe(AuthorizationStatus::Expired)
        ->and($scheduled->refresh()->status)->toBe(SurgicalCaseStatus::Scheduled)
        ->and($scheduled->authorization_status)->toBe(AuthorizationStatus::Expired);

    // Idempotente: rodar de novo não duplica evento.
    expect(app(SurgeryAuthorizationService::class)->expireDue())->toBe(0);
    expect($authorized->authorizationEvents()->where('type', 'expired')->count())->toBe(1);
});

// ── Cancelar, suspender, retomar, excluir ───────────────────────────────────

it('cancela com motivo e o caso fica final', function () {
    $case = sclCase($this->w);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.cancel', $case), [])->assertStatus(422);

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.cancel', $case), ['reason' => 'Paciente desistiu'])
        ->assertRedirect();

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Cancelled)
        ->and($case->cancellation_reason)->toBe('Paciente desistiu')
        ->and($case->cancelled_at)->not->toBeNull();

    surgeryAs($this->w, 'secretary')->putJson(route('panel.surgery.update', $case), ['notes' => 'x'])->assertStatus(409);
});

it('suspende e retoma no estado de origem; suspenso de agendada volta autorizado sem janela', function () {
    $case = sclRequested($this->w);
    app(SurgeryAuthorizationService::class)->record($case, ['type' => 'authorized', 'authorization_number' => 'A-9']);

    $case->forceFill([
        'status'          => SurgicalCaseStatus::Scheduled,
        'scheduled_start' => '2026-11-02 08:00:00',
        'scheduled_end'   => '2026-11-02 09:00:00',
    ])->save();

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.suspend', $case), ['reason' => 'Paciente com febre'])->assertRedirect();

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Suspended)
        ->and($case->status_before_suspension)->toBe(SurgicalCaseStatus::Scheduled);

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.resume', $case))->assertRedirect();

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and($case->scheduled_start)->toBeNull()
        ->and($case->scheduled_end)->toBeNull()
        ->and($case->status_before_suspension)->toBeNull();
});

it('só exclui rascunho que nunca foi enviado', function () {
    $draft = sclCase($this->w);
    surgeryAs($this->w, 'secretary')->delete(route('panel.surgery.destroy', $draft))->assertRedirect(route('panel.surgery.index'));
    expect(SurgicalCase::query()->count())->toBe(0);

    $requested = sclRequested($this->w);
    surgeryAs($this->w, 'secretary')->deleteJson(route('panel.surgery.destroy', $requested))->assertStatus(409);
    expect(SurgicalCase::query()->count())->toBe(1);
});

// ── Lista, detalhe e isolamento ─────────────────────────────────────────────

it('a lista conta por grupo e filtra por busca', function () {
    sclCase($this->w, ['title' => 'Catarata OD']);
    sclRequested($this->w, ['title' => 'Pterígio OE']);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.index'))->assertInertia(fn ($page) => $page
        ->component('Panel/Surgery/Index')
        ->where('counts.all', 2)
        ->where('counts.draft', 1)
        ->where('counts.awaiting', 1)
        ->has('cases.data', 2));

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.index', ['q' => 'pterígio']))
        ->assertInertia(fn ($page) => $page->has('cases.data', 1)->where('cases.data.0.title', 'Pterígio OE'));
});

it('o médico só vê casos em que é cirurgião ou equipe', function () {
    $mine  = sclCase($this->w, ['title' => 'Meu caso']);
    $other = createDoctorForEntity($this->w->entity);
    sclCase($this->w, ['title' => 'Caso do colega', 'surgeon_doctor_id' => (string) $other->id]);

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.index'))
        ->assertInertia(fn ($page) => $page->has('cases.data', 1)->where('cases.data.0.title', 'Meu caso'));

    $theirs = SurgicalCase::query()->where('title', 'Caso do colega')->firstOrFail();

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $theirs))->assertForbidden();
    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $mine))->assertOk();
});

it('o detalhe entrega as abas do módulo e o 404 vale para caso de outra clínica', function () {
    $case = sclCase($this->w);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $case))->assertInertia(fn ($page) => $page
        ->component('Panel/Surgery/Show')
        ->where('surgicalCase.code', $case->code)
        ->where('tabs.0.key', 'request')
        ->has('sections.authorization.events')
        ->where('can.manage', true)
        ->where('can.perform', false));

    $w2 = surgeryWorld();

    surgeryAs($w2, 'admin')->get(route('panel.surgery.show', $case))->assertNotFound();
    surgeryAs($w2, 'admin')->putJson(route('panel.surgery.update', $case), ['notes' => 'invasão'])->assertNotFound();
});

it('depois de autorizado ainda se registra a LIO planejada, a anestesia e a equipe — o pedido continua travado', function () {
    $case = sclRequested($this->w);

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.authorization.record', $case), [
        'type' => 'authorized', 'authorization_number' => 'AUT-1',
    ])->assertRedirect();

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Authorized);

    // O médico completa a parte clínica; a secretária, a equipe.
    surgeryAs($this->w, 'doctor')->put(route('panel.surgery.update', $case), [
        'anesthesia' => 'topical',
        'iol_plans'  => [['eye' => 'od', 'model_label' => 'LIO X', 'power' => 21.5]],
    ])->assertRedirect();

    surgeryAs($this->w, 'secretary')->put(route('panel.surgery.update', $case), [
        'team' => [['role' => 'assistant', 'name' => 'Dra. Auxiliar']],
    ])->assertRedirect();

    $case->refresh();

    expect($case->anesthesia?->value)->toBe('topical')
        ->and($case->iolPlans()->count())->toBe(1)
        ->and($case->team()->pluck('name')->all())->toBe(['Dra. Auxiliar']);

    // O pedido (o que a operadora autorizou) não muda sem novo pedido.
    surgeryAs($this->w, 'secretary')->putJson(route('panel.surgery.update', $case), ['title' => 'Outro título'])->assertStatus(409);
});

it('o formulário do caso abre sempre que algum grupo de campos aceita alteração, e a aba Pedido só oferece Editar quando o formulário existe', function () {
    $case = sclCase($this->w);

    // Estado => o cadastro ainda aceita alguma alteração? (o pedido trava; equipe, exames e parte clínica não.)
    $expected = [
        'draft'     => true, 'awaiting_authorization' => true, 'authorized' => true, 'partially_authorized' => true,
        'denied'    => true, 'scheduled' => true, 'suspended' => true,
        'performed' => false, 'discharged' => false, 'cancelled' => false,
    ];

    foreach (SurgicalCaseStatus::cases() as $status) {
        $case->forceFill(['status' => $status])->save();

        foreach (['secretary', 'doctor', 'admin'] as $who) {
            surgeryAs($this->w, $who)->get(route('panel.surgery.show', $case))->assertInertia(function ($page) use ($status, $who, $expected) {
                $props = $page->toArray()['props'];
                $tab   = $props['sections']['request'];

                // Editar no cabeçalho (monta o formulário) e Editar na aba (abre o formulário) andam juntos.
                $tabOffersEdit = $tab['can_edit_request'] || $tab['can_edit_clinical'] || $tab['can_edit_team'];

                expect($props['can']['edit'])->toBe($expected[$status->value], "{$who} / {$status->value}")
                    ->and($tabOffersEdit)->toBe($props['can']['edit'], "aba × formulário: {$who} / {$status->value}")
                    ->and($props['surgicalCase']['editable']['any'])->toBe($expected[$status->value]);
            });
        }
    }
});

it('abrir o caso registra o acesso a dado sensível do paciente (CFM/LGPD)', function () {
    $case = sclCase($this->w);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $case))->assertOk();
    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $case))->assertOk();

    $logs = DataAccessLog::query()->where('resource_id', $case->id)->orderBy('accessed_at')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('purpose')->map->value->all())->toBe(['administrative', 'patient_care'])
        ->and((string) $logs->first()->patient_id)->toBe((string) $this->w->patient->id)
        ->and((string) $logs->first()->entity_id)->toBe((string) $this->w->entity->id);
});

it('o comando diário vence as autorizações e está agendado', function () {
    $case = sclRequested($this->w);
    app(SurgeryAuthorizationService::class)->record($case, ['type' => 'authorized', 'authorization_number' => 'A-1', 'valid_until' => '2026-10-16']);

    $this->travelTo(Carbon::parse('2026-10-17 06:00:00'));

    $this->artisan('surgery:expire-authorizations')->expectsOutputToContain('vencidas agora: 1')->assertSuccessful();
    $this->artisan('surgery:expire-authorizations')->expectsOutputToContain('vencidas agora: 0')->assertSuccessful();

    expect($case->refresh()->authorization_status)->toBe(AuthorizationStatus::Expired);

    $scheduled = collect(app(Schedule::class)->events())
        ->contains(fn ($event) => str_contains((string) $event->command, 'surgery:expire-authorizations'));

    expect($scheduled)->toBeTrue();
});

it('recusa produto e procedimento de outra clínica nos itens do pedido (IDOR)', function () {
    $foreignProduct   = EntityProduct::create(['entity_id' => $this->w->other->id, 'name' => 'Produto alheio', 'unit' => 'un', 'active' => true]);
    $foreignProcedure = Procedure::query()->create(['entity_id' => $this->w->other->id, 'code' => '99999999', 'name' => 'Alheio', 'active' => true]);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.store'), sclPayload($this->w, [
        'iol_plans' => [['eye' => 'od', 'model_label' => 'LIO', 'power' => 20, 'entity_product_id' => (string) $foreignProduct->id]],
    ]))->assertStatus(422);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.store'), sclPayload($this->w, [
        'needs_opme' => true,
        'materials'  => [['description' => 'Anel', 'entity_product_id' => (string) $foreignProduct->id, 'technical_justification' => 'x']],
    ]))->assertStatus(422);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.store'), sclPayload($this->w, [
        'procedures' => [['description' => 'Procedimento', 'procedure_id' => (string) $foreignProcedure->id]],
    ]))->assertStatus(422);

    expect(SurgicalCase::query()->count())->toBe(0);
});

it('a LIO planejada leva o médico logado como autor, não o que o cliente mandar', function () {
    $other = createDoctorForEntity($this->w->entity);

    surgeryAs($this->w, 'doctor')->post(route('panel.surgery.store'), sclPayload($this->w, [
        'iol_plans' => [['eye' => 'od', 'model_label' => 'LIO', 'power' => 20, 'planned_by_doctor_id' => (string) $other->id]],
    ]))->assertRedirect();

    expect((string) SurgicalCase::query()->firstOrFail()->iolPlans->first()->planned_by_doctor_id)->toBe((string) $this->w->doctor->id);
});

it('LIO planejada de outro olho e biometria do olho contrário são recusadas; caso em AO aceita os dois', function () {
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.store'), sclPayload($this->w, [
        'eye' => 'od', 'iol_plans' => [['eye' => 'oe', 'model_label' => 'LIO', 'power' => 20]],
    ]))->assertStatus(422);

    $examType = ExamType::factory()->create(['entity_id' => null]);
    $oeExam   = PatientExam::factory()->create(['patient_id' => $this->w->patient->id, 'exam_id' => $examType->id, 'laterality' => 2]);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.store'), sclPayload($this->w, [
        'eye' => 'od', 'exams' => [['patient_exam_id' => (string) $oeExam->id, 'role' => 'biometry']],
    ]))->assertStatus(422);

    // Outro tipo de exame do olho contrário pode ser anexado (ex.: OCT do olho adelfo); AO aceita tudo.
    surgeryAs($this->w, 'doctor')->post(route('panel.surgery.store'), sclPayload($this->w, [
        'eye' => 'od', 'exams' => [['patient_exam_id' => (string) $oeExam->id, 'role' => 'oct']],
    ]))->assertRedirect();
    surgeryAs($this->w, 'doctor')->post(route('panel.surgery.store'), sclPayload($this->w, [
        'eye'   => 'ao', 'iol_plans' => [['eye' => 'oe', 'model_label' => 'LIO', 'power' => 20]],
        'exams' => [['patient_exam_id' => (string) $oeExam->id, 'role' => 'biometry']],
    ]))->assertRedirect();

    expect(SurgicalCase::query()->count())->toBe(2);
});

it('caso nos dois olhos: dois planos de LIO para o mesmo olho são erro de campo (422), não exceção do banco, e o plano antigo fica', function () {
    $case = sclCase($this->w, ['eye' => 'ao', 'iol_plans' => [['eye' => 'od', 'model_label' => 'LIO ANTIGA', 'power' => 20]]]);

    $dup = ['iol_plans' => [
        ['eye' => 'od', 'model_label' => 'LIO A', 'power' => 21],
        ['eye' => 'od', 'model_label' => 'LIO B', 'power' => 22],
    ]];

    $res = surgeryAs($this->w, 'doctor')->putJson(route('panel.surgery.update', $case), $dup)
        ->assertStatus(422)
        ->assertJsonValidationErrors('iol_plans.1.eye');

    expect($res->json('errors')['iol_plans.1.eye'][0])->toBe(__('surgery.errors.iol_plan_duplicate_eye'));

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.store'), sclPayload($this->w, ['eye' => 'ao'] + $dup))
        ->assertStatus(422)
        ->assertJsonValidationErrors('iol_plans.1.eye');

    // Quem não passa pelo formulário esbarra na regra do serviço — antes de apagar os planos antigos.
    expect(fn () => app(SurgicalCaseService::class)->update($case, $dup))
        ->toThrow(SurgeryRuleException::class, __('surgery.errors.iol_plan_duplicate_eye'));

    expect($case->iolPlans()->pluck('model_label')->all())->toBe(['LIO ANTIGA']);

    // Um plano por olho continua valendo (OD e OE juntos).
    surgeryAs($this->w, 'doctor')->put(route('panel.surgery.update', $case), ['iol_plans' => [
        ['eye' => 'od', 'model_label' => 'LIO A', 'power' => 21],
        ['eye' => 'oe', 'model_label' => 'LIO B', 'power' => 22],
    ]])->assertRedirect();

    expect($case->iolPlans()->count())->toBe(2);
});

it('exame vinculado ao caso é do paciente do caso: exame de outro paciente é recusado (PatientExam não tem entity_id)', function () {
    $examType = ExamType::factory()->create(['entity_id' => null]);
    $other    = Patient::factory()->create(['entity_id' => $this->w->entity->id, 'covenant_id' => $this->w->particular->id, 'active' => true]);
    $foreign  = PatientExam::factory()->create(['patient_id' => $other->id, 'exam_id' => $examType->id]);
    $own      = PatientExam::factory()->create(['patient_id' => $this->w->patient->id, 'exam_id' => $examType->id]);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.store'), sclPayload($this->w, [
        'exams' => [['patient_exam_id' => (string) $foreign->id, 'role' => 'other']],
    ]))->assertStatus(422);

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.store'), sclPayload($this->w, [
        'exams' => [['patient_exam_id' => (string) $own->id, 'role' => 'topography']],
    ]))->assertRedirect();

    surgeryAs($this->w, 'secretary')->getJson(route('panel.surgery.patient-exams', ['patient' => (string) $this->w->patient->id]))
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', (string) $own->id);
});
