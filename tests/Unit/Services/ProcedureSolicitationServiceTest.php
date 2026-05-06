<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\{Entity, Indication, Procedure, User};
use App\Services\ProcedureSolicitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);
uses(RefreshDatabase::class);

beforeEach(function () {
    $entity = Entity::factory()->create(['is_client' => true]);
    $user   = User::factory()->create();
    $this->actingAs($user);
    session(['selected_entity_id' => $entity->id]);
});

it('formatProcedureLine sem tipo gera linha simples', function () {
    $procedure = Procedure::factory()->create(['name' => 'Facoemulsificação']);

    $line = (new ProcedureSolicitationService())->formatProcedureLine($procedure);

    expect($line)->toBe('- Facoemulsificação');
});

it('formatProcedureLine com tipo rotina gera linha com label', function () {
    $procedure = Procedure::factory()->create(['name' => 'Mapeamento de retina']);

    $line = (new ProcedureSolicitationService())->formatProcedureLine($procedure, 'rotina');

    expect($line)->toBe('- Mapeamento de retina (Rotina)');
});

it('formatProcedureLine aceita todos os tipos canônicos', function () {
    $procedure = Procedure::factory()->create(['name' => 'Tonometria']);
    $service   = new ProcedureSolicitationService();

    expect($service->formatProcedureLine($procedure, 'urgencia'))->toContain('(Urgência)')
        ->and($service->formatProcedureLine($procedure, 'controle'))->toContain('(Controle)')
        ->and($service->formatProcedureLine($procedure, 'comparativo'))->toContain('(Comparativo)');
});

it('formatProcedureLine rejeita tipo inválido', function () {
    $procedure = Procedure::factory()->create();

    (new ProcedureSolicitationService())->formatProcedureLine($procedure, 'inexistente');
})->throws(InvalidArgumentException::class, 'Tipo de solicitação inválido');

it('formatIndicationLine prefixa com "Indicação:"', function () {
    $indication = Indication::factory()->create(['description' => 'Suspeita de glaucoma']);

    $line = (new ProcedureSolicitationService())->formatIndicationLine($indication);

    expect($line)->toBe('- Indicação: Suspeita de glaucoma');
});

it('joinLines concatena linhas com newline final único', function () {
    $out = (new ProcedureSolicitationService())->joinLines([
        '- Facoemulsificação (Rotina)',
        '- Indicação: Catarata madura',
    ]);

    expect($out)->toContain('- Facoemulsificação (Rotina)')
        ->and($out)->toContain('- Indicação: Catarata madura')
        ->and(str_ends_with($out, "\n"))->toBeTrue()
        ->and(str_ends_with($out, "\n\n"))->toBeFalse();
});

it('joinLines descarta linhas vazias', function () {
    $out = (new ProcedureSolicitationService())->joinLines(['- A', '   ', '', '- B']);

    expect(substr_count($out, "\n"))->toBe(2);
});

it('expoe MAX_PROCEDURES + MAX_INDICATIONS + typeKeys', function () {
    expect(ProcedureSolicitationService::MAX_PROCEDURES)->toBe(10)
        ->and(ProcedureSolicitationService::MAX_INDICATIONS)->toBe(10);

    expect(ProcedureSolicitationService::typeKeys())
        ->toEqualCanonicalizing(['rotina', 'urgencia', 'controle', 'comparativo']);
});
