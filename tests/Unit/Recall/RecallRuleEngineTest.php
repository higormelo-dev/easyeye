<?php

declare(strict_types=1);

/**
 * O motor de datas do recall (puro): âncora, intervalos, tolerâncias, olhos, tarefas de uma vez só,
 * "começa depois de N meses", pedido do médico e o limite validado. Sem banco, sem relógio.
 */

use App\Domains\Recall\Engine\{RecallCondition, RecallFacts, RecallItem, RecallRuleEngine, RecallState};
use App\Domains\Recall\Support\{ProposedProtocolDrafts, ProtocolContentSchema};
use App\Domains\Recall\Support\RecallPreview;
use App\Enums\Eye;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

function reDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date)->startOfDay();
}

/** Conteúdo normalizado da PROPOSTA de um protocolo (o mesmo que a clínica adotaria). */
function reContent(string $key): array
{
    return ProtocolContentSchema::normalize(ProposedProtocolDrafts::all()[$key]['content']);
}

function reCondition(string $key = 'glaucoma', Eye $eye = Eye::Both, ?string $start = '2026-01-15', array $parameters = [], ?array $content = null): RecallCondition
{
    return new RecallCondition('cond-1', $key, $eye, $start === null ? null : reDay($start), $content ?? reContent($key), $parameters);
}

/** @param list<string> $consultations */
function reFacts(array $consultations = [], array $exams = []): RecallFacts
{
    return new RecallFacts(
        array_map('reDay', $consultations),
        array_map(static fn (array $exam): array => ['key' => $exam[0], 'eye' => $exam[1], 'date' => reDay($exam[2])], $exams),
    );
}

function reItems(RecallCondition $condition, RecallFacts $facts, string $today = '2026-02-01'): array
{
    $items = [];

    foreach ((new RecallRuleEngine())->evaluateCondition($condition, $facts, reDay($today)) as $item) {
        $items[$item->taskKey] = $item;
    }

    return $items;
}

const RE_OPERATIONAL = ['lead_days' => 30, 'grace_days' => 30, 'lost_after_days' => 180];

// ── as datas de uma tarefa ──────────────────────────────────────────────────

it('consulta do glaucoma: o último atendimento + o intervalo padrão, o convite antes, a tolerância e o "perdido de vista"', function () {
    $item = reItems(reCondition(), reFacts(['2026-01-15']))['consulta'];

    expect($item->source)->toBe('protocol')
        ->and($item->lastDoneAt->toDateString())->toBe('2026-01-15')
        ->and($item->dueAt->toDateString())->toBe('2026-05-15')
        ->and($item->inviteFrom->toDateString())->toBe('2026-04-15')
        ->and($item->overdueFrom->toDateString())->toBe('2026-06-15')
        ->and($item->lostFrom->toDateString())->toBe('2026-11-12')
        ->and($item->recurs)->toBeTrue()
        ->and($item->completed)->toBeFalse()
        ->and($item->meta['interval'])->toBe(4)
        ->and($item->meta['anchor_is_start'])->toBeFalse();
});

it('é a mesma conta que a tela do Revisor mostra nos exemplos (TaskTimeline)', function () {
    $content = reContent('glaucoma');
    $example = RecallPreview::examples($content, reDay('2026-01-15'))[0];
    $item    = reItems(reCondition(), reFacts(['2026-01-15']))['consulta'];

    expect([$item->dueAt->toDateString(), $item->inviteFrom->toDateString(), $item->overdueFrom->toDateString(), $item->lostFrom->toDateString()])
        ->toBe([$example['due_at'], $example['invite'], $example['overdue'], $example['lost']]);
});

