<?php

declare(strict_types=1);

/**
 * Esquema v1 do conteúdo de protocolo de retorno (ProtocolContentSchema): o que é
 * aceito, o que é normalizado e — principalmente — o que é RECUSADO. Conteúdo
 * aceito vira JSON canônico e hash; qualquer regra frouxa aqui vira regra clínica
 * frouxa em produção.
 */

use App\Domains\Recall\Exceptions\InvalidProtocolContentException;
use App\Domains\Recall\Support\{ProposedProtocolDrafts, ProtocolContentSchema};
use App\Support\CanonicalJson;
use Tests\TestCase;

uses(TestCase::class);

/** @return array<string, mixed> o menor conteúdo válido */
function pcsMinimal(): array
{
    return [
        'entry'     => ['cid' => ['strong' => ['H401']], 'medications' => []],
        'tasks'     => [['key' => 'consulta', 'kind' => 'consultation', 'interval' => ['unit' => 'months', 'default' => 6, 'min' => 3, 'max' => 12]]],
        'rationale' => 'Justificativa.',
    ];
}

/** Aplica `$change` ao conteúdo mínimo e devolve [caminho => código] dos erros. */
function pcsErrors(callable $change): array
{
    $content = pcsMinimal();
    $change($content);

    try {
        ProtocolContentSchema::normalize($content);
    } catch (InvalidProtocolContentException $e) {
        return $e->codes();
    }

    return [];
}

it('completa o conteúdo mínimo com os padrões e devolve o formato inteiro', function () {
    $content = ProtocolContentSchema::normalize(pcsMinimal());

    expect($content)->toBe([
        'schema_version' => 1,
        'entry'          => ['cid' => ['strong' => ['H401'], 'weak' => [], 'exclude' => []], 'medications' => []],
        'tasks'          => [[
            'key'                 => 'consulta',
            'kind'                => 'consultation',
            'exam_key'            => null,
            'interval'            => ['unit' => 'months', 'default' => 6, 'min' => 3, 'max' => 12],
            'lead_days'           => 30,
            'grace_days'          => 30,
            'lost_after_days'     => 180,
            'starts_after_months' => null,
            'recurs'              => true,
            'required'            => true,
        ]],
        'doctor_order' => ['cap_to_max' => true],
        'references'   => [],
        'rationale'    => 'Justificativa.',
        'change_note'  => '',
    ]);
});

it('o mesmo conteúdo lógico vira os mesmos bytes, qualquer que seja a ordem, a caixa e a pontuação do CID', function () {
    $a = pcsMinimal();
    $b = [
        'rationale' => "  Justificativa.\r\n",
        'tasks'     => [['required' => true, 'interval' => ['max' => 12, 'min' => 3, 'default' => 6, 'unit' => 'months'], 'kind' => 'consultation', 'key' => 'consulta']],
        'entry'     => ['medications' => [], 'cid' => ['strong' => ['h40.1'], 'exclude' => [], 'weak' => []]],
    ];

    expect(ProtocolContentSchema::prepare($a)['canonical'])->toBe(ProtocolContentSchema::prepare($b)['canonical'])
        ->and(ProtocolContentSchema::prepare($a)['hash'])->toBe(ProtocolContentSchema::prepare($b)['hash']);
});

it('CID: maiúsculo, sem ponto, sem †/*, ordenado e sem repetição', function () {
    $content                 = pcsMinimal();
    $content['entry']['cid'] = ['strong' => ['h40.2', 'H40.1', 'H401', 'E10.3†'], 'weak' => ['h36.0*'], 'exclude' => ['H40 0']];

    expect(ProtocolContentSchema::normalize($content)['entry']['cid'])->toBe([
        'strong'  => ['E103', 'H401', 'H402'],
        'weak'    => ['H360'],
        'exclude' => ['H400'],
    ]);
});

it('texto: NFC, trim, CRLF vira LF e a acentuação não é perdida', function () {
    $content              = pcsMinimal();
    $content['rationale'] = "  Revisa\u{0303}o semestral\r\nSegunda linha  ";

    expect(ProtocolContentSchema::normalize($content)['rationale'])->toBe("Revisão semestral\nSegunda linha");
});

