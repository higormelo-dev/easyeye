<?php

declare(strict_types=1);

/**
 * Salvar o mapeamento de exames × a trava ESTÁVEL da clínica (achado via revisão adversarial Codex). Salvar troca o
 * CONJUNTO inteiro; um `FOR UPDATE` sobre as linhas que existem não trava nada quando não existe nenhuma (a 1ª
 * configuração), então duas trocas simultâneas liam o conjunto vazio: as iguais batiam no índice único (500) e as
 * disjuntas deixavam a UNIÃO gravada — uma configuração que ninguém enviou, e que decide quais exames cumprem os
 * retornos. A ordem das instruções é checada aqui (determinística); a corrida com duas conexões reais está em
 * tests/Integration/RecallExamMappingConcurrencyTest.php.
 */

use App\Domains\Recall\Models\RecallExamMapping;
use App\Domains\Recall\Services\ExamMappingService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    ['entity' => $this->entity] = rcfClinic(recall: true);
});

/**
 * Roda `sync` e devolve as instruções na ordem em que saíram: `lock` (a trava da clínica), `other-lock` (qualquer outra
 * trava advisory), `types` (a conferência dos tipos de exame), `mapping` (qualquer leitura/escrita do mapeamento).
 *
 * @param array<string, mixed> $mappings
 *
 * @return list<string>
 */
function emlOrder(object $test, array $mappings): array
{
    $seen = [];

    DB::listen(function ($query) use (&$seen, $test): void {
        $sql = $query->sql;

        if (str_contains($sql, 'pg_advisory_xact_lock')) {
            $seen[] = ($query->bindings[0] ?? null) === 'easyeye.recall.exam_mappings' && ($query->bindings[1] ?? null) === (string) $test->entity->id ? 'lock' : 'other-lock';
        } elseif (str_contains($sql, '"recall_exam_mappings"')) {
            $seen[] = 'mapping';
        } elseif (str_contains($sql, '"exam_types"')) {
            $seen[] = 'types';
        }
    });

    app(ExamMappingService::class)->sync((string) $test->entity->id, $mappings);

    return $seen;
}

it('salvar toma a trava da clínica ANTES de conferir os tipos e de ler ou gravar qualquer linha do mapeamento', function () {
    $type = rcfExamType('Campimetria computadorizada');

    $order = emlOrder($this, ['visual_field' => [(string) $type->id]]);

    expect(array_keys($order, 'lock', true))->toHaveCount(1)
        ->and($order[0])->toBe('lock') // nenhuma instrução antes dela
        ->and($order)->toContain('types', 'mapping')
        ->and($order)->not->toContain('other-lock');
});

it('a trava é por clínica: outra clínica tem outra chave (nunca uma fila só para o sistema inteiro)', function () {
    ['entity' => $other] = rcfClinic(recall: true);
    $type                = rcfExamType('OCT de mácula');
    $keys                = [];

    DB::listen(function ($query) use (&$keys): void {
        if (str_contains($query->sql, 'pg_advisory_xact_lock') && ($query->bindings[0] ?? null) === 'easyeye.recall.exam_mappings') {
            $keys[] = (string) $query->bindings[1];
        }
    });

    app(ExamMappingService::class)->sync((string) $this->entity->id, ['oct_macula' => [(string) $type->id]]);
    app(ExamMappingService::class)->sync((string) $other->id, ['oct_macula' => [(string) $type->id]]);

    expect($keys)->toBe([(string) $this->entity->id, (string) $other->id]);
});

it('conjunto recusado (tipo indisponível) não grava nada, e a conferência acontece depois da trava', function () {
    $own = rcfExamType('Campimetria computadorizada');
    rcfMapExam($this->entity, 'visual_field', $own);

    expect(fn () => app(ExamMappingService::class)->sync((string) $this->entity->id, ['visual_field' => [(string) $own->id, '00000000-0000-4000-8000-000000000000']]))
        ->toThrow(InvalidArgumentException::class);

    expect(RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->pluck('exam_type_id')->all())->toBe([(string) $own->id]);
});

it('chave canônica desconhecida é recusada sem tocar o banco (nem a trava)', function () {
    $type = rcfExamType('Campimetria computadorizada');
    $seen = [];

    DB::listen(function ($query) use (&$seen): void {
        $seen[] = $query->sql;
    });

    expect(fn () => app(ExamMappingService::class)->sync((string) $this->entity->id, ['inventado' => [(string) $type->id]]))->toThrow(InvalidArgumentException::class)
        ->and($seen)->toBe([]);
});
