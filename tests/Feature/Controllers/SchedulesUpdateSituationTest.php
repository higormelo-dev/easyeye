<?php

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Entity, Schedule, User};
use Illuminate\Support\Facades\Cache;

// ── fixtures ──────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user   = User::factory()->create();

    createEntityUser($this->entity, $this->user, ClientRule::Admin->value);

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, [
        'situation' => ScheduleSituation::Scheduled->value,
    ]);
});

/** Realiza PATCH autenticado com sessão da entity. */
function patchSituation($test, Schedule $schedule, int $situation): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->user)
        ->withSession(['selected_entity_id' => $test->entity->id])
        ->patchJson(route('panel.schedules.situation', $schedule), ['situation' => $situation]);
}

// ── happy path ────────────────────────────────────────────────────────────────

describe('PATCH /panel/schedules/{schedule}/situation', function () {
    it('atualiza situação com sucesso e retorna JSON', function () {
        patchSituation($this, $this->schedule, ScheduleSituation::Waiting->value)
            ->assertOk()
            ->assertJsonFragment(['situation' => ScheduleSituation::Waiting->value]);
    });

    it('resposta contém label da situação', function () {
        patchSituation($this, $this->schedule, ScheduleSituation::InProgress->value)
            ->assertOk()
            ->assertJsonFragment(['label' => ScheduleSituation::InProgress->label()]);
    });

    it('persiste nova situação no banco', function () {
        patchSituation($this, $this->schedule, ScheduleSituation::InProgress->value)->assertOk();

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::InProgress);
    });

    // ── arrived_at ────────────────────────────────────────────────────────────

    it('define arrived_at ao mover para Waiting', function () {
        $this->schedule->update(['arrived_at' => null]);

        patchSituation($this, $this->schedule, ScheduleSituation::Waiting->value)->assertOk();

        expect($this->schedule->fresh()->arrived_at)->not->toBeNull();
    });

    it('não define arrived_at para situações diferentes de Waiting', function () {
        $this->schedule->update(['arrived_at' => null]);

        patchSituation($this, $this->schedule, ScheduleSituation::InProgress->value)->assertOk();

        expect($this->schedule->fresh()->arrived_at)->toBeNull();
    });

    // ── cache ─────────────────────────────────────────────────────────────────

    it('invalida o cache waiting_room da entity', function () {
        $key = "waiting_room:{$this->entity->id}";
        Cache::put($key, ['active' => [], 'recently_called' => []], 60);

        patchSituation($this, $this->schedule, ScheduleSituation::Waiting->value)->assertOk();

        expect(Cache::has($key))->toBeFalse();
    });

    // ── auth ──────────────────────────────────────────────────────────────────

    it('retorna 302 para usuário não autenticado', function () {
        $this->patchJson(route('panel.schedules.situation', $this->schedule), ['situation' => 2])
            ->assertUnauthorized();
    });

    // ── validação ─────────────────────────────────────────────────────────────

    it('retorna 500 para situação com valor inválido', function () {
        // ScheduleSituation::from() lança ValueError para valores desconhecidos
        $this->withoutExceptionHandling();

        $this->expectException(\ValueError::class);

        patchSituation($this, $this->schedule, 99);
    });
});