it('o hash do conteúdo preparado confere com o texto canônico no domínio dos protocolos', function () {
    $prepared = ProtocolContentSchema::prepare(pcsMinimal());

    expect(CanonicalJson::matches(ProtocolContentSchema::HASH_DOMAIN, $prepared['canonical'], $prepared['hash']))->toBeTrue()
        ->and(CanonicalJson::isCanonical($prepared['canonical']))->toBeTrue()
        ->and(CanonicalJson::matches('easyeye.outra.v1', $prepared['canonical'], $prepared['hash']))->toBeFalse();
});

it('normalizar duas vezes dá o mesmo resultado (ponto fixo)', function () {
    $once  = ProtocolContentSchema::normalize(pcsMinimal());
    $twice = ProtocolContentSchema::normalize($once);

    expect($twice)->toBe($once);
});

// ── recusas ────────────────────────────────────────────────────────────────

it('recusa o que não é objeto', function (mixed $input) {
    expect(fn () => ProtocolContentSchema::normalize($input))->toThrow(InvalidProtocolContentException::class);
})->with(['null' => [null], 'string' => ['x'], 'lista' => [[1, 2]], 'int' => [3]]);

it('recusa chave desconhecida em todos os níveis', function () {
    expect(pcsErrors(fn (&$c) => $c['extra'] = 1))->toBe(['extra' => 'unknown_field'])
        ->and(pcsErrors(fn (&$c) => $c['entry']['extra'] = 1))->toBe(['entry.extra' => 'unknown_field'])
        ->and(pcsErrors(fn (&$c) => $c['entry']['cid']['extra'] = []))->toBe(['entry.cid.extra' => 'unknown_field'])
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['extra'] = 1))->toBe(['tasks.0.extra' => 'unknown_field'])
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['interval']['extra'] = 1))->toBe(['tasks.0.interval.extra' => 'unknown_field'])
        ->and(pcsErrors(fn (&$c) => $c['doctor_order'] = ['extra' => 1]))->toBe(['doctor_order.extra' => 'unknown_field'])
        ->and(pcsErrors(fn (&$c) => $c['references'] = [['title' => 'Fonte', 'evidence' => 'high', 'extra' => 1]]))->toBe(['references.0.extra' => 'unknown_field']);
});

it('é estrito com tipos: "6" não é 6, 6.0 não é 6 e true não é 6', function () {
    foreach (['6', 6.0, true, null] as $bad) {
        expect(pcsErrors(fn (&$c) => $c['tasks'][0]['interval']['default'] = $bad))
            ->toHaveKey('tasks.0.interval.default');
    }

    expect(pcsErrors(fn (&$c) => $c['tasks'][0]['recurs'] = 'sim'))->toBe(['tasks.0.recurs' => 'must_be_boolean'])
        ->and(pcsErrors(fn (&$c) => $c['rationale'] = 12))->toBe(['rationale' => 'must_be_string'])
        ->and(pcsErrors(fn (&$c) => $c['schema_version'] = '1'))->toBe(['schema_version' => 'schema_version_unsupported'])
        ->and(pcsErrors(fn (&$c) => $c['schema_version'] = 2))->toBe(['schema_version' => 'schema_version_unsupported']);
});

it('campos obrigatórios', function () {
    expect(pcsErrors(function (&$c) {
        unset($c['entry']);
    }))->toHaveKey('entry', 'required')
        ->and(pcsErrors(function (&$c) {
            unset($c['tasks']);
        }))->toBe(['tasks' => 'required'])
        ->and(pcsErrors(function (&$c) {
            unset($c['rationale']);
        }))->toBe(['rationale' => 'required'])
        ->and(pcsErrors(fn (&$c) => $c['rationale'] = '   '))->toBe(['rationale' => 'required'])
        ->and(pcsErrors(function (&$c) {
            unset($c['tasks'][0]['key']);
        }))->toBe(['tasks.0.key' => 'required'])
        ->and(pcsErrors(function (&$c) {
            unset($c['tasks'][0]['kind']);
        }))->toBe(['tasks.0.kind' => 'required'])
        ->and(pcsErrors(function (&$c) {
            unset($c['tasks'][0]['interval']);
        }))->toBe(['tasks.0.interval' => 'required']);
});

it('tarefas: ao menos uma, no máximo oito, chave única e ao menos uma obrigatória', function () {
    expect(pcsErrors(fn (&$c) => $c['tasks'] = []))->toBe(['tasks' => 'too_few'])
        ->and(pcsErrors(fn (&$c) => $c['tasks'] = array_fill(0, 9, $c['tasks'][0])))->toHaveKey('tasks', 'too_many')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][] = $c['tasks'][0]))->toBe(['tasks.1.key' => 'duplicate'])
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['required'] = false))->toBe(['tasks' => 'no_required_task']);
});

