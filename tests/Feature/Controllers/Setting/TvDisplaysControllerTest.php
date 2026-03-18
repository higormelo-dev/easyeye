<?php

use App\Enums\ClientRule;
use App\Models\{Entity, TvDisplay, User};

// ── fixtures ──────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user   = User::factory()->create();

    createEntityUser($this->entity, $this->user, ClientRule::Admin->value);
});

/** Realiza request autenticado com sessão da entity. */
function actingOnEntity($test, string $method, string $route, array $data = []): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->user)
        ->withSession(['selected_entity_id' => $test->entity->id])
        ->{$method}($route, $data);
}

// ── POST /panel/setting/tv-displays ──────────────────────────────────────────

describe('POST /panel/setting/tv-displays', function () {
    it('cria display ativo com token e retorna 201', function () {
        actingOnEntity($this, 'postJson', route('panel.setting.tv-displays.store'), ['name' => 'Recepção'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'url']]);

        expect(TvDisplay::where('entity_id', $this->entity->id)
            ->where('status', TvDisplay::STATUS_ACTIVE)
            ->where('name', 'Recepção')
            ->exists()
        )->toBeTrue();
    });

    it('token gerado tem 48 caracteres', function () {
        $response = actingOnEntity($this, 'postJson', route('panel.setting.tv-displays.store'), ['name' => 'Sala 1'])
            ->assertCreated();

        $tv = TvDisplay::where('entity_id', $this->entity->id)->latest()->first();
        expect(strlen($tv->token))->toBe(48);
    });

    it('retorna 422 sem nome', function () {
        actingOnEntity($this, 'postJson', route('panel.setting.tv-displays.store'), [])
            ->assertUnprocessable();
    });

    it('requer autenticação', function () {
        $this->postJson(route('panel.setting.tv-displays.store'), ['name' => 'Sala'])
            ->assertUnauthorized();
    });
});

// ── POST /panel/setting/tv-displays/{tvDisplay}/approve ──────────────────────

describe('POST /panel/setting/tv-displays/{tvDisplay}/approve', function () {
    it('aprova TV pendente e define status active', function () {
        $tv = TvDisplay::factory()->pending()->create(['entity_id' => $this->entity->id]);

        actingOnEntity(
            $this,
            'postJson',
            route('panel.setting.tv-displays.approve', $tv),
            ['name' => 'Recepção Aprovada']
        )->assertOk();

        $tv->refresh();
        expect($tv->status)->toBe(TvDisplay::STATUS_ACTIVE);
        expect($tv->token)->not->toBeNull();
        expect($tv->pin)->toBeNull();
    });

    it('retorna 422 ao tentar aprovar TV já ativa', function () {
        $tv = TvDisplay::factory()->active()->create(['entity_id' => $this->entity->id]);

        actingOnEntity(
            $this,
            'postJson',
            route('panel.setting.tv-displays.approve', $tv),
            ['name' => 'Display Ativo']
        )->assertUnprocessable();
    });

    it('retorna 403 quando TV pertence a outra entity', function () {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $tv    = TvDisplay::factory()->pending()->create(['entity_id' => $other->id]);

        actingOnEntity(
            $this,
            'postJson',
            route('panel.setting.tv-displays.approve', $tv),
            ['name' => 'Invasão']
        )->assertForbidden();
    });

    it('retorna 422 ao aprovar sem nome', function () {
        $tv = TvDisplay::factory()->pending()->create(['entity_id' => $this->entity->id]);

        actingOnEntity($this, 'postJson', route('panel.setting.tv-displays.approve', $tv), [])
            ->assertUnprocessable();
    });
});

// ── DELETE /panel/setting/tv-displays/{tvDisplay} ────────────────────────────

describe('DELETE /panel/setting/tv-displays/{tvDisplay}', function () {
    it('revoga e remove o display', function () {
        $tv = TvDisplay::factory()->active()->create(['entity_id' => $this->entity->id]);

        actingOnEntity($this, 'deleteJson', route('panel.setting.tv-displays.destroy', $tv))
            ->assertOk();

        expect(TvDisplay::withTrashed()->find($tv->id)->status)->toBe(TvDisplay::STATUS_REVOKED);
        expect(TvDisplay::find($tv->id))->toBeNull();
    });

    it('retorna 403 quando TV pertence a outra entity', function () {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $tv    = TvDisplay::factory()->active()->create(['entity_id' => $other->id]);

        actingOnEntity($this, 'deleteJson', route('panel.setting.tv-displays.destroy', $tv))
            ->assertForbidden();
    });

    it('requer autenticação', function () {
        $tv = TvDisplay::factory()->active()->create(['entity_id' => $this->entity->id]);

        $this->deleteJson(route('panel.setting.tv-displays.destroy', $tv))
            ->assertUnauthorized();
    });
});
