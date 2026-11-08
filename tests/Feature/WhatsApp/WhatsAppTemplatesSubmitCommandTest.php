<?php

declare(strict_types=1);

use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\WhatsAppTemplateCatalog;
use Illuminate\Http\Client\{ConnectionException, Request};
use Illuminate\Support\Facades\{Artisan, Cache, Http};
use Illuminate\Support\Sleep;

/**
 * whatsapp:templates:submit: cria na Gupshup os modelos do catálogo que faltam. Http::fake — nada sai.
 * Sem --send só mostra; com --send nunca recria quem já existe e para no primeiro erro.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Cache::flush();

    config([
        'app.url'                        => 'https://easyeye.app',
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

/** Lista de modelos do app (GET) + resposta do POST de criação (padrão: criado, PENDING). */
function submitStub(array $existing = [], ?Closure $create = null): void
{
    Http::fake(function (Request $request) use ($existing, $create) {
        if ($request->method() === 'GET') {
            return Http::response(['status' => 'success', 'templates' => $existing]);
        }

        return $create ? $create($request) : Http::response(['status' => 'success', 'template' => ['id' => 'tpl-novo', 'status' => 'PENDING']]);
    });
}

function submitRun(string $arguments = ''): array
{
    return [Artisan::call('whatsapp:templates:submit ' . $arguments), Artisan::output()];
}

/** @return list<Request> POSTs de criação enviados. */
function submitPosts(): array
{
    return Http::recorded(fn (Request $request) => $request->method() === 'POST')->map(fn (array $pair) => $pair[0])->values()->all();
}

function submitForm(string $key, string $language = 'pt_BR'): array
{
    $entry = collect(app(WhatsAppTemplateCatalog::class)->entries([$language]))->firstWhere('key', $key);

    return app(WhatsAppProvider::class)->templateForm($entry, $language);
}

describe('campos enviados', function () {
    it('modelo simples (teste de conexão): corpo, exemplo entre colchetes, sem rodapé nem botões', function () {
        expect(submitForm('connection_test'))->toBe([
            'elementName'                 => 'easyeye_teste_conexao',
            'languageCode'                => 'pt_BR',
            'category'                    => 'UTILITY',
            'templateType'                => 'TEXT',
            'vertical'                    => 'Cadastro',
            'content'                     => 'Este é um teste de envio do {{1}}. Se você recebeu, o WhatsApp está configurado corretamente.',
            'example'                     => 'Este é um teste de envio do [EasyEye]. Se você recebeu, o WhatsApp está configurado corretamente.',
            'enableSample'                => 'true',
            'allowTemplateCategoryChange' => 'false',
        ]);
    });

    it('confirmação: rodapé do SAIR e dois botões de resposta, na ordem', function () {
        $form = submitForm('appointment_confirmation');

        expect($form['content'])->toBe('Olá, {{1}}! Lembrete de {{2}}: sua consulta está marcada para {{3}} com {{4}}. Podemos confirmar sua presença?')
            ->and($form['example'])->toBe('Olá, [Maria]! Lembrete de [Clínica Visão]: sua consulta está marcada para [10/10/2026 às 14:30] com [Dr. João Silva]. Podemos confirmar sua presença?')
            ->and($form['footer'])->toBe('Para não receber mais avisos, responda SAIR.')
            ->and($form['vertical'])->toBe('Atendimento')
            ->and(json_decode($form['buttons'], true))->toBe([
                ['type' => 'QUICK_REPLY', 'text' => 'Confirmar'],
                ['type' => 'QUICK_REPLY', 'text' => 'Cancelar'],
            ]);
    });

    it('aviso do SaaS: botão URL dinâmico {APP_URL}/{{1}} com exemplo', function () {
        $form = submitForm('saas_charge');

        expect($form['vertical'])->toBe('Assinatura')
            ->and($form)->not->toHaveKey('footer')
            ->and(json_decode($form['buttons'], true))->toBe([
                ['type' => 'URL', 'text' => 'Abrir o EasyEye', 'url' => 'https://easyeye.app/{{1}}', 'example' => ['https://easyeye.app/panel/my-subscription']],
            ]);
    });

    it('todos os modelos, em pt_BR e en: variáveis do corpo = as do catálogo, exemplo sem {{n}} sobrando, limites da Meta e botões em JSON', function () {
        foreach (['pt_BR', 'en'] as $language) {
            foreach (config('whatsapp.templates') as $key => $template) {
                $form    = submitForm($key, $language);
                $buttons = isset($form['buttons']) ? json_decode($form['buttons'], true) : [];

                expect(preg_match_all('/\{\{\d+\}\}/', $form['content']))->toBe(count($template['body']), "{$key}/{$language}: variáveis")
                    ->and($form['example'])->not->toContain('{{', "{$key}/{$language}: exemplo")
                    ->and(mb_strlen($form['content']))->toBeLessThanOrEqual(1024)
                    ->and(mb_strlen($form['footer'] ?? ''))->toBeLessThanOrEqual(60)
                    ->and(count($buttons))->toBe(count($template['buttons']), "{$key}/{$language}: botões");
            }
        }
    });

    it('código de verificação: autenticação com só a 1ª frase, botão copiar código, validade do código e sem rodapé', function () {
        $form = submitForm('verification_code');

        expect($form['category'])->toBe('AUTHENTICATION')
            ->and($form['content'])->toBe('*{{1}}* é seu código de verificação.')
            ->and($form['example'])->toBe('*[482913]* é seu código de verificação.')
            ->and($form['addSecurityRecommendation'])->toBe('true')
            ->and($form['codeExpirationMinutes'])->toBe('10')
            ->and($form)->not->toHaveKey('footer')
            ->and(json_decode($form['buttons'], true))->toBe([['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copiar código']]);
    });
});

