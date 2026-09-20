<?php

declare(strict_types=1);

use App\Domains\Tiss\Models\{TissBatch, TissGlosa, TissGuide, TissOperator, TissProtocol, TissReturn};
use App\Domains\Tiss\Services\ProcessGlosaReturnService;
use App\Models\Entity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('keeps two glosas with the same code separate when no guide can be identified in a multi-guide batch', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $operator = TissOperator::query()->create([
        'ans_code' => '326305',
        'name'     => 'Operadora Teste',
        'active'   => true,
    ]);

    $batch = TissBatch::query()->create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOT-TESTE-0001',
        'reference_month' => now()->format('Y-m'),
        'status'          => 'sent',
    ]);

    // Lote com MAIS DE UMA guia — condição necessária pra ambiguidade em
    // resolveGuide() quando o retorno não traz guide_number_provider casando.
    $guideA = TissGuide::query()->create([
        'entity_id'             => $entity->id,
        'operator_id'           => $operator->id,
        'guide_type'            => 'consultation',
        'guide_number_provider' => 'GUI-0001',
        'status'                => 'sent',
        'attendance_date'       => now()->toDateString(),
        'total_amount'          => 150,
    ]);

    $guideB = TissGuide::query()->create([
        'entity_id'             => $entity->id,
        'operator_id'           => $operator->id,
        'guide_type'            => 'consultation',
        'guide_number_provider' => 'GUI-0002',
        'status'                => 'sent',
        'attendance_date'       => now()->toDateString(),
        'total_amount'          => 200,
    ]);

    foreach ([$guideA, $guideB] as $guide) {
        DB::table('tiss_batch_guides')->insert([
            'id'          => (string) Str::uuid(),
            'entity_id'   => $entity->id,
            'batch_id'    => $batch->id,
            'guide_id'    => $guide->id,
            'attached_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    $protocol = TissProtocol::query()->create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_id'        => $batch->id,
        'protocol_number' => 'PRT-TESTE-0001',
        'status'          => 'received',
    ]);

    $return = TissReturn::query()->create([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
        'protocol_id' => $protocol->id,
        'return_type' => 'batch_return',
        'status'      => 'parsed',
        'received_at' => now(),
    ]);

    // Duas glosas com o MESMO código, nenhuma com guide_number_provider —
    // antes do fix, a segunda updateOrCreate() sobrescrevia a primeira.
    $glosas = [
        [
            'code'                  => '3099',
            'description'           => 'Primeira glosa sem guia identificada',
            'amount'                => 50.0,
            'guide_number_provider' => null,
            'procedure_code'        => null,
        ],
        [
            'code'                  => '3099',
            'description'           => 'Segunda glosa sem guia identificada',
            'amount'                => 75.0,
            'guide_number_provider' => null,
            'procedure_code'        => null,
        ],
    ];

    $result = app(ProcessGlosaReturnService::class)->process($return, $glosas);

    expect($result['count'])->toBe(2)
        ->and($result['total_denied'])->toBe(125.0);

    $stored = TissGlosa::query()->where('return_id', $return->id)->get();

    expect($stored)->toHaveCount(2)
        ->and($stored->pluck('glosa_code')->unique()->all())->toBe(['3099'])
        ->and($stored->pluck('raw_hash')->filter()->unique())->toHaveCount(2)
        ->and((float) $stored->sum('amount'))->toBe(125.0);
});

it('still uses the plain key (no raw_hash) when a single guide in the batch resolves unambiguously', function (): void {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $operator = TissOperator::query()->create([
        'ans_code' => '326306',
        'name'     => 'Operadora Teste 2',
        'active'   => true,
    ]);

    $batch = TissBatch::query()->create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOT-TESTE-0002',
        'reference_month' => now()->format('Y-m'),
        'status'          => 'sent',
    ]);

    $guide = TissGuide::query()->create([
        'entity_id'             => $entity->id,
        'operator_id'           => $operator->id,
        'guide_type'            => 'consultation',
        'guide_number_provider' => 'GUI-0003',
        'status'                => 'sent',
        'attendance_date'       => now()->toDateString(),
        'total_amount'          => 150,
    ]);

    DB::table('tiss_batch_guides')->insert([
        'id'          => (string) Str::uuid(),
        'entity_id'   => $entity->id,
        'batch_id'    => $batch->id,
        'guide_id'    => $guide->id,
        'attached_at' => now(),
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    $protocol = TissProtocol::query()->create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_id'        => $batch->id,
        'protocol_number' => 'PRT-TESTE-0002',
        'status'          => 'received',
    ]);

    $return = TissReturn::query()->create([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
        'protocol_id' => $protocol->id,
        'return_type' => 'batch_return',
        'status'      => 'parsed',
        'received_at' => now(),
    ]);

    $result = app(ProcessGlosaReturnService::class)->process($return, [[
        'code'        => '3099',
        'description' => 'Glosa com guia única no lote',
        'amount'      => 50.0,
    ]]);

    expect($result['count'])->toBe(1);

    $glosa = TissGlosa::query()->where('return_id', $return->id)->firstOrFail();

    expect($glosa->guide_id)->toBe($guide->id)
        ->and($glosa->raw_hash)->toBeNull();
});
