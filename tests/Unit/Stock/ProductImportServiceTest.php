<?php

declare(strict_types=1);

use App\Models\{Entity, EntityProduct, ProductCategory};
use App\Services\Stock\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

// tests/Unit não herda TestCase::class + RefreshDatabase de Pest.php (só
// 'Feature' herda) — mesmo padrão do resto de tests/Unit/Stock.
uses(TestCase::class, RefreshDatabase::class);

/**
 * Importação em massa de produtos via CSV (GAP fechado — revisão pós-Fase
 * 4, "melhorar o módulo de estoque") —
 * App\Services\Stock\ProductImportService.
 */
beforeEach(function () {
    $this->service = app(ProductImportService::class);
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
});

function makeCsv(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'import_test_') . '.csv';
    file_put_contents($path, $content);

    return new UploadedFile($path, 'produtos.csv', 'text/csv', null, true);
}

it('preview() aceita unidade pelo VALOR (un) ou pelo NOME por extenso (Unidade), case-insensitive', function () {
    $csv    = "nome;unidade\nProduto A;un\nProduto B;UNIDADE\nProduto C;Caixa";
    $result = $this->service->preview(makeCsv($csv), $this->entity->id);

    expect($result['errors'])->toBe([])
        ->and($result['valid'])->toHaveCount(3)
        ->and($result['valid'][0]['unit'])->toBe('un')
        ->and($result['valid'][1]['unit'])->toBe('un')
        ->and($result['valid'][2]['unit'])->toBe('cx');
});

it('preview() rejeita linha sem nome e linha com unidade inválida, mas aceita o resto do arquivo', function () {
    $csv    = "nome;unidade\n;un\nProduto Válido;un\nProduto C;unidade-que-nao-existe";
    $result = $this->service->preview(makeCsv($csv), $this->entity->id);

    expect($result['valid'])->toHaveCount(1)
        ->and($result['valid'][0]['name'])->toBe('Produto Válido')
        ->and($result['errors'])->toHaveCount(2)
        ->and($result['errors'][0]['reason'])->toContain('Nome')
        ->and($result['errors'][1]['reason'])->toContain('Unidade');
});

it('preview() rejeita SKU duplicado tanto contra o BANCO quanto ENTRE LINHAS do próprio arquivo', function () {
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Já existe', 'unit' => 'un', 'active' => true, 'sku' => 'SKU-1']);

    $csv    = "nome;unidade;sku\nContra o banco;un;SKU-1\nDuplicata A;un;SKU-2\nDuplicata B;un;SKU-2";
    $result = $this->service->preview(makeCsv($csv), $this->entity->id);

    expect($result['valid'])->toHaveCount(1)
        ->and($result['valid'][0]['name'])->toBe('Duplicata A')
        ->and($result['errors'])->toHaveCount(2);
});

it('preview() NÃO cruza SKU com OUTRA clínica (isolamento)', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    EntityProduct::create(['entity_id' => $otherEntity->id, 'name' => 'De outra clínica', 'unit' => 'un', 'active' => true, 'sku' => 'SKU-1']);

    $csv    = "nome;unidade;sku\nProduto local;un;SKU-1";
    $result = $this->service->preview(makeCsv($csv), $this->entity->id);

    expect($result['errors'])->toBe([])
        ->and($result['valid'])->toHaveCount(1);
});

it('preview() resolve categoria pelo NOME (case-insensitive) e marca category_not_found quando não existe', function () {
    $category = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'Lentes', 'active' => true]);

    $csv    = "nome;unidade;categoria\nA;un;lentes\nB;un;Categoria Inexistente\nC;un;";
    $result = $this->service->preview(makeCsv($csv), $this->entity->id);

    expect($result['valid'][0]['product_category_id'])->toBe($category->id)
        ->and($result['valid'][0]['category_not_found'])->toBeFalse()
        ->and($result['valid'][1]['product_category_id'])->toBeNull()
        ->and($result['valid'][1]['category_not_found'])->toBeTrue()
        ->and($result['valid'][2]['category_not_found'])->toBeFalse(); // sem categoria informada não é "não encontrada"
});

it('preview() aceita preço/mínimo/máximo em formato BR (vírgula decimal) e US (ponto)', function () {
    $csv    = "nome;unidade;preco_venda;estoque_minimo\nA;un;\"1.500,50\";5\nB;un;1500.50;10";
    $result = $this->service->preview(makeCsv($csv), $this->entity->id);

    expect($result['valid'][0]['sale_price'])->toBe(1500.5)
        ->and($result['valid'][1]['sale_price'])->toBe(1500.5);
});

it('preview() ignora linhas em branco no meio do arquivo', function () {
    $csv    = "nome;unidade\nA;un\n;\nB;un";
    $result = $this->service->preview(makeCsv($csv), $this->entity->id);

    expect($result['total'])->toBe(2)
        ->and($result['valid'])->toHaveCount(2);
});

it('[GAP] import() cria os produtos e ignora sku que virou duplicata ENTRE o preview e a confirmação', function () {
    $result = $this->service->import([
        ['name' => 'Produto A', 'unit' => 'un', 'sku' => 'SKU-A', 'barcode' => null, 'product_category_id' => null, 'sale_price' => null, 'min_qty' => 0, 'max_qty' => null],
    ], $this->entity->id);

    expect($result['created'])->toBe(1)
        ->and($result['skipped'])->toBe([]);

    // Concorrência simulada: entre o preview e agora, outra requisição já
    // usou o mesmo SKU — import() revalida e pula em vez de estourar erro
    // de unicidade do banco.
    $result2 = $this->service->import([
        ['name' => 'Produto A de novo', 'unit' => 'un', 'sku' => 'SKU-A', 'barcode' => null, 'product_category_id' => null, 'sale_price' => null, 'min_qty' => 0, 'max_qty' => null],
    ], $this->entity->id);

    expect($result2['created'])->toBe(0)
        ->and($result2['skipped'])->toHaveCount(1);

    expect(EntityProduct::where('entity_id', $this->entity->id)->count())->toBe(1);
});

it('[GAP][SEGURANÇA] import() ignora product_category_id adulterado apontando pra OUTRA clínica — produto entra sem categoria', function () {
    $otherEntity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherCategory = ProductCategory::create(['entity_id' => $otherEntity->id, 'name' => 'Categoria alheia', 'active' => true]);

    $result = $this->service->import([
        ['name' => 'Produto', 'unit' => 'un', 'sku' => null, 'barcode' => null, 'product_category_id' => $otherCategory->id, 'sale_price' => null, 'min_qty' => 0, 'max_qty' => null],
    ], $this->entity->id);

    expect($result['created'])->toBe(1);

    $product = EntityProduct::where('entity_id', $this->entity->id)->firstOrFail();
    expect($product->product_category_id)->toBeNull();
});

it('[GAP] import() ignora unidade inválida na confirmação em vez de derrubar com ValueError', function () {
    $result = $this->service->import([
        ['name' => 'Produto ruim', 'unit' => 'unidade-invalida', 'sku' => null, 'barcode' => null, 'product_category_id' => null, 'sale_price' => null, 'min_qty' => 0, 'max_qty' => null],
        ['name' => 'Produto bom', 'unit' => 'un', 'sku' => null, 'barcode' => null, 'product_category_id' => null, 'sale_price' => null, 'min_qty' => 0, 'max_qty' => null],
    ], $this->entity->id);

    expect($result['created'])->toBe(1)
        ->and($result['skipped'])->toHaveCount(1);
});
