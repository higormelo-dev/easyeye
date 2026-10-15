<?php

declare(strict_types=1);

use App\Domains\Tiss\Models\{TissGlosa, TissGuideItem, TissReturn, TissXmlDocument};
use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\Services\ProcessTissReturnService;
use App\Models\{BillingClaim, Covenant, Entity};
use App\Services\Financial\BillingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Exceptions, Log};
use Illuminate\Testing\TestResponse;

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

    // Fase 4: as abas vêm paginadas (paginator do Laravel) — as linhas ficam em `data`.
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Panel/Financial/Billing/Index')
        ->has('claims.data', 1)
        ->where('claims.total', 1)
        ->where('claims.data.0.mark_paid_url', fn ($url) => str_contains($url, '/paid'))
        ->where('claims.data.0.mark_denied_url', fn ($url) => str_contains($url, '/denied'))
        ->where('claims.data.0.cancel_url', fn ($url) => str_contains($url, '/cancel'))
        ->where('claims.data.0.status_label', 'Rascunho'));
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

/**
 * Lote enviado de verdade (createBatch + submitBatch) e o XML de retorno com UMA
 * glosa de R$ 150,00 na guia dele — para os testes de importação repetida.
 *
 * @return array{covenant: Covenant, guide: TissGuide, xml: string}
 */
function bhtriFixture(): array
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);

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

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ans:ansTISS xmlns:ans="http://www.ans.gov.br/padroes/tiss/schemas">
  <ans:operadoraParaPrestador>
    <ans:protocoloRecebimento>
      <ans:numeroProtocolo>PRT-IMPORT-REPETIDO</ans:numeroProtocolo>
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

    return ['covenant' => Covenant::query()->findOrFail($schedule->covenant_id), 'guide' => $guide, 'xml' => $xml];
}

/** POST do mesmo arquivo no endpoint de importação de retorno. */
function bhtriImport(Covenant $covenant, string $xml): TestResponse
{
    return test()->post(route('panel.financial.billing.import-return'), [
        'covenant_id' => $covenant->id,
        'xml_file'    => UploadedFile::fake()->createWithContent('retorno.xml', $xml),
    ]);
}

it('refuses the same tiss return file imported again: field error with the original date, nothing is summed twice', function (string $locale): void {
    ['covenant' => $covenant, 'guide' => $guide, 'xml' => $xml] = bhtriFixture();

    Exceptions::fake();
    Log::spy();

    $this->withSession(['locale' => $locale]);

    bhtriImport($covenant, $xml)->assertRedirect()->assertSessionHas('success');

    $return   = TissReturn::query()->sole();
    $expected = __('financial_billing.errors.import_return_duplicate', [
        'date' => $return->processed_at->copy()->locale($locale)->isoFormat('L LT'),
    ], $locale);

    expect($expected)->not->toContain('import_return_duplicate');

    // Duplo clique, reenvio do formulário, importação repetida dois dias depois: sempre o mesmo
    // veredito, com a data da importação ORIGINAL (sem o salto, "agora" e "original" coincidem).
    $this->travel(2)->days();

    foreach (range(1, 3) as $attempt) {
        bhtriImport($covenant, $xml)
            ->assertRedirect()
            ->assertSessionHasErrors(['xml_file' => $expected])
            ->assertSessionMissing('success')
            ->assertSessionMissing('error');
    }

    expect(TissReturn::query()->count())->toBe(1)
        ->and(TissXmlDocument::query()->where('document_type', 'return_xml')->count())->toBe(1)
        ->and(TissGlosa::query()->where('guide_id', $guide->id)->count())->toBe(1)
        // Antes da correção: 300,00 na 2ª importação, 450,00 na 3ª e 600,00 na 4ª.
        ->and((float) $guide->fresh()->denied_amount)->toBe(150.0);

    // É um resultado esperado, não uma falha: nada vai ao Sentry nem ao log de erro.
    Exceptions::assertNothingReported();
    Log::shouldNotHaveReceived('error');
})->with(['pt_BR' => ['pt_BR'], 'en' => ['en']]);

it('lets the user retry a tiss return import that failed halfway: the same file is resumed and the denial is registered once', function (): void {
    ['covenant' => $covenant, 'guide' => $guide, 'xml' => $xml] = bhtriFixture();

    Exceptions::fake();

    // O processamento cai na 1ª vez (depois de o retorno ser gravado) e funciona nas seguintes.
    $real  = app(ProcessTissReturnService::class);
    $flaky = new class($real) extends ProcessTissReturnService {
        public int $calls = 0;

        public function __construct(private readonly ProcessTissReturnService $real)
        {
        }

        public function process(TissReturn $return): TissReturn
        {
            if (++$this->calls === 1) {
                throw new RuntimeException('falha simulada no processamento');
            }

            return $this->real->process($return);
        }
    };
    $this->app->instance(ProcessTissReturnService::class, $flaky);

    // Erro de campo, não flash `error`: o flash fecha o modal (onSuccess) e o toast idêntico
    // não reaparece no "tente novamente".
    bhtriImport($covenant, $xml)
        ->assertRedirect()
        ->assertSessionHasErrors(['xml_file' => __('financial_billing.errors.import_return_failed')])
        ->assertSessionMissing('error');

    expect(TissReturn::query()->sole()->status->value)->toBe('parsed')
        ->and((float) $guide->fresh()->denied_amount)->toBe(0.0);

    // A própria tela manda "verifique o arquivo e tente novamente": o mesmo arquivo tem que passar.
    bhtriImport($covenant, $xml)->assertRedirect()->assertSessionHas('success')->assertSessionDoesntHaveErrors();

    expect(TissReturn::query()->count())->toBe(1)
        ->and(TissReturn::query()->sole()->status->value)->toBe('processed')
        ->and(TissGlosa::query()->where('guide_id', $guide->id)->count())->toBe(1)
        ->and((float) $guide->fresh()->denied_amount)->toBe(150.0)
        ->and($flaky->calls)->toBe(2);

    // E agora sim é repetição.
    bhtriImport($covenant, $xml)->assertSessionHasErrors('xml_file');
    expect((float) $guide->fresh()->denied_amount)->toBe(150.0);
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
    $response->assertSessionHasErrors(['covenant_id' => __('financial_billing.errors.import_return_no_operator')]);
    $response->assertSessionMissing('error');
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
