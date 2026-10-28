<?php

declare(strict_types=1);

use App\Domains\Recall\Enums\ProtocolVersionState as State;
use Tests\TestCase;

uses(TestCase::class);

it('a tabela de transições cobre todos os estados e só aponta para estados que existem', function () {
    $transitions = State::transitions();

    expect(array_keys($transitions))->toEqualCanonicalizing(State::values());

    foreach ($transitions as $from => $targets) {
        foreach ($targets as $target) {
            expect($target)->toBeInstanceOf(State::class)
                ->and($target->value)->not->toBe($from, "{$from} não transita para si mesmo");
        }
    }
});

it('rascunho é o único estado de onde se parte sem volta: ninguém transita PARA draft', function () {
    foreach (State::cases() as $from) {
        expect($from->canTransitionTo(State::Draft))->toBeFalse($from->value);
    }
});

it('aposentada é terminal e todo estado congelado pode ser aposentado, menos draft', function () {
    expect(State::transitions()[State::Retired->value])->toBe([]);

    foreach (State::cases() as $state) {
        $expected = ! in_array($state, [State::Draft, State::Retired], true);

        expect($state->canTransitionTo(State::Retired))->toBe($expected, $state->value);
    }
});

it('o caminho feliz é draft → in_review → validated → released → superseded', function () {
    expect(State::Draft->canTransitionTo(State::InReview))->toBeTrue()
        ->and(State::InReview->canTransitionTo(State::Validated))->toBeTrue()
        ->and(State::Validated->canTransitionTo(State::Released))->toBeTrue()
        ->and(State::Released->canTransitionTo(State::Superseded))->toBeTrue();
});

it('não se pula etapa: rascunho não vai a validada/liberada, em revisão não vai a liberada', function () {
    expect(State::Draft->canTransitionTo(State::Validated))->toBeFalse()
        ->and(State::Draft->canTransitionTo(State::Released))->toBeFalse()
        ->and(State::InReview->canTransitionTo(State::Released))->toBeFalse()
        ->and(State::Declined->canTransitionTo(State::Released))->toBeFalse()
        ->and(State::Withdrawn->canTransitionTo(State::Released))->toBeFalse();
});

it('suspender e retomar: liberada e substituída suspendem; suspensa volta a liberada ou substituída', function () {
    expect(State::Released->canTransitionTo(State::Suspended))->toBeTrue()
        ->and(State::Superseded->canTransitionTo(State::Suspended))->toBeTrue()
        ->and(State::Suspended->canTransitionTo(State::Released))->toBeTrue()
        ->and(State::Suspended->canTransitionTo(State::Superseded))->toBeTrue()
        ->and(State::Validated->canTransitionTo(State::Suspended))->toBeFalse();
});

it('só liberada é adotável; liberada e substituída estão em uso; suspensa e aposentada não', function () {
    foreach (State::cases() as $state) {
        expect($state->isAdoptable())->toBe($state === State::Released, "adotável {$state->value}")
            ->and($state->isInUse())->toBe(in_array($state, [State::Released, State::Superseded], true), "em uso {$state->value}");
    }
});

it('congelado é tudo menos rascunho; em andamento é rascunho ou em revisão', function () {
    foreach (State::cases() as $state) {
        expect($state->isFrozen())->toBe($state !== State::Draft, "congelado {$state->value}")
            ->and($state->isOpen())->toBe(in_array($state, [State::Draft, State::InReview], true), "aberto {$state->value}");
    }
});

it('todo estado tem rótulo nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach (State::cases() as $state) {
            expect($state->label())->not->toStartWith('recall_protocol.')->and($state->label())->not->toBeEmpty();
        }
    }
});
