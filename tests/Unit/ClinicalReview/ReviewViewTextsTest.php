<?php

declare(strict_types=1);

/**
 * Contrato dos textos das telas da revisão clínica (página pública do Revisor e Manager):
 * toda chave que um componente Vue pede com `tx('caminho')` existe nos dois idiomas, e os
 * arquivos pt_BR e en têm exatamente as mesmas chaves. Chave que falta apareceria na tela
 * como o próprio caminho ("show.review") — este teste a pega antes do navegador.
 */

use App\Domains\ClinicalReview\Enums\{AcceptanceDecision, ReviewDecision, ReviewInvitationStatus};
use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

/** @return list<string> */
function rvwStaticPaths(string $file): array
{
    preg_match_all('/\btx\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', (string) file_get_contents(base_path($file)), $matches);

    return array_values(array_unique($matches[1]));
}

/** @return list<string> prefixos de tx(`prefixo.${…}`) */
function rvwDynamicPrefixes(string $file): array
{
    preg_match_all('/\btx\(\s*`([A-Za-z0-9_.]*)\$\{/', (string) file_get_contents(base_path($file)), $matches);

    return array_values(array_unique($matches[1]));
}

/** @return list<string> chaves-folha de um array de textos ("a.b.c") */
function rvwLeafKeys(array $texts, string $prefix = ''): array
{
    $keys = [];

    foreach ($texts as $key => $value) {
        is_array($value) ? array_push($keys, ...rvwLeafKeys($value, "{$prefix}{$key}.")) : $keys[] = "{$prefix}{$key}";
    }

    return $keys;
}

// [arquivo, arquivo de textos, prefixo dentro do arquivo de textos]
dataset('rvw_files', [
    'ReviewLayout'              => ['resources/js/Layouts/ReviewLayout.vue', 'clinical_review', 'public.'],
    'Show'                      => ['resources/js/Pages/ClinicalReview/Show.vue', 'clinical_review', 'public.'],
    'Done'                      => ['resources/js/Pages/ClinicalReview/Done.vue', 'clinical_review', 'public.'],
    'Unavailable'               => ['resources/js/Pages/ClinicalReview/Unavailable.vue', 'clinical_review', 'public.'],
    'AccessCode'                => ['resources/js/Pages/ClinicalReview/AccessCode.vue', 'clinical_review', 'public.'],
    'ReviewItemCard'            => ['resources/js/Components/ClinicalReview/ReviewItemCard.vue', 'clinical_review', 'public.'],
    'ReviewAttestationFields'   => ['resources/js/Components/ClinicalReview/ReviewAttestationFields.vue', 'clinical_review', 'public.'],
    'ReviewSummary'             => ['resources/js/Components/ClinicalReview/ReviewSummary.vue', 'clinical_review', 'public.'],
    'ManagerIndex'              => ['resources/js/Pages/Panel/Manager/ClinicalReviews/Index.vue', 'manager_reviews', ''],
    'ReviewerFormModal'         => ['resources/js/Components/ClinicalReview/ReviewerFormModal.vue', 'manager_reviews', ''],
    'ReviewInvitationFormModal' => ['resources/js/Components/ClinicalReview/ReviewInvitationFormModal.vue', 'manager_reviews', ''],
    'ReviewOpinionModal'        => ['resources/js/Components/ClinicalReview/ReviewOpinionModal.vue', 'manager_reviews', ''],
]);

it('toda chave tx() literal existe em pt_BR e en', function (string $file, string $group, string $prefix) {
    $paths = rvwStaticPaths($file);

    expect($paths)->not->toBeEmpty();

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = require lang_path("{$locale}/{$group}.php");

        foreach ($paths as $path) {
            expect(Arr::get($texts, $prefix . $path))->toBeString("{$file}: {$locale}/{$group}.php não tem '{$prefix}{$path}'");
        }
    }
})->with('rvw_files');

