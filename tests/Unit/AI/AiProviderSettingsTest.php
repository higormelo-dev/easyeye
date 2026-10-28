<?php

declare(strict_types=1);

use App\Domains\AI\Services\AiProviderSettings;
use App\Enums\AI\{AiProviderCallRole, AiRunMode};
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function aiSettingsSvc(): AiProviderSettings
{
    return new AiProviderSettings();
}

function cacheAiEnabled(array $codes): void
{
    Cache::put('subscription_setting:' . AiProviderSettings::SETTING_KEY, json_encode($codes), 600);
}

beforeEach(function () {
    config()->set('ai.enable_consensus', true);

    foreach (['openai', 'anthropic', 'gemini'] as $c) {
        config()->set("services.{$c}.api_key", 'k');
        config()->set("ai.providers.{$c}.model", "{$c}-model");
    }
});

describe('availableModes (oferta de modos por contagem)', function () {
    it('1 provedor -> apenas Economia', function () {
        cacheAiEnabled(['gemini']);
        expect(aiSettingsSvc()->availableModes())->toBe([AiRunMode::Economy]);
    });

    it('2 provedores -> piso Validado (Economia bloqueada)', function () {
        cacheAiEnabled(['gemini', 'openai']);
        expect(aiSettingsSvc()->availableModes())->toBe([AiRunMode::Validated]);
    });

    it('3 provedores -> Validado + Consenso', function () {
        cacheAiEnabled(['gemini', 'openai', 'anthropic']);
        expect(aiSettingsSvc()->availableModes())->toBe([AiRunMode::Validated, AiRunMode::Consensus]);
    });

    it('3 provedores com consenso desabilitado por config -> só Validado', function () {
        config()->set('ai.enable_consensus', false);
        cacheAiEnabled(['gemini', 'openai', 'anthropic']);
        expect(aiSettingsSvc()->availableModes())->toBe([AiRunMode::Validated]);
    });
});

describe('resolução de provedores', function () {
    it('providerCodesForMode corta ao número de provedores ativos', function () {
        cacheAiEnabled(['gemini', 'openai', 'anthropic']);
        $s = aiSettingsSvc();

        expect($s->providerCodesForMode(AiRunMode::Economy))->toBe(['gemini'])
            ->and($s->providerCodesForMode(AiRunMode::Validated))->toBe(['gemini', 'openai'])
            ->and($s->providerCodesForMode(AiRunMode::Consensus))->toBe(['gemini', 'openai', 'anthropic']);
    });

    it('roleCode faz clamp quando há menos provedores que papéis', function () {
        cacheAiEnabled(['gemini']);
        $s = aiSettingsSvc();

        expect($s->roleCode(AiProviderCallRole::Generator))->toBe('gemini')
            ->and($s->roleCode(AiProviderCallRole::Reviewer))->toBe('gemini')
            ->and($s->roleCode(AiProviderCallRole::Adjudicator))->toBe('gemini');
    });

    it('fallbackOrder rotaciona a lista de prioridade pelo papel', function () {
        cacheAiEnabled(['openai', 'anthropic', 'gemini']);
        $s = aiSettingsSvc();

        expect($s->fallbackOrder(AiProviderCallRole::Generator))->toBe(['openai', 'anthropic', 'gemini'])
            ->and($s->fallbackOrder(AiProviderCallRole::Reviewer))->toBe(['anthropic', 'gemini', 'openai'])
            ->and($s->fallbackOrder(AiProviderCallRole::Adjudicator))->toBe(['gemini', 'openai', 'anthropic']);
    });

    it('ignora códigos desconhecidos e deduplica preservando a ordem', function () {
        cacheAiEnabled(['gemini', 'xpto', 'gemini', 'openai']);
        expect(aiSettingsSvc()->enabledCodes())->toBe(['gemini', 'openai']);
    });

    it('cai para a ordem de config quando o setting está ausente', function () {
        Cache::forget('subscription_setting:' . AiProviderSettings::SETTING_KEY);
        config()->set('ai.providers.primary', 'gemini');
        config()->set('ai.providers.reviewer', 'openai');
        config()->set('ai.providers.adjudicator', 'anthropic');

        expect(aiSettingsSvc()->enabledCodes())->toBe(['gemini', 'openai', 'anthropic']);
    });
});

describe('isConfigured', function () {
    it('true quando há api_key e modelo; false sem credencial', function () {
        config()->set('services.openai.api_key', null);
        $s = aiSettingsSvc();

        expect($s->isConfigured('gemini'))->toBeTrue()
            ->and($s->isConfigured('openai'))->toBeFalse();
    });
});
