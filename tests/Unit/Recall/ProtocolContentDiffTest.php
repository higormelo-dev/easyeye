<?php

declare(strict_types=1);

use App\Domains\Recall\Support\{ProposedProtocolDrafts, ProtocolContentDiff, ProtocolContentSchema};
use Tests\TestCase;

uses(TestCase::class);

/** @return array<string, mixed> conteúdo normalizado do glaucoma proposto */
function pcdBase(): array
{
    return ProtocolContentSchema::normalize(ProposedProtocolDrafts::all()['glaucoma']['content']);
}

/** Aplica a mudança ao glaucoma e devolve o diff (antigo → novo), já normalizado. */
function pcdDiff(callable $change): array
{
    $input = ProposedProtocolDrafts::all()['glaucoma']['content'];
    $change($input);

    return ProtocolContentDiff::between(pcdBase(), ProtocolContentSchema::normalize($input));
}

it('sem versão anterior não há diferença', function () {
    expect(ProtocolContentDiff::between(null, pcdBase()))->toBe([]);
});

it('conteúdo igual não tem diferença, mesmo com as tarefas e referências em outra ordem', function () {
    $base      = pcdBase();
    $reordered = $base;

    $reordered['tasks']      = array_reverse($base['tasks']);
    $reordered['references'] = array_reverse($base['references']);

    expect(ProtocolContentDiff::between($base, $base))->toBe([])
        ->and(ProtocolContentDiff::between($base, $reordered))->toBe([]);
});

it('CID: acrescentado e retirado em cada lista', function () {
    $diff = pcdDiff(function (&$c) {
        $c['entry']['cid']['strong']  = ['H401', 'H402', 'H403', 'H404', 'H405', 'H406', 'H408'];
        $c['entry']['cid']['weak']    = ['H40', 'H42', 'H44'];
        $c['entry']['cid']['exclude'] = [];
    });

    expect($diff)->toEqualCanonicalizing([
        ['section' => 'cid.strong', 'op' => 'removed', 'value' => 'H409'],
        ['section' => 'cid.weak', 'op' => 'added', 'value' => 'H44'],
        ['section' => 'cid.exclude', 'op' => 'removed', 'value' => 'H400'],
    ]);
});

it('medicação acrescentada', function () {
    expect(pcdDiff(fn (&$c) => $c['entry']['medications'] = ['chloroquine']))
        ->toBe([['section' => 'medication', 'op' => 'added', 'value' => 'chloroquine']]);
});

it('tarefa: campo alterado, um item por campo, com o antes e o depois', function () {
    $diff = pcdDiff(function (&$c) {
        $c['tasks'][1]['interval']['default'] = 9;
        $c['tasks'][1]['interval']['min']     = 6;
        $c['tasks'][1]['lead_days']           = 45;
        $c['tasks'][2]['required']            = true;
    });

    expect($diff)->toEqualCanonicalizing([
        ['section' => 'task', 'op' => 'changed', 'key' => 'campimetria', 'field' => 'interval.default', 'from' => 12, 'to' => 9],
        ['section' => 'task', 'op' => 'changed', 'key' => 'campimetria', 'field' => 'lead_days', 'from' => 30, 'to' => 45],
        ['section' => 'task', 'op' => 'changed', 'key' => 'oct_nervo_optico', 'field' => 'required', 'from' => false, 'to' => true],
    ]);
});

it('tarefa: cada campo do esquema é comparado', function (string $field, mixed $change) {
    $diff = pcdDiff(function (&$c) use ($field, $change) {
        match ($field) {
            'kind'                => $c['tasks'][1]                        = ['key' => 'campimetria', 'kind' => 'consultation', 'interval' => $c['tasks'][1]['interval']],
            'exam_key'            => $c['tasks'][1]['exam_key']            = $change,
            'interval.unit'       => $c['tasks'][1]['interval']            = ['unit' => 'weeks', 'default' => 12, 'min' => 6, 'max' => 12],
            'interval.max'        => $c['tasks'][1]['interval']['max']     = $change,
            'grace_days'          => $c['tasks'][1]['grace_days']          = $change,
            'lost_after_days'     => $c['tasks'][1]['lost_after_days']     = $change,
            'starts_after_months' => $c['tasks'][1]['starts_after_months'] = $change,
            'recurs'              => $c['tasks'][1]['recurs']              = $change,
        };
    });

    expect(array_column($diff, 'field'))->toContain($field);
})->with([
    ['kind', null],
    ['exam_key', 'oct_macula'],
    ['interval.unit', null],
    ['interval.max', 18],
    ['grace_days', 45],
    ['lost_after_days', 200],
    ['starts_after_months', 24],
    ['recurs', false],
]);

