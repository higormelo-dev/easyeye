<?php

declare(strict_types=1);

/**
 * Importação do retorno TISS × o MESMO arquivo importado ao mesmo tempo, com
 * conexões PostgreSQL de verdade — achado da 26ª rodada. Dois cliques no botão,
 * duas abas, ou a tela e um job: cada importação achava "nenhum retorno deste
 * arquivo" e criava o seu, e a glosa era somada duas vezes
 * (ProcessGlosaReturnService soma em guide.denied_amount e chaveia a glosa por
 * return_id).
 *
 * Agora receive() toma um advisory lock por (clínica, operadora, SHA-256 do XML)
 * como 1ª instrução da transação e relê o retorno sob a trava. Os testes seguram
 * a chave numa transação ainda aberta do processo principal, sobem a "outra
 * requisição" (processo filho) e só liberam depois de ver o filho PARADO nessa
 * chave (pg_locks) — nada de sleep adivinhando a corrida.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por
 * caminho explícito. O XML vai para o disco REAL de teste (FILESYSTEM_DISK=local):
 * cada teste apaga o que gravou.
 */

use App\Domains\Tiss\DTOs\ReceivedTissReturn;
use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissProtocolStatus};
use App\Domains\Tiss\Models\{TissBatch, TissGlosa, TissGuide, TissOperator, TissProtocol, TissReturn, TissStatusHistory, TissXmlDocument};
use App\Domains\Tiss\Services\ReceiveTissResponseService;
use App\Models\Entity;
use Illuminate\Contracts\Process\{InvokedProcess, ProcessResult};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function rtirChildEnv(): array
{
    $db = config('database.connections.' . config('database.default'));

    return [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'DB_CONNECTION'    => config('database.default'),
        'DB_HOST'          => (string) ($db['host'] ?? ''),
        'DB_PORT'          => (string) ($db['port'] ?? ''),
        'DB_DATABASE'      => (string) ($db['database'] ?? ''),
        'DB_USERNAME'      => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'      => (string) ($db['password'] ?? ''),
    ];
}

/**
 * Retorno aceito com UMA glosa de R$ 150,50. Com lote: protocolo, lote e guia
 * (R$ 300) batem com o que está no banco. Sem lote: o XML não traz protocolo nem
 * lote, então nenhuma outra trava existe — só a chave segura a segunda importação.
 */
function rtirXml(bool $withBatch): string
{
    $identifiers = $withBatch
        ? '<ans:numeroProtocolo>PRT-RTIR-0001</ans:numeroProtocolo>'
        : '';
    $batchNumber = $withBatch ? '<ans:numeroLote>LOTE-RTIR-0001</ans:numeroLote>' : '';

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ans:ansTISS xmlns:ans="http://www.ans.gov.br/padroes/tiss/schemas">
  <ans:operadoraParaPrestador>
    <ans:protocoloRecebimento>
      {$identifiers}
      <ans:statusProtocolo>Aceito</ans:statusProtocolo>
    </ans:protocoloRecebimento>
    <ans:loteGuias>
      {$batchNumber}
      <ans:guiasTISS>
        <ans:guiaSP-SADT>
          <ans:cabecalhoGuia>
            <ans:numeroGuiaPrestador>GUI-0001</ans:numeroGuiaPrestador>
          </ans:cabecalhoGuia>
          <ans:procedimentoExecutado>
            <ans:codigoProcedimento>10101012</ans:codigoProcedimento>
            <ans:glosa>
              <ans:codigoGlosa>3099</ans:codigoGlosa>
              <ans:descricaoGlosa>Fora da cobertura contratual</ans:descricaoGlosa>
              <ans:valorGlosa>150.50</ans:valorGlosa>
            </ans:glosa>
          </ans:procedimentoExecutado>
        </ans:guiaSP-SADT>
      </ans:guiasTISS>
    </ans:loteGuias>
  </ans:operadoraParaPrestador>
</ans:ansTISS>
XML;
}