it('a prévia do Revisor aplica o início da tarefa (starts_after_months) como o motor: a triagem da hidroxicloroquina só vence aos 60 meses de uso', function () {
    $examples = array_column(RecallPreview::examples(reContent('hidroxicloroquina'), reDay('2026-01-01')), null, 'key');
    $items    = reItems(reCondition('hidroxicloroquina', Eye::Both, '2026-01-01'), reFacts(), '2026-06-01');

    // A triagem anual começa 60 meses depois do início do uso: o exemplo vence em 01/01/2031 (e não em 01/01/2027, que
    // seria 4 anos ANTES de a tarefa valer) e os prazos andam junto. O exame basal vale desde o começo.
    expect($examples['triagem_campimetria']['due_at'])->toBe('2031-01-01')
        ->and($examples['triagem_campimetria']['starts_at'])->toBe('2031-01-01')
        ->and($examples['triagem_campimetria']['invite'])->toBe(reDay('2031-01-01')->subDays(30)->toDateString())
        ->and($examples['triagem_campimetria']['overdue'])->toBe(reDay('2031-01-01')->addDays(31)->toDateString())
        ->and($examples['basal_campimetria']['due_at'])->toBe('2027-01-01')
        ->and($examples['basal_campimetria']['starts_at'])->toBeNull();

    // E é a MESMA conta do motor, tarefa a tarefa e prazo a prazo (o que o Revisor aprova é o que o sistema calcula).
    $dates = static fn (string $due, string $invite, string $overdue, string $lost): array => compact('due', 'invite', 'overdue', 'lost');

    expect(array_keys($examples))->toEqualCanonicalizing(['basal_campimetria', 'basal_oct_macula', 'triagem_campimetria', 'triagem_oct_macula'])
        ->and(array_map(static fn (array $example): array => $dates($example['due_at'], $example['invite'], $example['overdue'], $example['lost']), $examples))
        ->toEqual(array_map(static fn (RecallItem $item): array => $dates($item->dueAt->toDateString(), $item->inviteFrom->toDateString(), $item->overdueFrom->toDateString(), $item->lostFrom->toDateString()), array_intersect_key($items, $examples)));
});

it('mês sem overflow: 31/10 + 4 meses é 28/02, nunca 03/03', function () {
    $item = reItems(reCondition(start: '2026-10-31'), reFacts(['2026-10-31']), '2026-11-15')['consulta'];

    expect($item->dueAt->toDateString())->toBe('2027-02-28');
});

it('o estado do prazo anda sozinho com o dia: limites INCLUSIVOS do primeiro dia de cada estado', function (string $today, string $expected) {
    $item = reItems(reCondition(), reFacts(['2026-01-15']), $today)['consulta'];

    expect($item->state(reDay($today))->value)->toBe($expected);
})->with([
    'longe'                    => ['2026-04-14', 'upcoming'],
    'dia do convite'           => ['2026-04-15', 'due_soon'],
    'véspera do vencimento'    => ['2026-05-14', 'due_soon'],
    'dia do vencimento'        => ['2026-05-15', 'due'],
    'último dia da tolerância' => ['2026-06-14', 'due'],
    'primeiro dia vencido'     => ['2026-06-15', 'overdue'],
    'último dia vencido'       => ['2026-11-11', 'overdue'],
    'primeiro dia perdido'     => ['2026-11-12', 'lost'],
]);

it('RecallState: o que aparece na lista e o que já passou do vencimento', function () {
    expect(RecallState::Upcoming->isListed())->toBeFalse()
        ->and(RecallState::DueSoon->isListed())->toBeTrue()
        ->and(RecallState::DueSoon->isPastDue())->toBeFalse()
        ->and(RecallState::Due->isPastDue())->toBeTrue()
        ->and(RecallState::Lost->isPastDue())->toBeTrue()
        ->and(RecallState::values())->toBe(['upcoming', 'due_soon', 'due', 'overdue', 'lost']);
});

// ── a âncora: o último ato desde o início da condição ───────────────────────

it('a âncora é o ato MAIS RECENTE; ato antes do diagnóstico e ato no futuro não contam', function () {
    $facts = reFacts(['2025-06-01', '2026-01-15', '2026-03-10', '2027-01-01']);

    $item = reItems(reCondition(start: '2026-01-15'), $facts, '2026-04-01')['consulta'];

    // 2025-06-01 é antes do início (2026-01-15); 2027-01-01 é futuro (hoje 2026-04-01).
    expect($item->lastDoneAt->toDateString())->toBe('2026-03-10')
        ->and($item->dueAt->toDateString())->toBe('2026-07-10');
});

it('sem nenhum ato a âncora é o INÍCIO da condição, e o item avisa', function () {
    $item = reItems(reCondition(start: '2026-01-15'), reFacts(), '2026-02-01')['consulta'];

    expect($item->lastDoneAt)->toBeNull()
        ->and($item->anchor->toDateString())->toBe('2026-01-15')
        ->and($item->dueAt->toDateString())->toBe('2026-05-15')
        ->and($item->meta['anchor_is_start'])->toBeTrue();
});

