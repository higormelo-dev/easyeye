<?php

declare(strict_types=1);

use App\Domains\Tiss\Actions\ResolveGlosaAppealAction;
use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\{TissGlosa, TissStatusHistory};
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

// Guarda de domínio (além da validação HTTP): "Aceito" sem valor positivo, ou
// acima do valor glosado, deixava recurso Aceito com glosa Mantida / recuperado
// maior que o glosado. A action agora recusa sem tocar em nada.
it('refuses to resolve an appeal as accepted without a positive amount or above the glosa amount', function (float $amount): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $glosa = denyClaimAndGetGlosa($entity);

    $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Cobertura contratual válida.']);
    $appeal = $glosa->appeals()->firstOrFail();
    $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id));
    $appeal->refresh();

    expect(fn () => app(ResolveGlosaAppealAction::class)($appeal, TissAppealStatus::Accepted, ['accepted_amount' => $amount]))
        ->toThrow(InvalidArgumentException::class);

    expect($appeal->fresh()->status)->toBe(TissAppealStatus::Submitted)
        ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Appealed);
})->with([
    'zero'        => [0.0],
    'acima glosa' => [150.01],
    // numeric(14,2) gravaria 0,00: recurso Aceito sem valor com glosa "Revertida parcialmente".
    'arredonda para zero' => [0.004],
]);

// A coluna é numeric(14,2): o status da glosa precisa ser decidido pelo MESMO valor
// que fica gravado. Antes, 149.996 gravava 150,00 (recuperado total) mas a glosa
// ficava "Revertida parcialmente" (149.996 < 150).
it('rounds accepted_amount to cents before deciding the glosa status', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $glosa = denyClaimAndGetGlosa($entity);

    $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Cobertura contratual válida.']);
    $appeal = $glosa->appeals()->firstOrFail();
    $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id));
    $appeal->refresh();

    app(ResolveGlosaAppealAction::class)($appeal, TissAppealStatus::Accepted, ['accepted_amount' => 149.996]);

    expect((float) $appeal->fresh()->accepted_amount)->toBe(150.0)
        ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Reversed);
});

// Histórico TISS é dado de auditoria: o texto gravado não pode depender do idioma
// da interface de quem registrou a decisão (os rótulos dos status agora usam __()).
it('records the TISS status history reason in pt_BR even when the UI locale is English', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $glosa = denyClaimAndGetGlosa($entity);

    $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Cobertura contratual válida.']);
    $appeal = $glosa->appeals()->firstOrFail();
    $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id));
    $appeal->refresh();

    app()->setLocale('en');

    app(ResolveGlosaAppealAction::class)($appeal, TissAppealStatus::Accepted, ['accepted_amount' => 150]);

    $appealReason = TissStatusHistory::query()
        ->where('context_type', 'glosa_appeal')
        ->where('context_id', $appeal->id)
        ->where('current_status', TissAppealStatus::Accepted->value)
        ->value('reason');

    $glosaReason = TissStatusHistory::query()
        ->where('context_type', 'glosa')
        ->where('context_id', $glosa->id)
        ->where('current_status', TissGlosaStatus::Reversed->value)
        ->value('reason');

    expect($appealReason)->toBe("Recurso {$appeal->appeal_number} resolvido: Aceito.")
        ->and($glosaReason)->toBe("Glosa Revertida após decisão do recurso {$appeal->appeal_number}.");
});

it('forces accepted_amount to zero when the appeal is rejected (hidden form value is not persisted)', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $glosa = denyClaimAndGetGlosa($entity);

    $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Cobertura contratual válida.']);
    $appeal = $glosa->appeals()->firstOrFail();
    $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id));
    $appeal->refresh();

    app(ResolveGlosaAppealAction::class)($appeal, TissAppealStatus::Rejected, ['accepted_amount' => 80]);

    expect((float) $appeal->fresh()->accepted_amount)->toBe(0.0)
        ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Maintained);
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