/**
 * Dados COMITADOS + o XML num arquivo temporário (o filho lê pelo caminho).
 *
 * @return array{entity: Entity, operator: TissOperator, batch: ?TissBatch, guide: ?TissGuide, xml: string, xmlPath: string}
 */
function rtirFixture(bool $withBatch): array
{
    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);
    $batch    = null;
    $guide    = null;

    if ($withBatch) {
        $batch = TissBatch::create([
            'entity_id'       => $entity->id,
            'operator_id'     => $operator->id,
            'batch_number'    => 'LOTE-RTIR-0001',
            'reference_month' => now()->format('Y-m'),
            'status'          => TissBatchStatus::Sent->value,
        ]);

        $guide = TissGuide::create([
            'entity_id'             => $entity->id,
            'operator_id'           => $operator->id,
            'guide_type'            => 'consultation',
            'guide_number_provider' => 'GUI-0001',
            'status'                => TissGuideStatus::Sent->value,
            'attendance_date'       => now()->toDateString(),
            'total_amount'          => 300,
        ]);

        DB::table('tiss_batch_guides')->insert([
            'id'          => (string) Str::uuid(),
            'entity_id'   => $entity->id,
            'batch_id'    => $batch->id,
            'guide_id'    => $guide->id,
            'attached_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        TissProtocol::create([
            'entity_id'       => $entity->id,
            'operator_id'     => $operator->id,
            'batch_id'        => $batch->id,
            'protocol_number' => 'PRT-RTIR-0001',
            'status'          => TissProtocolStatus::Received->value,
        ]);
    }

    $xml     = rtirXml($withBatch);
    $xmlPath = sys_get_temp_dir() . '/rtir-' . Str::uuid() . '.xml';

    file_put_contents($xmlPath, $xml);

    return ['entity' => $entity, 'operator' => $operator, 'batch' => $batch, 'guide' => $guide, 'xml' => $xml, 'xmlPath' => $xmlPath];
}

/** Apaga o que o teste gravou fora do banco: o XML temporário e os retornos no disco. */
function rtirCleanup(array $f): void
{
    @unlink($f['xmlPath']);

    Storage::disk()->deleteDirectory('tiss/' . $f['entity']->id);
}

/** Importa no processo principal (sem sessão HTTP). */
function rtirReceive(array $f, bool $sync): ReceivedTissReturn
{
    return app(ReceiveTissResponseService::class)->receive(
        entityId: (string) $f['entity']->id,
        operatorId: (string) $f['operator']->id,
        xmlContent: $f['xml'],
        processSynchronously: $sync,
    );
}

