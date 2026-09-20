<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\TissGlosaStatus;
use App\Domains\Tiss\Models\TissGlosa;
use App\Models\{BillingClaim, Entity};
use App\Services\Financial\BillingService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Helpers (seedActiveTissVersion, createBillableSchedule, createParticularSchedule,
// actingAsFinancialEntityUser) vivem em tests/Pest.php — compartilhados com
// BillingHttpTest.php e ResolveTissOperatorForCovenantActionTest.php, e assim
// continuam funcionando mesmo rodando este arquivo isoladamente.

it('creates a billing batch that generates a real tiss xml document via the rich domain', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    $service = app(BillingService::class);

    $batch = $service->createBatch([
        'covenant_id'           => $schedule->covenant_id,
        'date_from'             => now()->subDay()->toDateString(),
        'date_until'            => now()->toDateString(),
        'unit_price'            => 150,
        'tuss_code'             => '10101012',
        'procedure_description' => 'CONSULTA OFTALMOLOGICA',
        'clinical_indication'   => 'H52.1',
    ]);

    expect($batch->tiss_batch_id)->not->toBeNull();

    $tissBatch = $batch->tissBatch;
    expect($tissBatch->guides_count)->toBe(1)
        ->and($tissBatch->status->value)->toBe('open');

    $claim = BillingClaim::query()->where('batch_id', $batch->id)->firstOrFail();
    expect($claim->tiss_guide_id)->not->toBeNull();

    $submitted = $service->submitBatch($batch->fresh());

    expect($submitted->status->value)->toBe('submitted')
        ->and($submitted->xml_path)->not->toBeNull();

    $submitted->tissBatch->refresh();
    expect($submitted->tissBatch->status->value)->toBe('sent');

    $xmlContent = Storage::disk('local')->get($submitted->xml_path);
    expect($xmlContent)->toContain('guiaConsulta')
        ->and($xmlContent)->toContain('326305');
});

it('leaves a guide unattached with a recorded pendency when clinical indication is missing', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    $service = app(BillingService::class);

    $batch = $service->createBatch([
        'covenant_id' => $schedule->covenant_id,
        'date_from'   => now()->subDay()->toDateString(),
        'date_until'  => now()->toDateString(),
        'unit_price'  => 150,
        // sem clinical_indication de propósito
    ]);

    expect($batch->tissBatch->guides_count)->toBe(0)
        ->and($batch->notes)->toContain('pendência');

    $guide = BillingClaim::query()->where('batch_id', $batch->id)->firstOrFail()->tissGuide;
    expect($guide->errors)->not->toBeNull();

    expect(fn () => $service->submitBatch($batch->fresh()))
        ->toThrow(ValidationException::class);
});

it('marking a claim as denied records a structured tiss glosa', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    $service = app(BillingService::class);

    $claim = $service->createIndividual([
        'schedule_id'         => $schedule->id,
        'unit_price'          => 200,
        'tuss_code'           => '10101012',
        'clinical_indication' => 'H40.1',
    ]);

    $service->markClaimDenied($claim, [
        'glosa_amount' => 200,
        'glosa_code'   => '3099',
        'notes'        => 'Procedimento fora da cobertura contratual.',
    ]);

    $glosa = TissGlosa::query()->where('guide_id', $claim->fresh()->tiss_guide_id)->first();

    expect($glosa)->not->toBeNull()
        ->and($glosa->status)->toBe(TissGlosaStatus::Open)
        ->and((float) $glosa->amount)->toBe(200.0)
        ->and($glosa->glosa_code)->toBe('3099');
});

it('bills a particular (no ans registry) schedule individually without touching the tiss domain', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createParticularSchedule($entity);

    $service = app(BillingService::class);

    $claim = $service->createIndividual([
        'schedule_id' => $schedule->id,
        'unit_price'  => 180,
    ]);

    expect($claim->tiss_guide_id)->toBeNull()
        ->and($claim->covenant_id)->toBe($schedule->covenant_id);
});

it('creates a particular batch without a tiss batch and still allows submission', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createParticularSchedule($entity);

    $service = app(BillingService::class);

    $batch = $service->createBatch([
        'covenant_id' => $schedule->covenant_id,
        'date_from'   => now()->subDay()->toDateString(),
        'date_until'  => now()->toDateString(),
        'unit_price'  => 180,
    ]);

    expect($batch->tiss_batch_id)->toBeNull()
        ->and((float) $batch->total_amount)->toBe(180.0);

    $claim = BillingClaim::query()->where('batch_id', $batch->id)->firstOrFail();
    expect($claim->tiss_guide_id)->toBeNull();

    $submitted = $service->submitBatch($batch->fresh());

    expect($submitted->status->value)->toBe('submitted');
});
