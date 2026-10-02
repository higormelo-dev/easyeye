<?php

declare(strict_types=1);

/**
 * Visualização da consulta (modal, drawer da lista e painel "Consultas
 * anteriores"): os rótulos vêm de actions.medical_records (prop `t`). Chave
 * usada no componente e ausente num idioma cai no fallback em português com a
 * tela em inglês — por isso cada chave referenciada precisa existir nos dois.
 * O Vitest (RecordViewI18n.test.js) garante que os rótulos passam por `t`.
 */
dataset('record_view_components', [
    'modal'  => 'resources/js/Pages/Panel/MedicalRecords/Components/MedicalRecordViewModal.vue',
    'painel' => 'resources/js/Pages/Panel/MedicalRecords/Components/PreviousRecordsCard.vue',
    'drawer' => 'resources/js/Pages/Panel/MedicalRecords/MedicalRecordDetailDrawer.vue',
]);

it('toda chave de tradução usada existe em pt_BR e en', function (string $file) {
    preg_match_all("/\\btt\\('([a-z0-9_]+)'|\\bt\\.([a-z0-9_]+)/", (string) file_get_contents(base_path($file)), $m);

    $keys = array_values(array_unique(array_filter([...$m[1], ...$m[2]])));

    expect($keys)->not->toBeEmpty();

    foreach (['pt_BR', 'en'] as $locale) {
        $group = trans('actions.medical_records', [], $locale);

        expect(array_values(array_diff($keys, array_keys(array_filter($group, 'is_string')))))
            ->toBe([], "chaves ausentes em {$locale}");
    }
})->with('record_view_components');

it('pt_BR e en têm as mesmas chaves de prontuário', function () {
    $pt = array_keys(trans('actions.medical_records', [], 'pt_BR'));
    $en = array_keys(trans('actions.medical_records', [], 'en'));

    expect(array_values(array_diff($pt, $en)))->toBe([])
        ->and(array_values(array_diff($en, $pt)))->toBe([]);
});
