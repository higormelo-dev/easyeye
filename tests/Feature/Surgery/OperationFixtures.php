<?php

declare(strict_types=1);

use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\{EntityProduct, StockLot};
use App\Models\Surgery\SurgicalCase;
use App\Services\IolLensStockBridgeService;
use App\Services\Stock\StockService;
use App\Services\Surgery\{SurgeryAuthorizationService, SurgicalCaseService};
use Carbon\Carbon;
use Tests\TestCase;

/*
 * Cenários compartilhados dos testes do M4 (cirurgia realizada, implante,
 * descrição). Funções globais com prefixo `sop` (Pest carrega todos os
 * arquivos no mesmo processo: nome repetido quebra a suíte).
 */

/** Caso AGENDADO hoje às 08:00, autorização dispensada (particular). */
function sopCase(object $w, array $create = [], array $state = []): SurgicalCase
{
    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, array_merge([
        'patient_id'        => (string) $w->patient->id,
        'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'             => 'Facoemulsificação com LIO',
        'eye'               => 'od',
        'regime'            => 'ambulatory',
        'urgency'           => 'elective',
        'days_requested'    => 1,
        'procedures'        => [['description' => 'Facoemulsificação com implante de LIO', 'tuss_code' => '30306019', 'quantity' => 1]],
    ], $create));

    app(SurgeryAuthorizationService::class)->record($case, ['type' => 'waived', 'note' => 'Cirurgia particular']);

    $case->refresh()->forceFill(array_merge([
        'status'          => SurgicalCaseStatus::Scheduled,
        'scheduled_start' => now()->setTime(8, 0),
        'scheduled_end'   => now()->setTime(9, 0),
    ], $state))->save();

    return $case->refresh();
}

/**
 * Lente IOL do estoque da clínica (produto + lote com validade e saldo).
 *
 * @return array{0: EntityProduct, 1: StockLot}
 */
function sopIol(object $w, string $lotNumber = 'L-001', string $expiry = '+6 months', float $qty = 5, string $name = 'LIO Monofocal'): array
{
    $lens    = app(IolLensStockBridgeService::class)->create((string) $w->entity->id, ['model_name' => $name, 'manufacturer' => 'ACME']);
    $product = $lens->entityProduct;

    return [$product->fresh(), sopLot($product, $lotNumber, $expiry, $qty)];
}

function sopLot(EntityProduct $product, string $number, string $expiry = '+6 months', float $qty = 5): StockLot
{
    $stock = app(StockService::class);
    $lot   = $stock->findOrCreateLot($product, $number, Carbon::parse($expiry));

    if ($qty > 0) {
        $stock->manualIn($product, $qty, 100.00, lot: $lot);
    }

    return $lot->fresh();
}

/** Material OPM comum (anel, sem série obrigatória). @return array{0: EntityProduct, 1: StockLot} */
function sopOpm(object $w, float $qty = 5, bool $serial = false): array
{
    $product = EntityProduct::create([
        'entity_id' => $w->entity->id, 'name' => 'Anel de tensão capsular', 'manufacturer' => 'RINGCO', 'unit' => 'un',
        'is_opm'    => true, 'requires_lot' => true, 'requires_serial' => $serial, 'active' => true,
    ]);

    return [$product, sopLot($product, 'R-100', '+1 year', $qty)];
}

/** @return array<string, mixed> */
function sopImplantPayload(EntityProduct $product, ?StockLot $lot, array $over = []): array
{
    return array_merge([
        'source'              => 'clinic_stock',
        'eye'                 => 'od',
        'entity_product_id'   => (string) $product->id,
        'stock_lot_id'        => $lot ? (string) $lot->id : null,
        'serial_number'       => 'SN-0001',
        'power'               => 21.5,
        'anvisa_registration' => '80123456789',
    ], $over);
}

/** Sessão de OUTRO médico da mesma clínica (sem relação com o caso). */
function sopOtherDoctor(object $w): TestCase
{
    $doctor = createDoctorForEntity($w->entity);
    $eu     = $doctor->entityUser;

    return test()->actingAs($eu->user)->withSession(panelSession($eu));
}

/** POST do registro de implante, como o perfil `$who` da clínica do mundo cirúrgico. */
function sopStore(object $t, $case, array $payload, string $who = 'doctor')
{
    return surgeryAs($t->w, $who)->postJson(route('panel.surgery.operation.implants.store', $case), $payload);
}
