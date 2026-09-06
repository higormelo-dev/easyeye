<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, SkinType, User};

/**
 * Achado de segurança: BaseSettingController::destroy()/restore()/genericUpdate()
 * resolviam o registro via BaseSettingService::findByIdOrCode(), que casa
 * entity_id da sessão OU entity_id NULL (global, seedado pra todas as
 * clínicas) — correto para os paths de LEITURA (show/edit/fetchTableRows/
 * cards), mas os paths de ESCRITA reaproveitavam a mesma lookup sem checagem
 * adicional. Qualquer staff com permissão `settings.manage` (Role custom, não
 * necessariamente admin) conseguia PATCH/DELETE/restore um catálogo GLOBAL
 * (skin types, iris types, etc.) e corromper/apagar o registro compartilhado
 * por todas as outras clínicas da plataforma.
 *
 * Cobre com SkinTypesController (rota panel.setting.skintypes.*) como
 * catálogo concreto representativo — a correção fica na classe base
 * BaseSettingController::assertOwnsRecord(), usada por destroy/restore/
 * genericUpdate, então cobre os 12 catálogos filhos igualmente.
 */
beforeEach(function () {
    $this->entity      = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->staff           = User::factory()->create();
    $this->staffEntityUser = createEntityUser($this->entity, $this->staff, ClientRule::Admin->value);
});

function skinTypePayload(array $overrides = []): array
{
    return array_merge(['name' => 'Pele clara alterada', 'active' => true], $overrides);
}

describe('Catálogos globais (BaseSettingController) — escopo de tenant nas escritas', function () {
    it('[SEGURANÇA] rejeita PATCH em catálogo GLOBAL (entity_id null) com 404', function () {
        $global = SkinType::create(['entity_id' => null, 'code' => 'STPGLB1', 'name' => 'Pele global', 'active' => true]);

        $this->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->put(route('panel.setting.skintypes.update', $global->id), skinTypePayload(), ['Accept' => 'application/json'])
            ->assertNotFound();

        expect($global->fresh()->name)->toBe('PELE GLOBAL');
    });

    it('[SEGURANÇA] rejeita DELETE em catálogo GLOBAL (entity_id null) com 404', function () {
        $global = SkinType::create(['entity_id' => null, 'code' => 'STPGLB2', 'name' => 'Pele global', 'active' => true]);

        $this->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->delete(route('panel.setting.skintypes.destroy', $global->id), [], ['Accept' => 'application/json'])
            ->assertNotFound();

        expect($global->fresh())->not->toBeNull();
    });

    it('[SEGURANÇA] rejeita restore em catálogo GLOBAL (entity_id null) com 404', function () {
        $global = SkinType::create(['entity_id' => null, 'code' => 'STPGLB3', 'name' => 'Pele global', 'active' => true]);
        $global->delete();

        $this->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->get(route('panel.setting.skintypes.restore', $global->id), ['Accept' => 'application/json'])
            ->assertNotFound();

        expect($global->fresh()->trashed())->toBeTrue();
    });

    it('permite PATCH/DELETE no catálogo CUSTOM da PRÓPRIA clínica normalmente', function () {
        $own = SkinType::create(['entity_id' => $this->entity->id, 'code' => 'STOWN01', 'name' => 'Pele própria', 'active' => true]);

        $this->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->put(route('panel.setting.skintypes.update', $own->id), skinTypePayload(['name' => 'Pele própria editada']), ['Accept' => 'application/json'])
            ->assertOk();

        expect($own->fresh()->name)->toBe('PELE PRÓPRIA EDITADA');

        $this->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->delete(route('panel.setting.skintypes.destroy', $own->id), [], ['Accept' => 'application/json'])
            ->assertOk();

        expect($own->fresh()->trashed())->toBeTrue();
    });

    it('[SEGURANÇA] rejeita PATCH/DELETE em catálogo CUSTOM de OUTRA clínica com 404', function () {
        $foreign = SkinType::create(['entity_id' => $this->otherEntity->id, 'code' => 'STFRG01', 'name' => 'Pele de outra clínica', 'active' => true]);

        $this->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->put(route('panel.setting.skintypes.update', $foreign->id), skinTypePayload(), ['Accept' => 'application/json'])
            ->assertNotFound();

        $this->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->delete(route('panel.setting.skintypes.destroy', $foreign->id), [], ['Accept' => 'application/json'])
            ->assertNotFound();

        expect($foreign->fresh()->name)->toBe('PELE DE OUTRA CLÍNICA');
        expect($foreign->fresh()->trashed())->toBeFalse();
    });
});
