<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\{Entity, Medicine, MedicinePresentation, User};
use App\Services\MedicationPrescriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);
uses(RefreshDatabase::class);

beforeEach(function () {
    // Audit columns + traits exigem usuário autenticado + entidade na sessão
    $entity = Entity::factory()->create(['is_client' => true]);
    $user   = User::factory()->create();
    $this->actingAs($user);
    session(['selected_entity_id' => $entity->id]);
});

it('formata linha completa com presentation, dosage, frequency, duration, instructions', function () {
    $presentation = MedicinePresentation::create(['name' => 'Frasco 10ml', 'active' => true]);
    $medicine     = Medicine::create([
        'name'                     => 'Tobramicina',
        'medicine_presentation_id' => $presentation->id,
        'dosage'                   => '1 gota',
        'frequency'                => '4x ao dia',
        'duration'                 => '7 dias',
        'instructions'             => 'Pingar em ambos os olhos',
        'active'                   => true,
    ]);

    $line = (new MedicationPrescriptionService())->formatLine($medicine);

    expect($line)->toContain('- Tobramicina (Frasco 10ml)')
        ->and($line)->toContain('1 gota 4x ao dia por 7 dias')
        ->and($line)->toContain('Obs: Pingar em ambos os olhos');
});

it('formata linha mínima só com nome', function () {
    $medicine = Medicine::create([
        'name'   => 'Soro fisiológico',
        'active' => true,
    ]);

    $line = (new MedicationPrescriptionService())->formatLine($medicine);

    expect($line)->toContain('- Soro fisiológico')
        ->and($line)->not->toContain('por ')
        ->and($line)->not->toContain('Obs:');
});

it('omite "por" quando duration vazio', function () {
    $medicine = Medicine::create([
        'name'      => 'Lágrima artificial',
        'dosage'    => '1 gota',
        'frequency' => '6x ao dia',
        'active'    => true,
    ]);

    $line = (new MedicationPrescriptionService())->formatLine($medicine);

    expect($line)->toContain('1 gota 6x ao dia')
        ->and($line)->not->toContain(' por ');
});

it('formatPrescription concatena linhas e finaliza com newline único', function () {
    $m1 = Medicine::create(['name' => 'Med A', 'active' => true]);
    $m2 = Medicine::create(['name' => 'Med B', 'active' => true]);

    $out = (new MedicationPrescriptionService())->formatPrescription([$m1, $m2]);

    expect($out)->toContain('- Med A')
        ->and($out)->toContain('- Med B')
        ->and(str_ends_with($out, "\n"))->toBeTrue();
});

it('exposes MAX_MEDICINES = 5 (regra clínica)', function () {
    expect(MedicationPrescriptionService::MAX_MEDICINES)->toBe(5);
});
