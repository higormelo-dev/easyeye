<?php

declare(strict_types=1);

use App\Enums\ClinicalSafety\{AllergyCategory, AllergySeverity, AllergyStatus, MedicationSource, MedicationStatus};
use Illuminate\Support\Arr;

/*
 * Fase 2 — textos de segurança clínica (lang/<locale>/clinical_safety.php,
 * enviados como `t_clinical`): pt_BR e en com as mesmas chaves; toda chave
 * usada nas telas e todo rótulo de enum existe nos dois idiomas (chave
 * faltando cai no texto de reserva em português com a tela em inglês).
 */
it('pt_BR e en têm as mesmas chaves', function () {
    $pt = array_keys(Arr::dot(require lang_path('pt_BR/clinical_safety.php')));
    $en = array_keys(Arr::dot(require lang_path('en/clinical_safety.php')));

    expect(array_values(array_diff($pt, $en)))->toBe([])
        ->and(array_values(array_diff($en, $pt)))->toBe([]);
});

it('toda chave usada nas telas existe nos dois idiomas', function () {
    $files = [
        ...glob(resource_path('js/Components/Panel/ClinicalSafety/*.vue')),
        resource_path('js/Support/clinicalSafety.js'),
        resource_path('js/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue'),
        resource_path('js/Pages/Panel/Patients/PatientDetailDrawer.vue'),
    ];

    $groups = 'banner|allergies|medications|prescription|snapshot|risk|categories|severities|allergy_statuses|medication_statuses|medication_sources';
    $keys   = collect($files)
        ->flatMap(function (string $file) use ($groups) {
            preg_match_all("/'((?:{$groups})\\.[a-z0-9_]+)'/", (string) file_get_contents($file), $m);

            return $m[1];
        })
        ->unique()
        ->values();

    expect($keys->count())->toBeGreaterThan(60);

    foreach (['pt_BR', 'en'] as $locale) {
        $missing = $keys->reject(fn (string $key) => is_string(trans("clinical_safety.{$key}", [], $locale))
            && trans("clinical_safety.{$key}", [], $locale) !== "clinical_safety.{$key}")->values()->all();

        expect($missing)->toBe([], "faltando em {$locale}");
    }
});

it('rótulos dos enums existem nos dois idiomas', function () {
    $cases = [...AllergyCategory::cases(), ...AllergySeverity::cases(), ...AllergyStatus::cases(), ...MedicationStatus::cases(), ...MedicationSource::cases()];

    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ($cases as $case) {
            expect($case->label())->not->toStartWith('clinical_safety.', $case::class . '::' . $case->name . " ({$locale})");
        }
    }
});
