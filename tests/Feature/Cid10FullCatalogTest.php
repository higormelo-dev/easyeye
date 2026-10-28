<?php

use App\Enums\ClientRule;
use App\Models\{Cid10Code, Entity, User};
use App\Services\Cid10CatalogImporter;
use Database\Seeders\Cid10CodesSeeder;

/**
 * Lista completa da CID-10 (DATASUS) no catálogo global, carregada pela
 * migration 2026_10_09 (bancos existentes e instalação nova), sem perder a
 * curadoria oftalmológica do Cid10CodesSeeder.
 */
function cidFullDoctor(): array
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $doctor = User::factory()->create();

    return [$doctor, createEntityUser($entity, $doctor, ClientRule::Doctor->value)];
}

function cidFullSearch(object $test, string $q): array
{
    [$doctor, $entityUser] = cidFullDoctor();

    return $test->actingAs($doctor)
        ->withSession(panelSession($entityUser))
        ->getJson(route('panel.cid10.search', ['q' => $q]))
        ->assertOk()
        ->json('*.code');
}

it('o banco tem todos os 12.451 códigos oficiais, no formato com ponto', function () {
    expect(Cid10Code::count())->toBeGreaterThanOrEqual(12451)
        // subcategoria de outro capítulo, categoria sem subdivisão e a descrição mais longa (264 caracteres)
        ->and(Cid10Code::where('code', 'A00.0')->value('description'))->toBe('Cólera devida a Vibrio cholerae 01, biótipo cholerae')
        ->and(Cid10Code::where('code', 'A09')->exists())->toBeTrue()
        ->and(Cid10Code::where('code', 'A090')->exists())->toBeFalse()
        ->and(Cid10Code::query()->whereRaw('LENGTH(description) > 255')->exists())->toBeTrue()
        ->and(Cid10Code::where('code', 'A00.0')->value('category'))->toBe('Doenças infecciosas intestinais');
});

it('mantém as aspas literais da descrição oficial (ex.: "Flutter")', function () {
    expect(Cid10Code::where('code', 'I48')->value('description'))->toBe('"Flutter" e fibrilação atrial')
        ->and(Cid10Code::where('code', 'Z73.3')->value('description'))->toBe('"Stress" não classificado em outra parte');
});

it('descrição OFICIAL em todo código da lista; a categoria curada de oftalmologia continua', function () {
    // A seleção escrita à mão apontava para outra doença nestes (ex.: H50.5
    // aparecia como "Estrabismo paralítico").
    expect(Cid10Code::where('code', 'H50.5')->sole()->only('description', 'category'))
        ->toBe(['description' => 'Heteroforia', 'category' => 'Motilidade e Refração'])
        ->and(Cid10Code::where('code', 'B00.3')->value('description'))->toBe('Meningite devida ao vírus do herpes')
        ->and(Cid10Code::where('code', 'Q14.2')->value('description'))->toBe('Malformação congênita do disco óptico')
        ->and(Cid10Code::where('code', 'H00.1')->sole()->only('description', 'category'))
        ->toBe(['description' => 'Calázio', 'category' => 'Pálpebras'])
        ->and(Cid10Code::where('code', 'Z01.0')->value('category'))->toBe('Exames e Acompanhamento');
});

it('importar de novo é idempotente: não duplica, corrige texto divergente e não toca código fora da lista oficial', function () {
    Cid10Code::where('code', 'H50.5')->update(['description' => 'Estrabismo paralítico']);
    Cid10Code::where('code', 'B30')->update(['description' => 'Conjuntivite viral (curada)']);
    $total = Cid10Code::count();

    $result = app(Cid10CatalogImporter::class)->import();
    $again  = app(Cid10CatalogImporter::class)->import();

    expect($result)->toBe(['read' => 12451, 'inserted' => 0, 'corrected' => 1, 'official_updated' => 0, 'kept_edited' => 0, 'skipped' => 0])
        ->and($again['corrected'])->toBe(0)
        ->and(Cid10Code::count())->toBe($total)
        ->and(Cid10Code::where('code', 'H50.5')->value('description'))->toBe('Heteroforia')
        ->and(Cid10Code::where('code', 'B30')->value('description'))->toBe('Conjuntivite viral (curada)');
});

it('banco antigo só com a seleção oftalmológica recebe o restante e as descrições corrigidas pela migration', function () {
    Cid10Code::where('code', 'not like', 'H%')->where('category', '!=', 'Exames e Acompanhamento')->delete();
    Cid10Code::where('code', 'H25.2')->update(['description' => 'Catarata senil polar posterior']);
    expect(Cid10Code::where('code', 'A00.0')->exists())->toBeFalse();

    (require database_path('migrations/2026_10_09_000000_load_full_cid10_catalog.php'))->up();

    expect(Cid10Code::where('code', 'A00.0')->exists())->toBeTrue()
        ->and(Cid10Code::where('code', 'H00.1')->value('description'))->toBe('Calázio')
        ->and(Cid10Code::where('code', 'H25.2')->value('description'))->toBe('Catarata senil tipo Morgagni');
});

it('busca acha código de qualquer capítulo, por código e por nome sem acento', function () {
    expect(cidFullSearch($this, 'J45'))->toContain('J45.0')
        ->and(cidFullSearch($this, 'colera'))->toContain('A00.0')
        ->and(cidFullSearch($this, 'diabetes'))->not->toBeEmpty();
});

it('na busca por nome, o capítulo do olho (H00–H59) vem antes dos outros', function () {
    // "conjuntivite" também existe fora do capítulo do olho (ex.: B30
    // conjuntivite viral, A-infecciosas), que em ordem alfabética viriam antes.
    expect(Cid10Code::search('conjuntivite')->where('code', '<', 'H00')->exists())->toBeTrue();

    $codes = cidFullSearch($this, 'conjuntivite');
    $isEye = fn (string $c) => $c >= 'H00' && $c < 'H60';
    $first = collect($codes)->search(fn ($c) => ! $isEye($c));

    expect($isEye($codes[0]))->toBeTrue()
        // nenhum código do olho depois do primeiro de outro capítulo
        ->and($first === false ? [] : collect($codes)->slice($first)->filter($isEye)->all())->toBe([]);
});
