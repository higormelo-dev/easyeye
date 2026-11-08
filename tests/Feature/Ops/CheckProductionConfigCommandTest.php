<?php

declare(strict_types=1);

use App\Enums\SaasRule;
use App\Models\{Entity, User};
use App\Notifications\ProductionConfigAlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{Cache, Notification};

/**
 * ops:check-production-config: o que está simulado ou desligado na configuração avisa o time (em
 * produção, no máximo 1x por semana por item). Fora de produção só lista. Em 08/11/2026 captcha,
 * TISS e WhatsApp estavam assim em produção sem ninguém saber.
 */
function opsGoodConfig(array $overrides = []): void
{
    config([
        'billing.turnstile.site_key'   => 'site',
        'billing.turnstile.secret_key' => 'secret',
        'tiss.transport.driver'        => 'http',
        'whatsapp.driver'              => 'gupshup',
        'whatsapp.gupshup.sandbox'     => false,
        'mail.default'                 => 'smtp',
        'ai.provider_runtime'          => 'real',
        ...$overrides,
    ]);
}

beforeEach(function () {
    Notification::fake();
    Cache::flush();

    $saas        = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($saas, $this->admin, SaasRule::Admin->value);
});

it('tudo configurado: não lista nem avisa nada', function () {
    app()->detectEnvironment(fn () => 'production');
    opsGoodConfig();

    $this->artisan('ops:check-production-config')->expectsOutput('Nada simulado ou desligado.')->assertSuccessful();

    Notification::assertNothingSent();
});

it('produção com tudo simulado: avisa o time na hora, uma vez, com todos os itens', function () {
    app()->detectEnvironment(fn () => 'production');
    opsGoodConfig([
        'billing.turnstile.site_key' => null,
        'tiss.transport.driver'      => 'mock',
        'whatsapp.driver'            => 'mock',
        'mail.default'               => 'log',
        'ai.provider_runtime'        => 'fake',
    ]);

    $this->artisan('ops:check-production-config')->assertSuccessful();

    Notification::assertSentToTimes($this->admin, ProductionConfigAlertNotification::class, 1);
    Notification::assertSentTo($this->admin, ProductionConfigAlertNotification::class, fn (ProductionConfigAlertNotification $n) => $n->codes === [
        'turnstile_off', 'tiss_simulated', 'whatsapp_simulated', 'mail_not_sending', 'ai_fake',
    ]);

    // No dia seguinte o aviso não se repete (limite de 1x por semana por item).
    $this->travel(1)->days();
    $this->artisan('ops:check-production-config')->assertSuccessful();

    Notification::assertSentToTimes($this->admin, ProductionConfigAlertNotification::class, 1);
});

it('item novo depois do primeiro aviso: avisa só o novo; passada a semana, avisa o que persiste', function () {
    app()->detectEnvironment(fn () => 'production');
    opsGoodConfig(['tiss.transport.driver' => 'mock']);

    $this->artisan('ops:check-production-config');

    opsGoodConfig(['tiss.transport.driver' => 'mock', 'whatsapp.gupshup.sandbox' => true]);
    $this->travel(1)->days();
    $this->artisan('ops:check-production-config');

    Notification::assertSentTo($this->admin, ProductionConfigAlertNotification::class, fn (ProductionConfigAlertNotification $n) => $n->codes === ['whatsapp_sandbox']);

    $this->travel(7)->days();
    $this->artisan('ops:check-production-config');

    Notification::assertSentTo($this->admin, ProductionConfigAlertNotification::class, fn (ProductionConfigAlertNotification $n) => $n->codes === ['tiss_simulated', 'whatsapp_sandbox']);
});

it('fora de produção só lista, sem e-mail', function () {
    opsGoodConfig(['tiss.transport.driver' => 'mock']);

    $this->artisan('ops:check-production-config')
        ->expectsOutputToContain('TISS em modo simulação')
        ->expectsOutput('Fora de produção: só listei, sem alerta.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('WhatsApp em simulação não repete o aviso de sandbox', function () {
    opsGoodConfig(['whatsapp.driver' => 'mock', 'whatsapp.gupshup.sandbox' => true]);

    $this->artisan('ops:check-production-config')
        ->expectsOutputToContain('WhatsApp em simulação')
        ->doesntExpectOutputToContain('sandbox')
        ->assertSuccessful();
});

it('o e-mail não passa pela fila e lista os itens no idioma do destinatário', function () {
    // O canal aplica o locale da notificação; aqui chamamos toMail() direto, então fixamos o idioma do app.
    app()->setLocale('en');
    $mail = (new ProductionConfigAlertNotification(['turnstile_off', 'ai_fake']))->toMail($this->admin);

    expect($mail->subject)->toBe('[Config alert] 2 simulated or disabled item(s) in production')
        ->and(implode("\n", $mail->introLines))->toContain('• Sign-up captcha is off')->toContain('• AI is in fake mode')
        ->and((new ProductionConfigAlertNotification([])))->not->toBeInstanceOf(ShouldQueue::class);
});

it('lang/{pt_BR,en}/ops_alerts.php têm as mesmas chaves e nenhum valor vazio', function () {
    $pt = Arr::dot(require lang_path('pt_BR/ops_alerts.php'));
    $en = Arr::dot(require lang_path('en/ops_alerts.php'));

    expect(array_keys($pt))->toEqualCanonicalizing(array_keys($en))
        ->and(array_filter($pt, fn ($v) => trim((string) $v) === ''))->toBe([])
        ->and(array_filter($en, fn ($v) => trim((string) $v) === ''))->toBe([]);
});
