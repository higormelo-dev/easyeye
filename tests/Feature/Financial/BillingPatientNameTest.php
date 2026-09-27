<?php

declare(strict_types=1);

/**
 * Nome do paciente no faturamento.
 *
 * Antes: o código lia `person->name`, mas App\Models\People só tem `full_name`.
 * Resultado: coluna Paciente sempre "—" nas abas do faturamento e, pior, TODA
 * guia TISS criada pelo faturamento saía com beneficiary_name nulo (XML sem
 * nomeBeneficiario, aviso BENEFICIARY_NAME_MISSING na pré-validação).
 */

use App\Domains\Tiss\Models\TissGuide;
use App\Enums\BillingClaimStatus;
use App\Models\{BillingClaim, Entity};
use App\Services\Financial\BillingService;

beforeEach(function (): void {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
    session(['selected_entity_id' => $this->entity->id]);
});

it('guia TISS individual nasce com o nome do beneficiário (full_name da pessoa)', function (): void {
    $schedule = createBillableSchedule($this->entity);

    $claim = app(BillingService::class)->createIndividual([
        'schedule_id'         => $schedule->id,
        'unit_price'          => 150,
        'tuss_code'           => '10101012',
        'clinical_indication' => 'H52.1',
    ]);

    $guide = TissGuide::query()->findOrFail($claim->tiss_guide_id);

    expect($guide->beneficiary_name)->not->toBeNull()
        ->and($guide->beneficiary_name)->toBe($schedule->patient->person->full_name);
});

it('guias TISS do lote também levam o nome do beneficiário', function (): void {
    $schedule = createBillableSchedule($this->entity);

    app(BillingService::class)->createBatch([
        'covenant_id' => $schedule->covenant_id,
        'date_from'   => $schedule->date_time->toDateString(),
        'date_until'  => $schedule->date_time->toDateString(),
        'unit_price'  => 150,
        'tuss_code'   => '10101012',
    ]);

    $claim = BillingClaim::query()->where('schedule_id', $schedule->id)->firstOrFail();

    expect(TissGuide::query()->findOrFail($claim->tiss_guide_id)->beneficiary_name)
        ->toBe($schedule->patient->person->full_name);
});

it('tela de faturamento mostra paciente e médico em "A faturar" e em "Guias" (antes: sempre "—")', function (): void {
    $pending = createBillableSchedule($this->entity);
    $billed  = createBillableSchedule($this->entity);

    $claim = BillingClaim::query()->create([
        'entity_id'       => $this->entity->id,
        'schedule_id'     => $billed->id,
        'patient_id'      => $billed->patient_id,
        'doctor_id'       => $billed->doctor_id,
        'covenant_id'     => $billed->covenant_id,
        'status'          => BillingClaimStatus::Submitted->value,
        'attendance_date' => $billed->date_time->toDateString(),
        'amount'          => 150,
        'quantity'        => 1,
        'unit_price'      => 150,
    ]);

    $this->get(route('panel.financial.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Fase 4: abas paginadas — as linhas ficam em `data`.
            ->where('eligibleSchedules.data', fn ($rows) => collect($rows)->firstWhere('id', $pending->id)['patient_name'] === $pending->patient->person->full_name
                && collect($rows)->firstWhere('id', $pending->id)['doctor_name'] === $pending->doctor->person->full_name)
            ->where('claims.data', fn ($rows) => collect($rows)->firstWhere('id', $claim->id)['patient_name'] === $billed->patient->person->full_name
                && collect($rows)->firstWhere('id', $claim->id)['doctor_name'] === $billed->doctor->person->full_name));
});