describe('comando', function () {
    it('sem --send: mostra o que seria enviado e não cria nada', function () {
        submitStub();

        [$exit, $out] = submitRun('--only=connection_test');

        expect($exit)->toBe(0)
            ->and($out)->toContain('easyeye_teste_conexao (pt_BR) · UTILITY')
            ->and($out)->toContain('content: Este é um teste de envio do {{1}}.')
            ->and($out)->toContain('example: Este é um teste de envio do [EasyEye].')
            ->and($out)->toContain('1 a criar. Rode com --send');

        expect(submitPosts())->toBe([]);
        Http::assertSentCount(1);
    });

    it('sem --only: todos os 18 modelos do catálogo faltando', function () {
        submitStub();

        [, $out] = submitRun();

        expect($out)->toContain('18 a criar');
        expect(submitPosts())->toBe([]);
    });

    it('--send: um POST com a chave no header e os campos do modelo', function () {
        submitStub();

        [$exit, $out] = submitRun('--only=connection_test --send');

        $posts = submitPosts();

        expect($exit)->toBe(0)->and($posts)->toHaveCount(1)
            ->and($posts[0]->url())->toBe('https://api.gupshup.io/wa/app/app-123/template')
            ->and($posts[0]->hasHeader('apikey', 'sk_conta_comum'))->toBeTrue()
            ->and($posts[0]->isForm())->toBeTrue()
            ->and($posts[0]->data())->toBe(submitForm('connection_test'))
            ->and($out)->toContain('easyeye_teste_conexao (pt_BR): criado (PENDING)')
            ->and($out)->not->toContain('sk_conta_comum');

        Sleep::assertNeverSlept();
    });

    it('--send: quem já existe, em qualquer estado, é pulado sem criar duplicata', function () {
        submitStub([['id' => 'x', 'elementName' => 'easyeye_teste_conexao', 'languageCode' => 'pt_BR', 'status' => 'FAILED']]);

        [$exit, $out] = submitRun('--only=connection_test --send');

        expect($exit)->toBe(0)
            ->and($out)->toContain('easyeye_teste_conexao (pt_BR): já existe (FAILED), pulado.')
            ->and($out)->toContain('Nada a criar.');
        expect(submitPosts())->toBe([]);
    });

    it('--send com vários: espera entre as chamadas (limite de 10 por minuto)', function () {
        submitStub();

        [$exit, $out] = submitRun('--only=connection_test,care_recall --send');

        expect($exit)->toBe(0)->and(submitPosts())->toHaveCount(2)
            ->and($out)->toContain('easyeye_teste_conexao (pt_BR): criado')->toContain('easyeye_lembrete_retorno (pt_BR): criado');

        Sleep::assertSleptTimes(1);
    });

    it('--send: para no primeiro erro e mostra o motivo da Meta; os demais não são tentados', function () {
        submitStub([], fn () => Http::response(['status' => 'error', 'message' => 'This WhatsApp business account does not have permission to create message template'], 400));

        [$exit, $out] = submitRun('--only=connection_test,care_recall --send');

        expect($exit)->toBe(1)->and(submitPosts())->toHaveCount(1)
            ->and($out)->toContain('(pt_BR): Gupshup HTTP 400')
            ->and($out)->toContain('does not have permission to create message template')
            ->and($out)->toContain('Parei aqui');

        Sleep::assertNeverSlept();
    });

    it('--send: timeout depois do POST para e avisa que o modelo pode ter sido criado', function () {
        submitStub([], fn () => throw new ConnectionException('timeout'));

        [$exit, $out] = submitRun('--only=connection_test,care_recall --send');

        // A exceção sai antes de o Http::fake registrar o POST: "não tentou o segundo" = nunca esperou os 7 s.
        expect($exit)->toBe(1)->and($out)->toContain('o modelo pode ter sido criado');

        Sleep::assertNeverSlept();
    });

    it('--only com chave fora do catálogo: lista as válidas e não chama a Gupshup', function () {
        [$exit, $out] = submitRun('--only=connection_tset');

        expect($exit)->toBe(1)->and($out)->toContain('Chave fora do catálogo: connection_tset')->toContain('connection_test');
        Http::assertNothingSent();
    });

    it('fora da conta comum (mock): recusa sem chamar a Gupshup', function () {
        config(['whatsapp.driver' => 'mock']);

        [$exit, $out] = submitRun('--send');

        expect($exit)->toBe(1)->and($out)->toContain('conta comum');
        Http::assertNothingSent();
    });
});