it('chave de tarefa: letra minúscula primeiro, depois minúsculas, números e _', function (string $key, bool $valid) {
    $errors = pcsErrors(fn (&$c) => $c['tasks'][0]['key'] = $key);

    expect($errors === [])->toBe($valid, $key);
})->with([
    ['consulta', true], ['oct_macula', true], ['a1', true], ['Consulta', false], ['1abc', false],
    ['a', false], ['com espaço', false], ['com-hifen', false], ['ação', false], [str_repeat('a', 41), false],
]);

it('tipo da tarefa e exame: exame exige exam_key válido; as outras não podem ter', function () {
    $exam = fn (&$c) => $c['tasks'][0] = ['key' => 'campo', 'kind' => 'exam', 'exam_key' => 'visual_field', 'interval' => ['unit' => 'months', 'default' => 12, 'min' => 6, 'max' => 12]];

    expect(pcsErrors($exam))->toBe([])
        ->and(pcsErrors(function (&$c) use ($exam) {
            $exam($c);
            unset($c['tasks'][0]['exam_key']);
        }))->toBe(['tasks.0.exam_key' => 'exam_key_required'])
        ->and(pcsErrors(function (&$c) use ($exam) {
            $exam($c);
            $c['tasks'][0]['exam_key'] = 'raio_x';
        }))->toBe(['tasks.0.exam_key' => 'invalid_choice'])
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['exam_key'] = 'visual_field'))->toBe(['tasks.0.exam_key' => 'exam_key_not_allowed'])
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['kind'] = 'cirurgia'))->toBe(['tasks.0.kind' => 'invalid_choice']);
});

it('intervalo: unidade conhecida, mínimo ≤ padrão ≤ máximo, dentro do teto de cada unidade', function () {
    expect(pcsErrors(fn (&$c) => $c['tasks'][0]['interval']['unit'] = 'years'))->toHaveKey('tasks.0.interval.unit', 'invalid_choice')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['interval']['min'] = 7))->toBe(['tasks.0.interval' => 'interval_order'])
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['interval']['max'] = 5))->toBe(['tasks.0.interval' => 'interval_order'])
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['interval']['min'] = 0))->toHaveKey('tasks.0.interval.min', 'out_of_range')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['interval'] = ['unit' => 'months', 'default' => 121, 'min' => 1, 'max' => 121]))
        ->toHaveKey('tasks.0.interval.default', 'out_of_range')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['interval'] = ['unit' => 'weeks', 'default' => 16, 'min' => 4, 'max' => 520]))->toBe([])
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['interval'] = ['unit' => 'weeks', 'default' => 16, 'min' => 4, 'max' => 521]))
        ->toHaveKey('tasks.0.interval.max', 'out_of_range')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['interval'] = ['unit' => 'days', 'default' => 3650, 'min' => 1, 'max' => 3650]))->toBe([])
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['interval'] = ['unit' => 'days', 'default' => 5, 'min' => 5, 'max' => 5]))->toBe([]);
});

it('prazos operacionais: faixas, e "perdido de vista" depois da tolerância', function () {
    expect(pcsErrors(fn (&$c) => $c['tasks'][0]['lead_days'] = -1))->toHaveKey('tasks.0.lead_days', 'out_of_range')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['lead_days'] = 366))->toHaveKey('tasks.0.lead_days', 'out_of_range')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['grace_days'] = 366))->toHaveKey('tasks.0.grace_days', 'out_of_range')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['lost_after_days'] = 0))->toHaveKey('tasks.0.lost_after_days', 'out_of_range')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['lost_after_days'] = 1826))->toHaveKey('tasks.0.lost_after_days', 'out_of_range')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['lost_after_days'] = 30))->toBe(['tasks.0.lost_after_days' => 'lost_not_after_grace'])
        ->and(pcsErrors(function (&$c) {
            $c['tasks'][0]['grace_days']      = 10;
            $c['tasks'][0]['lost_after_days'] = 11;
        }))->toBe([])
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['starts_after_months'] = 0))->toHaveKey('tasks.0.starts_after_months', 'out_of_range')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['starts_after_months'] = 601))->toHaveKey('tasks.0.starts_after_months', 'out_of_range')
        ->and(pcsErrors(fn (&$c) => $c['tasks'][0]['starts_after_months'] = 60))->toBe([]);
});

