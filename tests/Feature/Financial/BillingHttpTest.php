<?php

declare(strict_types=1);

use App\Domains\Tiss\Models\{TissGlosa, TissGuideItem};
use App\Models\{BillingClaim, Covenant, Entity};
use App\Services\Financial\BillingService;
use Illuminate\Http\UploadedFile;

it('renders the billing index without route errors when claims already exist', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    app(BillingService::class)->createIndividual([
        'schedule_id'         => $schedule->id,
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ]);

    $response = $this->get(route('panel.financial.billing.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Panel/Financial/Billing/Index')
        ->has('claims', 1)
        ->where('claims.0.mark_paid_url', fn ($url) => str_contains($url, '/paid'))
        ->where('claims.0.mark_denied_url', fn ($url) => str_contains($url, '/denied'))
        ->where('claims.0.status_label', 'Rascunho'));
});

it('creates an individual claim via http and flashes a success message', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    $response = $this->post(route('panel.financial.billing.individual.store'), [
        'schedule_id'         => $schedule->id,
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    $response->assertSessionMissing('message');

    expect(BillingClaim::query()->where('schedule_id', $schedule->id)->exists())->toBeTrue();
});

it('stores the eye side on the tiss guide item metadata when billing individually', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    $response = $this->post(route('panel.financial.billing.individual.store'), [
        'schedule_id'         => $schedule->id,
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
        'eye_side'            => 'od',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $claim = BillingClaim::query()->where('schedule_id', $schedule->id)->firstOrFail();
    $item  = TissGuideItem::query()->where('guide_id', $claim->tiss_guide_id)->firstOrFail();

    expect($item->metadata)->toBe(['eye_side' => 'OD']);
});

it('rejects an eye side value outside OD/OE/AO on individual billing', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    $response = $this->post(route('panel.financial.billing.individual.store'), [
        'schedule_id' => $schedule->id,
        'unit_price'  => 150,
        'eye_side'    => 'XX',
    ]);

    $response->assertSessionHasErrors('eye_side');
});

it('marks a claim as paid via the correct named route', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    $claim = app(BillingService::class)->createIndividual([
        'schedule_id'         => $schedule->id,
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ]);

    $response = $this->post(route('panel.financial.billing.claims.paid', $claim->id));

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($claim->fresh()->status->value)->toBe('paid');
});

it('marks a claim as denied via http with a structured glosa code', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    $claim = app(BillingService::class)->createIndividual([
        'schedule_id'         => $schedule->id,
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ]);

    $response = $this->post(route('panel.financial.billing.claims.denied', $claim->id), [
        'glosa_amount' => 150,
        'glosa_code'   => '3099',
        'notes'        => 'Fora da cobertura contratual.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $glosa = TissGlosa::query()->where('guide_id', $claim->fresh()->tiss_guide_id)->first();
    expect($glosa)->not->toBeNull()
        ->and($glosa->glosa_code)->toBe('3099');
});

it('submits a batch to the tiss workflow via http and flashes success', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    $batch = app(BillingService::class)->createBatch([
        'covenant_id'         => $schedule->covenant_id,
        'date_from'           => now()->subDay()->toDateString(),
        'date_until'          => now()->toDateString(),
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ]);

    $response = $this->post(route('panel.financial.billing.batches.submit', $batch->id));

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($batch->fresh()->status->value)->toBe('submitted');
});

it('opens a glosa appeal via http and flashes success instead of a dead message key', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

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

    $glosa = TissGlosa::query()->where('guide_id', $claim->fresh()->tiss_guide_id)->firstOrFail();

    $response = $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), [
        'reason' => 'Procedimento coberto conforme contrato anexo.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    $response->assertSessionMissing('message');
});

it('pre-validates a tiss guide over http with the expected json shape', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    $claim = app(BillingService::class)->createIndividual([
        'schedule_id'         => $schedule->id,
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ]);

    $response = $this->getJson(route('panel.financial.tiss.guides.pre-validate', $claim->tiss_guide_id));

    $response->assertOk();
    $response->assertJsonStructure(['passes', 'errors', 'warnings', 'summary']);
    $response->assertJsonPath('passes', true);
});

