<?php

declare(strict_types=1);

/**
 * Política v1.1 (LGPD art. 9º): nova versão publicada por comando — nunca
 * editando a 1.0 — e só com o Encarregado designado (art. 41; Res. CD/ANPD
 * nº 18/2024). Publicar desativa a 1.0 (o painel não pede novo aceite — G21).
 */

use App\Models\{TermVersion, User};
use Database\Seeders\PrivacyPolicySeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 11, 10));
    config([
        'legal.controller_name'    => 'Controladora Exemplo Ltda.',
        'legal.controller_cnpj'    => '00.000.000/0001-00',
        'legal.controller_address' => 'Endereço de teste',
        'legal.privacy_email'      => 'privacidade@example.test',
        'legal.privacy_phone'      => '+55 11 0000-0000',
        'legal.encarregado_name'   => null,
        'legal.encarregado_email'  => null,
        'legal.encarregado_phone'  => null,
    ]);
    $this->seed(PrivacyPolicySeeder::class);
    $this->publisher = User::factory()->create();
    $this->publish   = fn (array $options = []) => $this->artisan('legal:publish-privacy-policy', [
        'version'   => '1.1',
        '--by'      => $this->publisher->email,
        '--summary' => 'Encarregado, fontes próprias, provedores de IA e transferências internacionais.',
        ...$options,
    ]);
});

it('não publica a 1.1 sem Encarregado designado e mantém a 1.0 vigente', function (): void {
    ($this->publish)()
        ->expectsOutputToContain('Encarregado não designado')
        ->assertFailed();

    expect(TermVersion::query()->where('type', 'privacy_policy')->pluck('version')->all())->toBe(['1.0'])
        ->and(TermVersion::currentFor('privacy_policy')->version)->toBe('1.0');
});

it('publica a 1.1 com Encarregado, canal, tradução e sem Google Fonts; a 1.0 sai de vigência', function (): void {
    config(['legal.encarregado_name' => 'Maria Encarregada', 'legal.encarregado_email' => 'dpo@example.test']);

    ($this->publish)()->assertSuccessful();

    $current = TermVersion::currentFor('privacy_policy');

    expect($current->version)->toBe('1.1')
        ->and($current->created_by)->toBe($this->publisher->id)
        ->and($current->summary)->toContain('Encarregado')
        ->and($current->content)
        ->toContain('Maria Encarregada', 'dpo@example.test · +55 11 0000-0000', 'Controladora Exemplo Ltda.', '/privacidade#direitos-titular')
        ->not->toContain('{{')
        ->not->toContain('Google Fonts')
        ->and($current->translations['en'] ?? '')
        ->toContain('Maria Encarregada', 'address: Endereço de teste')
        ->not->toContain('{{')
        ->and(TermVersion::query()->where('type', 'privacy_policy')->where('version', '1.0')->value('active'))->toBeFalse();

    // Tradução com a mesma estrutura do oficial (mesmos blocos e seções).
    $blocks = fn (string $text) => count(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text))));
    expect($blocks($current->translations['en']))->toBe($blocks($current->content));

    $this->get('/privacidade')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document.version', '1.1')
            ->where('document.content', $current->content));
});

it('não republica nem altera uma versão já publicada', function (): void {
    config(['legal.encarregado_name' => 'Maria Encarregada']);
    ($this->publish)()->assertSuccessful();
    $original = TermVersion::currentFor('privacy_policy')->getAttributes();

    ($this->publish)()
        ->expectsOutputToContain('já foi publicada')
        ->assertFailed();

    expect(TermVersion::query()->where('type', 'privacy_policy')->count())->toBe(2)
        ->and(TermVersion::currentFor('privacy_policy')->getAttributes())->toBe($original);
});

it('exige quem publica e o resumo das mudanças', function (array $options, string $message): void {
    config(['legal.encarregado_name' => 'Maria Encarregada']);

    ($this->publish)($options)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(TermVersion::currentFor('privacy_policy')->version)->toBe('1.0');
})->with([
    'sem --by'            => [['--by' => null], 'Informe --by'],
    'usuário inexistente' => [['--by' => 'ninguem@example.test'], 'Informe --by'],
    'sem --summary'       => [['--summary' => ''], 'Informe --summary'],
]);