it('critério de entrada: ao menos um CID forte ou uma medicação', function () {
    expect(pcsErrors(fn (&$c) => $c['entry']['cid']['strong'] = []))->toBe(['entry' => 'no_entry_criteria'])
        ->and(pcsErrors(fn (&$c) => $c['entry']['cid'] = ['strong' => [], 'weak' => ['H40']]))->toBe(['entry' => 'no_entry_criteria'])
        ->and(pcsErrors(function (&$c) {
            $c['entry']['cid']['strong'] = [];
            $c['entry']['medications']   = ['hydroxychloroquine'];
        }))->toBe([]);
});

it('CID: formato, limite e conflito de prefixo entre listas (prefixos diferentes são o uso normal)', function () {
    expect(pcsErrors(fn (&$c) => $c['entry']['cid']['strong'] = ['XYZ']))->toHaveKey('entry.cid.strong.0', 'invalid_cid')
        ->and(pcsErrors(fn (&$c) => $c['entry']['cid']['strong'] = ['H4']))->toHaveKey('entry.cid.strong.0', 'invalid_cid')
        ->and(pcsErrors(fn (&$c) => $c['entry']['cid']['strong'] = ['H40.1234']))->toHaveKey('entry.cid.strong.0', 'invalid_cid')
        ->and(pcsErrors(fn (&$c) => $c['entry']['cid']['strong'] = [401]))->toHaveKey('entry.cid.strong.0', 'invalid_cid')
        ->and(pcsErrors(fn (&$c) => $c['entry']['cid']['strong'] = 'H401'))->toBe(['entry.cid.strong' => 'not_a_list'])
        ->and(pcsErrors(fn (&$c) => $c['entry']['cid']['strong'] = array_map(fn ($i) => 'H' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '1', range(0, 60))))
        ->toHaveKey('entry.cid.strong', 'too_many')
        ->and(pcsErrors(fn (&$c) => $c['entry']['cid']['weak'] = ['H401']))->toBe(['entry.cid.weak' => 'cid_conflict'])
        ->and(pcsErrors(fn (&$c) => $c['entry']['cid']['exclude'] = ['h40.1']))->toBe(['entry.cid.exclude' => 'cid_conflict'])
        ->and(pcsErrors(function (&$c) {
            $c['entry']['cid']['weak']    = ['H40'];
            $c['entry']['cid']['exclude'] = ['H400'];
        }))->toBe([]);
});

it('medicação: só as chaves do config/recall.php', function () {
    expect(pcsErrors(fn (&$c) => $c['entry']['medications'] = ['aspirina']))->toHaveKey('entry.medications.0', 'invalid_medication')
        ->and(pcsErrors(fn (&$c) => $c['entry']['medications'] = [7]))->toHaveKey('entry.medications.0', 'invalid_medication')
        ->and(pcsErrors(fn (&$c) => $c['entry']['medications'] = 'hydroxychloroquine'))->toBe(['entry.medications' => 'not_a_list'])
        ->and(ProtocolContentSchema::normalize(array_replace_recursive(pcsMinimal(), ['entry' => ['medications' => ['hydroxychloroquine', 'hydroxychloroquine']]]))['entry']['medications'])
        ->toBe(['hydroxychloroquine']);
});

it('referências: título, evidência e ano válidos', function () {
    $ref = fn (array $overrides) => array_merge(['title' => 'Fonte', 'year' => 2021, 'evidence' => 'high', 'note' => ''], $overrides);

    expect(pcsErrors(fn (&$c) => $c['references'] = [$ref([])]))->toBe([])
        ->and(pcsErrors(fn (&$c) => $c['references'] = [$ref(['title' => ''])]))->toBe(['references.0.title' => 'required'])
        ->and(pcsErrors(fn (&$c) => $c['references'] = [$ref(['evidence' => 'ótima'])]))->toHaveKey('references.0.evidence', 'invalid_choice')
        ->and(pcsErrors(fn (&$c) => $c['references'] = [$ref(['evidence' => null])]))->toHaveKey('references.0.evidence', 'required')
        ->and(pcsErrors(fn (&$c) => $c['references'] = [$ref(['year' => 1800])]))->toHaveKey('references.0.year', 'out_of_range')
        ->and(pcsErrors(fn (&$c) => $c['references'] = [$ref(['year' => null])]))->toBe([])
        ->and(pcsErrors(fn (&$c) => $c['references'] = [$ref(['title' => str_repeat('a', 241)])]))->toHaveKey('references.0.title', 'too_long')
        ->and(pcsErrors(fn (&$c) => $c['references'] = array_fill(0, 13, $ref([]))))->toHaveKey('references', 'too_many')
        ->and(pcsErrors(fn (&$c) => $c['references'] = 'x'))->toBe(['references' => 'not_a_list']);
});

