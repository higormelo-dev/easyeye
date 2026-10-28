<?php

use App\Enums\Fiscal\{NfseEnvironment, NfseStatus};
use App\Exceptions\Fiscal\FocusNfeException;
use App\Jobs\Fiscal\SendNfseJob;
use App\Models\Fiscal\NfseDocument;
use App\Services\Fiscal\{NfseSendService, NfseSyncService};
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{DB, Http, Queue, Storage};

require_once __DIR__ . '/Support/NfseFixtures.php';

/** Deixa a nota parada há $minutes (a varredura olha o updated_at). */
function nfseStale(NfseDocument $document, int $minutes): NfseDocument
{
    DB::table('nfse_documents')->where('id', $document->id)->update(['updated_at' => now()->subMinutes($minutes)]);

    return $document->fresh();
}

function nfseProcessing(array $overrides = [], int $minutes = 30): NfseDocument
{
    return nfseStale(nfseDocument(nfsePaidInvoice(), array_replace(['status' => NfseStatus::Processing->value], $overrides)), $minutes);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake();
    Queue::fake([SendNfseJob::class]);
    nfseReady();
});

describe('notas em processamento', function () {
    it('consulta na Focus a que está parada há mais de 10 min e aplica o resultado (autorizada, com XML)', function () {
        $document = nfseProcessing();

        Http::fake([
            '*/v2/nfse/*'  => Http::response(focusAuthorized() + ['ref' => $document->ref], 200),
            '*/arquivos/*' => Http::response(NFSE_XML, 200),
        ]);

        $this->artisan('fiscal:nfse-sync')->expectsOutputToContain('1 nota(s) em processamento')->expectsOutputToContain('Consultadas: 1')->assertExitCode(0);

        $fresh = $document->fresh();

        expect($fresh->status)->toBe(NfseStatus::Authorized)
            ->and($fresh->numero)->toBe('1')
            ->and($fresh->xml_path)->not->toBeNull();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === FOCUS_HOMOLOG . "/v2/nfse/{$document->ref}");
    });

    it('não consulta a que ainda está dentro dos 10 min (o webhook ainda pode chegar)', function () {
        nfseProcessing([], minutes: 5);

        Http::fake();

        $this->artisan('fiscal:nfse-sync')->assertExitCode(0);

        Http::assertNothingSent();
    });

    it('marca erro_autorizacao com os erros legíveis quando a Focus diz que a prefeitura recusou', function () {
        $document = nfseProcessing();

        Http::fake(['*' => Http::response([
            'cnpj_prestador' => '12345678000123', 'ref' => $document->ref, 'status' => 'erro_autorizacao',
            'erros'          => [['codigo' => 'L99', 'mensagem' => 'Regime Especial de Tributação ausente/inválido', 'correcao' => 'Corrija o Regime Especial de Tributação']],
        ], 200)]);

        $this->artisan('fiscal:nfse-sync')->assertExitCode(0);

        $fresh = $document->fresh();

        expect($fresh->status)->toBe(NfseStatus::AuthorizationError)
            ->and($fresh->errors[0]['correcao'])->toBe('Corrija o Regime Especial de Tributação');
    });

    it('consulta o nacional em /v2/nfsen', function () {
        $document = nfseProcessing(['standard' => 'nacional']);

        Http::fake(['*' => Http::response(['ref' => $document->ref, 'status' => 'processando_autorizacao', 'protocolo' => 'P1'], 200)]);

        $this->artisan('fiscal:nfse-sync')->assertExitCode(0);

        Http::assertSent(fn (Request $request) => $request->url() === FOCUS_HOMOLOG . "/v2/nfsen/{$document->ref}");
    });

    it('ainda processando na Focus: guarda o que ela disse e segue para a próxima varredura', function () {
        $document = nfseProcessing();

        Http::fake(['*' => Http::response(focusProcessing() + ['ref' => $document->ref], 200)]);

        $this->artisan('fiscal:nfse-sync')->assertExitCode(0);

        expect($document->fresh())->status->toBe(NfseStatus::Processing);
    });

    it('a Focus não conhece a ref (404): a nota fica como está', function () {
        $document = nfseProcessing();

        Http::fake(['*' => Http::response(['codigo' => 'nao_encontrado', 'mensagem' => 'Nota fiscal não encontrada'], 404)]);

        $this->artisan('fiscal:nfse-sync')->assertExitCode(0);

        expect($document->fresh()->status)->toBe(NfseStatus::Processing);
    });

    it('respeita o limite por execução, começando pela mais antiga', function () {
        config(['fiscal.sync.limit' => 2]);

        $newest = nfseProcessing([], minutes: 20);
        $oldest = nfseProcessing([], minutes: 90);
        $middle = nfseProcessing([], minutes: 40);

        Http::fake(['*' => Http::response(['status' => 'processando_autorizacao'], 200)]);

        $this->artisan('fiscal:nfse-sync')->assertExitCode(0);

        $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();

        expect($urls)->toBe([FOCUS_HOMOLOG . "/v2/nfse/{$oldest->ref}", FOCUS_HOMOLOG . "/v2/nfse/{$middle->ref}"]);
    });

    it('token recusado interrompe as consultas DAQUELE ambiente, mas o outro segue', function () {
        nfseCredential(NfseEnvironment::Production, ['token' => 'tok-prod']);

        $h1 = nfseProcessing([], minutes: 90);
        $h2 = nfseProcessing([], minutes: 80);
        $p1 = nfseProcessing(['environment' => 'producao'], minutes: 70);

        Http::fake([
            FOCUS_HOMOLOG . '/*' => Http::response('HTTP Basic: Access denied', 401),
            FOCUS_PROD . '/*'    => Http::response(['status' => 'processando_autorizacao'], 200),
        ]);

        $this->artisan('fiscal:nfse-sync')->expectsOutputToContain('homologacao recusado/ausente')->assertExitCode(0);

        $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();

        expect($urls)->toBe([FOCUS_HOMOLOG . "/v2/nfse/{$h1->ref}", FOCUS_PROD . "/v2/nfse/{$p1->ref}"]);
    });

    it('queda de rede numa nota não impede as outras', function () {
        $first  = nfseProcessing([], minutes: 90);
        $second = nfseProcessing([], minutes: 80);

        Http::fake([
            "*/v2/nfse/{$first->ref}"  => Http::response('Bad Gateway', 502),
            "*/v2/nfse/{$second->ref}" => Http::response(focusAuthorized() + ['ref' => $second->ref], 200),
            '*/arquivos/*'             => Http::response(NFSE_XML, 200),
        ]);

        $this->artisan('fiscal:nfse-sync')->expectsOutputToContain('Falhas na consulta: 1')->assertExitCode(0);

        expect($first->fresh()->status)->toBe(NfseStatus::Processing)
            ->and($second->fresh()->status)->toBe(NfseStatus::Authorized);
    });

    it('sem credencial do ambiente da nota, não derruba a varredura', function () {
        $document = nfseProcessing(['environment' => 'producao']);

        Http::fake();

        $this->artisan('fiscal:nfse-sync')->assertExitCode(0);

        expect($document->fresh()->status)->toBe(NfseStatus::Processing);
        Http::assertNothingSent();
    });
});

