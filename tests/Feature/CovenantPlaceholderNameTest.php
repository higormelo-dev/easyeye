<?php

/**
 * Convênio com nome "******" no select — placeholder da base aberta da ANS
 * para "sem nome fantasia" (ANS 423891, BENEFIT BENEFICIOS LTDA) que o
 * CovenantsSeeder copiava como nome.
 */

use App\Models\Covenant;
use Database\Seeders\CovenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('seeder usa a razão social quando o nome fantasia da ANS é placeholder de asteriscos', function () {
    $this->seed(CovenantsSeeder::class);

    $benefit = Covenant::withoutGlobalScopes()->where('ans_registry', '423891')->firstOrFail();

    expect($benefit->name)->toBe('BENEFIT BENEFICIOS LTDA');
    expect(Covenant::withoutGlobalScopes()->where('name', 'like', '%*%')->count())->toBe(0);
});

it('migration corrige convênio já gravado com nome "******" e não mexe nos demais', function () {
    $id = (string) Str::uuid7();
    DB::table('covenants')->insert([
        'id'           => $id,
        'code'         => 'CVP-9999999998',
        'name'         => '******',
        'company_name' => 'Benefit Beneficios Ltda',
        'table'        => true,
        'active'       => true,
    ]);
    $normal = Covenant::factory()->create(['name' => 'Unimed']);

    (require database_path('migrations/2026_09_25_110000_fix_placeholder_covenant_names.php'))->up();

    expect(DB::table('covenants')->where('id', $id)->value('name'))->toBe('BENEFIT BENEFICIOS LTDA');
    expect($normal->fresh()->name)->toBe('UNIMED');
});
