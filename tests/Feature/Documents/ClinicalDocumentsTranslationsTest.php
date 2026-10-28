<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * Fase 2 / Bloco B — textos dos documentos do atendimento
 * (lang/<locale>/clinical_documents.php, `t_documents`/`t` das telas):
 * pt_BR e en com as mesmas chaves e os mesmos marcadores; toda chave usada
 * nas telas existe nos dois idiomas.
 */
it('pt_BR e en têm as mesmas chaves e os mesmos marcadores', function () {
    $pt = Arr::dot(require lang_path('pt_BR/clinical_documents.php'));
    $en = Arr::dot(require lang_path('en/clinical_documents.php'));

    expect(array_values(array_diff(array_keys($pt), array_keys($en))))->toBe([])
        ->and(array_values(array_diff(array_keys($en), array_keys($pt))))->toBe([]);

    $markers = function (string $text): array {
        preg_match_all('/:([a-z_]+)/', $text, $m);
        sort($m[1]);

        return $m[1];
    };

    foreach ($pt as $key => $text) {
        expect($markers((string) $en[$key]))->toBe($markers((string) $text), $key);
    }
});

it('toda chave usada nas telas existe nos dois idiomas', function () {
    $files = [
        ...glob(resource_path('js/Components/Panel/Documents/*.vue')),
        resource_path('js/Pages/Panel/Settings/ExamKits/Index.vue'),
        resource_path('js/Pages/Panel/Manager/ExamKits/Index.vue'),
        resource_path('js/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue'),
    ];

    $keys = collect($files)
        ->flatMap(function (string $file) {
            preg_match_all("/\\b(?:tt|dtt)\\('((?:date|certificates|request|kits|templates)\\.[a-z0-9_.]+)'/", (string) file_get_contents($file), $m);

            return $m[1];
        })
        ->unique()
        ->values();

    expect($keys->count())->toBeGreaterThan(80);

    foreach (['pt_BR', 'en'] as $locale) {
        $missing = $keys->reject(fn (string $key) => is_string(trans("clinical_documents.{$key}", [], $locale))
            && trans("clinical_documents.{$key}", [], $locale) !== "clinical_documents.{$key}")->values()->all();

        expect($missing)->toBe([], "faltando em {$locale}");
    }
});