it('as chaves montadas em tempo de execução cobrem todos os valores possíveis', function () {
    // [arquivo, grupo, prefixo no arquivo de textos, prefixo como aparece no tx(`…${…}`), sufixos possíveis]
    $dynamic = [
        ['resources/js/Components/ClinicalReview/ReviewItemCard.vue', 'clinical_review', 'public.', 'item.decision_hint.', ReviewDecision::values()],
        ['resources/js/Pages/ClinicalReview/Show.vue', 'clinical_review', 'public.', 'errors.', [
            'decision_required', 'comment_required', 'comment_too_long', 'proposed_too_long', 'name_required', 'email_invalid',
            'number_required', 'number_invalid', 'uf_required', 'specialty_required', 'choice_required',
            'conflict_description_required', 'agreed_required',
        ]],
        ['resources/js/Pages/Panel/Manager/ClinicalReviews/Index.vue', 'manager_reviews', '', 'tabs.', ['invitations', 'reviewers']],
        ['resources/js/Components/ClinicalReview/ReviewOpinionModal.vue', 'manager_reviews', '', 'acceptance.mismatch_fields.', ['crm_number', 'crm_uf', 'rqe', 'email', 'reviewer']],
    ];

    foreach ($dynamic as [$file, $group, $filePrefix, $prefix, $suffixes]) {
        expect(in_array($prefix, rvwDynamicPrefixes($file), true))->toBeTrue("{$file} não monta mais {$prefix}…");

        foreach (['pt_BR', 'en'] as $locale) {
            $texts = require lang_path("{$locale}/{$group}.php");

            foreach ($suffixes as $suffix) {
                expect(Arr::get($texts, $filePrefix . $prefix . $suffix))->toBeString("{$locale}/{$group}.php não tem '{$filePrefix}{$prefix}{$suffix}'");
            }
        }
    }
});

it('as chaves indiretas da página (rótulos de campo e de erro) existem nos dois idiomas', function () {
    // Show.vue monta FIELD_KEYS/ITEM_FIELD_KEYS com caminhos que não passam por tx() literal.
    $indirect = [
        'attestation.name', 'attestation.email', 'attestation.crm_number', 'attestation.crm_uf', 'attestation.rqe',
        'attestation.specialty', 'attestation.conflict_title', 'attestation.conflict_description', 'attestation.display_title',
        'attestation.agreed', 'item.field_decision', 'item.field_comment', 'item.field_proposed',
    ];

    $source = (string) file_get_contents(base_path('resources/js/Pages/ClinicalReview/Show.vue'));

    foreach ($indirect as $path) {
        expect($source)->toContain("'{$path}'");

        foreach (['pt_BR', 'en'] as $locale) {
            expect(Arr::get(require lang_path("{$locale}/clinical_review.php"), 'public.' . $path))->toBeString("{$locale} {$path}");
        }
    }
});

it('pt_BR e en têm exatamente as mesmas chaves nos arquivos da revisão clínica', function (string $group) {
    $pt = rvwLeafKeys(require lang_path("pt_BR/{$group}.php"));
    $en = rvwLeafKeys(require lang_path("en/{$group}.php"));

    expect(array_diff($pt, $en))->toBe([], "faltam em en/{$group}.php")
        ->and(array_diff($en, $pt))->toBe([], "faltam em pt_BR/{$group}.php");
})->with(['clinical_review', 'manager_reviews']);

it('os rótulos de enum da revisão cobrem todos os valores, nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ([ReviewDecision::cases(), ReviewInvitationStatus::cases(), AcceptanceDecision::cases()] as $cases) {
            foreach ($cases as $case) {
                expect($case->label())->toBeString();
                $this->assertStringNotContainsString('clinical_review.', $case->label(), "{$locale} {$case->value} vazou a chave de tradução");
            }
        }
    }
});

it('os campos que o formulário do servidor nomeia existem em public.fields (mensagens legíveis)', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        $fields = (require lang_path("{$locale}/clinical_review.php"))['public']['fields'];

        foreach (['decisions', 'decision', 'comment', 'proposed_changes', 'name', 'email', 'crm_number', 'crm_uf', 'rqe', 'specialty', 'has_conflict', 'conflict_description', 'allow_identity_display', 'agreed', 'statement'] as $field) {
            expect($fields[$field] ?? null)->toBeString("{$locale} public.fields.{$field}");
        }
    }
});

it('todo motivo de recusa do envio tem texto traduzido (a página nunca mostra o código cru)', function () {
    $reasons = [
        'not_open', 'set_changed', 'statement_changed', 'missing_decision', 'comment_required', 'statement_not_accepted',
        'conflict_description_required', 'number_required',
    ];

    foreach (['pt_BR', 'en'] as $locale) {
        $errors = (require lang_path("{$locale}/clinical_review.php"))['public']['errors'];

        foreach ($reasons as $reason) {
            expect($errors[$reason] ?? null)->toBeString("{$locale} public.errors.{$reason}");
        }
    }
});