it('texto: UTF-8 inválido, caractere de controle e tamanho', function () {
    expect(pcsErrors(fn (&$c) => $c['rationale'] = "abc\xff"))->toBe(['rationale' => 'invalid_utf8'])
        ->and(pcsErrors(fn (&$c) => $c['rationale'] = "abc\x07"))->toBe(['rationale' => 'control_characters'])
        ->and(pcsErrors(fn (&$c) => $c['rationale'] = str_repeat('a', 2001)))->toBe(['rationale' => 'too_long'])
        ->and(pcsErrors(fn (&$c) => $c['rationale'] = str_repeat('a', 2000)))->toBe([])
        ->and(pcsErrors(fn (&$c) => $c['change_note'] = str_repeat('a', 1001)))->toBe(['change_note' => 'too_long'])
        ->and(pcsErrors(fn (&$c) => $c['references'] = [['title' => "linha\nquebrada", 'evidence' => 'low']]))->toBe(['references.0.title' => 'control_characters']);
});

it('junta TODOS os erros de uma vez, cada um no seu caminho', function () {
    $errors = pcsErrors(function (&$c) {
        $c['extra']                           = 1;
        $c['tasks'][0]['interval']['default'] = 'x';
        $c['entry']['cid']['strong']          = ['XX'];
        $c['rationale']                       = '';
    });

    expect(array_keys($errors))->toEqualCanonicalizing(['extra', 'tasks.0.interval.default', 'entry.cid.strong.0', 'rationale']);
});

it('as mensagens saem traduzidas nos dois idiomas, sem vazar a chave crua', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        try {
            ProtocolContentSchema::normalize(['tasks' => [['key' => 'X']], 'entry' => 'x']);
        } catch (InvalidProtocolContentException $e) {
            foreach ($e->messages() as $path => $message) {
                expect($message)->not->toStartWith('recall_protocol.')->and($message)->not->toBeEmpty("{$locale} {$path}");
            }
        }
    }

    // O parâmetro entra na mensagem.
    app()->setLocale('pt_BR');

    try {
        ProtocolContentSchema::normalize(array_replace(pcsMinimal(), ['extra' => 1]));
    } catch (InvalidProtocolContentException $e) {
        expect($e->messages()['extra'])->toContain('extra');
    }
});

// ── classificação de CID ───────────────────────────────────────────────────

it('classifyCid: o prefixo mais longo decide (H40 weak, H400 exclude, H401 strong)', function () {
    $content = ProtocolContentSchema::normalize(array_replace_recursive(pcsMinimal(), [
        'entry' => ['cid' => ['strong' => ['H401', 'H402'], 'weak' => ['H40'], 'exclude' => ['H400']]],
    ]));

    expect(ProtocolContentSchema::classifyCid($content, 'H40.1'))->toBe('strong')
        ->and(ProtocolContentSchema::classifyCid($content, 'h401'))->toBe('strong')
        ->and(ProtocolContentSchema::classifyCid($content, 'H40.2X'))->toBe('strong')
        ->and(ProtocolContentSchema::classifyCid($content, 'H40.0'))->toBe('exclude')
        ->and(ProtocolContentSchema::classifyCid($content, 'H40'))->toBe('weak')
        ->and(ProtocolContentSchema::classifyCid($content, 'H40.9'))->toBe('weak')
        ->and(ProtocolContentSchema::classifyCid($content, 'H35.3'))->toBeNull()
        ->and(ProtocolContentSchema::classifyCid($content, 'H4'))->toBeNull()
        ->and(ProtocolContentSchema::classifyCid($content, ''))->toBeNull()
        ->and(ProtocolContentSchema::classifyCid($content, null))->toBeNull()
        ->and(ProtocolContentSchema::classifyCid($content, 'não é cid'))->toBeNull();
});

