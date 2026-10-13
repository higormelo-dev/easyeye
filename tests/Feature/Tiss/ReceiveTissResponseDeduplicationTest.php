<?php

declare(strict_types=1);

/**
 * Importação do retorno TISS (ReceiveTissResponseService::receive) é IDEMPOTENTE
 * POR ARQUIVO — achado da 26ª rodada, que fechou o ponto deixado em aberto na
 * 25ª. ProcessTissReturnService só protege o MESMO retorno; o mesmo XML importado
 * duas vezes virava dois retornos, e ProcessGlosaReturnService (que chaveia a
 * glosa por return_id e SOMA em guide.denied_amount) dobrava o valor: glosa de
 * R$ 150,50 virava 301,00 e o pago caía a zero.
 *
 * Identidade: (clínica, operadora, SHA-256 dos bytes) — o file_hash que o
 * documento de retorno já grava. Retorno `processed` do mesmo arquivo =
 * `alreadyImported` (nada gravado, travado ou despachado); `received`/`parsed`/
 * `error` = RETOMADO (o mesmo retorno é processado). A corrida de verdade, com
 * conexões reais, está em tests/Integration/TissReturnImportRaceTest.php.
 */

use App\Domains\Tiss\DTOs\ReceivedTissReturn;
use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissProtocolStatus, TissReturnStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Jobs\ProcessTissReturnJob;
use App\Domains\Tiss\Models\{TissBatch, TissGlosa, TissGuide, TissOperator, TissProtocol, TissReturn, TissStatusHistory, TissXmlDocument};
use App\Domains\Tiss\Services\ReceiveTissResponseService;
use App\Models\Entity;
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{DB, Log, Queue, Storage};

uses(RefreshDatabase::class);

const RTD_PROBE_CONNECTION = 'rtd_probe';

beforeEach(function (): void {
    Storage::fake();
    Carbon::setTestNow('2026-10-13 12:00:00');
});

/**
 * Clínica com lote enviado de uma guia (R$ 300) e protocolo ligado a ele.
 *
 * @return array{entity: Entity, operator: TissOperator, batch: TissBatch, guide: TissGuide, protocol: TissProtocol}
 */
function rtdFixture(?TissOperator $operator = null): array
{
    $entity = Entity::factory()->create(['is_client' => true]);
    $operator ??= TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);

    $batch = TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-RTD-0001',
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

    $protocol = TissProtocol::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_id'        => $batch->id,
        'protocol_number' => 'PRT-RTD-0001',
        'status'          => TissProtocolStatus::Received->value,
        'details'         => ['source' => 'batch_send'],
    ]);

    return ['entity' => $entity, 'operator' => $operator, 'batch' => $batch, 'guide' => $guide, 'protocol' => $protocol];
}

/** Retorno aceito com UMA glosa de R$ 150,50 na guia GUI-0001. `$suffix` muda os bytes sem mudar o conteúdo. */
function rtdXml(string $suffix = ''): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ans:ansTISS xmlns:ans="http://www.ans.gov.br/padroes/tiss/schemas">
  <ans:operadoraParaPrestador>
    <ans:protocoloRecebimento>
      <ans:numeroProtocolo>PRT-RTD-0001</ans:numeroProtocolo>
      <ans:statusProtocolo>Aceito</ans:statusProtocolo>
    </ans:protocoloRecebimento>
    <ans:loteGuias>
      <ans:numeroLote>LOTE-RTD-0001</ans:numeroLote>
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
XML . $suffix;
}

/**
 * @param array{entity: Entity, operator: TissOperator} $f
 */
function rtdReceive(array $f, string $xml, bool $sync = true): ReceivedTissReturn
{
    return app(ReceiveTissResponseService::class)->receive(
        entityId: (string) $f['entity']->id,
        operatorId: (string) $f['operator']->id,
        xmlContent: $xml,
        processSynchronously: $sync,
    );
}

/** @return array<string, mixed> o que uma importação escreve e uma repetição NÃO pode mexer */
function rtdSnapshot(array $f): array
{
    $guide    = $f['guide']->fresh();
    $protocol = $f['protocol']->fresh();

    return [
        'returns'   => TissReturn::query()->count(),
        'documents' => TissXmlDocument::query()->where('document_type', 'return_xml')->count(),
        'glosas'    => TissGlosa::query()->count(),
        'history'   => TissStatusHistory::query()->count(),
        'files'     => count(Storage::disk()->allFiles()),
        'guide'     => [$guide->status->value, (float) $guide->denied_amount, (float) $guide->paid_amount],
        'protocol'  => [$protocol->status->value, $protocol->details, $protocol->getRawOriginal('updated_at')],
    ];
}

