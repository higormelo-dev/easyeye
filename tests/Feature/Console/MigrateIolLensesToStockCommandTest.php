<?php

use App\Models\{Entity, EntityProduct};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/**
 * php artisan iol-lenses:migrate-to-stock — backfill de dados (fase 2 do
 * plano de migração de lentes IOL pro estoque). App\Console\Commands\
 * MigrateIolLensesToStockCommand.
 *
 * O comando lê as colunas LEGADAS de entity_iol_lenses (manufacturer/
 * model_name/price/image_path/active), que só existem ANTES da migration
 * de aperto de schema (`2026_09_16_090300_tighten_entity_iol_lenses_stock_link`)
 * rodar. Como o teste normal roda com o schema já migrado por completo
 * (RefreshDatabase), `beforeEach` recoloca essas colunas via `Schema::table`
 * puro — SEM tocar na tabela de bookkeeping de migrations do Laravel (não
 * usa `migrate:rollback`, que mexeria no registro de migrations e
 * conflitaria com o RefreshDatabase). Postgres suporta DDL transacional —
 * como RefreshDatabase já envolve cada teste numa transação, essas colunas
 * extras somem sozinhas no rollback automático do fim do teste, sem
 * teardown manual.
 *
 * Verificado manualmente fim-a-fim antes deste arquivo (dry-run → --force
 * → idempotência → migration de aperto reaplicada com sucesso) usando o
 * ciclo real de rollback/remigrate — este teste automatiza os mesmos
 * cenários pra regressão contínua.
 */
beforeEach(function () {
    Schema::table('entity_iol_lenses', function (Blueprint $table) {
        $table->string('manufacturer')->nullable();
        $table->string('model_name')->nullable();
        $table->decimal('price', 10, 2)->nullable();
        $table->string('image_path')->nullable();
        $table->boolean('active')->default(true);
    });

    Schema::table('entity_iol_lenses', function (Blueprint $table) {
        $table->dropUnique(['entity_product_id']);
        $table->uuid('entity_product_id')->nullable()->change();
    });

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
});

function insertLegacyLens(string $entityId, array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('entity_iol_lenses')->insert(array_merge([
        'id'           => $id,
        'entity_id'    => $entityId,
        'manufacturer' => 'Alcon',
        'model_name'   => 'AcrySof IQ',
        'category'     => 'monofocal',
        'diopter_min'  => 10,
        'diopter_max'  => 30,
        'price'        => 2500.50,
        'active'       => true,
        'created_at'   => now(),
        'updated_at'   => now(),
    ], $overrides));

    return $id;
}

it('dry-run (sem --force) não escreve nada — só relata o que faria', function () {
    insertLegacyLens($this->entity->id);

    $this->artisan('iol-lenses:migrate-to-stock')
        ->expectsOutputToContain('lente(s) sem EntityProduct vinculado')
        ->expectsOutputToContain('Dry-run. Rode novamente com --force para aplicar.')
        ->assertSuccessful();

    expect(EntityProduct::count())->toBe(0);
    expect(DB::table('entity_iol_lenses')->whereNull('entity_product_id')->count())->toBe(1);
});

it('--force cria EntityProduct pra lente pendente, vincula, e auto-provisiona a categoria "Lentes IOL"', function () {
    $lensId = insertLegacyLens($this->entity->id, [
        'manufacturer' => 'Zeiss',
        'model_name'   => 'CT Asphina',
        'price'        => 1900,
        'active'       => false,
    ]);

    $this->artisan('iol-lenses:migrate-to-stock', ['--force' => true])->assertSuccessful();

    $lens = DB::table('entity_iol_lenses')->where('id', $lensId)->first();
    expect($lens->entity_product_id)->not->toBeNull();

    $product = EntityProduct::find($lens->entity_product_id);
    expect($product->name)->toBe('CT Asphina');
    expect($product->manufacturer)->toBe('Zeiss');
    expect((float) $product->sale_price)->toBe(1900.0);
    expect($product->active)->toBeFalse();
    expect($product->is_opm)->toBeTrue();
    expect((float) $product->qty_on_hand)->toBe(0.0);
    expect($product->category->name)->toBe('Lentes IOL');
});

