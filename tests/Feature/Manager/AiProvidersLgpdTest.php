<?php

use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Services\AiProviderSettings;
use App\Enums\AI\AiProvider;
use App\Enums\SaasRule;
use App\Models\{Entity, User};
use Illuminate\Support\Facades\DB;

/**
 * LGPD dos provedores de IA (Manager → Provedores de IA): provedor bloqueado
 * para dados de pacientes fora dos papéis do assistente, registro do
 * mecanismo de transferência internacional (art. 33) antes de levar dado de
 * paciente para fora do Brasil e filtro em execução real.
 */
beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create(['name' => 'Admin LGPD']);
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    foreach (AiProvider::cases() as $provider) {
        config()->set("services.{$provider->value}.api_key", "{$provider->value}-test-key-0000000000");
    }
    config()->set('ai.providers.azure_openai.base_url', 'https://easyeye-se.openai.azure.com/openai/v1');
    config()->set('ai.providers.azure_openai.data_region', 'eu');
    config()->set('ai.providers.maritaca.model', 'sabia-4-br-sp');

    $this->settings = app(AiProviderSettings::class);
    $this->settings->setRoleAssignments(['primary' => 'mistral', 'reviewer' => null, 'adjudicator' => null]);
});

function asLgpdAdmin(): mixed
{
    return test()->actingAs(test()->admin)->withSession([
        'selected_entity_id' => test()->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'admin',
    ]);
}

function lgpdRecord(array $overrides = []): array
{
    return ['mechanism' => 'standard_clauses', 'reference' => 'Aditivo CPC-ANPD ao DPA', 'signed_at' => '2026-09-15', ...$overrides];
}

/** @return array<string, array<string, mixed>> card LGPD de cada provedor na tela */
function lgpdCards(): array
{
    $cards = [];

    asLgpdAdmin()->get(route('manager.ai-providers.index'))->assertOk()
        ->assertInertia(function ($page) use (&$cards) {
            $cards = collect($page->toArray()['props']['providers'])->mapWithKeys(fn ($p) => [$p['code'] => $p['lgpd']])->all();
        });

    return $cards;
}