it('o atendimento no dia do início conta como o primeiro (≥, não >)', function () {
    $item = reItems(reCondition(start: '2026-01-15'), reFacts(['2026-01-15']))['consulta'];

    expect($item->lastDoneAt->toDateString())->toBe('2026-01-15');
});

// ── exames e olhos ──────────────────────────────────────────────────────────

it('exame em AO: OD sozinho NÃO cobre os dois olhos — vale a data do olho mais antigo', function () {
    $condition = reCondition(eye: Eye::Both);

    $onlyRight = reItems($condition, reFacts(exams: [['visual_field', Eye::Right, '2026-02-01']]))['campimetria'];
    $both      = reItems($condition, reFacts(exams: [['visual_field', Eye::Right, '2026-02-01'], ['visual_field', Eye::Left, '2026-03-01']]), '2026-04-01')['campimetria'];

    expect($onlyRight->lastDoneAt)->toBeNull()
        ->and($onlyRight->meta['anchor_is_start'])->toBeTrue()
        ->and($both->lastDoneAt->toDateString())->toBe('2026-02-01');
});

it('exame em AO cobre qualquer olho', function (Eye $eye) {
    $item = reItems(reCondition(eye: $eye), reFacts(exams: [['visual_field', Eye::Both, '2026-02-01']]))['campimetria'];

    expect($item->lastDoneAt->toDateString())->toBe('2026-02-01')
        ->and($item->eye)->toBe($eye);
})->with([Eye::Right, Eye::Left, Eye::Both]);

it('condição de um olho só olha exames que cobrem ESSE olho', function () {
    $facts = reFacts(exams: [['visual_field', Eye::Left, '2026-02-01']]);

    expect(reItems(reCondition(eye: Eye::Right), $facts)['campimetria']->lastDoneAt)->toBeNull()
        ->and(reItems(reCondition(eye: Eye::Left), $facts)['campimetria']->lastDoneAt->toDateString())->toBe('2026-02-01');
});

it('exame de outra chave não é a campimetria', function () {
    $item = reItems(reCondition(), reFacts(exams: [['oct_macula', Eye::Both, '2026-02-01']]))['campimetria'];

    expect($item->lastDoneAt)->toBeNull();
});

it('o exame pega o mais recente entre vários', function () {
    $item = reItems(reCondition(eye: Eye::Right), reFacts(exams: [['visual_field', Eye::Right, '2026-02-01'], ['visual_field', Eye::Right, '2026-03-20'], ['visual_field', Eye::Right, '2026-01-20']]), '2026-04-01')['campimetria'];

    expect($item->lastDoneAt->toDateString())->toBe('2026-03-20');
});

// ── o que a clínica ajusta ──────────────────────────────────────────────────

it('o intervalo da clínica DENTRO da faixa muda o vencimento', function () {
    $condition = reCondition(parameters: ['tasks' => ['consulta' => ['interval' => 6]]]);

    $item = reItems($condition, reFacts(['2026-01-15']))['consulta'];

    expect($item->dueAt->toDateString())->toBe('2026-07-15')
        ->and($item->meta['interval'])->toBe(6)
        ->and($item->meta)->not->toHaveKey('interval_fallback');
});

it('intervalo FORA da faixa validada nunca é usado: cai no padrão e sinaliza', function (int $outside) {
    $item = reItems(reCondition(parameters: ['tasks' => ['consulta' => ['interval' => $outside]]]), reFacts(['2026-01-15']))['consulta'];

    expect($item->dueAt->toDateString())->toBe('2026-05-15')
        ->and($item->meta['interval'])->toBe(4)
        ->and($item->meta['interval_fallback'])->toBeTrue();
})->with([2, 7, 0, 120]);

it('tarefa OPCIONAL só roda se a clínica ligou; obrigatória roda sempre', function () {
    $off = reItems(reCondition(), reFacts(['2026-01-15']));

    expect(array_keys($off))->toBe(['consulta', 'campimetria'])
        ->and($off)->not->toHaveKey('oct_nervo_optico');

    $on = reItems(reCondition(parameters: ['tasks' => ['oct_nervo_optico' => ['enabled' => true]]]), reFacts(['2026-01-15']));

    expect(array_keys($on))->toBe(['consulta', 'campimetria', 'oct_nervo_optico']);
});

