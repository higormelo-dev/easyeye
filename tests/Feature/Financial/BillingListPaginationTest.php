<?php

declare(strict_types=1);

use App\Enums\{BillingBatchStatus, BillingClaimStatus, ScheduleSituation};
use App\Models\{BillingBatch, BillingClaim, Covenant, Entity, Patient, People, Schedule};
use App\Services\Financial\BillingService;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

/*
 * Fase 4 — as três abas do Faturamento paginam no servidor (substitui o teto
 * "mostrando X de N"): página própria por aba, busca (paciente sem acento,
 * GUI, LOT, nº TISS; %, _ e \ literais) e ordenação por whitelist — sempre
 * escopadas pela clínica. KPIs continuam sobre o conjunto filtrado inteiro.
 */

beforeEach(function (): void {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

function blpPatient(Entity $entity, Covenant $covenant, string $name): Patient
{
    return Patient::factory()->create([
        'entity_id'   => $entity->id,
        'covenant_id' => $covenant->id,
        'person_id'   => People::factory()->create(['full_name' => $name])->id,
    ]);
}

/**
 * Agendamento faturável com paciente de nome FIXO: o nome aleatório do Faker
 * (ex.: "… Souza", "João …") casava com as buscas por paciente e fazia o teste
 * falhar de vez em quando.
 */
function blpBillableSchedule(Entity $entity, string $name): Schedule
{
    $schedule = createBillableSchedule($entity, ['full_name' => $name]);
    $schedule->patient->person->update(['full_name' => $name]);

    return $schedule;
}

/** @param array<string, mixed> $overrides */
function blpClaim(Entity $entity, Covenant $covenant, array $overrides = []): BillingClaim
{
    return BillingClaim::query()->create(array_merge([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant->id,
        'status'          => BillingClaimStatus::Submitted->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 100.00,
        'quantity'        => 1,
        'unit_price'      => 100.00,
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function blpBatch(Entity $entity, Covenant $covenant, array $overrides = []): BillingBatch
{
    return BillingBatch::query()->create(array_merge([
        'entity_id'    => $entity->id,
        'covenant_id'  => $covenant->id,
        'status'       => BillingBatchStatus::Draft->value,
        'period_start' => now()->toDateString(),
        'period_end'   => now()->toDateString(),
        'issued_at'    => now(),
    ], $overrides));
}

/** Atendimentos elegíveis (Atendido, sem guia) de um paciente. */
function blpSchedules(Entity $entity, Covenant $covenant, Patient $patient, int $count): Collection
{
    $doctor = createDoctorForEntity($entity);

    return collect(range(1, $count))->map(fn (int $i): Schedule => Schedule::create([
        'entity_id'   => $entity->id,
        'doctor_id'   => $doctor->id,
        'patient_id'  => $patient->id,
        'covenant_id' => $covenant->id,
        'full_name'   => 'AGENDADO SEM CADASTRO',
        'date_time'   => now()->startOfDay()->addMinutes($i),
        'situation'   => ScheduleSituation::Attended->value,
        'active'      => true,
    ]));
}

/** @return array<string, mixed> */
function blpPage(TestResponse $response, string $prop): array
{
    return $response->viewData('page')['props'][$prop];
}

/** @return list<string> */
function blpIds(TestResponse $response, string $prop): array
{
    return collect(blpPage($response, $prop)['data'])->pluck('id')->all();
}

function blpIndex(array $query = []): TestResponse
{
    return test()->get(route('panel.financial.billing.index', $query))->assertOk();
}

describe('paginação por aba', function (): void {
    it('cada aba pagina sozinha, com links que levam a URL inteira e a própria aba; KPIs do conjunto todo', function (): void {
        collect(range(1, 55))->each(fn () => blpClaim($this->entity, $this->covenant));
        collect(range(1, 27))->each(fn () => blpBatch($this->entity, $this->covenant));
        blpSchedules($this->entity, $this->covenant, blpPatient($this->entity, $this->covenant, 'PACIENTE ELEGIVEL'), 52);

        $response = blpIndex(['claims_page' => 2, 'batches_page' => 2, 'tab' => 'claims', 'claims_search' => 'GUI']);

        $claims   = blpPage($response, 'claims');
        $batches  = blpPage($response, 'batches');
        $eligible = blpPage($response, 'eligibleSchedules');

        expect($claims['current_page'])->toBe(2)
            ->and($claims['last_page'])->toBe(2)
            ->and($claims['total'])->toBe(55)
            ->and($claims['data'])->toHaveCount(5)
            ->and($batches['current_page'])->toBe(2)
            ->and($batches['data'])->toHaveCount(2)
            ->and($batches['total'])->toBe(27)
            ->and($eligible['current_page'])->toBe(1)
            ->and($eligible['data'])->toHaveCount(50)
            ->and($eligible['total'])->toBe(52);

        // Links: nome de página próprio, filtros/busca preservados e a aba da lista.
        $claimsPrev   = $claims['prev_page_url'];
        $eligibleNext = $eligible['next_page_url'];

        expect($claimsPrev)->toContain('claims_page=1')
            ->and($claimsPrev)->toContain('claims_search=GUI')
            ->and($claimsPrev)->toContain('batches_page=2')
            ->and($claimsPrev)->toContain('tab=claims')
            ->and($eligibleNext)->toContain('eligible_page=2')
            ->and($eligibleNext)->toContain('claims_page=2')
            ->and($eligibleNext)->toContain('tab=eligible')
            ->and($eligibleNext)->not->toContain('tab=claims');

        // KPIs e contagens das abas não dependem da página.
        $response->assertInertia(fn ($page) => $page
            ->where('kpis.open.count', 55)
            ->where('kpis.to_bill.count', 52)
            ->where('totals.claims', 55)
            ->where('totals.batches', 27)
            ->where('totals.eligible', 52)
            ->missing('limits'));
    });

    it('página inválida, fora do fim ou em array não vira 500', function (): void {
        blpClaim($this->entity, $this->covenant);

        expect(blpPage(blpIndex(['claims_page' => 'abc']), 'claims')['current_page'])->toBe(1)
            ->and(blpPage(blpIndex(['claims_page' => ['1']]), 'claims')['current_page'])->toBe(1)
            ->and(blpPage(blpIndex(['claims_page' => -3]), 'claims')['current_page'])->toBe(1)
            ->and(blpPage(blpIndex(['claims_page' => 99]), 'claims')['data'])->toBe([]);
    });
});

describe('busca por aba', function (): void {
    it('guias: paciente sem acento, código GUI, código LOT e nº da guia TISS — só da clínica', function (): void {
        $joao  = blpPatient($this->entity, $this->covenant, 'João da Silva');
        $maria = blpPatient($this->entity, $this->covenant, 'Maria Souza');
        $batch = blpBatch($this->entity, $this->covenant);

        $byPatient = blpClaim($this->entity, $this->covenant, ['patient_id' => $joao->id]);
        $inBatch   = blpClaim($this->entity, $this->covenant, ['patient_id' => $maria->id, 'batch_id' => $batch->id]);
        $tiss      = app(BillingService::class)->createIndividual([
            'schedule_id' => blpBillableSchedule($this->entity, 'Paciente Guia Tiss')->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1',
        ]);

        // Outra clínica com o mesmo nome e códigos parecidos: nunca aparece.
        $other      = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirCov   = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]);
        $theirJoao  = blpPatient($other, $theirCov, 'João da Silva');
        $theirClaim = blpClaim($other, $theirCov, ['patient_id' => $theirJoao->id]);

        expect(blpIds(blpIndex(['claims_search' => 'joao silva']), 'claims'))->toBe([])
            ->and(blpIds(blpIndex(['claims_search' => 'joão da']), 'claims'))->toBe([$byPatient->id])
            ->and(blpIds(blpIndex(['claims_search' => 'JOAO']), 'claims'))->toBe([$byPatient->id])
            ->and(blpIds(blpIndex(['claims_search' => $inBatch->code]), 'claims'))->toBe([$inBatch->id])
            ->and(blpIds(blpIndex(['claims_search' => mb_strtolower($batch->code)]), 'claims'))->toBe([$inBatch->id])
            ->and(blpIds(blpIndex(['claims_search' => $tiss->tissGuide->guide_number_provider]), 'claims'))->toBe([$tiss->id])
            ->and(blpIds(blpIndex(['claims_search' => 'joão']), 'claims'))->not->toContain($theirClaim->id);

        // A contagem da aba acompanha a busca; o KPI, não.
        blpIndex(['claims_search' => 'joão'])->assertInertia(fn ($page) => $page
            ->where('totals.claims', 1)
            ->where('kpis.open.count', 2)
            ->where('lists.claims.search', 'joão'));
    });

    it('lotes: código LOT, nº do lote TISS ou uma guia do lote (GUI, paciente)', function (): void {
        $maria = blpPatient($this->entity, $this->covenant, 'Maria Souza');
        $plain = blpBatch($this->entity, $this->covenant);
        $withC = blpBatch($this->entity, $this->covenant);
        $claim = blpClaim($this->entity, $this->covenant, ['patient_id' => $maria->id, 'batch_id' => $withC->id]);

        $schedule = blpBillableSchedule($this->entity, 'Paciente Lote Tiss');
        $tissLot  = app(BillingService::class)->createBatch([
            'covenant_id'         => $schedule->covenant_id,
            'date_from'           => now()->subDay()->toDateString(),
            'date_until'          => now()->toDateString(),
            'unit_price'          => 150,
            'clinical_indication' => 'H40.1',
        ]);

        expect(blpIds(blpIndex(['batches_search' => $plain->code]), 'batches'))->toBe([$plain->id])
            ->and(blpIds(blpIndex(['batches_search' => $tissLot->tissBatch->batch_number]), 'batches'))->toBe([$tissLot->id])
            ->and(blpIds(blpIndex(['batches_search' => $claim->code]), 'batches'))->toBe([$withC->id])
            ->and(blpIds(blpIndex(['batches_search' => 'souza']), 'batches'))->toBe([$withC->id]);
    });

    it('a faturar: pelo nome do paciente (cadastro) sem acento', function (): void {
        $ines   = blpPatient($this->entity, $this->covenant, 'Inês Araújo');
        $bob    = blpPatient($this->entity, $this->covenant, 'Roberto Lima');
        [$hers] = blpSchedules($this->entity, $this->covenant, $ines, 1)->all();
        blpSchedules($this->entity, $this->covenant, $bob, 2);

        expect(blpIds(blpIndex(['eligible_search' => 'ines araujo']), 'eligibleSchedules'))->toBe([$hers->id]);
    });

    it('%, _ e \\ são literais (não curinga) e não viram 500', function (): void {
        $underscore = blpClaim($this->entity, $this->covenant, ['patient_id' => blpPatient($this->entity, $this->covenant, 'Ana_Maria')->id]);
        blpClaim($this->entity, $this->covenant, ['patient_id' => blpPatient($this->entity, $this->covenant, 'AnaXMaria')->id]);
        $percent = blpClaim($this->entity, $this->covenant, ['patient_id' => blpPatient($this->entity, $this->covenant, 'Desconto 50% Maria')->id]);
        blpClaim($this->entity, $this->covenant, ['patient_id' => blpPatient($this->entity, $this->covenant, 'Desconto 500 Maria')->id]);

        expect(blpIds(blpIndex(['claims_search' => 'a_m']), 'claims'))->toBe([$underscore->id])
            ->and(blpIds(blpIndex(['claims_search' => '50%']), 'claims'))->toBe([$percent->id])
            ->and(blpIds(blpIndex(['claims_search' => '%']), 'claims'))->toBe([$percent->id])
            ->and(blpIds(blpIndex(['claims_search' => '_']), 'claims'))->toBe([$underscore->id])
            ->and(blpIds(blpIndex(['claims_search' => '\\']), 'claims'))->toBe([])
            ->and(blpIds(blpIndex(['batches_search' => '%']), 'batches'))->toBe([]);
    });

    it('busca em array ou longa demais não vira 500 (vazia / cortada em 100 caracteres)', function (): void {
        blpClaim($this->entity, $this->covenant);

        blpIndex(['claims_search' => ['x'], 'eligible_search' => str_repeat('a', 300)])
            ->assertInertia(fn ($page) => $page
                ->where('lists.claims.search', '')
                ->where('lists.eligible.search', str_repeat('a', 100))
                ->where('totals.claims', 1));
    });
});

describe('ordenação (whitelist no servidor)', function (): void {
    it('guias por valor, atendimento e paciente; desempate estável', function (): void {
        $b   = blpClaim($this->entity, $this->covenant, ['amount' => 300, 'attendance_date' => now()->subDays(2)->toDateString(), 'patient_id' => blpPatient($this->entity, $this->covenant, 'Carla')->id]);
        $a   = blpClaim($this->entity, $this->covenant, ['amount' => 100, 'attendance_date' => now()->toDateString(), 'patient_id' => blpPatient($this->entity, $this->covenant, 'Bruno')->id]);
        $c   = blpClaim($this->entity, $this->covenant, ['amount' => 200, 'attendance_date' => now()->subDay()->toDateString(), 'patient_id' => blpPatient($this->entity, $this->covenant, 'Diego')->id]);
        $old = now()->subDays(2)->startOfMonth()->toDateString();

        expect(blpIds(blpIndex(['from' => $old, 'claims_sort' => 'amount', 'claims_direction' => 'asc']), 'claims'))->toBe([$a->id, $c->id, $b->id])
            ->and(blpIds(blpIndex(['from' => $old, 'claims_sort' => 'amount', 'claims_direction' => 'desc']), 'claims'))->toBe([$b->id, $c->id, $a->id])
            ->and(blpIds(blpIndex(['from' => $old, 'claims_sort' => 'attendance', 'claims_direction' => 'asc']), 'claims'))->toBe([$b->id, $c->id, $a->id])
            // Padrão: mais recentes primeiro (criação desc).
            ->and(blpIds(blpIndex(['from' => $old]), 'claims'))->toBe([$c->id, $a->id, $b->id]);

        // Paciente: nomes sem acento — a posição de "Á" depende da collation do
        // banco (C no CI, pt_BR no PostgreSQL local) e fazia o teste falhar.
        expect(blpIds(blpIndex(['from' => $old, 'claims_sort' => 'patient', 'claims_direction' => 'asc']), 'claims'))->toBe([$a->id, $b->id, $c->id])
            ->and(blpIds(blpIndex(['from' => $old, 'claims_sort' => 'patient', 'claims_direction' => 'desc']), 'claims'))->toBe([$c->id, $b->id, $a->id]);
    });

    it('lotes por total e período; a faturar por convênio', function (): void {
        $small = blpBatch($this->entity, $this->covenant, ['total_amount' => 10]);
        $big   = blpBatch($this->entity, $this->covenant, ['total_amount' => 999]);

        expect(blpIds(blpIndex(['batches_sort' => 'total', 'batches_direction' => 'desc']), 'batches'))->toBe([$big->id, $small->id])
            ->and(blpIds(blpIndex(['batches_sort' => 'total', 'batches_direction' => 'asc']), 'batches'))->toBe([$small->id, $big->id]);

        $zeta  = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'ZETA SAUDE']);
        $alpha = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'ALFA SAUDE']);
        [$z]   = blpSchedules($this->entity, $zeta, blpPatient($this->entity, $zeta, 'Paciente Z'), 1)->all();
        [$l]   = blpSchedules($this->entity, $alpha, blpPatient($this->entity, $alpha, 'Paciente A'), 1)->all();

        expect(blpIds(blpIndex(['eligible_sort' => 'covenant', 'eligible_direction' => 'asc']), 'eligibleSchedules'))->toBe([$l->id, $z->id]);
    });

    it('chave ou direção fora da whitelist voltam ao padrão da aba (sem 500 e sem SQL injetado)', function (): void {
        blpClaim($this->entity, $this->covenant);

        blpIndex([
            'claims_sort'        => 'amount; drop table billing_claims',
            'claims_direction'   => 'sideways',
            'batches_sort'       => ['total'],
            'eligible_sort'      => 'patient',
            'eligible_direction' => 'DESC',
        ])->assertInertia(fn ($page) => $page
            ->where('lists.claims.sort', 'created')
            ->where('lists.claims.direction', 'desc')
            ->where('lists.batches.sort', 'created')
            ->where('lists.eligible.sort', 'patient')
            ->where('lists.eligible.direction', 'asc')
            ->where('lists.claims.default_sort', 'created')
            ->where('lists.claims.default_direction', 'desc')
            ->where('totals.claims', 1));
    });
});