it('tarefa: acrescentada e retirada, pela chave', function () {
    $diff = pcdDiff(function (&$c) {
        array_pop($c['tasks']);
        $c['tasks'][] = ['key' => 'tonometria', 'kind' => 'exam', 'exam_key' => 'oct_macula', 'interval' => ['unit' => 'months', 'default' => 6, 'min' => 3, 'max' => 12]];
    });

    expect(array_map(fn ($c) => [$c['section'], $c['op'], $c['key']], $diff))->toEqualCanonicalizing([
        ['task', 'added', 'tonometria'],
        ['task', 'removed', 'oct_nervo_optico'],
    ])
        ->and(collect($diff)->firstWhere('op', 'added')['value']['key'])->toBe('tonometria')
        ->and(collect($diff)->firstWhere('op', 'removed')['value']['required'])->toBeFalse();
});

it('pedido do médico (cap_to_max)', function () {
    expect(pcdDiff(fn (&$c) => $c['doctor_order']['cap_to_max'] = false))
        ->toBe([['section' => 'doctor_order', 'op' => 'changed', 'field' => 'cap_to_max', 'from' => true, 'to' => false]]);
});

it('referência: acrescentada, retirada e alterada, pelo título', function () {
    $diff = pcdDiff(function (&$c) {
        $c['references'][0]['evidence'] = 'high';
        $c['references'][0]['year']     = 2022;
        array_pop($c['references']);
        $c['references'][] = ['title' => 'Nova diretriz', 'year' => 2025, 'evidence' => 'low'];
    });

    expect(array_map(fn ($c) => [$c['op'], $c['key'], $c['field'] ?? null], $diff))->toEqualCanonicalizing([
        ['changed', 'American Academy of Ophthalmology. Primary Open-Angle Glaucoma Preferred Practice Pattern. Ophthalmology 2021', 'year'],
        ['changed', 'American Academy of Ophthalmology. Primary Open-Angle Glaucoma Preferred Practice Pattern. Ophthalmology 2021', 'evidence'],
        ['added', 'Nova diretriz', null],
        ['removed', 'Chauhan BC et al. Practical recommendations for measuring rates of visual field change in glaucoma. Br J Ophthalmol 2008', null],
    ]);
});

it('justificativa e nota de mudança', function () {
    $diff = pcdDiff(function (&$c) {
        $c['rationale']   = 'Outra justificativa.';
        $c['change_note'] = 'Revisão pedida pelo revisor.';
    });

    expect($diff)->toBe([
        ['section' => 'rationale', 'op' => 'changed', 'from' => pcdBase()['rationale'], 'to' => 'Outra justificativa.'],
        ['section' => 'change_note', 'op' => 'changed', 'from' => pcdBase()['change_note'], 'to' => 'Revisão pedida pelo revisor.'],
    ]);
});

it('simetria: o que entra de A para B sai de B para A', function () {
    $a = pcdBase();
    $b = ProtocolContentSchema::normalize(array_replace_recursive(ProposedProtocolDrafts::all()['glaucoma']['content'], [
        'entry' => ['medications' => ['chloroquine']],
    ]));

    $forward  = ProtocolContentDiff::between($a, $b);
    $backward = ProtocolContentDiff::between($b, $a);

    expect($forward)->toBe([['section' => 'medication', 'op' => 'added', 'value' => 'chloroquine']])
        ->and($backward)->toBe([['section' => 'medication', 'op' => 'removed', 'value' => 'chloroquine']]);
});