it('rodar --force duas vezes é idempotente — segunda vez não encontra nada pendente nem cria produto novo', function () {
    insertLegacyLens($this->entity->id);

    $this->artisan('iol-lenses:migrate-to-stock', ['--force' => true])->assertSuccessful();
    expect(EntityProduct::count())->toBe(1);

    $this->artisan('iol-lenses:migrate-to-stock', ['--force' => true])
        ->expectsOutputToContain('Nenhuma lente pendente de vínculo com o estoque. Nada a fazer.')
        ->assertSuccessful();

    expect(EntityProduct::count())->toBe(1);
});

it('lente já vinculada manualmente (GAP-FILL antigo) com manufacturer/imagem faltando no produto é completada, sem sobrescrever o que a clínica já customizou', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Nome customizado pela clínica', 'unit' => 'un', 'active' => true]);

    insertLegacyLens($this->entity->id, [
        'entity_product_id' => $product->id,
        'manufacturer'      => 'Bausch+Lomb',
        'model_name'        => 'enVista',
    ]);

    $this->artisan('iol-lenses:migrate-to-stock', ['--force' => true])->assertSuccessful();

    $product->refresh();
    expect($product->manufacturer)->toBe('Bausch+Lomb');
    // Nome NÃO é tocado pelo gap-fill — só manufacturer/image_path.
    expect($product->name)->toBe('Nome customizado pela clínica');
});

it('idempotência do gap-fill: depois de completado uma vez, rodar de novo não reporta mais a mesma lente como pendente', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Produto', 'unit' => 'un', 'active' => true]);
    insertLegacyLens($this->entity->id, ['entity_product_id' => $product->id, 'manufacturer' => 'Alcon']);

    $this->artisan('iol-lenses:migrate-to-stock', ['--force' => true])->assertSuccessful();

    $this->artisan('iol-lenses:migrate-to-stock')
        ->doesntExpectOutputToContain('já vinculada(s) manualmente com manufacturer/imagem faltando')
        ->assertSuccessful();
});

it('duas lentes DIFERENTES (fabricante/modelo/preço próprios) apontando pro MESMO entity_product_id (dado sujo pré-existente) — a mais antiga mantém o produto original, a outra recebe um produto PRÓPRIO com OS DADOS DELA (nunca funde, nunca clona o produto errado)', function () {
    $sharedProduct = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Produto original', 'unit' => 'un', 'active' => true]);

    $originalLensId = insertLegacyLens($this->entity->id, [
        'entity_product_id' => $sharedProduct->id,
        'manufacturer'      => 'Bausch+Lomb',
        'model_name'        => 'enVista',
        'created_at'        => now()->subMinute(),
    ]);

    $duplicateLensId = insertLegacyLens($this->entity->id, [
        'entity_product_id' => $sharedProduct->id,
        'manufacturer'      => 'Duplicata Clone',
        'model_name'        => 'Modelo X',
        'price'             => 999,
        'active'            => false,
        'created_at'        => now(),
    ]);

    $this->artisan('iol-lenses:migrate-to-stock')
        ->expectsOutputToContain('entity_product_id duplicado(s)')
        ->assertSuccessful();

    // Dry-run não resolveu nada ainda.
    expect(DB::table('entity_iol_lenses')->where('id', $duplicateLensId)->value('entity_product_id'))->toBe($sharedProduct->id);

    $this->artisan('iol-lenses:migrate-to-stock', ['--force' => true])->assertSuccessful();

    $originalLink   = DB::table('entity_iol_lenses')->where('id', $originalLensId)->value('entity_product_id');
    $duplicateLink  = DB::table('entity_iol_lenses')->where('id', $duplicateLensId)->value('entity_product_id');

    expect($originalLink)->toBe($sharedProduct->id);
    expect($duplicateLink)->not->toBe($sharedProduct->id);

    $duplicateProduct = EntityProduct::find($duplicateLink);
    expect($duplicateProduct->name)->toBe('Modelo X');
    expect($duplicateProduct->manufacturer)->toBe('Duplicata Clone');
    expect((float) $duplicateProduct->sale_price)->toBe(999.0);
    expect($duplicateProduct->active)->toBeFalse();

    // Sem duplicata restante — pré-condição da migration de aperto satisfeita.
    $remaining = DB::table('entity_iol_lenses')
        ->select('entity_product_id')
        ->whereNotNull('entity_product_id')
        ->groupBy('entity_product_id')
        ->havingRaw('COUNT(*) > 1')
        ->get();
    expect($remaining)->toHaveCount(0);
});
