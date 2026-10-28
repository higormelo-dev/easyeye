<?php

use App\Enums\ActivationStep;
use App\Models\Entity;
use App\Services\ActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regressão do bug: card "Configure sua clínica" nunca sumia porque a
 * visibilidade exigia activationScore === 100, mas TeamMemberInvited
 * (clínica solo, nunca convida ninguém) e IntegratorConnected (exige
 * hardware/API de integrador — feature opcional) são etapas que boa parte
 * das clínicas legitimamente nunca cumpre.
 *
 * @see ActivationStep::required()
 * @see ActivationService::isComplete()
 */
beforeEach(function () {
    $this->service = app(ActivationService::class);
    $this->entity  = Entity::factory()->create(['is_client' => true]);
});

function completeRequiredActivationSteps($service, $entityId): void
{
    $service->complete($entityId, ActivationStep::EntityProfileCompleted);
    $service->complete($entityId, ActivationStep::FirstDoctorAdded);
    $service->complete($entityId, ActivationStep::FirstPatientAdded);
    $service->complete($entityId, ActivationStep::FirstScheduleCreated);
    $service->complete($entityId, ActivationStep::FirstMedicalRecord);
}

test('isComplete é falso quando nenhuma etapa foi concluída', function () {
    expect($this->service->isComplete($this->entity->id))->toBeFalse();
});

test('isComplete é true com etapas obrigatórias completas, mesmo sem as opcionais', function () {
    // Clínica solo: nunca convida equipe (TeamMemberInvited) nem conecta
    // integrador de hardware (IntegratorConnected). Score fica em 80/100,
    // mas o onboarding "core" está 100% feito — card deve sumir.
    completeRequiredActivationSteps($this->service, $this->entity->id);

    expect($this->service->getScore($this->entity->id))->toBe(80)
        ->and($this->service->isComplete($this->entity->id))->toBeTrue();
});

test('isComplete é falso se faltar qualquer etapa obrigatória, mesmo com as opcionais feitas', function () {
    $this->service->complete($this->entity->id, ActivationStep::EntityProfileCompleted);
    $this->service->complete($this->entity->id, ActivationStep::FirstDoctorAdded);
    $this->service->complete($this->entity->id, ActivationStep::FirstPatientAdded);
    $this->service->complete($this->entity->id, ActivationStep::FirstScheduleCreated);
    // FirstMedicalRecord faltando de propósito.
    $this->service->complete($this->entity->id, ActivationStep::TeamMemberInvited);
    $this->service->complete($this->entity->id, ActivationStep::IntegratorConnected);

    expect($this->service->getScore($this->entity->id))->toBe(80)
        ->and($this->service->isComplete($this->entity->id))->toBeFalse();
});

test('score chega a 100 quando todas as etapas, incluindo as opcionais, são concluídas', function () {
    completeRequiredActivationSteps($this->service, $this->entity->id);
    $this->service->complete($this->entity->id, ActivationStep::TeamMemberInvited);
    $this->service->complete($this->entity->id, ActivationStep::IntegratorConnected);

    expect($this->service->getScore($this->entity->id))->toBe(100)
        ->and($this->service->isComplete($this->entity->id))->toBeTrue();
});

test('soma dos pesos de todas as etapas de ativação é 100', function () {
    $total = array_sum(array_map(fn (ActivationStep $s) => $s->weight(), ActivationStep::ordered()));

    expect($total)->toBe(100);
});

test('getProgress expõe required por etapa e reflete required() do enum', function () {
    $progress = $this->service->getProgress($this->entity->id);
    $byStep   = collect($progress)->keyBy('step');

    expect($byStep->get(ActivationStep::TeamMemberInvited->value)['required'])->toBeFalse()
        ->and($byStep->get(ActivationStep::IntegratorConnected->value)['required'])->toBeFalse()
        ->and($byStep->get(ActivationStep::EntityProfileCompleted->value)['required'])->toBeTrue();
});
