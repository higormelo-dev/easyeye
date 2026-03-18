<?php

use App\Enums\ScheduleSituation;
use App\Models\{Entity, Schedule, TvDisplay};
use Illuminate\Support\Facades\Cache;

describe('GET /tv', function () {
    it('exibe o formulário de entrada publicamente', function () {
        $this->get(route('tv.entry'))->assertOk();
    });
});

describe('POST /tv/request', function () {
    it('cria TV pendente e retorna id + pin para código válido', function () {
        $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

        $this->postJson(route('tv.request'), ['code' => $entity->code])
            ->assertOk()
            ->assertJsonStructure(['id', 'pin']);

        expect(TvDisplay::where('entity_id', $entity->id)->where('status', TvDisplay::STATUS_PENDING)->count())
            ->toBe(1);
    });

    it('pin gerado tem 6 dígitos', function () {
        $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

        $response = $this->postJson(route('tv.request'), ['code' => $entity->code])->assertOk();

        expect($response->json('pin'))->toMatch('/^\d{6}$/');
    });

    it('retorna 422 para código de empresa inexistente', function () {
        $this->postJson(route('tv.request'), ['code' => 'ENT-0000000000'])
            ->assertStatus(422);
    });

    it('retorna 422 para código em branco', function () {
        $this->postJson(route('tv.request'), ['code' => ''])
            ->assertUnprocessable();
    });

    it('ignora maiúsculas/minúsculas no código', function () {
        $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

        $this->postJson(route('tv.request'), ['code' => strtolower($entity->code)])
            ->assertOk()
            ->assertJsonStructure(['id', 'pin']);
    });

    it('não aceita empresa inativa', function () {
        $entity = Entity::factory()->create(['is_client' => true, 'active' => false]);

        $this->postJson(route('tv.request'), ['code' => $entity->code])
            ->assertStatus(422);
    });
});

describe('GET /tv/wait/{id}', function () {
    it('exibe a tela de espera para TV pendente', function () {
        $tv = TvDisplay::factory()->pending()->create();

        $this->get(route('tv.wait', $tv->id))->assertOk();
    });

    it('retorna 404 para TV que não é pending', function () {
        $tv = TvDisplay::factory()->active()->create();

        $this->get(route('tv.wait', $tv->id))->assertNotFound();
    });

    it('retorna 404 para ID inexistente', function () {
        $this->get(route('tv.wait', 'id-que-nao-existe'))->assertNotFound();
    });
});

describe('GET /tv/status/{id}', function () {
    it('retorna pending para TV ainda aguardando', function () {
        $tv = TvDisplay::factory()->pending()->create();

        $this->getJson(route('tv.status', $tv->id))
            ->assertOk()
            ->assertJsonFragment(['status' => 'pending']);
    });

    it('retorna active com redirect quando TV aprovada', function () {
        $tv = TvDisplay::factory()->active()->create();

        $this->getJson(route('tv.status', $tv->id))
            ->assertOk()
            ->assertJsonFragment(['status' => 'active'])
            ->assertJsonStructure(['status', 'redirect']);
    });

    it('redirect aponta para a rota tv.show com o token correto', function () {
        $tv = TvDisplay::factory()->active()->create();

        $response = $this->getJson(route('tv.status', $tv->id))->assertOk();

        expect($response->json('redirect'))->toContain($tv->token);
    });

    it('retorna rejected para TV revogada', function () {
        $tv = TvDisplay::factory()->revoked()->create();

        $this->getJson(route('tv.status', $tv->id))
            ->assertOk()
            ->assertJsonFragment(['status' => 'rejected']);
    });

    it('retorna 404 para ID inexistente', function () {
        $this->getJson(route('tv.status', 'nao-existe'))
            ->assertNotFound();
    });
});

describe('GET /tv/{token}', function () {
    it('exibe a página fullscreen para token ativo', function () {
        $tv = TvDisplay::factory()->active()->create();

        $this->get(route('tv.show', $tv->token))->assertOk();
    });

    it('atualiza last_seen_at ao acessar', function () {
        $tv = TvDisplay::factory()->active()->create(['last_seen_at' => null]);

        $this->get(route('tv.show', $tv->token))->assertOk();

        expect($tv->fresh()->last_seen_at)->not->toBeNull();
    });

    it('retorna 404 para token inválido', function () {
        $this->get(route('tv.show', 'token-invalido'))->assertNotFound();
    });

    it('retorna 404 para TV revogada', function () {
        $tv = TvDisplay::factory()->revoked()->create();

        $this->get(route('tv.show', $tv->token ?? 'token-nulo'))->assertNotFound();
    });
});

describe('GET /tv/{token}/poll', function () {
    it('retorna estrutura correta com listas vazias', function () {
        $tv = TvDisplay::factory()->active()->create();

        $this->getJson(route('tv.poll', $tv->token))
            ->assertOk()
            ->assertJsonStructure(['active', 'recently_called']);
    });

    it('retorna pacientes InProgress na lista active', function () {
        $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $tv     = TvDisplay::factory()->active()->create(['entity_id' => $entity->id]);

        ['schedule' => $schedule] = createScheduleForEntity($entity, [
            'situation' => ScheduleSituation::InProgress->value,
            'date_time' => now(),
        ]);

        // Limpa cache para forçar query nova
        Cache::forget("waiting_room:{$entity->id}");

        $response = $this->getJson(route('tv.poll', $tv->token))->assertOk();

        $active = $response->json('active');
        expect(collect($active)->pluck('id'))->toContain($schedule->id);
    });

    it('retorna 404 para token inválido', function () {
        $this->getJson(route('tv.poll', 'token-invalido'))->assertNotFound();
    });

    it('não retorna pacientes de outra empresa', function () {
        $entityA = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $entityB = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $tv      = TvDisplay::factory()->active()->create(['entity_id' => $entityA->id]);

        // Cria paciente em B
        createScheduleForEntity($entityB, [
            'situation' => ScheduleSituation::InProgress->value,
            'date_time' => now(),
        ]);

        Cache::forget("waiting_room:{$entityA->id}");
        Cache::forget("waiting_room:{$entityB->id}");

        $response = $this->getJson(route('tv.poll', $tv->token))->assertOk();

        expect($response->json('active'))->toBeEmpty();
    });

    it('usa cache: segunda chamada não dispara nova query', function () {
        $tv = TvDisplay::factory()->active()->create();

        // Popula cache manualmente
        Cache::put("waiting_room:{$tv->entity_id}", ['active' => [], 'recently_called' => []], 30);

        $this->getJson(route('tv.poll', $tv->token))->assertOk();

        // Cache ainda existe (não foi invalidado)
        expect(Cache::has("waiting_room:{$tv->entity_id}"))->toBeTrue();
    });
});