describe('notas pending_send paradas', function () {
    it('reenfileira o envio da que passou de 15 min sem sair (job perdido), com a mesma nota', function () {
        $stuck = nfseStale(nfseDocument(nfsePaidInvoice()), 30);
        nfseStale(nfseDocument(nfsePaidInvoice()), 5); // recém-criada: o job ainda deve estar na fila

        $this->artisan('fiscal:nfse-sync')->expectsOutputToContain('Reenvios enfileirados: 1')->assertExitCode(0);

        Queue::assertPushed(SendNfseJob::class, 1);
        Queue::assertPushed(SendNfseJob::class, fn (SendNfseJob $job) => $job->documentId === $stuck->id);
    });

    it('não mexe em send_failed, erro_autorizacao, autorizado nem cancelado', function () {
        foreach ([NfseStatus::SendFailed, NfseStatus::AuthorizationError, NfseStatus::Authorized, NfseStatus::Cancelled] as $status) {
            nfseStale(nfseDocument(nfsePaidInvoice(), ['status' => $status->value]), 600);
        }

        Http::fake();

        $this->artisan('fiscal:nfse-sync')->expectsOutputToContain('0 nota(s) em processamento')->assertExitCode(0);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    });

    it('respeita o mesmo limite por execução', function () {
        config(['fiscal.sync.limit' => 1]);

        nfseStale(nfseDocument(nfsePaidInvoice()), 60);
        nfseStale(nfseDocument(nfsePaidInvoice()), 50);

        $this->artisan('fiscal:nfse-sync')->assertExitCode(0);

        Queue::assertPushed(SendNfseJob::class, 1);
    });
});

