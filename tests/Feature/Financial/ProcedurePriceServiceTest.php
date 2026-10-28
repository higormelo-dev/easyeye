<?php

use App\Models\{AuditLog, User};
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

    // Regressão: a remoção é soft delete, mas o UNIQUE (entity_id, covenant_id,
    // procedure_id) não considera deleted_at. Antes, recadastrar o preço fazia
    // updateOrCreate → INSERT → violação 23505 → rollback do lote INTEIRO.
    it('recadastra um preço apagado restaurando a linha (sem violar o unique) e sem perder o lote', function () {
        $other = Procedure::factory()->create(['entity_id' => null, 'active' => true]);

        $this->service->syncForCovenant($this->entity->id, $this->covenant->id, [
            ['procedure_id' => $this->procedure->id, 'price' => 100.00, 'charging' => true],
        ]);
        $originalId = ProcedurePrice::query()->where('procedure_id', $this->procedure->id)->value('id');

        $this->service->syncForCovenant($this->entity->id, $this->covenant->id, [
            ['procedure_id' => $this->procedure->id, 'price' => null],
        ]);

        // Recoloca o preço apagado + um preço novo no MESMO lote.
        $this->service->syncForCovenant($this->entity->id, $this->covenant->id, [
            ['procedure_id' => $this->procedure->id, 'price' => 130.00, 'charging' => false],
            ['procedure_id' => $other->id, 'price' => 80.00, 'charging' => true],
        ]);

        $rows = ProcedurePrice::withTrashed()
            ->where('entity_id', $this->entity->id)
            ->where('covenant_id', $this->covenant->id)
            ->where('procedure_id', $this->procedure->id)
            ->get();

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->id)->toBe($originalId)
            ->and($rows->first()->trashed())->toBeFalse()
            ->and($rows->first()->deleted_by)->toBeNull()
            ->and((float) $rows->first()->price)->toBe(130.00)
            ->and($rows->first()->charging)->toBeFalse()
            ->and($this->service->getPrice($this->procedure->id, $this->covenant->id, $this->entity->id))->toBe(130.00)
            ->and($this->service->getPrice($other->id, $this->covenant->id, $this->entity->id))->toBe(80.00);
    });

    // Regressão: o DELETE era feito no query builder (sem eventos de model),
    // então Auditable não registrava a exclusão e deleted_by ficava vazio.
    it('remove o preço pelo model: grava deleted_by e registra a exclusão na auditoria', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->service->syncForCovenant($this->entity->id, $this->covenant->id, [
            ['procedure_id' => $this->procedure->id, 'price' => 100.00],
        ]);
        $priceId = ProcedurePrice::query()->where('procedure_id', $this->procedure->id)->value('id');

        $this->service->syncForCovenant($this->entity->id, $this->covenant->id, [
            ['procedure_id' => $this->procedure->id, 'price' => null],
        ]);

        $trashed = ProcedurePrice::withTrashed()->findOrFail($priceId);

        expect($trashed->trashed())->toBeTrue()
            ->and($trashed->deleted_by)->toBe($user->id)
            ->and(AuditLog::query()
                ->where('auditable_id', $priceId)
                ->where('event', 'deleted')
                ->exists())->toBeTrue();
    });
});