/** Retorno (e documento) criados à mão, como estão nos dados legados. */
function rtdLegacyReturn(array $f, string $xml, TissReturnStatus $status, string $receivedAt): TissReturn
{
    $document = TissXmlDocument::create([
        'entity_id'     => $f['entity']->id,
        'operator_id'   => $f['operator']->id,
        'batch_id'      => $f['batch']->id,
        'document_type' => 'return_xml',
        'direction'     => TissXmlDirection::Inbound->value,
        'status'        => TissXmlStatus::Validated->value,
        'file_path'     => 'tiss/' . $f['entity']->id . '/returns/legado-' . Str::uuid() . '.xml',
        'file_hash'     => hash('sha256', $xml),
        'xml_content'   => $xml,
        'generated_at'  => $receivedAt,
    ]);

    return TissReturn::create([
        'entity_id'       => $f['entity']->id,
        'operator_id'     => $f['operator']->id,
        'protocol_id'     => $f['protocol']->id,
        'xml_document_id' => $document->id,
        'return_type'     => 'batch_return',
        'status'          => $status->value,
        'received_at'     => $receivedAt,
        'summary'         => ['status' => 'accepted'],
    ]);
}

it('[SONDA] o MESMO arquivo importado duas vezes: um retorno só, a glosa entra UMA vez e a repetição não escreve nada', function () {
    $f   = rtdFixture();
    $xml = rtdXml();

    $first = rtdReceive($f, $xml);

    expect($first->alreadyImported)->toBeFalse()
        ->and($first->return->status)->toBe(TissReturnStatus::Processed);

    $afterFirst = rtdSnapshot($f);

    expect($afterFirst['guide'])->toBe(['partially_denied', 150.5, 149.5])
        ->and($afterFirst['returns'])->toBe(1)
        ->and($afterFirst['documents'])->toBe(1)
        ->and($afterFirst['glosas'])->toBe(1)
        ->and($afterFirst['files'])->toBe(1);

    // Outro segundo: o nome do arquivo em disco tem granularidade de segundo, e sem isto
    // uma gravação repetida sobrescreveria o mesmo caminho e passaria despercebida.
    $this->travel(2)->seconds();

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    $second       = rtdReceive($f, $xml);
    $duringSecond = $statements; // antes do retrato abaixo, que também consulta o banco

    expect($second->alreadyImported)->toBeTrue()
        ->and($second->return->id)->toBe($first->return->id)
        ->and(rtdSnapshot($f))->toBe($afterFirst)
        // O atalho: UMA leitura do retorno existente — nenhuma escrita, trava ou transação.
        ->and($duringSecond)->toHaveCount(1)
        ->and($duringSecond[0])->toStartWith('select')->toContain('"tiss_returns"');
});

it('o aviso de arquivo repetido registra só ids no log, nunca o conteúdo do XML', function () {
    $f   = rtdFixture();
    $xml = rtdXml();

    rtdReceive($f, $xml);

    Log::spy();

    $second = rtdReceive($f, $xml);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'tiss.return.duplicate_import'
            && $context === [
                'entity_id'   => (string) $f['entity']->id,
                'operator_id' => (string) $f['operator']->id,
                'return_id'   => (string) $second->return->id,
            ])
        ->once();
});

it('[RETOMADA] retorno do mesmo arquivo que ficou recebido/parseado/em erro é RETOMADO: o MESMO retorno é processado e a glosa entra uma vez', function (string $status) {
    config(['tiss.returns.auto_process' => false]);

    $f   = rtdFixture();
    $xml = rtdXml();

    // 1ª tentativa: o retorno foi gravado e o processamento não terminou.
    $first = rtdReceive($f, $xml, sync: false);

    expect($first->return->status)->toBe(TissReturnStatus::Parsed);

    $first->return->update(['status' => $status, 'error_message' => $status === 'error' ? 'falha anterior' : null]);

    $this->travel(2)->seconds();

    $second = rtdReceive($f, $xml);

    expect($second->alreadyImported)->toBeFalse()
        ->and($second->return->id)->toBe($first->return->id)
        ->and($second->return->status)->toBe(TissReturnStatus::Processed)
        ->and(TissReturn::query()->count())->toBe(1)
        ->and(TissXmlDocument::query()->where('document_type', 'return_xml')->count())->toBe(1)
        ->and(TissGlosa::query()->count())->toBe(1)
        ->and(count(Storage::disk()->allFiles()))->toBe(1)
        ->and((float) $f['guide']->fresh()->denied_amount)->toBe(150.5)
        ->and((float) $f['guide']->fresh()->paid_amount)->toBe(149.5)
        ->and(TissStatusHistory::query()->where('context_type', 'return')->where('context_id', $first->return->id)->where('current_status', 'processed')->count())->toBe(1);
})->with(['recebido' => ['received'], 'parseado' => ['parsed'], 'em erro' => ['error']]);