describe('--dry-run', function () {
    it('lista o que faria sem consultar, enfileirar nem gravar', function () {
        $processing = nfseProcessing();
        nfseStale(nfseDocument(nfsePaidInvoice()), 60);

        Http::fake();

        $this->artisan('fiscal:nfse-sync --dry-run')->expectsOutputToContain("consultaria {$processing->ref}")->assertExitCode(0);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    });
});

describe('consultar agora (syncDocument)', function () {
    it('aplica o que a Focus responde, na hora, para qualquer nota', function () {
        $document = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Processing->value]);

        Http::fake(['*/v2/nfse/*' => Http::response(focusAuthorized() + ['ref' => $document->ref], 200), '*/arquivos/*' => Http::response(NFSE_XML, 200)]);

        $result = app(NfseSyncService::class)->syncDocument($document);

        expect($result->status)->toBe(NfseStatus::Authorized)->and($result->numero)->toBe('1');
    });

    it('lança a falha da consulta para quem chamou decidir (token, rede)', function () {
        $document = nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Processing->value]);

        Http::fake(['*' => Http::response('HTTP Basic: Access denied', 401)]);

        expect(fn () => app(NfseSyncService::class)->syncDocument($document))->toThrow(FocusNfeException::class);
    });
});

describe('reenvio confere a Focus antes de repetir o POST', function () {
    it('a Focus já tem a nota (resposta do envio se perdeu): adota o estado dela e NÃO reenvia', function () {
        $document = nfseDocument(nfsePaidInvoice(), ['request_payload' => ['data_emissao' => '2026-10-18T10:00:00-03:00']]);

        Http::fake([
            "*/v2/nfse/{$document->ref}" => Http::response(focusAuthorized() + ['ref' => $document->ref], 200),
            '*/arquivos/*'               => Http::response(NFSE_XML, 200),
        ]);

        (new SendNfseJob($document->id))->handle(app(NfseSendService::class));

        expect($document->fresh()->status)->toBe(NfseStatus::Authorized);

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    });

    it('a Focus responde algo que não entendemos: segue para o POST em vez de ficar parado', function () {
        $document = nfseDocument(nfsePaidInvoice(), ['request_payload' => ['data_emissao' => '2026-10-18T10:00:00-03:00']]);

        Http::fake([
            "*/v2/nfse/{$document->ref}" => Http::response(['status' => 'estado_novo_da_focus'], 200),
            '*/v2/nfse*'                 => Http::response(focusProcessing(), 201),
        ]);

        (new SendNfseJob($document->id))->handle(app(NfseSendService::class));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST');
        expect($document->fresh()->status)->toBe(NfseStatus::Processing);
    });

    it('422 nfe_autorizada no POST: consulta na hora e traz a nota autorizada', function () {
        $document = nfseDocument(nfsePaidInvoice());

        Http::fake([
            FOCUS_HOMOLOG . '/v2/nfse?ref=*' => Http::response(['codigo' => 'nfe_autorizada', 'mensagem' => 'Nota fiscal já autorizada'], 422),
            "*/v2/nfse/{$document->ref}"     => Http::response(focusAuthorized() + ['ref' => $document->ref], 200),
            '*/arquivos/*'                   => Http::response(NFSE_XML, 200),
        ]);

        (new SendNfseJob($document->id))->handle(app(NfseSendService::class));

        expect($document->fresh()->status)->toBe(NfseStatus::Authorized);
    });
});

describe('agenda', function () {
    it('roda a cada 10 minutos, sem sobreposição', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'fiscal:nfse-sync'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('*/10 * * * *')
            ->and($event->withoutOverlapping)->toBeTrue();
    });
});
