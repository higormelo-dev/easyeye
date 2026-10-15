<?php

declare(strict_types=1);

/**
 * A clínica ADOTA uma versão validada (ADR 0002, itens 3 e 8): a prova da validação é reconferida na hora, os
 * parâmetros ficam dentro da faixa que o Revisor validou, só a versão liberada é ativada, e a adoção guarda o
 * retrato do que o médico viu.
 */

use App\Domains\ClinicalReview\Enums\AcceptanceDecision;
use App\Domains\ClinicalReview\Services\ReviewAcceptanceService;
use App\Domains\Recall\Exceptions\ProtocolActivationException as Refused;
use App\Domains\Recall\Models\{RecallClinicProtocol, RecallProtocol};
use App\Domains\Recall\Services\{ProtocolActivationService, ProtocolCatalogService, ProtocolGovernanceService};
use App\Domains\Recall\Support\{ProposedProtocolDrafts, ValidationEvidence};
use App\Models\{EntityUser, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);
    $this->actor                                           = User::query()->findOrFail(EntityUser::query()->findOrFail($this->doctor->entity_user_id)->user_id);
    $this->service                                         = app(ProtocolActivationService::class);
});

function paRefused(callable $call): ?Refused
{
    try {
        $call();
    } catch (Refused $e) {
        return $e;
    }

    return null;
}

function paActivate(object $test, $version, array $tasks = [], bool $ack = true, ?bool $auto = null)
{
    return $test->service->activate($test->entity, (string) $version->protocol_id, (string) $version->id, ['tasks' => $tasks] + ($auto === null ? [] : ['auto_contact' => $auto]), $ack, $test->actor, $test->doctor);
}

// ── a prova ─────────────────────────────────────────────────────────────────

it('a prova da validação: versão validada de verdade vale; sem aceite, adulterada ou recusada pelo Revisor, não', function () {
    $released = rcfReleased('glaucoma');

    expect(ValidationEvidence::holds($released))->toBeTrue();

    // Congelada, sem parecer: sem prova.
    $frozen = crfFrozenVersion('dmri');
    expect(ValidationEvidence::holds($frozen))->toBeFalse();

    // Parecer "ajustes": a versão vira recusada pelo Revisor e nunca ganha prova.
    $created = crfInvitation(null, [crfFrozenVersion('retinopatia_diabetica')]);
    crfSubmit($created, 'changes_requested', 'Mudar o intervalo da consulta, por favor.');
    app(ReviewAcceptanceService::class)->record($created['invitation']->fresh(), AcceptanceDecision::Accepted, true, true, null);
    expect(ValidationEvidence::holds($created['versions'][0]->fresh()))->toBeFalse();

    // Conteúdo adulterado depois: o hash gravado não bate.
    crfTamperVersionContent($released);
    expect(ValidationEvidence::holds($released->fresh()))->toBeFalse();
});

it('o resumo da prova mostra o Revisor SÓ se ele autorizou; sem autorização, nenhum nome nem registro', function () {
    $shown  = rcfReleased('glaucoma');
    $hidden = crfValidatedVersion('dmri', reviewer: crfReviewer());

    // O atestado do segundo: refaz o convite pedindo para NÃO mostrar a identidade.
    $created = crfInvitation(null, [crfFrozenVersion('ceratocone')]);
    crfSubmit($created, attestation: ['allow_identity_display' => false]);
    app(ReviewAcceptanceService::class)->record($created['invitation']->fresh(), AcceptanceDecision::Accepted, true, true, null);
    $anonymous = app(ProtocolGovernanceService::class)->release($created['versions'][0]->fresh());

    $withIdentity = ValidationEvidence::summary($shown);
    $without      = ValidationEvidence::summary($anonymous);

    expect($withIdentity['reviewer'])->toMatchArray(['name' => 'DRA. ANA PAULA SOUZA', 'specialty' => 'Oftalmologia'])
        ->and($withIdentity['reviewer']['crm'])->toStartWith('CRM/SP ')
        ->and($withIdentity['reviewer']['rqe'])->toStartWith('RQE ')
        ->and($withIdentity['version'])->toBe(1)
        ->and($withIdentity['hash_short'])->toHaveLength(12)
        ->and($without['reviewer'])->toBeNull()
        ->and(json_encode($without))->not->toContain('ANA PAULA')
        ->and($hidden['version']->state->value)->toBe('validated');
});