it('blocks pre-validating a tiss guide that belongs to another clinic', function (): void {
    $ownerEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($ownerEntity);
    $schedule = createBillableSchedule($ownerEntity);
    $claim    = app(BillingService::class)->createIndividual([
        'schedule_id'         => $schedule->id,
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ]);

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($otherEntity);

    $response = $this->getJson(route('panel.financial.tiss.guides.pre-validate', $claim->tiss_guide_id));

    // TissGuide::resolveRouteBinding() já escopa por entity_id da sessão
    // (App\Domains\Tiss\Concerns\BelongsToEntity) e usa firstOrFail(): uma
    // guia de outra clínica nunca chega a "existir" para o binding de rota,
    // então vira 404 antes mesmo do controller rodar — nunca 403. Mais
    // seguro (não confirma a existência do registro para quem não tem acesso).
    $response->assertNotFound();
});

it('imports a tiss return xml over http and registers a denial linked to the real guide', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

    // Precisa ser lote (createBatch), não individual: só o lote cria o
    // TissBatch/vínculo de guia que ProcessGlosaReturnService::resolveGuide()
    // usa pra achar a guia real a partir do numeroLote do XML de retorno.
    $batch = app(BillingService::class)->createBatch([
        'covenant_id'         => $schedule->covenant_id,
        'date_from'           => now()->subDay()->toDateString(),
        'date_until'          => now()->toDateString(),
        'unit_price'          => 150,
        'tuss_code'           => '10101012',
        'clinical_indication' => 'H40.1',
    ]);

    $submitted = app(BillingService::class)->submitBatch($batch->fresh());
    $tissBatch = $submitted->tissBatch;
    $guide     = BillingClaim::query()->where('batch_id', $submitted->id)->firstOrFail()->tissGuide;
    $covenant  = Covenant::query()->findOrFail($schedule->covenant_id);

    expect($covenant->tiss_operator_id)->not->toBeNull();

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ans:ansTISS xmlns:ans="http://www.ans.gov.br/padroes/tiss/schemas">
  <ans:operadoraParaPrestador>
    <ans:protocoloRecebimento>
      <ans:numeroProtocolo>PRT-IMPORT-TESTE</ans:numeroProtocolo>
      <ans:statusProtocolo>Aceito</ans:statusProtocolo>
    </ans:protocoloRecebimento>
    <ans:loteGuias>
      <ans:numeroLote>{$tissBatch->batch_number}</ans:numeroLote>
      <ans:guiasTISS>
        <ans:guiaSP-SADT>
          <ans:cabecalhoGuia>
            <ans:numeroGuiaPrestador>{$guide->guide_number_provider}</ans:numeroGuiaPrestador>
          </ans:cabecalhoGuia>
          <ans:procedimentoExecutado>
            <ans:codigoProcedimento>10101012</ans:codigoProcedimento>
            <ans:glosa>
              <ans:codigoGlosa>3099</ans:codigoGlosa>
              <ans:descricaoGlosa>Fora da cobertura contratual</ans:descricaoGlosa>
              <ans:valorGlosa>150.00</ans:valorGlosa>
            </ans:glosa>
          </ans:procedimentoExecutado>
        </ans:guiaSP-SADT>
      </ans:guiasTISS>
    </ans:loteGuias>
  </ans:operadoraParaPrestador>
</ans:ansTISS>
XML;

    $response = $this->post(route('panel.financial.billing.import-return'), [
        'covenant_id' => $covenant->id,
        'xml_file'    => UploadedFile::fake()->createWithContent('retorno.xml', $xml),
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $glosa = TissGlosa::query()->where('guide_id', $guide->id)->first();

    expect($glosa)->not->toBeNull()
        ->and($glosa->glosa_code)->toBe('3099')
        ->and((float) $glosa->amount)->toBe(150.0);
});

it('rejects importing a tiss return for a covenant without a linked tiss operator', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $covenant = Covenant::factory()->create([
        'entity_id'        => $entity->id,
        'active'           => true,
        'table'            => true,
        'ans_registry'     => null,
        'tiss_operator_id' => null,
    ]);

    $response = $this->post(route('panel.financial.billing.import-return'), [
        'covenant_id' => $covenant->id,
        'xml_file'    => UploadedFile::fake()->createWithContent('retorno.xml', '<a></a>'),
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

it('rejects a non-xml file on the tiss return import endpoint', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);
    $covenant = Covenant::query()->findOrFail($schedule->covenant_id);

    $response = $this->post(route('panel.financial.billing.import-return'), [
        'covenant_id' => $covenant->id,
        'xml_file'    => UploadedFile::fake()->create('retorno.txt', 10),
    ]);

    $response->assertSessionHasErrors('xml_file');
});
