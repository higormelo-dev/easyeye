<?php

declare(strict_types=1);

/**
 * Contrato dos textos das telas de protocolo de retorno: toda chave que um componente Vue
 * pede com `tx('caminho')` existe nos dois idiomas, e os rótulos de enum que a tela recebe
 * cobrem todos os valores do enum. Chave que falta apareceria na tela como o próprio
 * caminho ("tasks.lead_zero") — este teste a pega antes do navegador.
 */

use App\Domains\Recall\Enums\{IntervalUnit, RecallExamKey, RecallTaskKind};
use App\Domains\Recall\Support\{ProtocolContentSchema, RecallRuleLabels};
use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

/** @return list<string> caminhos literais usados em tx('…') / tx("…") */
function rvtStaticPaths(string $file): array
{
    $source = (string) file_get_contents(base_path($file));

    preg_match_all('/\btx\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', $source, $matches);

    return array_values(array_unique($matches[1]));
}

/** @return list<string> prefixos de tx(`prefixo.${…}`) — a parte dinâmica é conferida à parte */
function rvtDynamicPrefixes(string $file): array
{
    $source = (string) file_get_contents(base_path($file));

    preg_match_all('/\btx\(\s*`([A-Za-z0-9_.]*)\$\{/', $source, $matches);

    return array_values(array_unique($matches[1]));
}

dataset('rvt_files', [
    'RecallRuleCard'         => ['resources/js/Components/Recall/RecallRuleCard.vue', 'recall_rule'],
    'RecallContentDiff'      => ['resources/js/Components/Recall/RecallContentDiff.vue', 'recall_rule'],
    'RecallTimelineExamples' => ['resources/js/Components/Recall/RecallTimelineExamples.vue', 'recall_rule'],
    'RecallProtocolEditor'   => ['resources/js/Components/Recall/RecallProtocolEditor.vue', 'manager_recall'],
    'Index'                  => ['resources/js/Pages/Panel/Manager/RecallProtocols/Index.vue', 'manager_recall'],
    'Show'                   => ['resources/js/Pages/Panel/Manager/RecallProtocols/Show.vue', 'manager_recall'],
    'Version'                => ['resources/js/Pages/Panel/Manager/RecallProtocols/Version.vue', 'manager_recall'],
]);

it('toda chave tx() literal dos componentes existe em pt_BR e en', function (string $file, string $group) {
    $paths = rvtStaticPaths($file);

    expect($paths)->not->toBeEmpty();

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = require lang_path("{$locale}/{$group}.php");

        foreach ($paths as $path) {
            expect(Arr::get($texts, $path))->toBeString("{$file}: {$locale}/{$group}.php não tem '{$path}'");
        }
    }
})->with('rvt_files');

it('as chaves montadas em tempo de execução cobrem todos os valores possíveis', function () {
    // [arquivo, grupo, prefixo (como aparece no tx(`…${…}`)), sufixos possíveis]
    $dynamic = [
        ['resources/js/Components/Recall/RecallRuleCard.vue', 'recall_rule', 'entry.', ['strong', 'weak', 'exclude', 'strong_hint', 'weak_hint', 'exclude_hint']],
        ['resources/js/Components/Recall/RecallContentDiff.vue', 'recall_rule', 'diff.', ['added', 'removed', 'changed']],
        ['resources/js/Components/Recall/RecallProtocolEditor.vue', 'manager_recall', 'editor.cid_', ['strong', 'weak', 'exclude']],
        ['resources/js/Components/Recall/RecallProtocolEditor.vue', 'manager_recall', 'editor.interval_', ['min', 'default', 'max']],
        ['resources/js/Components/Recall/RecallProtocolEditor.vue', 'manager_recall', 'editor.', ['lead_days', 'grace_days', 'lost_after_days']],
        ['resources/js/Pages/Panel/Manager/RecallProtocols/Version.vue', 'manager_recall', 'version.tabs.', ['rule', 'changes', 'examples', 'validation', 'integrity']],
        ['resources/js/Pages/Panel/Manager/RecallProtocols/Version.vue', 'manager_recall', 'governance.', ['suspend.title', 'retire.title', 'withdraw.title', 'suspend.body', 'retire.body', 'withdraw.body', 'suspend.confirm', 'retire.confirm', 'withdraw.confirm']],
        ['resources/js/Pages/Panel/Manager/RecallProtocols/Version.vue', 'manager_recall', 'version.integrity.', ['frozen', 'validated', 'released', 'suspended', 'retired']],
    ];

    foreach ($dynamic as [$file, $group, $prefix, $suffixes]) {
        expect(in_array($prefix, rvtDynamicPrefixes($file), true))->toBeTrue("{$file} não monta mais {$prefix}…");

        foreach (['pt_BR', 'en'] as $locale) {
            $texts = require lang_path("{$locale}/{$group}.php");

            foreach ($suffixes as $suffix) {
                expect(Arr::get($texts, $prefix . $suffix))->toBeString("{$locale}/{$group}.php não tem '{$prefix}{$suffix}'");
            }
        }
    }
});

