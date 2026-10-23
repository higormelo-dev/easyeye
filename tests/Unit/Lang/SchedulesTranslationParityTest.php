<?php

use Illuminate\Support\Arr;

/**
 * Agenda: pt_BR e en do schedules.php têm as mesmas chaves — chave faltando devolveria o texto cru
 * ("schedules.series_reschedule_changed") para a recepção. Dívida antiga: 11 chaves do painel de chamada só em pt_BR
 * (LEGACY_PT_ONLY); qualquer outra chave só num idioma reprova, e a lista encolhe quando a dívida for paga.
 */
const SCHEDULES_LEGACY_PT_ONLY = [
    'btn_attend', 'btn_call', 'call_sent', 'call_panel_title', 'call_panel_subtitle', 'call_panel_enable',
    'call_panel_hint', 'call_panel_url', 'call_panel_open', 'call_panel_regen', 'call_panel_saved',
];

/** Chaves de "Reagendar a série": 7 do servidor (Forja) e 11 do modal (Prisma) — o contrato do plano serie-remarcar §5.3. */
const SCHEDULES_SERIES_RESCHEDULE_KEYS = [
    'series_rescheduled', 'series_reschedule_no_sessions', 'series_reschedule_anchor_not_cancellable',
    'series_reschedule_same_time', 'series_reschedule_past', 'series_reschedule_changed', 'series_reschedule_too_many',
    'series_reschedule_scope', 'series_reschedule_one', 'series_reschedule_following', 'series_reschedule_hint',
    'series_reschedule_doctor_hint', 'series_reschedule_fit_in_hint', 'series_reschedule_preview',
    'series_reschedule_loading', 'series_reschedule_conflicts', 'series_reschedule_error', 'series_reschedule_blocked',
];

/** @return array<string, mixed> */
function schedulesLang(string $locale): array
{
    return Arr::dot(require __DIR__ . "/../../../lang/{$locale}/schedules.php");
}

it('pt_BR e en do schedules.php só divergem nas chaves da dívida legada (pt-only)', function () {
    $pt = array_keys(schedulesLang('pt_BR'));
    $en = array_keys(schedulesLang('en'));

    expect(array_values(array_diff($pt, $en, SCHEDULES_LEGACY_PT_ONLY)))->toBe([])
        ->and(array_values(array_diff($en, $pt)))->toBe([]);
});

it('a lista da dívida legada só tem chaves que ainda faltam no en', function () {
    $stale = array_values(array_intersect(SCHEDULES_LEGACY_PT_ONLY, array_keys(schedulesLang('en'))));

    expect($stale)->toBe([]);
});

it('as chaves de reagendar a série existem, preenchidas, nos dois idiomas', function (string $locale) {
    $lang = schedulesLang($locale);

    foreach (SCHEDULES_SERIES_RESCHEDULE_KEYS as $key) {
        expect($lang)->toHaveKey($key)
            ->and(trim((string) $lang[$key]))->not->toBe('');
    }
})->with(['pt_BR', 'en']);

it('as mensagens de servidor de reagendar a série preservam os placeholders nos dois idiomas', function () {
    $pt = schedulesLang('pt_BR');
    $en = schedulesLang('en');

    foreach (['series_rescheduled', 'series_reschedule_following', 'series_reschedule_conflicts', 'series_reschedule_too_many'] as $key) {
        preg_match_all('/:[a-z_]+/', (string) $pt[$key], $ptPlaceholders);
        preg_match_all('/:[a-z_]+/', (string) $en[$key], $enPlaceholders);

        expect(array_unique($enPlaceholders[0]))->toEqualCanonicalizing(array_unique($ptPlaceholders[0]));
    }
});