it('"enabled" só vale como booleano verdadeiro (texto "true" não liga)', function () {
    $items = reItems(reCondition(parameters: ['tasks' => ['oct_nervo_optico' => ['enabled' => 'true']]]), reFacts(['2026-01-15']));

    expect($items)->not->toHaveKey('oct_nervo_optico');
});

// ── "começa depois de N meses" e tarefa de uma vez só (hidroxicloroquina) ───

it('triagem anual da hidroxicloroquina só vence DEPOIS dos 60 meses de uso, com os prazos andando junto', function () {
    $condition = reCondition('hidroxicloroquina', Eye::Both, '2026-01-01');
    $items     = reItems($condition, reFacts(), '2026-06-01');

    $screening = $items['triagem_campimetria'];

    // Sem nenhum exame a âncora seria o início (vencia em 2027), mas a triagem só começa aos 60 meses.
    expect($screening->dueAt->toDateString())->toBe('2031-01-01')
        ->and($screening->meta['starts_at'])->toBe('2031-01-01')
        ->and($screening->inviteFrom->toDateString())->toBe(reDay('2031-01-01')->subDays(30)->toDateString())
        ->and($screening->overdueFrom->toDateString())->toBe(reDay('2031-01-01')->addDays(31)->toDateString());
});

it('tarefa de uma vez só (basal) já feita: concluída, sem próximo vencimento; não feita: vence no prazo', function () {
    $condition = reCondition('hidroxicloroquina', Eye::Both, '2026-01-01');

    $undone = collect(reItems($condition, reFacts(), '2026-03-01'))->first(fn (RecallItem $item) => ! $item->recurs);

    expect($undone)->not->toBeNull()
        ->and($undone->completed)->toBeFalse()
        ->and($undone->dueAt)->not->toBeNull();

    $facts = reFacts(exams: [[$undone->examKey ?? 'visual_field', Eye::Both, '2026-02-10']]);
    $done  = collect(reItems($condition, $facts, '2026-03-01'))->first(fn (RecallItem $item) => $item->taskKey === $undone->taskKey);

    expect($done->completed)->toBeTrue()
        ->and($done->dueAt)->toBeNull()
        ->and($done->state(reDay('2026-03-01')))->toBeNull()
        ->and($done->lastDoneAt->toDateString())->toBe('2026-02-10');
});

it('sem o início da condição (uso de medicamento sem data) o motor pede o dado em vez de chutar', function () {
    $items = reItems(reCondition('hidroxicloroquina', Eye::Both, null), reFacts(['2026-01-15']));

    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        expect($item->needsInfo)->toBeTrue()
            ->and($item->meta['missing'])->toBe('started_on')
            ->and($item->dueAt)->toBeNull()
            ->and($item->state(reDay('2026-02-01')))->toBeNull();
    }
});

it('tarefa de série de tratamento (anti-VEGF) fica para a série de aplicações: o motor não a avalia', function () {
    $content            = reContent('glaucoma');
    $content['tasks'][] = [
        'key'       => 'aplicacao', 'kind' => 'treatment_course', 'exam_key' => null,
        'interval'  => ['unit' => 'weeks', 'default' => 8, 'min' => 4, 'max' => 16],
        'lead_days' => 7, 'grace_days' => 7, 'lost_after_days' => 90, 'starts_after_months' => null, 'recurs' => true, 'required' => true,
    ];

    $items = reItems(reCondition(content: $content), reFacts(['2026-01-15']));

    expect($items)->not->toHaveKey('aplicacao')->and($items)->toHaveKeys(['consulta', 'campimetria']);
});

// ── o retorno que o médico pediu ────────────────────────────────────────────

it('retorno pedido pelo médico: atendimento + dias, convite antes, tolerância e perdido de vista dos prazos operacionais', function () {
    $item = (new RecallRuleEngine())->doctorOrder(reDay('2026-01-15'), 60, null, RE_OPERATIONAL, reDay('2026-02-01'));

    expect($item->source)->toBe('doctor_order')
        ->and($item->taskKey)->toBe('doctor_order')
        ->and($item->conditionId)->toBeNull()
        ->and($item->dueAt->toDateString())->toBe('2026-03-16')
        ->and($item->inviteFrom->toDateString())->toBe('2026-02-14')
        ->and($item->overdueFrom->toDateString())->toBe('2026-04-16')
        ->and($item->lostFrom->toDateString())->toBe('2026-09-13')
        ->and($item->meta)->toMatchArray(['follow_up_days' => 60, 'capped' => false]);
});