it('o resumo diz se a revalidação venceu', function () {
    $version = rcfReleased('glaucoma');

    expect(ValidationEvidence::summary($version)['review_overdue'])->toBeFalse()
        ->and(ValidationEvidence::summary($version, CarbonImmutable::now()->addMonths(25))['review_overdue'])->toBeTrue()
        ->and(ValidationEvidence::summary(crfFrozenVersion('dmri')))->toBeNull(); // sem aceite: não há o que resumir
});

// ── ativar ──────────────────────────────────────────────────────────────────

it('ativa a versão liberada: obrigatórias ligadas no padrão, opcional desligada, e o retrato do que o médico viu', function () {
    $version = rcfReleased('glaucoma');

    $adoption = paActivate($this, $version);

    expect($adoption->version_id)->toBe((string) $version->id)
        ->and($adoption->adopted_content_hash)->toBe($version->content_hash)
        ->and($adoption->parameters)->toBe(['tasks' => [
            'consulta'         => ['interval' => 4, 'enabled' => true],
            'campimetria'      => ['interval' => 12, 'enabled' => true],
            'oct_nervo_optico' => ['interval' => 12, 'enabled' => false],
        ]])
        ->and($adoption->auto_contact)->toBeFalse()
        ->and($adoption->activated_by)->toBe((string) $this->actor->id)
        ->and($adoption->activated_by_doctor_id)->toBe((string) $this->doctor->id)
        ->and($adoption->activation_snapshot['version'])->toBe(1)
        ->and($adoption->activation_snapshot['hash'])->toBe($version->content_hash)
        ->and($adoption->activation_snapshot['doctor']['doctor_id'])->toBe((string) $this->doctor->id)
        ->and($adoption->activation_snapshot['evidence']['reviewer']['name'])->toBe('DRA. ANA PAULA SOUZA')
        ->and($adoption->activation_snapshot['parameters'])->toBe($adoption->parameters)
        ->and($adoption->activated_at)->not->toBeNull()
        ->and($adoption->deactivated_at)->toBeNull();
});

it('a clínica ajusta dentro da faixa e liga a opcional; o resto vale o padrão validado', function () {
    $adoption = paActivate($this, rcfReleased('glaucoma'), ['campimetria' => ['interval' => 6], 'oct_nervo_optico' => ['enabled' => true, 'interval' => '24']], auto: true);

    expect($adoption->parameters['tasks'])->toBe([
        'consulta'         => ['interval' => 4, 'enabled' => true],
        'campimetria'      => ['interval' => 6, 'enabled' => true],
        'oct_nervo_optico' => ['interval' => 24, 'enabled' => true],
    ])->and($adoption->auto_contact)->toBeTrue();
});

it('parâmetro fora do que foi validado é recusado, com o campo e o código de cada problema', function () {
    $version = rcfReleased('glaucoma');

    $refused = paRefused(fn () => paActivate($this, $version, [
        'consulta'         => ['interval' => 2],                  // a faixa é 3–6
        'campimetria'      => ['interval' => 'doze', 'enabled' => false], // não é inteiro; obrigatória não desliga
        'oct_nervo_optico' => ['interval' => 25],                 // a faixa é 6–24
        'inventada'        => ['interval' => 3],
    ]));

    expect($refused?->reason())->toBe(Refused::INVALID_PARAMETERS)
        ->and($refused->errors())->toBe([
            'tasks.consulta.interval'         => 'interval_out_of_range',
            'tasks.campimetria.interval'      => 'interval_not_integer',
            'tasks.campimetria.enabled'       => 'required_task_cannot_be_disabled',
            'tasks.oct_nervo_optico.interval' => 'interval_out_of_range',
            'tasks.inventada'                 => 'unknown_task',
        ])
        ->and(RecallClinicProtocol::query()->withoutGlobalScopes()->count())->toBe(0);

    // O limite de cada ponta vale (3 e 6; 6 e 24).
    foreach ([3, 6] as $months) {
        expect(paRefused(fn () => paActivate($this, $version, ['consulta' => ['interval' => $months]])))->toBeNull();
    }
});

