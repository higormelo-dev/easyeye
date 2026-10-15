<?php

declare(strict_types=1);

/**
 * Contrato dos textos do convite de retorno por WhatsApp (Fase 6): todo impedimento, origem e resposta tem rótulo nos
 * dois idiomas; toda chave que os componentes Vue pedem existe; e o espelho em JSON usado pelo Vitest é idêntico ao
 * arquivo de idioma. Chave que falta apareceria na tela como o próprio caminho.
 */

use App\Domains\Recall\Enums\{InviteBlocker, InviteOrigin, InviteResponse};
use App\Domains\Recall\Services\RecallInviteOverview;
use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

function ritTexts(string $locale, string $group): array
{
    return require lang_path("{$locale}/{$group}.php");
}

it('todo impedimento, origem e resposta do convite tem rótulo nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ([InviteBlocker::cases(), InviteOrigin::cases(), InviteResponse::cases()] as $cases) {
            foreach ($cases as $case) {
                expect($case->label())->toBeString()->not->toContain('recall_invite.', "{$locale} {$case->value} vazou a chave de tradução");
            }
        }
    }
});

it('o código do impedimento gravado em error_code (recall_<código>) é o mesmo da tradução', function () {
    $texts = ritTexts('pt_BR', 'recall_invite')['blockers'];

    expect(array_keys($texts))->toEqualCanonicalizing(InviteBlocker::values());
});

it('a linha da lista fala de toda situação de entrega que o servidor produz', function () {
    $statuses = array_unique(array_map(
        static fn (array $args): string => RecallInviteOverview::deliveryStatus(...$args),
        [['pending', null, null], ['sending', null, null], ['sent', null, null], ['sent', '2026-01-01', null], ['sent', '2026-01-01', '2026-01-02'], ['answered', null, null], ['failed', null, null], ['skipped', null, null], ['suppressed', null, null], [null, null, null]],
    ));

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = ritTexts($locale, 'recall_list')['row']['invite'];

        foreach ($statuses as $status) {
            expect($texts[$status] ?? null)->toBeString("{$locale} row.invite.{$status}");
        }

        foreach (['origin_auto', 'origin_manual', 'requested', 'asked_book', 'asked_done', 'no_consent', 'opted_out'] as $key) {
            expect($texts[$key] ?? null)->toBeString("{$locale} row.invite.{$key}");
        }
    }

    expect($statuses)->toEqualCanonicalizing(['queued', 'sent', 'delivered', 'read', 'failed', 'cancelled']);
});

it('toda chave do cartão de pausa do Manager existe em pt_BR e en (relativa a whatsapp.recall)', function () {
    $source = (string) file_get_contents(base_path('resources/js/Pages/Panel/Manager/WhatsApp/WhatsAppRecallPause.vue'));

    // `tx('x')` lê de props.t.recall; `t.recall.x` é o mesmo dicionário; `t.ui.cancel` é o botão comum.
    preg_match_all('/\btx\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', $source, $static);
    preg_match_all('/(?<![\w.])t\.recall\.([A-Za-z0-9_]+)/', $source, $direct);

    $paths = array_values(array_unique([...$static[1], ...$direct[1]]));

    expect($paths)->not->toBeEmpty();

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = ritTexts($locale, 'whatsapp');

        foreach ($paths as $path) {
            expect(Arr::has($texts['recall'], $path))->toBeTrue("{$locale}/whatsapp.php não tem 'recall.{$path}'");
        }

        expect($texts['ui']['cancel'] ?? null)->toBeString("{$locale} ui.cancel");
    }
});

it('o espelho em JSON dos textos do Manager (usado pelo Vitest) é idêntico ao pt_BR/whatsapp.php', function () {
    $mirror = json_decode((string) file_get_contents(base_path('tests/JavaScript/Panel/Recall/support/whatsapp.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($mirror)->toBe(ritTexts('pt_BR', 'whatsapp'), 'regere com: php scripts/dump-lang-json.php whatsapp tests/JavaScript/Panel/Recall/support/whatsapp.json');
});