describe('papéis do assistente', function () {
    it('[LGPD] recusa provedor bloqueado para pacientes em qualquer papel', function (string $code, string $role) {
        $roles        = ['primary' => 'mistral', 'reviewer' => 'azure_openai', 'adjudicator' => 'maritaca'];
        $roles[$role] = $code;

        asLgpdAdmin()->patchJson(route('manager.ai-providers.update'), $roles)
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_ai.error_blocked_for_patients', ['providers' => AiProvider::from($code)->label()]));

        expect($this->settings->roleAssignments())->toBe(['primary' => 'mistral', 'reviewer' => null, 'adjudicator' => null]);
    })->with(['gemini'])->with(['primary', 'reviewer', 'adjudicator']);

    it('[LGPD] UE (Azure na Suécia, Mistral) e Brasil (Sabiá -br-sp) entram sem registro', function () {
        asLgpdAdmin()->patchJson(route('manager.ai-providers.update'), [
            'primary' => 'azure_openai', 'reviewer' => 'maritaca', 'adjudicator' => 'mistral',
        ])->assertOk();

        expect($this->settings->enabledCodes())->toBe(['azure_openai', 'maritaca', 'mistral']);
    });

    it('[LGPD] provedor que PASSA a levar dado aos EUA exige o mecanismo registrado antes', function () {
        asLgpdAdmin()->patchJson(route('manager.ai-providers.update'), ['primary' => 'mistral', 'reviewer' => 'anthropic'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_ai.error_transfer_record_required', ['providers' => AiProvider::Anthropic->label()]));

        asLgpdAdmin()->patchJson(route('manager.ai-providers.transfer.update', 'anthropic'), lgpdRecord())->assertOk();

        asLgpdAdmin()->patchJson(route('manager.ai-providers.update'), ['primary' => 'mistral', 'reviewer' => 'anthropic'])
            ->assertOk();

        expect($this->settings->roleAssignments()['reviewer'])->toBe('anthropic');
    });

    it('[LGPD] Azure declarado como global também exige o registro', function () {
        config()->set('ai.providers.azure_openai.data_region', 'global');

        asLgpdAdmin()->patchJson(route('manager.ai-providers.update'), ['primary' => 'azure_openai'])->assertStatus(422);
    });

    it('[LGPD] quem já estava num papel segue com aviso até o registro ("atual com aviso")', function () {
        $this->settings->setRoleAssignments(['primary' => 'openai', 'reviewer' => null, 'adjudicator' => null]);

        // Reordenar/adicionar com a OpenAI que já estava em uso não trava.
        asLgpdAdmin()->patchJson(route('manager.ai-providers.update'), ['primary' => 'mistral', 'reviewer' => 'openai'])
            ->assertOk();

        $cards = lgpdCards();
        expect($cards['openai']['pending'])->toBeTrue()
            ->and($cards['openai']['in_role'])->toBeTrue()
            ->and($cards['mistral']['pending'])->toBeFalse();

        asLgpdAdmin()->get(route('manager.ai-providers.index'))->assertInertia(fn ($page) => $page
            ->where('lgpd.mechanisms', ['standard_clauses', 'specific_clauses', 'corporate_rules'])
            ->where('lgpd.checked_at', fn ($date) => is_string($date) && $date !== ''));
    });

    it('[LGPD] Sabiá: trocar o modelo em uso para um sem "-br-sp" exige o registro', function () {
        $this->settings->setRoleAssignments(['primary' => 'maritaca', 'reviewer' => null, 'adjudicator' => null]);
        AiModelPrice::factory()->create(['provider' => 'maritaca', 'model' => 'sabia-4']);
        AiModelPrice::factory()->create(['provider' => 'maritaca', 'model' => 'sabiazinho-4-br-sp']);

        asLgpdAdmin()->patchJson(route('manager.ai-providers.model', 'maritaca'), ['model' => 'sabia-4'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_ai.error_transfer_record_required', ['providers' => AiProvider::Maritaca->label()]));
        asLgpdAdmin()->patchJson(route('manager.ai-providers.model', 'maritaca'), ['model' => 'sabiazinho-4-br-sp'])->assertOk();

        // Registro feito ANTES da troca (o painel mostra o formulário para a Maritaca).
        expect(lgpdCards()['maritaca']['can_record'])->toBeTrue();
        asLgpdAdmin()->patchJson(route('manager.ai-providers.transfer.update', 'maritaca'), lgpdRecord())->assertOk();
        asLgpdAdmin()->patchJson(route('manager.ai-providers.model', 'maritaca'), ['model' => 'sabia-4'])->assertOk();

        expect($this->settings->model('maritaca'))->toBe('sabia-4');
    });

    it('[LGPD] trocar o modelo de provedor que já transferia (OpenAI em uso) não trava', function () {
        $this->settings->setRoleAssignments(['primary' => 'openai', 'reviewer' => null, 'adjudicator' => null]);
        AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-4o']);

        asLgpdAdmin()->patchJson(route('manager.ai-providers.model', 'openai'), ['model' => 'gpt-4o'])->assertOk();
    });
});

describe('registro do mecanismo de transferência', function () {
    it('registra, mostra na tela com quem registrou e audita', function () {
        asLgpdAdmin()->patchJson(route('manager.ai-providers.transfer.update', 'openai'), lgpdRecord())
            ->assertOk()
            ->assertJsonPath('message', __('manager_ai.transfer_saved'));

        $record = $this->settings->transferRecords()['openai'];
        expect($record['mechanism'])->toBe('standard_clauses')
            ->and($record['reference'])->toBe('Aditivo CPC-ANPD ao DPA')
            ->and($record['signed_at'])->toBe('2026-09-15')
            ->and($record['registered_by'])->toBe((string) $this->admin->id);

        $card = lgpdCards()['openai'];
        expect($card['record']['registered_by'])->toBe('ADMIN LGPD') // User grava o nome em maiúsculas
            ->and($card['record']['signed_at_display'])->toBe(now()->setDate(2026, 9, 15)->isoFormat('L'))
            ->and($card['needs_record'])->toBeTrue()
            ->and($card['pending'])->toBeFalse();

        $audit = DB::table('audit_logs')->where('event', 'manager.ai_providers.transfer')->sole();
        expect(json_decode($audit->new_values, true)['provider'])->toBe('openai');
    });

    it('valida mecanismo, referência e data (não aceita data futura)', function (array $payload, string $field) {
        asLgpdAdmin()->patchJson(route('manager.ai-providers.transfer.update', 'openai'), lgpdRecord($payload))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        expect($this->settings->transferRecords())->toBe([]);
    })->with([
        'mecanismo fora da lista' => [['mechanism' => 'consent'], 'mechanism'],
        'referência vazia'        => [['reference' => ''], 'reference'],
        'referência longa'        => [['reference' => str_repeat('a', 256)], 'reference'],
        'data futura'             => [['signed_at' => now()->addDay()->toDateString()], 'signed_at'],
        'data inválida'           => [['signed_at' => '15/09/2026'], 'signed_at'],
    ]);

    it('provedor bloqueado para pacientes não tem transferência a registrar', function () {
        asLgpdAdmin()->patchJson(route('manager.ai-providers.transfer.update', 'gemini'), lgpdRecord())
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_ai.transfer_blocked_provider'));
    });

    it('remove só de provedor fora dos papéis (em uso, substitui) e audita', function () {
        $this->settings->setTransferRecord('openai', [...lgpdRecord(), 'registered_by' => null, 'registered_at' => null]);
        $this->settings->setRoleAssignments(['primary' => 'openai', 'reviewer' => null, 'adjudicator' => null]);

        asLgpdAdmin()->deleteJson(route('manager.ai-providers.transfer.destroy', 'openai'))
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_ai.transfer_remove_in_use'));

        $this->settings->setRoleAssignments(['primary' => 'mistral', 'reviewer' => null, 'adjudicator' => null]);

        asLgpdAdmin()->deleteJson(route('manager.ai-providers.transfer.destroy', 'openai'))->assertOk();

        expect($this->settings->transferRecords())->toBe([])
            ->and(DB::table('audit_logs')->where('event', 'manager.ai_providers.transfer_removed')->exists())->toBeTrue();

        // Sem registro: idempotente.
        asLgpdAdmin()->deleteJson(route('manager.ai-providers.transfer.destroy', 'openai'))->assertOk();
    });

    it('[SEGURANÇA] papel SaaS que não é admin não registra; provedor desconhecido é 404', function () {
        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);

        $res = test()->actingAs($support)->withSession([
            'selected_entity_id' => $this->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'support',
        ])->patchJson(route('manager.ai-providers.transfer.update', 'openai'), lgpdRecord());

        expect($res->status())->toBeIn([302, 403])
            ->and($this->settings->transferRecords())->toBe([]);

        asLgpdAdmin()->patchJson('/panel/manager/ai-providers/skynet/transfer', lgpdRecord())->assertNotFound();
    });
});

describe('execução real', function () {
    beforeEach(fn () => config()->set('ai.provider_runtime', 'real'));

    it('[LGPD] bloqueado que sobrou num papel salvo fica fora do assistente e o painel avisa', function () {
        $this->settings->setRoleAssignments(['primary' => 'openai', 'reviewer' => 'gemini', 'adjudicator' => null]);

        expect($this->settings->enabledCodes())->toBe(['openai']);

        $cards = lgpdCards();
        expect($cards['gemini']['blocked_in_role'])->toBeTrue()
            ->and($cards['gemini']['patients'])->toBeFalse()
            ->and($cards['gemini']['blocked_reason'])->toBe('gemini_terms');
    });

    it('[LGPD] o fallback do .env também nunca usa provedor bloqueado', function () {
        DB::table('system_settings')->whereIn('key', [AiProviderSettings::SETTING_KEY, AiProviderSettings::ROLES_SETTING_KEY])->delete();
        cache()->flush();
        config()->set('ai.providers.primary', 'gemini');
        config()->set('ai.providers.reviewer', 'skynet'); // código desconhecido também cai fora
        config()->set('ai.providers.adjudicator', 'gemini');

        expect($this->settings->enabledCodes())->toBe(['openai']);
    });

    it('com provedores fake (dev/testes) a lista salva é preservada', function () {
        config()->set('ai.provider_runtime', 'fake');
        $this->settings->setRoleAssignments(['primary' => 'openai', 'reviewer' => 'gemini', 'adjudicator' => null]);

        expect($this->settings->enabledCodes())->toBe(['openai', 'gemini']);
    });
});