it('pedido do médico acima do máximo validado é LIMITADO e sinalizado', function () {
    $condition = reCondition();   // glaucoma: consulta máx 6 meses, cap_to_max ligado
    $cap       = (new RecallRuleEngine())->consultationCap($condition, reDay('2026-01-15'));

    expect($cap->toDateString())->toBe('2026-07-15');

    $item = (new RecallRuleEngine())->doctorOrder(reDay('2026-01-15'), 400, $cap, RE_OPERATIONAL, reDay('2026-02-01'));

    expect($item->dueAt->toDateString())->toBe('2026-07-15')
        ->and($item->meta['capped'])->toBeTrue()
        ->and($item->meta['asked_due_at'])->toBe('2027-02-19')
        ->and($item->meta['follow_up_days'])->toBe(400);

    // Dentro do máximo, nada muda.
    $within = (new RecallRuleEngine())->doctorOrder(reDay('2026-01-15'), 90, $cap, RE_OPERATIONAL, reDay('2026-02-01'));

    expect($within->meta['capped'])->toBeFalse()->and($within->dueAt->toDateString())->toBe('2026-04-15');
});

it('sem cap_to_max (ou sem tarefa de consulta) o protocolo não limita o pedido', function () {
    $content                               = reContent('glaucoma');
    $content['doctor_order']['cap_to_max'] = false;

    expect((new RecallRuleEngine())->consultationCap(reCondition(content: $content), reDay('2026-01-15')))->toBeNull();

    $content          = reContent('glaucoma');
    $content['tasks'] = array_values(array_filter($content['tasks'], fn ($task) => $task['kind'] !== 'consultation'));

    expect((new RecallRuleEngine())->consultationCap(reCondition(content: $content), reDay('2026-01-15')))->toBeNull();
});

it('sem atendimento ou sem dias pedidos, não há retorno do médico', function (?string $consulted, ?int $days) {
    expect((new RecallRuleEngine())->doctorOrder($consulted === null ? null : reDay($consulted), $days, null, RE_OPERATIONAL, reDay('2026-02-01')))->toBeNull();
})->with([[null, 30], ['2026-01-15', null], ['2026-01-15', 0], ['2026-01-15', -5]]);

it('o estado do retorno do médico anda com o dia, como o de protocolo', function () {
    $item = (new RecallRuleEngine())->doctorOrder(reDay('2026-01-15'), 60, null, RE_OPERATIONAL, reDay('2026-02-01'));

    expect($item->state(reDay('2026-02-13'))->value)->toBe('upcoming')
        ->and($item->state(reDay('2026-02-14'))->value)->toBe('due_soon')
        ->and($item->state(reDay('2026-03-16'))->value)->toBe('due')
        ->and($item->state(reDay('2026-04-16'))->value)->toBe('overdue')
        ->and($item->state(reDay('2026-09-13'))->value)->toBe('lost');
});

it('a chave de tarefa do retorno de curso vai e volta (course:<id>); chave de outra origem ou sem id não tem curso', function () {
    $item = (new RecallRuleEngine())->courseItem('curso-123', Eye::Right, 'Aflibercepte', null, reDay('2026-01-01'), reDay('2026-03-01'), 8, ['lead_days' => 7, 'grace_days' => 7, 'lost_after_days' => 60]);

    expect($item->taskKey)->toBe('course:curso-123')
        ->and(RecallItem::courseTaskKey('curso-123'))->toBe('course:curso-123')
        ->and(RecallItem::courseIdOf($item->taskKey))->toBe('curso-123')
        ->and(RecallItem::courseIdOf('campimetria'))->toBeNull()
        ->and(RecallItem::courseIdOf('doctor_order'))->toBeNull()
        ->and(RecallItem::courseIdOf('course:'))->toBeNull()
        ->and(RecallItem::courseIdOf('xcourse:abc'))->toBeNull();
});