it('classifyCid: aceita a dupla codificação †/* e espaços do CID-10', function () {
    $content = ProtocolContentSchema::normalize(array_replace_recursive(pcsMinimal(), [
        'entry' => ['cid' => ['strong' => ['E103', 'H360']]],
    ]));

    expect(ProtocolContentSchema::classifyCid($content, 'E10.3†'))->toBe('strong')
        ->and(ProtocolContentSchema::classifyCid($content, 'H36.0*'))->toBe('strong')
        ->and(ProtocolContentSchema::classifyCid($content, ' H 36.0 '))->toBe('strong')
        ->and(ProtocolContentSchema::classifyCid($content, 'E10.9'))->toBeNull();
});

// ── as propostas iniciais ──────────────────────────────────────────────────

it('as 5 propostas iniciais passam no esquema, são canônicas e têm tradução nos dois idiomas', function () {
    $proposals = ProposedProtocolDrafts::all();

    expect(array_keys($proposals))->toBe(['glaucoma', 'dmri', 'retinopatia_diabetica', 'ceratocone', 'hidroxicloroquina']);

    foreach ($proposals as $key => $proposal) {
        $prepared = ProtocolContentSchema::prepare($proposal['content']);

        expect(CanonicalJson::isCanonical($prepared['canonical']))->toBeTrue($key)
            ->and($prepared['content']['tasks'])->not->toBeEmpty($key)
            ->and($prepared['content']['references'])->not->toBeEmpty($key)
            ->and($proposal['translations']['pt_BR']['title'])->not->toBeEmpty($key)
            ->and($proposal['translations']['en']['title'])->not->toBeEmpty($key);
    }
});

it('as propostas: a classificação de CID dos exemplos clínicos bate com o desenho', function () {
    $content = fn (string $key) => ProtocolContentSchema::prepare(ProposedProtocolDrafts::all()[$key]['content'])['content'];

    $glaucoma = $content('glaucoma');
    expect(ProtocolContentSchema::classifyCid($glaucoma, 'H40.1'))->toBe('strong')
        ->and(ProtocolContentSchema::classifyCid($glaucoma, 'H40.0'))->toBe('exclude')
        ->and(ProtocolContentSchema::classifyCid($glaucoma, 'H40'))->toBe('weak')
        ->and(ProtocolContentSchema::classifyCid($glaucoma, 'H42.0'))->toBe('weak')
        ->and(ProtocolContentSchema::classifyCid($glaucoma, 'Q15.0'))->toBeNull();

    $dr = $content('retinopatia_diabetica');
    expect(ProtocolContentSchema::classifyCid($dr, 'E11.3'))->toBe('strong')
        ->and(ProtocolContentSchema::classifyCid($dr, 'H36.0'))->toBe('strong')
        // Diabetes sem complicação oftálmica codificada não entra nesta versão.
        ->and(ProtocolContentSchema::classifyCid($dr, 'E11.9'))->toBeNull();

    expect(ProtocolContentSchema::classifyCid($content('dmri'), 'H35.3'))->toBe('strong')
        ->and(ProtocolContentSchema::classifyCid($content('ceratocone'), 'H18.6'))->toBe('strong')
        ->and($content('hidroxicloroquina')['entry']['medications'])->toBe(['hydroxychloroquine']);
});

it('as propostas: o glaucoma tem consulta 4 [3–6] e campimetria 12 [6–12]; a hidroxicloroquina tem basal e triagem após 60 meses', function () {
    $tasks = fn (string $key) => collect(ProtocolContentSchema::normalize(ProposedProtocolDrafts::all()[$key]['content'])['tasks'])->keyBy('key');

    $glaucoma = $tasks('glaucoma');
    expect($glaucoma['consulta']['interval'])->toBe(['unit' => 'months', 'default' => 4, 'min' => 3, 'max' => 6])
        ->and($glaucoma['campimetria']['interval'])->toBe(['unit' => 'months', 'default' => 12, 'min' => 6, 'max' => 12])
        ->and($glaucoma['oct_nervo_optico']['required'])->toBeFalse();

    $hcq = $tasks('hidroxicloroquina');
    expect($hcq['basal_campimetria']['recurs'])->toBeFalse()
        ->and($hcq['basal_campimetria']['starts_after_months'])->toBeNull()
        ->and($hcq['triagem_campimetria']['recurs'])->toBeTrue()
        ->and($hcq['triagem_campimetria']['starts_after_months'])->toBe(60)
        ->and($hcq['triagem_oct_macula']['starts_after_months'])->toBe(60);
});
