<?php

use App\Models\{Covenant, Entity, Procedure, ProcedurePrice};
use App\Services\Financial\ProcedurePriceService;

beforeEach(function () {
    $this->service   = app(ProcedurePriceService::class);
    $this->entity    = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant  = Covenant::factory()->create(['entity_id' => null, 'active' => true]);
    $this->procedure = Procedure::factory()->create(['entity_id' => null, 'active' => true]);
});

describe('ProcedurePriceService::getPrice', function () {
    it('retorna null quando não há preço cadastrado', function () {
        expect($this->service->getPrice($this->procedure->id, $this->covenant->id, $this->entity->id))
            ->toBeNull();
    });

    it('retorna o preço global (entity_id null) como fallback', function () {
        ProcedurePrice::factory()->create([
            'entity_id'    => null,
            'covenant_id'  => $this->covenant->id,
            'procedure_id' => $this->procedure->id,
            'price'        => 150.00,
        ]);

        expect($this->service->getPrice($this->procedure->id, $this->covenant->id, $this->entity->id))
            ->toBe(150.00);
    });

    it('a linha da entidade sobrepõe a global', function () {
        ProcedurePrice::factory()->create([
            'entity_id'    => null,
            'covenant_id'  => $this->covenant->id,
            'procedure_id' => $this->procedure->id,
            'price'        => 150.00,
        ]);
        ProcedurePrice::factory()->create([
            'entity_id'    => $this->entity->id,
            'covenant_id'  => $this->covenant->id,
            'procedure_id' => $this->procedure->id,
            'price'        => 220.00,
        ]);

        expect($this->service->getPrice($this->procedure->id, $this->covenant->id, $this->entity->id))
            ->toBe(220.00);
    });

    it('ignora preço inativo', function () {
        ProcedurePrice::factory()->create([
            'entity_id'    => $this->entity->id,
            'covenant_id'  => $this->covenant->id,
            'procedure_id' => $this->procedure->id,
            'price'        => 99.00,
            'active'       => false,
        ]);

        expect($this->service->getPrice($this->procedure->id, $this->covenant->id, $this->entity->id))
            ->toBeNull();
    });

    it('retorna null se procedimento ou convênio forem nulos', function () {
        expect($this->service->getPrice(null, $this->covenant->id, $this->entity->id))->toBeNull()
            ->and($this->service->getPrice($this->procedure->id, null, $this->entity->id))->toBeNull();
    });
});

describe('ProcedurePriceService::syncForCovenant + priceMap', function () {
    it('faz upsert dos preços e remove os vazios', function () {
        $this->service->syncForCovenant($this->entity->id, $this->covenant->id, [
            ['procedure_id' => $this->procedure->id, 'price' => 175.50, 'charging' => true],
        ]);

        $map = $this->service->priceMap($this->entity->id);
        expect($map["{$this->covenant->id}:{$this->procedure->id}"])->toBe(175.50);

        // Preço vazio remove a linha.
        $this->service->syncForCovenant($this->entity->id, $this->covenant->id, [
            ['procedure_id' => $this->procedure->id, 'price' => null],
        ]);

        expect($this->service->getPrice($this->procedure->id, $this->covenant->id, $this->entity->id))
            ->toBeNull();
    });
});
