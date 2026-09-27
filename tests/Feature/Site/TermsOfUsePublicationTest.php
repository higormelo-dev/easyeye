<?php

declare(strict_types=1);

use App\Models\{TermVersion, UserTermAcceptance};
use Database\Seeders\{PrivacyPolicySeeder, TermsOfUseSeeder};
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 9, 27));
});

it('publica os termos com os contatos configurados sem alterar a política de privacidade', function (): void {
    $this->seed(PrivacyPolicySeeder::class);
    $privacy = TermVersion::currentFor('privacy_policy')->getAttributes();

    config([
        'legal.controller_name'    => 'Empresa Exemplo Ltda.',
        'legal.controller_cnpj'    => '00.000.000/0001-00',
        'legal.controller_address' => 'Endereço de teste',
        'legal.terms_email'        => 'contratos@example.test',
        'legal.terms_phone'        => '+55 11 0000-0000',
        'mail.support_address'     => 'suporte@example.test',
    ]);

    $this->seed(TermsOfUseSeeder::class);

    $document = TermVersion::currentFor('terms_of_service');
    expect($document)->not->toBeNull()
        ->and($document->content)->toContain('Empresa Exemplo Ltda.', '00.000.000/0001-00', 'Endereço de teste', 'contratos@example.test', '+55 11 0000-0000', 'suporte@example.test')
        ->not->toContain('{{');

    $this->get('/termos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Site/Legal')
            ->where('kind', 'terms')
            ->where('document.version', '1.0')
            ->where('document.content', $document->content)
            ->where('t.legal.contents', 'Neste documento'));

    expect(TermVersion::currentFor('privacy_policy')->getAttributes())->toBe($privacy)
        ->and(UserTermAcceptance::query()->count())->toBe(0);
});

it('não reescreve os termos publicados ao executar novamente com outra configuração', function (): void {
    $this->seed(TermsOfUseSeeder::class);
    $original = TermVersion::currentFor('terms_of_service')->getAttributes();

    config(['legal.terms_email' => 'outro@example.test']);
    $this->travel(1)->day();
    $this->seed(TermsOfUseSeeder::class);

    expect(TermVersion::query()->count())->toBe(1)
        ->and(TermVersion::currentFor('terms_of_service')->getAttributes())->toBe($original);
});

it('preserva termos já cadastrados inclusive inativos ou futuros', function (bool $active, string $effectiveFrom): void {
    $existing = TermVersion::query()->create([
        'type'           => 'terms_of_service',
        'version'        => '2.0',
        'content'        => 'Condições já cadastradas.',
        'effective_from' => $effectiveFrom,
        'active'         => $active,
    ]);
    $original = $existing->fresh()->getAttributes();

    $this->seed(TermsOfUseSeeder::class);

    expect(TermVersion::query()->count())->toBe(1)
        ->and($existing->fresh()->getAttributes())->toBe($original);
})->with([
    'vigentes' => [true, '2026-09-01'],
    'inativos' => [false, '2026-09-01'],
    'futuros'  => [true, '2026-10-01'],
]);