it('[FILA] retomada despacha UM job para o retorno existente; arquivo já processado não despacha nada', function () {
    $f   = rtdFixture();
    $xml = rtdXml();

    config(['tiss.returns.auto_process' => false]);
    $first = rtdReceive($f, $xml, sync: false);

    config(['tiss.returns.auto_process' => true]);
    Queue::fake();

    $resumed = rtdReceive($f, $xml, sync: false);

    expect($resumed->alreadyImported)->toBeFalse()
        ->and($resumed->return->id)->toBe($first->return->id)
        ->and(TissReturn::query()->count())->toBe(1);

    Queue::assertPushed(ProcessTissReturnJob::class, 1);
    Queue::assertPushed(ProcessTissReturnJob::class, fn (ProcessTissReturnJob $job): bool => $job->returnId === (string) $first->return->id);

    // Arquivo já processado: nem o job.
    $first->return->update(['status' => TissReturnStatus::Processed->value]);
    Queue::fake();

    $again = rtdReceive($f, $xml, sync: false);

    expect($again->alreadyImported)->toBeTrue();

    Queue::assertNothingPushed();
});

it('[ISOLAMENTO] os mesmos bytes em OUTRA clínica, ou em OUTRA operadora, são outro arquivo; um byte a mais também (limite assumido: identidade por bytes exatos)', function () {
    $a   = rtdFixture();
    $xml = rtdXml();

    $first = rtdReceive($a, $xml);

    // Outra clínica, mesma operadora e mesmos bytes: retorno novo, sem tocar o da primeira.
    $b      = rtdFixture($a['operator']);
    $second = rtdReceive($b, $xml);

    expect($second->alreadyImported)->toBeFalse()
        ->and($second->return->id)->not->toBe($first->return->id)
        ->and((string) $second->return->entity_id)->toBe((string) $b['entity']->id)
        ->and((float) $a['guide']->fresh()->denied_amount)->toBe(150.5)
        ->and((float) $b['guide']->fresh()->denied_amount)->toBe(150.5);

    // Mesma clínica, OUTRA operadora, mesmos bytes: retorno novo (assíncrono: não há o que processar).
    config(['tiss.returns.auto_process' => false]);
    $otherOperator = TissOperator::create(['ans_code' => '654321', 'name' => 'Outra Operadora']);
    $third         = rtdReceive(['entity' => $a['entity'], 'operator' => $otherOperator], $xml, sync: false);

    expect($third->alreadyImported)->toBeFalse()
        ->and($third->return->id)->not->toBe($first->return->id)
        ->and((string) $third->return->operator_id)->toBe((string) $otherOperator->id);

    // Um byte a mais (quebra de linha final): outro arquivo.
    $fourth = rtdReceive($a, rtdXml("\n"), sync: false);

    expect($fourth->alreadyImported)->toBeFalse()
        ->and($fourth->return->id)->not->toBe($first->return->id);
});

it('[SOFT DELETE] retorno apagado libera a reimportação; documento apagado com retorno vivo continua barrando', function () {
    config(['tiss.returns.auto_process' => false]);

    $f   = rtdFixture();
    $xml = rtdXml();

    $first = rtdReceive($f, $xml, sync: false);

    // Documento apagado (soft delete) com o retorno vivo: o retorno é quem carrega o efeito.
    $first->return->update(['status' => TissReturnStatus::Processed->value]);
    TissXmlDocument::query()->whereKey($first->return->xml_document_id)->delete();

    expect(rtdReceive($f, $xml, sync: false)->alreadyImported)->toBeTrue();

    // Retorno apagado: o mesmo arquivo pode ser importado de novo.
    $first->return->delete();

    $again = rtdReceive($f, $xml, sync: false);

    expect($again->alreadyImported)->toBeFalse()
        ->and($again->return->id)->not->toBe($first->return->id);
});

