<?php

declare(strict_types=1);

use App\Models\{TermVersion, User, UserTermAcceptance};
use App\Services\TermsService;
use Illuminate\Database\QueryException;

/** Publica uma versão direto na tabela (os testes rodam sem seed): vigente desde ontem, salvo `$effectiveFrom`. */
function termsVersion(string $type, string $version = '1.0', ?string $effectiveFrom = null, bool $active = true): TermVersion
{
    return TermVersion::query()->create([
        'type'           => $type,
        'version'        => $version,
        'content'        => "Texto de {$type} v{$version}",
        'effective_from' => $effectiveFrom ?? now()->subDay()->toDateString(),
        'active'         => $active,
    ]);
}

it('accept() grava a versão aceita, a data, o IP e o navegador — e não duplica o aceite da mesma versão', function () {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 5));
    $user    = User::factory()->create();
    $version = termsVersion('terms_of_service');

    $this->withHeader('User-Agent', 'Navegador/9.9')->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->get('/');

    $acceptance = app(TermsService::class)->accept($user, $version);

    expect($acceptance->user_id)->toBe($user->id)
        ->and($acceptance->term_version_id)->toBe($version->id)
        ->and($acceptance->accepted_at->toDateTimeString())->toBe('2026-10-15 09:05:00')
        ->and($acceptance->ip_address)->not->toBeNull();

    $again = app(TermsService::class)->accept($user, $version);

    expect($again->id)->toBe($acceptance->id)
        ->and(UserTermAcceptance::query()->count())->toBe(1);
});

it('publicar a versão 2.0 deixa hasAcceptedAll falso até o novo aceite (sem apagar o histórico)', function () {
    $service = app(TermsService::class);
    $user    = User::factory()->create();
    $terms   = termsVersion('terms_of_service', '1.0');
    $privacy = termsVersion('privacy_policy', '1.0');

    $service->accept($user, $terms);
    $service->accept($user, $privacy);
    expect($service->hasAcceptedAll($user))->toBeTrue()
        ->and($service->pendingAcceptances($user))->toBe([]);

    $this->travel(1)->day();
    $v2 = $service->publish('terms_of_service', '2.0', 'Texto novo', now()->toDateString(), $user);

    expect($service->hasAcceptedAll($user))->toBeFalse()
        ->and(array_map(fn (TermVersion $v) => $v->id, $service->pendingAcceptances($user)))->toBe([$v2->id])
        ->and(UserTermAcceptance::query()->where('user_id', $user->id)->count())->toBe(2); // a prova do aceite antigo continua

    $service->accept($user, $v2);

    expect($service->hasAcceptedAll($user))->toBeTrue();
});

it('currentRequired() devolve a versão vigente de cada tipo e ignora o tipo sem versão vigente (nada publicado, futura ou inativa)', function () {
    $service = app(TermsService::class);

    expect($service->currentRequired())->toBe([]);

    $terms = termsVersion('terms_of_service', '1.0');
    termsVersion('terms_of_service', '2.0', now()->addDays(5)->toDateString()); // futura
    termsVersion('privacy_policy', '1.0', active: false);                        // inativa
    termsVersion('lgpd_notice', '1.0');                                          // fora de REQUIRED_TYPES

    $current = $service->currentRequired();

    expect(array_keys($current))->toBe(['terms_of_service'])
        ->and($current['terms_of_service']->id)->toBe($terms->id);

    $privacy = termsVersion('privacy_policy', '2.0');

    expect(array_keys($service->currentRequired()))->toBe(['privacy_policy', 'terms_of_service']) // ordem de REQUIRED_TYPES
        ->and($service->currentRequired()['privacy_policy']->id)->toBe($privacy->id);
});

it('sem versão vigente de nenhum tipo, hasAcceptedAll é verdadeiro (nada a aceitar) — a falha aberta é do cadastro, que avisa no log', function () {
    expect(app(TermsService::class)->hasAcceptedAll(User::factory()->create()))->toBeTrue();
});

it('publish() é atômico: se criar a nova versão falha, a versão vigente NÃO é desativada (nunca há instante sem versão vigente)', function () {
    $service = app(TermsService::class);
    $user    = User::factory()->create();
    $v1      = termsVersion('terms_of_service', '1.0');

    // Mesma versão (type, version) de novo: viola o índice único DEPOIS de desativar a anterior.
    expect(fn () => $service->publish('terms_of_service', '1.0', 'duplicada', now()->toDateString(), $user))
        ->toThrow(QueryException::class);

    expect($v1->fresh()->active)->toBeTrue()
        ->and($service->currentRequired()['terms_of_service']->id)->toBe($v1->id)
        ->and(TermVersion::query()->count())->toBe(1);
});
