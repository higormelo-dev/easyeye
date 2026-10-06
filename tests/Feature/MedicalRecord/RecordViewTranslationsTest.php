<?php

declare(strict_types=1);
use App\Services\ContactLensCalculator;

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

it('lente de contato: toda chave contact_lens_* usada na tela, no PDF e na exportação existe em pt_BR e en', function () {
    $files = [
        'resources/js/Pages/Panel/MedicalRecords/Components/contactLens.js',
        'resources/js/Pages/Panel/MedicalRecords/Components/ContactLensCalculatorModal.vue',
        'resources/js/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue',
        'app/Services/ContactLensFormatter.php',
        'resources/views/pdf/medical_record.blade.php',
    ];
    $keys = collect($files)
        ->flatMap(function (string $file) {
            preg_match_all("/'(contact_lens_[a-z_]+)'|medical_records\\.(contact_lens_[a-z_]+)/", (string) file_get_contents(base_path($file)), $m);

            return array_filter([...$m[1], ...$m[2]]);
        })
        // Avisos montados a partir do código (contact_lens_note_<código>[_extended]).
        ->merge(collect(ContactLensCalculator::NOTES)->map(fn (string $code) => "contact_lens_note_{$code}"))
        ->merge(['contact_lens_note_out_of_range_extended', 'contact_lens_note_cylinder_out_of_range_extended'])
        // Campo do form (não é texto) e prefixos citados em comentário ("contact_lens_note_*").
        ->reject(fn (string $key) => $key === 'contact_lens_calculation' || str_ends_with($key, '_'))
        ->unique()
        ->values()
        ->all();

    expect(count($keys))->toBeGreaterThan(40);

    foreach (['pt_BR', 'en'] as $locale) {
        $group = trans('actions.medical_records', [], $locale);

        expect(array_values(array_diff($keys, array_keys(array_filter($group, 'is_string')))))
            ->toBe([], "chaves ausentes em {$locale}");
    }
});