/** A "outra requisição": o mesmo arquivo importado por um processo filho. */
function rtirStartChild(array $f, string $mode): InvokedProcess
{
    return Process::path(base_path())
        ->env(rtirChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/tiss_receive_return_child.php', (string) $f['entity']->id, (string) $f['operator']->id, $f['xmlPath'], $mode]);
}

/**
 * Espera o filho ficar PARADO na chave desta importação (advisory lock, ao vivo em
 * pg_locks — pg_stat_activity é congelada na 1ª leitura de uma transação aberta). A
 * espera é específica da chave: uma espera genérica por "lock não concedido" também
 * pegaria lote ou protocolo e daria falso positivo.
 */
function rtirWaitOnImportLock(InvokedProcess $child, array $f): bool
{
    $key = ReceiveTissResponseService::importLockKey((string) $f['entity']->id, (string) $f['operator']->id, hash('sha256', $f['xml']));

    $deadline = microtime(true) + 30;

    while (microtime(true) < $deadline && $child->running()) {
        $waiting = DB::selectOne(
            "select count(*) as total from pg_locks where locktype = 'advisory' and not granted and pid <> pg_backend_pid() and ((classid::bigint << 32) | objid::bigint) = ?",
            [$key],
        );

        if ((int) $waiting->total > 0) {
            return true;
        }

        usleep(50_000);
    }

    return false;
}

/** @return array{id: ?string, dup: bool, status: ?string} */
function rtirParse(ProcessResult $result): array
{
    preg_match('/RETURN:(\S+)\|DUP:(\d)\|STATUS:(\w+)/', $result->output(), $m);

    return ['id' => $m[1] ?? null, 'dup' => ($m[2] ?? null) === '1', 'status' => $m[3] ?? null];
}

it('[CORRIDA] o MESMO arquivo importado ao mesmo tempo: a 2ª importação espera a chave, relê o retorno já processado e não soma nada', function (bool $withBatch) {
    config(['tiss.returns.auto_process' => false]);

    $f = rtirFixture($withBatch);

    // statement_timeout = rede de segurança contra pendurar a suíte.
    DB::statement("set statement_timeout = '30s'");

    $child   = null;
    $blocked = false;

    // Importação A: processa, mas AINDA NÃO comita — segura a chave (e o lote).
    DB::beginTransaction();

    try {
        $first = rtirReceive($f, sync: true);

        // Importação B: o mesmo arquivo, por outro processo.
        $child   = rtirStartChild($f, 'sync');
        $blocked = rtirWaitOnImportLock($child, $f);
    } finally {
        // Comita mesmo se algo falhou: o filho não pode ficar pendurado numa trava.
        DB::commit();
    }

    $result = $child->wait();

    try {
        expect($blocked)->toBeTrue('o filho deveria ficar esperando a chave da importação A')
            ->and($result->successful())->toBeTrue("filho falhou — stderr: {$result->errorOutput()}");

        $second = rtirParse($result);

        // B viu o retorno que A comitou e saiu sem escrever nada.
        expect($second['dup'])->toBeTrue()
            ->and($second['id'])->toBe((string) $first->return->id)
            ->and($second['status'])->toBe('processed');

        if ($withBatch) {
            // Antes: denied 301,00 / pago 0,00.
            expect((float) $f['guide']->fresh()->denied_amount)->toBe(150.5)
                ->and((float) $f['guide']->fresh()->paid_amount)->toBe(149.5)
                ->and(TissGlosa::query()->count())->toBe(1);
        }

        expect(TissReturn::query()->count())->toBe(1)
            ->and(TissXmlDocument::query()->where('document_type', 'return_xml')->count())->toBe(1)
            ->and(TissStatusHistory::query()->where('context_type', 'return')->where('context_id', $first->return->id)->where('current_status', 'processed')->count())->toBe(1)
            ->and(Storage::disk()->allFiles('tiss/' . $f['entity']->id . '/returns'))->toHaveCount(1);
    } finally {
        rtirCleanup($f);
    }
})->with([
    'retorno de um lote (a chave e o lote)'                          => [true],
    'XML sem protocolo nem lote (só a chave segura a 2ª importação)' => [false],
]);

it('[CORRIDA] retomada: a 2ª importação espera a chave, relê o retorno `parsed` e processa O MESMO retorno, uma vez só', function () {
    config(['tiss.returns.auto_process' => false]);

    $f = rtirFixture(true);

    DB::statement("set statement_timeout = '30s'");

    $child   = null;
    $blocked = false;

    // A grava o retorno e NÃO processa (assíncrono, sem despacho) nem comita: a chave fica retida.
    DB::beginTransaction();

    try {
        $first = rtirReceive($f, sync: false);

        $child   = rtirStartChild($f, 'sync');
        $blocked = rtirWaitOnImportLock($child, $f);
    } finally {
        DB::commit();
    }

    $result = $child->wait();

    try {
        expect($blocked)->toBeTrue('o filho deveria ficar esperando a chave da importação A')
            ->and($result->successful())->toBeTrue("filho falhou — stderr: {$result->errorOutput()}")
            ->and($first->return->status->value)->toBe('parsed');

        $second = rtirParse($result);

        // B relê `parsed` (não é duplicata): RETOMA o mesmo retorno e o processa.
        expect($second['dup'])->toBeFalse()
            ->and($second['id'])->toBe((string) $first->return->id)
            ->and($second['status'])->toBe('processed')
            ->and(TissReturn::query()->count())->toBe(1)
            ->and(TissXmlDocument::query()->where('document_type', 'return_xml')->count())->toBe(1)
            ->and((float) $f['guide']->fresh()->denied_amount)->toBe(150.5)
            ->and((float) $f['guide']->fresh()->paid_amount)->toBe(149.5)
            ->and(TissGlosa::query()->count())->toBe(1);
    } finally {
        rtirCleanup($f);
    }
});

it('[3 SESSÕES] lote ocupado por outra operação: a 1ª importação espera o lote com a chave na mão, a 2ª espera a chave e as três terminam (sem 40P01)', function () {
    config(['tiss.returns.auto_process' => false]);

    $f = rtirFixture(true);

    DB::statement("set statement_timeout = '30s'");

    // Isto é um COMBOIO (a chave numa mão, o lote na outra, ninguém esperando por quem espera
    // por ele), não um ciclo: o segurador não pede nada e termina sozinho. Mostra que a espera
    // atravessada pelas duas travas fecha o resultado certo (1 retorno, glosa 1 vez), NÃO que o
    // deadlock é impossível — essa garantia é estrutural e está em ReceiveTissResponseDeduplicationTest
    // ([ORDEM DA TRAVA]: a chave é a 1ª instrução da transação; process() e o despacho ficam
    // fora dela, sem FOR UPDATE sob a chave).
    //
    // Sessão 1 (segurador): trava o lote (FOR UPDATE — como o envio, a geração do XML ou o
    // process() de outro retorno) e o segura por tempo de sobra: a margem entre a retenção e o
    // piso do `$waited` absorve a subida lenta de um filho numa máquina carregada.
    $marker = sys_get_temp_dir() . '/rtir-holder-' . Str::uuid();
    $holder = Process::path(base_path())
        ->env(rtirChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/tiss_batch_lock_child.php', (string) $f['batch']->id, $marker, '8', '-']);

    $second        = null;
    $secondBlocked = false;
    $waited        = 0.0;
    $first         = null;

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $holder->running()) {
            usleep(50_000);
        }

        // Sessão 3: assim que a importação principal TOMA a chave, sobe a 2ª importação do mesmo
        // arquivo e espera ela parar nessa chave. A principal segue: o INSERT do documento pede
        // KEY SHARE no lote e fica atrás do segurador (a chave em uma mão, o lote esperando na outra).
        DB::listen(function (QueryExecuted $query) use (&$second, &$secondBlocked, $f): void {
            if ($second === null && str_contains(strtolower($query->sql), 'pg_advisory_xact_lock')) {
                $second        = rtirStartChild($f, 'sync');
                $secondBlocked = rtirWaitOnImportLock($second, $f);
            }
        });

        $startedAt = microtime(true);
        $first     = rtirReceive($f, sync: false);
        $waited    = microtime(true) - $startedAt;
    } finally {
        $holderResult = $holder->wait();
        $secondResult = $second?->wait();

        @unlink($marker);
    }

    try {
        // Nenhum 40P01 (deadlock detected) em nenhuma das três sessões.
        expect($holderResult->successful())->toBeTrue("segurador falhou — stderr: {$holderResult->errorOutput()}")
            ->and($secondResult?->successful())->toBeTrue("2ª importação falhou — stderr: {$secondResult?->errorOutput()}")
            ->and($secondBlocked)->toBeTrue('a 2ª importação deveria esperar a chave da principal')
            // A principal esperou o lote do segurador (a chave segurada não tirou a espera do caminho dele).
            // Retenção de 8 s contra piso de 2 s: o piso só falha se a principal nem chegou a esperar.
            ->and($waited)->toBeGreaterThan(2.0);

        $retried = rtirParse($secondResult);

        // A 2ª importação, depois da principal, RETOMOU o mesmo retorno (a principal o deixou `parsed`).
        expect($retried['dup'])->toBeFalse()
            ->and($retried['id'])->toBe((string) $first->return->id)
            ->and($retried['status'])->toBe('processed')
            ->and(TissReturn::query()->count())->toBe(1)
            ->and((float) $f['guide']->fresh()->denied_amount)->toBe(150.5)
            ->and(TissGlosa::query()->count())->toBe(1);
    } finally {
        rtirCleanup($f);
    }
});
