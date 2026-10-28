<?php

declare(strict_types=1);

use App\Domains\AI\Support\ProviderDataPolicy;
use App\Enums\AI\AiProvider;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * Política LGPD por provedor de IA (dados de pacientes): onde processa, base
 * da transferência internacional e bloqueio para uso clínico.
 */
it('[LGPD] bloqueia para pacientes só a Gemini API (termos)', function () {
    $blocked = array_values(array_filter(
        AiProvider::cases(),
        static fn (AiProvider $p) => ! ProviderDataPolicy::allowsPatientData($p),
    ));

    expect($blocked)->toBe([AiProvider::Gemini])
        ->and(ProviderDataPolicy::profile(AiProvider::Gemini)['blocked_reason'])->toBe('gemini_terms')
        // DeepSeek e OpenRouter saíram da lista pronta.
        ->and(AiProvider::tryFrom('deepseek'))->toBeNull()
        ->and(AiProvider::tryFrom('openrouter'))->toBeNull();
});

it('[LGPD] provedor dos EUA exige mecanismo registrado; UE (adequação) e bloqueados não', function () {
    expect(ProviderDataPolicy::requiresTransferRecord(AiProvider::OpenAI, 'gpt-4o'))->toBeTrue()
        ->and(ProviderDataPolicy::requiresTransferRecord(AiProvider::Anthropic, null))->toBeTrue()
        // Anthropic: inferência "global" por padrão — sem garantia de ficar nos EUA.
        ->and(ProviderDataPolicy::profile(AiProvider::Anthropic)['location'])->toBe(ProviderDataPolicy::LOCATION_ANY)
        ->and(ProviderDataPolicy::requiresTransferRecord(AiProvider::Mistral, 'mistral-large-latest'))->toBeFalse()
        ->and(ProviderDataPolicy::profile(AiProvider::Mistral)['transfer'])->toBe(ProviderDataPolicy::TRANSFER_ADEQUACY)
        // Bloqueado não "exige registro": não pode receber paciente de jeito nenhum.
        ->and(ProviderDataPolicy::requiresTransferRecord(AiProvider::Gemini, null))->toBeFalse();
});

it('[LGPD] Azure segue a região declarada do deployment (UE padrão, Brasil, global)', function (?string $region, string $location, bool $needsRecord) {
    config()->set('ai.providers.azure_openai.data_region', $region);

    expect(ProviderDataPolicy::profile(AiProvider::AzureOpenAI)['location'])->toBe($location)
        ->and(ProviderDataPolicy::requiresTransferRecord(AiProvider::AzureOpenAI, 'gpt-4o-mini'))->toBe($needsRecord);
})->with([
    'UE (Suécia, Standard regional)' => ['eu', ProviderDataPolicy::LOCATION_EU, false],
    'Brasil (Provisioned)'           => ['br', ProviderDataPolicy::LOCATION_BR, false],
    'Global / Data Zone'             => ['global', ProviderDataPolicy::LOCATION_ANY, true],
    'valor desconhecido'             => ['us-east', ProviderDataPolicy::LOCATION_ANY, true],
    'não declarado'                  => [null, ProviderDataPolicy::LOCATION_ANY, true],
]);

it('[LGPD] Maritaca: "-br-sp" processa no Brasil; sem sufixo exige registro', function () {
    expect(ProviderDataPolicy::profile(AiProvider::Maritaca, 'sabia-4-br-sp')['location'])->toBe(ProviderDataPolicy::LOCATION_BR)
        ->and(ProviderDataPolicy::requiresTransferRecord(AiProvider::Maritaca, 'sabiazinho-4-br-sp'))->toBeFalse()
        ->and(ProviderDataPolicy::requiresTransferRecord(AiProvider::Maritaca, 'sabia-4'))->toBeTrue()
        // Algum modelo exige: o painel deixa registrar ANTES de trocar o modelo.
        ->and(ProviderDataPolicy::mayRequireTransferRecord(AiProvider::Maritaca))->toBeTrue();
});

it('todo provedor tem fonte oficial em https', function () {
    foreach (AiProvider::cases() as $provider) {
        $sources = ProviderDataPolicy::sources($provider);

        expect($sources)->not->toBeEmpty();

        foreach ($sources as $url) {
            expect($url)->toStartWith('https://');
        }
    }
});