it('[LEGADO] várias linhas para os mesmos bytes (duplicata gravada antes da correção): processada primeiro, depois a mais antiga — nunca uma 3ª linha', function (array $statuses, int $expectedIndex, bool $expectedDuplicate) {
    config(['tiss.returns.auto_process' => false]);

    $f   = rtdFixture();
    $xml = rtdXml();

    // received_at crescente: o índice 0 é o mais antigo.
    $rows = [];

    foreach ($statuses as $i => $status) {
        $rows[] = rtdLegacyReturn($f, $xml, TissReturnStatus::from($status), sprintf('2026-10-0%d 10:00:00', $i + 1));
    }

    $result = rtdReceive($f, $xml);

    expect($result->alreadyImported)->toBe($expectedDuplicate)
        ->and($result->return->id)->toBe($rows[$expectedIndex]->id)
        ->and(TissReturn::query()->count())->toBe(count($rows));

    // Quem não foi o escolhido continua exatamente como estava.
    foreach ($rows as $i => $row) {
        if ($i !== $expectedIndex) {
            expect($row->fresh()->status->value)->toBe($statuses[$i]);
        }
    }
})->with([
    'processada nova + parseada antiga -> a processada'  => [['parsed', 'processed'], 1, true],
    'parseada antiga + parseada nova -> retoma a antiga' => [['parsed', 'parsed'], 0, false],
    'em erro antiga + processada -> a processada'        => [['error', 'processed'], 1, true],
]);

it('[ORDEM DA TRAVA] a chave é a 1ª instrução da transação, antes da releitura, do arquivo em disco e dos INSERTs; nenhum FOR UPDATE sob ela', function () {
    config(['tiss.returns.auto_process' => false]);

    $f   = rtdFixture();
    $xml = rtdXml();

    $baseline = DB::transactionLevel();
    $events   = [];

    DB::listen(function (QueryExecuted $query) use (&$events): void {
        $events[] = [
            'sql'      => strtolower($query->sql),
            'bindings' => $query->bindings,
            'level'    => DB::transactionLevel(),
            'files'    => count(Storage::disk()->allFiles()),
        ];
    });

    rtdReceive($f, $xml, sync: false);

    // Só o que roda DENTRO da transação de receive().
    $inside = array_values(array_filter($events, fn (array $e): bool => $e['level'] > $baseline));

    expect($inside)->not->toBeEmpty()
        ->and($inside[0]['sql'])->toBe('select pg_advisory_xact_lock(?)')
        ->and($inside[0]['bindings'])->toBe([ReceiveTissResponseService::importLockKey((string) $f['entity']->id, (string) $f['operator']->id, hash('sha256', $xml))]);

    $position = function (string $needle) use ($inside): ?int {
        foreach ($inside as $i => $event) {
            if (str_contains($event['sql'], $needle)) {
                return $i;
            }
        }

        return null;
    };

    $reread = $position('from "tiss_returns"');
    $insert = $position('insert into "tiss_xml_documents"');

    // Relê o retorno sob a trava e SÓ DEPOIS grava arquivo e linhas.
    expect($reread)->not->toBeNull()->toBeGreaterThan(0)
        ->and($insert)->not->toBeNull()->toBeGreaterThan($reread)
        ->and($inside[$reread]['files'])->toBe(0)
        ->and($inside[$insert]['files'])->toBe(1);

    // Invariante 2: nenhum FOR UPDATE sob a chave (lote → retorno → protocolo, só fora dela).
    foreach ($events as $event) {
        expect($event['sql'])->not->toContain('for update');
    }
});

it('[ORDEM DA TRAVA] process() roda FORA da transação de receive(): seus FOR UPDATE ficam um nível acima da linha de base, nunca dois', function () {
    $f   = rtdFixture();
    $xml = rtdXml();

    $baseline = DB::transactionLevel();
    $locks    = [];

    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $locks[] = DB::transactionLevel();
        }
    });

    rtdReceive($f, $xml);

    // process() trava o lote e o retorno dentro da PRÓPRIA transação (baseline + 1).
    // Dentro da de receive() (que tem a chave) seria baseline + 2: deadlock de upgrade
    // com outro receive() do mesmo lote, que segura KEY SHARE nele.
    expect($locks)->not->toBeEmpty()
        ->and(array_unique($locks))->toBe([$baseline + 1]);
});

