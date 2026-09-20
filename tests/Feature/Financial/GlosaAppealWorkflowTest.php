<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\TissGlosa;
use App\Models\Entity;
use App\Services\Financial\BillingService;

function denyClaimAndGetGlosa(Entity $entity): TissGlosa
{
    $schedule = createBillableSchedule($entity);

    $claim = app(BillingService::class)->createIndividual([
        'schedule_id'         => $schedule->id,
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ]);

    app(BillingService::class)->markClaimDenied($claim, [
        'glosa_amount' => 150,
        'glosa_code'   => '3099',
    ]);

    return TissGlosa::query()->where('guide_id', $claim->fresh()->tiss_guide_id)->firstOrFail();
}

it('submits an opened appeal to the operator and sets a response deadline', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $glosa = denyClaimAndGetGlosa($entity);

    $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), [
        'reason' => 'Procedimento coberto conforme contrato anexo.',
    ]);

    $appeal = $glosa->appeals()->firstOrFail();

    $response = $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id));

    $response->assertRedirect();
    $response->assertSessionHas('success');
    $response->assertSessionMissing('message');

    $appeal->refresh();
    expect($appeal->status)->toBe(TissAppealStatus::Submitted)
        ->and($appeal->submitted_at)->not->toBeNull()
        ->and($appeal->deadline)->not->toBeNull();
});

it('blocks submitting an appeal that was already submitted', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $glosa = denyClaimAndGetGlosa($entity);

    $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Cobertura contratual válida.']);
    $appeal = $glosa->appeals()->firstOrFail();

    $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id));

    $response = $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id));

    $response->assertStatus(409);
});

it('resolves a submitted appeal as fully accepted and reverses the glosa', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $glosa = denyClaimAndGetGlosa($entity);

    $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Cobertura contratual válida.']);
    $appeal = $glosa->appeals()->firstOrFail();
    $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id));

    $response = $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), [
        'decision'        => 'accepted',
        'accepted_amount' => 150,
        'result_notes'    => 'Operadora reverteu a glosa integralmente.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $appeal->refresh();
    $glosa->refresh();

    expect($appeal->status)->toBe(TissAppealStatus::Accepted)
        ->and((float) $appeal->accepted_amount)->toBe(150.0)
        ->and($glosa->status)->toBe(TissGlosaStatus::Reversed)
        ->and($glosa->resolved_at)->not->toBeNull();
});

it('resolves a submitted appeal as partially accepted and marks the glosa as partially reversed', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $glosa = denyClaimAndGetGlosa($entity);

    $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Cobertura contratual válida.']);
    $appeal = $glosa->appeals()->firstOrFail();
    $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id));

    $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), [
        'decision'        => 'accepted',
        'accepted_amount' => 60,
    ]);

    expect($glosa->fresh()->status)->toBe(TissGlosaStatus::PartialReversed);
});

it('resolves a submitted appeal as rejected and keeps the glosa maintained', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $glosa = denyClaimAndGetGlosa($entity);

    $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Cobertura contratual válida.']);
    $appeal = $glosa->appeals()->firstOrFail();
    $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id));

    $response = $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), [
        'decision' => 'rejected',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $appeal->refresh();

    expect($appeal->status)->toBe(TissAppealStatus::Rejected)
        ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Maintained);
});

it('blocks resolving an appeal that has not been submitted yet', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $glosa = denyClaimAndGetGlosa($entity);

    $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Cobertura contratual válida.']);
    $appeal = $glosa->appeals()->firstOrFail();

    $response = $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), [
        'decision'        => 'accepted',
        'accepted_amount' => 150,
    ]);

    $response->assertStatus(409);
});

it('blocks resolving an appeal with an invalid decision value', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $glosa = denyClaimAndGetGlosa($entity);

    $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Cobertura contratual válida.']);
    $appeal = $glosa->appeals()->firstOrFail();
    $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id));

    $response = $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), [
        'decision' => 'maybe',
    ]);

    $response->assertSessionHasErrors('decision');
});
