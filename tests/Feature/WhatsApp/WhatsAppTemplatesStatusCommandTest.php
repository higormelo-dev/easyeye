<?php

declare(strict_types=1);

use App\Models\WhatsApp\WhatsAppSetting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Artisan, Cache, Http};

/**
 * whatsapp:templates:status: só leitura (GET na lista de modelos do app, Http::fake — nada
 * sai). Mostra o que falta, o que está pendente e o motivo da recusa (08/11/2026: os modelos
 * do código do cadastro ficaram FAILED por falta de permissão da conta WABA na Meta).
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();

    config([
        'whatsapp.driver'                => 'gupshup',
        'whatsapp.gupshup.auth_mode'     => '',
        'whatsapp.gupshup.api_base_url'  => 'https://api.gupshup.io',
        'whatsapp.gupshup.api_key'       => 'sk_conta_comum',
        'whatsapp.gupshup.app_name'      => 'EasyEyeApp',
        'whatsapp.gupshup.source_number' => '+55 61 99999-0000',
        'whatsapp.template_languages'    => ['pt_BR'],
    ]);

    WhatsAppSetting::create([
        'entity_id'     => null,
        'app_id'        => 'app-123',
        'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'active'        => true,
    ]);
});

function statusTemplate(string $name, string $language, string $status, string $reason = ''): array
{
    return array_filter([
        'id' => md5($name . $language . $status), 'elementName' => $name, 'languageCode' => $language, 'status' => $status, 'reason' => $reason,
    ], fn ($value) => $value !== '');
}

function statusRun(): array
{
    return [Artisan::call('whatsapp:templates:status'), Artisan::output()];
}

it('mostra aprovado, pendente, recusado com o motivo, ausente e o que está fora do catálogo', function () {
    $reason = 'This WhatsApp business account does not have permission to create message template';

    Http::fake(['api.gupshup.io/wa/app/app-123/template*' => Http::response(['status' => 'success', 'templates' => [
        statusTemplate('easyeye_confirmacao_consulta', 'pt_BR', 'APPROVED'),
        statusTemplate('easyeye_teste_conexao', 'pt_BR', 'PENDING'),
        statusTemplate('easyeye_codigo_verificacao', 'pt_BR', 'FAILED', $reason),
        statusTemplate('easyeye_codigo_verificacao', 'en', 'FAILED', $reason),
    ]])]);

    [$exit, $out] = statusRun();

    expect($exit)->toBe(0)
        ->and($out)->toMatch('/easyeye_confirmacao_consulta\s*\|\s*pt_BR\s*\|\s*UTILITY\s*\|\s*APPROVED/')
        ->and($out)->toMatch('/easyeye_teste_conexao\s*\|\s*pt_BR\s*\|\s*UTILITY\s*\|\s*PENDING/')
        ->and($out)->toMatch('/easyeye_codigo_verificacao\s*\|\s*pt_BR\s*\|\s*AUTHENTICATION\s*\|\s*FAILED/')
        ->and($out)->toMatch('/easyeye_cobranca_enviada\s*\|\s*pt_BR\s*\|\s*UTILITY\s*\|\s*AUSENTE/')
        ->and($out)->toContain('Resumo: ')->toContain('APPROVED 1')->toContain('PENDING 1')->toContain('FAILED 1')->toContain('AUSENTE 15')
        ->and($out)->toContain("easyeye_codigo_verificacao (pt_BR): {$reason}")
        ->and($out)->toContain('fora do catálogo')->toContain('easyeye_codigo_verificacao (en): FAILED');
});

it('só lê: um GET com a chave no header, nada é criado, reenviado ou impresso', function () {
    Http::fake(['api.gupshup.io/wa/app/app-123/template*' => Http::response(['status' => 'success', 'templates' => []])]);

    [, $out] = statusRun();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->hasHeader('apikey', 'sk_conta_comum'));
    expect($out)->not->toContain('sk_conta_comum');
});

it('nome e idioma repetidos: o APPROVED vale mais que um FAILED antigo', function () {
    Http::fake(['api.gupshup.io/wa/app/app-123/template*' => Http::response(['status' => 'success', 'templates' => [
        statusTemplate('easyeye_teste_conexao', 'pt_BR', 'APPROVED'),
        statusTemplate('easyeye_teste_conexao', 'pt_BR', 'FAILED', 'tentativa antiga'),
    ]])]);

    [, $out] = statusRun();

    expect($out)->toMatch('/easyeye_teste_conexao\s*\|\s*pt_BR\s*\|\s*UTILITY\s*\|\s*APPROVED/')
        ->and($out)->not->toContain('tentativa antiga');
});

it('lista com mais de 100 modelos: lê a página seguinte antes de dizer que falta', function () {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $templates = ($query['pageNo'] ?? '0') === '0'
            ? array_map(fn (int $n) => statusTemplate("outro_modelo_{$n}", 'pt_BR', 'APPROVED'), range(1, 100))
            : [statusTemplate('easyeye_teste_conexao', 'pt_BR', 'APPROVED')];

        return Http::response(['status' => 'success', 'templates' => $templates]);
    });

    [, $out] = statusRun();

    Http::assertSentCount(2);
    expect($out)->toMatch('/easyeye_teste_conexao\s*\|\s*pt_BR\s*\|\s*UTILITY\s*\|\s*APPROVED/');
});

it('Gupshup recusa (401): mostra o erro e falha, sem imprimir a chave', function () {
    Http::fake(['api.gupshup.io/wa/app/app-123/template*' => Http::response(['status' => 'error', 'message' => 'Invalid API key'], 401)]);

    [$exit, $out] = statusRun();

    expect($exit)->toBe(1)
        ->and($out)->toContain('Gupshup HTTP 401')
        ->and($out)->not->toContain('sk_conta_comum');
});

it('fora da conta comum (mock): recusa sem chamar a Gupshup', function () {
    config(['whatsapp.driver' => 'mock']);

    [$exit, $out] = statusRun();

    expect($exit)->toBe(1)->and($out)->toContain('conta comum');
    Http::assertNothingSent();
});

it('sem App ID do número global: pede o cadastro sem chamar a Gupshup', function () {
    WhatsAppSetting::query()->whereNull('entity_id')->update(['app_id' => null]);

    [$exit, $out] = statusRun();

    expect($exit)->toBe(1)->and($out)->toContain('App ID');
    Http::assertNothingSent();
});