it('[ESPERA] a mesma importação espera a chave de quem a tem: sem ela não grava nada', function () {
    config(['tiss.returns.auto_process' => false]);

    $f   = rtdFixture();
    $xml = rtdXml();

    DB::statement("set local lock_timeout = '300ms'");

    // A "outra requisição": segura a chave do MESMO conteúdo.
    $probe = rtdProbe();

    try {
        $probe->select('select pg_advisory_xact_lock(?)', [ReceiveTissResponseService::importLockKey((string) $f['entity']->id, (string) $f['operator']->id, hash('sha256', $xml))]);

        try {
            rtdReceive($f, $xml, sync: false);
            $this->fail('receive() deveria esperar a chave do mesmo conteúdo.');
        } catch (QueryException $e) {
            // 55P03 = lock_not_available (o lock_timeout estourou esperando a chave).
            expect($e->getCode())->toBe('55P03');
        }

        // A espera não deixou nada para trás, e a transação do chamador segue utilizável.
        expect(TissReturn::query()->count())->toBe(0)
            ->and(TissXmlDocument::query()->count())->toBe(0)
            ->and(Storage::disk()->allFiles())->toBe([]);
    } finally {
        rtdCloseProbe($probe);
    }
});

it('[ESPERA] a chave de OUTRA operadora, de OUTRO conteúdo ou de OUTRA clínica não faz a importação esperar', function (string $scope) {
    config(['tiss.returns.auto_process' => false]);

    $f   = rtdFixture();
    $xml = rtdXml();

    DB::statement("set local lock_timeout = '300ms'");

    // Um teste por escopo (transação própria): a chave que a importação já tomou fica retida
    // até o fim da transação do teste, e uma 2ª sonda disputando a MESMA chave esperaria a
    // própria importação — dentro do mesmo processo, para sempre.
    $other = match ($scope) {
        'operadora' => [(string) $f['entity']->id, (string) TissOperator::create(['ans_code' => '654321', 'name' => 'Outra Operadora'])->id, hash('sha256', $xml)],
        'conteúdo'  => [(string) $f['entity']->id, (string) $f['operator']->id, hash('sha256', $xml . "\n")],
        'clínica'   => [(string) rtdFixture($f['operator'])['entity']->id, (string) $f['operator']->id, hash('sha256', $xml)],
    };

    $probe = rtdProbe();

    try {
        // Se as chaves colidissem, a sonda falharia depressa em vez de esperar a importação.
        $probe->statement("set local lock_timeout = '300ms'");
        $probe->select('select pg_advisory_xact_lock(?)', [ReceiveTissResponseService::importLockKey(...$other)]);

        $result = rtdReceive($f, $xml, sync: false);

        expect($result->alreadyImported)->toBeFalse()
            ->and(TissReturn::query()->where('entity_id', $f['entity']->id)->count())->toBe(1);
    } finally {
        rtdCloseProbe($probe);
    }
})->with(['operadora' => ['operadora'], 'conteúdo' => ['conteúdo'], 'clínica' => ['clínica']]);

it('[LIMITE ASSUMIDO] retorno processado ÓRFÃO (sem lote nem guia conhecidos) também barra a reimportação', function () {
    // Nenhum lote, protocolo ou guia no banco: a glosa fica sem guia e o retorno vira `processed`.
    $f = [
        'entity'   => Entity::factory()->create(['is_client' => true]),
        'operator' => TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']),
    ];

    $xml   = rtdXml();
    $first = rtdReceive($f, $xml);

    expect($first->alreadyImported)->toBeFalse()
        ->and($first->return->status)->toBe(TissReturnStatus::Processed)
        ->and(TissGlosa::query()->count())->toBe(1)
        ->and(TissGlosa::query()->sole()->guide_id)->toBeNull();

    // Decisão de produto fixada por este teste (CLAUDE.md, item 12): sem ação de "substituir retorno",
    // o arquivo órfão já processado não é importado de novo.
    expect(rtdReceive($f, $xml)->alreadyImported)->toBeTrue()
        ->and(TissReturn::query()->count())->toBe(1);
});

it('[PRECONDIÇÃO] a releitura sob a trava depende de READ COMMITTED: é o isolamento padrão das transações do app', function () {
    $level = DB::transaction(fn (): string => (string) DB::selectOne('show transaction_isolation')->transaction_isolation);

    expect($level)->toBe('read committed');
});

/** Segunda sessão PostgreSQL (a "outra requisição"), com transação aberta. Fechar com rtdCloseProbe(). */
function rtdProbe(): Connection
{
    config(['database.connections.' . RTD_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

    $probe = DB::connection(RTD_PROBE_CONNECTION);
    $probe->beginTransaction();

    return $probe;
}

function rtdCloseProbe(Connection $probe): void
{
    if ($probe->transactionLevel() > 0) {
        $probe->rollBack();
    }

    DB::purge(RTD_PROBE_CONNECTION);
}