it('os rótulos de enum entregues às telas cobrem todos os valores, nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);
        $labels = RecallRuleLabels::labels();

        expect(array_keys($labels['kinds']))->toEqual(RecallTaskKind::values())
            ->and(array_keys($labels['exams']))->toEqual(RecallExamKey::values())
            ->and(array_keys($labels['units']))->toEqual(IntervalUnit::values())
            ->and(array_keys($labels['units_one']))->toEqual(IntervalUnit::values())
            ->and(array_keys($labels['evidence']))->toEqual(ProtocolContentSchema::EVIDENCE_LEVELS)
            ->and(array_keys($labels['medications']))->toEqual(array_keys(config('recall.medications')));

        foreach ($labels as $group => $map) {
            foreach ($map as $value => $label) {
                expect($label)->toBeString()->not->toBeEmpty();
                $this->assertStringNotContainsString('recall_', $label, "{$locale} {$group}.{$value} vazou a chave de tradução");
            }
        }
    }
});

it('os campos que a tela de diferenças rotula existem em recall_rule.diff.fields', function () {
    $fields = [
        'kind', 'exam_key', 'interval.unit', 'interval.default', 'interval.min', 'interval.max',
        'lead_days', 'grace_days', 'lost_after_days', 'starts_after_months', 'recurs', 'required',
        'cap_to_max', 'year', 'evidence', 'note',
    ];
    $sections = ['cid.strong', 'cid.weak', 'cid.exclude', 'medication', 'task', 'doctor_order', 'reference', 'rationale', 'change_note'];

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = require lang_path("{$locale}/recall_rule.php");

        expect(array_keys($texts['diff']['fields']))->toEqualCanonicalizing($fields, $locale)
            ->and(array_keys($texts['diff']['sections']))->toEqualCanonicalizing($sections, $locale);
    }
});

it('todo evento de governança que a tela do Manager pode mostrar tem rótulo', function () {
    $texts = require lang_path('pt_BR/manager_recall.php');

    foreach (['created', 'revision_started', 'frozen', 'invited', 'submitted', 'accepted', 'refused', 'validated', 'declined', 'withdrawn', 'released', 'superseded', 'suspended', 'resumed', 'retired', 'integrity_failed'] as $event) {
        expect($texts['events'][$event] ?? null)->toBeString($event);
    }
});

it('as chaves da governança que a tela da versão monta fora do tx() literal existem nos dois idiomas', function () {
    $source = (string) file_get_contents(base_path('resources/js/Pages/Panel/Manager/RecallProtocols/Version.vue'));
    $keys   = ['banner.review_due_overdue', 'banner.review_due_soon', 'governance.release.superseded', 'governance.release.no_superseded'];

    foreach ($keys as $key) {
        expect($source)->toContain("'{$key}'");

        foreach (['pt_BR', 'en'] as $locale) {
            expect(Arr::get(require lang_path("{$locale}/manager_recall.php"), $key))->toBeString("{$locale} {$key}");
        }
    }
});

it('todo erro de governança que o controller pode devolver tem texto nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        $errors = (require lang_path("{$locale}/manager_recall.php"))['errors'];

        foreach (['not_draft', 'stale', 'invalid_transition', 'open_version_exists', 'frozen_content', 'no_validation_evidence', 'integrity_failed', 'reason_too_short'] as $reason) {
            expect($errors[$reason] ?? null)->toBeString("{$locale} errors.{$reason}");
        }
    }
});