it('é preciso confirmar que leu a regra e a validação', function () {
    expect(paRefused(fn () => paActivate($this, rcfReleased('glaucoma'), ack: false))?->reason())->toBe(Refused::NOT_ACKNOWLEDGED)
        ->and(RecallClinicProtocol::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('só a versão LIBERADA é ativada: validada, em revisão ou substituída (numa adoção nova) são recusadas', function () {
    $validated = crfValidatedVersion('dmri')['version'];
    expect(paRefused(fn () => paActivate($this, $validated))?->reason())->toBe(Refused::VERSION_NOT_RELEASED);

    $first = rcfReleased('glaucoma');
    rcfReleaseSecond($first);

    expect($first->fresh()->state->value)->toBe('superseded')
        ->and(paRefused(fn () => paActivate($this, $first->fresh()))?->reason())->toBe(Refused::VERSION_NOT_RELEASED);
});

it('sem a prova da validação (conteúdo adulterado) a ativação é recusada', function () {
    $version = rcfReleased('glaucoma');
    crfTamperVersionContent($version);

    expect(paRefused(fn () => paActivate($this, $version->fresh()))?->reason())->toBe(Refused::NO_VALIDATION_EVIDENCE);
});

it('versão de outro protocolo, protocolo arquivado e protocolo inexistente', function () {
    $glaucoma = rcfReleased('glaucoma');
    $dmri     = rcfReleased('dmri');

    expect(paRefused(fn () => $this->service->activate($this->entity, (string) $glaucoma->protocol_id, (string) $dmri->id, [], true, $this->actor, $this->doctor))?->reason())->toBe(Refused::VERSION_MISMATCH)
        ->and(paRefused(fn () => $this->service->activate($this->entity, '01a00000-0000-7000-8000-000000000000', (string) $dmri->id, [], true, $this->actor, $this->doctor))?->reason())->toBe(Refused::PROTOCOL_UNAVAILABLE);

    RecallProtocol::query()->whereKey($glaucoma->protocol_id)->update(['status' => RecallProtocol::STATUS_ARCHIVED]);

    expect(paRefused(fn () => paActivate($this, $glaucoma))?->reason())->toBe(Refused::PROTOCOL_UNAVAILABLE);
});

it('versão nova liberada: a clínica troca (continua UMA adoção ativa); ajustar parâmetros na versão substituída é permitido', function () {
    $first = rcfReleased('glaucoma');
    paActivate($this, $first);
    $second = rcfReleaseSecond($first);

    // Ajuste de parâmetro continuando na v1 (já substituída, ainda em uso): vale.
    $kept = paActivate($this, $first->fresh(), ['campimetria' => ['interval' => 8]]);
    expect($kept->version_id)->toBe((string) $first->id)->and($kept->parameters['tasks']['campimetria']['interval'])->toBe(8);

    $upgraded = paActivate($this, $second);

    expect($upgraded->id)->toBe($kept->id) // a MESMA adoção, agora na v2
        ->and($upgraded->version_id)->toBe((string) $second->id)
        ->and($upgraded->adopted_content_hash)->toBe($second->content_hash)
        ->and($upgraded->parameters['tasks']['campimetria']['interval'])->toBe(9) // o padrão validado da v2
        ->and($upgraded->activation_snapshot['version'])->toBe(2)
        ->and(RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->whereNull('deactivated_at')->count())->toBe(1);
});

it('a adoção de uma clínica nunca mexe na da outra', function () {
    $version = rcfReleased('glaucoma');
    $other   = rcfClinic(recall: true);

    paActivate($this, $version, ['consulta' => ['interval' => 3]]);
    $this->service->activate($other['entity'], (string) $version->protocol_id, (string) $version->id, ['tasks' => ['consulta' => ['interval' => 6]]], true, $this->actor, $other['doctor']);

    $mine   = RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->sole();
    $theirs = RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $other['entity']->id)->sole();

    expect($mine->parameters['tasks']['consulta']['interval'])->toBe(3)
        ->and($theirs->parameters['tasks']['consulta']['interval'])->toBe(6);

    // Desativar é por clínica: quem nunca ativou não desativa a de ninguém; a outra clínica desativa só a dela.
    $third = rcfClinic(recall: true);
    expect(paRefused(fn () => $this->service->deactivate($third['entity'], (string) $version->protocol_id, 'Esta clínica nunca ativou este protocolo.', $this->actor))?->reason())->toBe(Refused::NOT_ADOPTED);

    $this->service->deactivate($other['entity'], (string) $version->protocol_id, 'Esta clínica deixou de acompanhar glaucoma.', $this->actor);

    expect($mine->fresh()->deactivated_at)->toBeNull()
        ->and($theirs->fresh()->deactivated_at)->not->toBeNull();
});

it('o curso de tratamento não é parâmetro da lista: não entra no que a clínica ajusta nem no que é gravado', function () {
    $proposal           = ProposedProtocolDrafts::all()['glaucoma'];
    $content            = $proposal['content'];
    $content['tasks'][] = [
        'key'       => 'aplicacao', 'kind' => 'treatment_course', 'exam_key' => null,
        'interval'  => ['unit' => 'weeks', 'default' => 8, 'min' => 4, 'max' => 16],
        'lead_days' => 7, 'grace_days' => 7, 'lost_after_days' => 90, 'starts_after_months' => null, 'recurs' => true, 'required' => true,
    ];
    app(ProtocolCatalogService::class)->createProtocol('glaucoma', $proposal['translations'], $content, $proposal['sort_order']);

    $adoption = paActivate($this, rcfReleased('glaucoma'));

    expect(array_keys($adoption->parameters['tasks']))->toBe(['consulta', 'campimetria', 'oct_nervo_optico']);
});

// ── desativar ───────────────────────────────────────────────────────────────

it('desativar: exige motivo, grava quem/quando/por quê, e a clínica pode ativar de novo', function () {
    $version = rcfReleased('glaucoma');
    $first   = paActivate($this, $version);

    expect(paRefused(fn () => $this->service->deactivate($this->entity, (string) $version->protocol_id, 'curto', $this->actor))?->reason())->toBe(Refused::REASON_REQUIRED)
        ->and(paRefused(fn () => $this->service->deactivate($this->entity, (string) $version->protocol_id, " nove letr \n", $this->actor))?->reason())->toBe(Refused::REASON_REQUIRED) // 9 caracteres depois de aparar e juntar espaços
        ->and($first->fresh()->deactivated_at)->toBeNull();

    // Aparar e juntar espaços antes de contar: "dez letras" tem exatamente 10 caracteres e vale.
    $off = $this->service->deactivate($this->entity, (string) $version->protocol_id, "  dez\n letras  ", $this->actor);

    expect($off->deactivated_at)->not->toBeNull()
        ->and($off->deactivated_by)->toBe((string) $this->actor->id)
        ->and($off->deactivation_reason)->toBe('dez letras')
        ->and(paRefused(fn () => $this->service->deactivate($this->entity, (string) $version->protocol_id, 'Já estava desativado, outra vez.', $this->actor))?->reason())->toBe(Refused::NOT_ADOPTED);

    $again = paActivate($this, $version);

    expect($again->id)->not->toBe($first->id)
        ->and(RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(2)
        ->and(RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->whereNull('deactivated_at')->count())->toBe(1);
});

it('ativar ou desativar marca a clínica inteira para reavaliação (outbox)', function () {
    $version = rcfReleased('glaucoma');
    $patient = rcfPatient($this->entity);
    rcfRecord($this->entity, $patient, $this->doctor, now()->subDays(10)->toDateString(), 60);
    DB::table('recall_dirty_patients')->delete();

    paActivate($this, $version);
    expect(DB::table('recall_dirty_patients')->where('patient_id', $patient->id)->count())->toBe(1);

    DB::table('recall_dirty_patients')->delete();
    $this->service->deactivate($this->entity, (string) $version->protocol_id, 'Desativado para o teste da outbox.', $this->actor);
    expect(DB::table('recall_dirty_patients')->where('patient_id', $patient->id)->count())->toBe(1);
});
