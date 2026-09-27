<?php

declare(strict_types=1);

use App\Models\TermVersion;
use Database\Seeders\PrivacyPolicySeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 9, 27));
});

it('publica a política com a identificação configurada na página pública', function (): void {
    config([
        'legal.controller_name'    => 'Clínica Exemplo Ltda.',
        'legal.controller_cnpj'    => '00.000.000/0001-00',
        'legal.controller_address' => 'Endereço de teste',
        'legal.privacy_email'      => 'privacidade@example.test',
        'legal.privacy_phone'      => '+55 11 0000-0000',
    ]);

    $this->seed(PrivacyPolicySeeder::class);

    $document = TermVersion::currentFor('privacy_policy');
    expect($document)->not->toBeNull()
        ->and($document->content)->toContain('Clínica Exemplo Ltda.', '00.000.000/0001-00', 'Endereço de teste', 'privacidade@example.test', '+55 11 0000-0000')
        ->not->toContain('{{');

    $this->get('/privacidade')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Site/Legal')
            ->where('kind', 'privacy')
            ->where('document.version', '1.0')
            ->where('document.content', $document->content));

    expect(TermVersion::query()->where('type', 'terms_of_service')->exists())->toBeFalse();
});

it('não modifica um documento publicado quando o seeder roda novamente', function (): void {
    $this->seed(PrivacyPolicySeeder::class);
    $original = TermVersion::currentFor('privacy_policy')->getAttributes();

    config(['legal.privacy_email' => 'outro@example.test']);
    $this->travel(1)->day();
    $this->seed(PrivacyPolicySeeder::class);

    expect(TermVersion::query()->count())->toBe(1)
        ->and(TermVersion::currentFor('privacy_policy')->getAttributes())->toBe($original);
});

it('preserva políticas existentes inclusive as inativas e futuras', function (bool $active, string $effectiveFrom): void {
    $existing = TermVersion::query()->create([
        'type'           => 'privacy_policy',
        'version'        => '2.0',
        'content'        => 'Documento já cadastrado.',
        'effective_from' => $effectiveFrom,
        'active'         => $active,
    ]);
    $original = $existing->fresh()->getAttributes();

    $this->seed(PrivacyPolicySeeder::class);

    expect(TermVersion::query()->count())->toBe(1)
        ->and($existing->fresh()->getAttributes())->toBe($original);
})->with([
    'vigente' => [true, '2026-09-01'],
    'inativa' => [false, '2026-09-01'],
    'futura'  => [true, '2026-10-01'],
]);
