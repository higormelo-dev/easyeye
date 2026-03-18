<?php

/**
 * Teste de aceitação: fluxo completo de pareamento de TV.
 *
 * Etapas cobertas:
 *  1. TV solicita acesso via POST /tv/request (código da empresa)
 *  2. Tela de espera fica acessível (GET /tv/wait/{id})
 *  3. Polling retorna status 'pending' enquanto aguarda
 *  4. Admin aprova via POST /panel/setting/tv-displays/{id}/approve
 *  5. Polling retorna status 'active' + redirect URL
 *  6. TV acessa GET /tv/{token} com sucesso
 *  7. TV revogada não acessa mais GET /tv/{token}
 */

use App\Enums\ClientRule;
use App\Models\{Entity, TvDisplay, User};

describe('Fluxo de pareamento de TV (end-to-end)', function () {
    it('percorre o ciclo completo: solicitação → aprovação → exibição → revogação', function () {
        // ── 1. Arrange: empresa e admin ──────────────────────────────────────
        $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $admin  = User::factory()->create();
        createEntityUser($entity, $admin, ClientRule::Admin->value);

        // ── 2. TV solicita acesso ────────────────────────────────────────────
        $requestResponse = $this->postJson(route('tv.request'), ['code' => $entity->code])
            ->assertOk()
            ->assertJsonStructure(['id', 'pin']);

        $tvId  = $requestResponse->json('id');
        $pin   = $requestResponse->json('pin');

        expect($pin)->toMatch('/^\d{6}$/');

        // ── 3. Tela de espera acessível ──────────────────────────────────────
        $this->get(route('tv.wait', $tvId))->assertOk();

        // ── 4. Polling retorna 'pending' ─────────────────────────────────────
        $this->getJson(route('tv.status', $tvId))
            ->assertOk()
            ->assertJsonFragment(['status' => 'pending']);

        // ── 5. Admin aprova ──────────────────────────────────────────────────
        $this->actingAs($admin)
            ->withSession(['selected_entity_id' => $entity->id])
            ->postJson(route('panel.setting.tv-displays.approve', $tvId), ['name' => 'Recepção'])
            ->assertOk();

        // ── 6. Polling retorna 'active' + redirect ───────────────────────────
        $statusResponse = $this->getJson(route('tv.status', $tvId))
            ->assertOk()
            ->assertJsonFragment(['status' => 'active'])
            ->assertJsonStructure(['status', 'redirect']);

        $redirect = $statusResponse->json('redirect');

        // ── 7. TV acessa a URL de exibição ───────────────────────────────────
        $tv = TvDisplay::find($tvId);
        expect($redirect)->toContain($tv->token);

        $this->get(route('tv.show', $tv->token))->assertOk();

        // ── 8. Tela de espera não está mais acessível (não é mais pending) ───
        $this->get(route('tv.wait', $tvId))->assertNotFound();

        // ── 9. Admin revoga ──────────────────────────────────────────────────
        $this->actingAs($admin)
            ->withSession(['selected_entity_id' => $entity->id])
            ->deleteJson(route('panel.setting.tv-displays.destroy', $tvId))
            ->assertOk();

        // ── 10. TV revogada não acessa mais ──────────────────────────────────
        $this->get(route('tv.show', $tv->token))->assertNotFound();
    });
});
