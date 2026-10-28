<?php

declare(strict_types=1);

/**
 * Envio de lote pelo faturamento (BillingService::submitBatch) × idempotência
 * do TISS — achado via revisão adversarial Codex (13ª rodada).
 *
 * submitViaTissWorkflow() SEMPRE regerava o XML antes de enviar, e o builder
 * grava o horário corrente no cabeçalho (dataRegistroTransacao /
 * horaRegistroTransacao) e no hash do epílogo: o MESMO lote, sem nenhuma guia
 * mudada, saía com outro hash — logo, outra chave de idempotência — um segundo
 * depois. Se a operadora já tinha confirmado o lote e só o fechamento do lado
 * do faturamento falhou, o "tentar de novo" enviava o lote aceito OUTRA vez,
 * com chave nova; o mesmo valia para a tentativa em dúvida (timeout, worker
 * morto): a retomada não reaproveitava a chave. Regerar também empurrava o
 * lote TISS de volta de "enviado" para "XML pronto" (e as guias idem).
 *
 * Aqui o transporte é um duplo que CONTA as requisições (e guarda a chave e o
 * XML de cada uma): "não manda requisição nova" é o que o teste mede. O
 * relógio é congelado e avançado de propósito — rodar rápido demais deixava
 * os dois XMLs no mesmo segundo e o defeito passava despercebido.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissTransmissionStatus};
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissIdempotencyKey, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\TissWorkflowService;
use App\Domains\Tiss\Services\Transport\TissTransportContract;
use App\Enums\{BillingBatchStatus, ScheduleSituation};
use App\Models\{BillingBatch, BillingClaim, Entity, Patient, Schedule};
use App\Services\Financial\{BillingAdjustmentService, BillingService};
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Transporte que registra cada requisição QUE SAIU. $script: um desfecho por
 * chamada ('ok' | 'timeout' | 'gateway' | 'lost' | 'server_error' | 'error' |
 * 'unauthorized' | 'no_endpoint' | 'crash'); roteiro vazio = 'ok'.
 * 'timeout' = HTTP 408; 'gateway' = um status FORA da lista de dúvida (422) mas com "timeout" no código do erro (transporte que nomeia o timeout); 'lost' = a operadora PODE ter recebido e processado,
 * mas a resposta se perdeu (exceção de transporte, sem status HTTP — como o
 * HttpTissTransportClient devolve); 'server_error' = HTTP 500 sem recusa
 * explícita (a operadora pode ter gravado o lote e falhado ao montar a
 * resposta: dúvida); 'error' = a operadora RESPONDEU recusando (HTTP 422:
 * definitivo); 'unauthorized' = a operadora respondeu 401; 'no_endpoint' = falha
 * LOCAL, o endpoint sumiu e nada saiu da clínica (não entra em $requests);
 * 'crash' lança (worker morto no meio do envio, sem resposta gravada).
 *
 * @param list<string> $script
 */
function btrTransport(array $script = []): object
{
    $transport = new class($script) implements TissTransportContract {
        /** @var list<array{key: string, xml: string}> */
        public array $requests = [];

        /** @param list<string> $script */
        public function __construct(private array $script)
        {
        }

        public function sendBatch(
            TissEntityOperatorCredential $credential,
            string $xml,
            string $idempotencyKey,
            array $context = [],
        ): array {
            $outcome = array_shift($this->script) ?? 'ok';

            if ($outcome !== 'no_endpoint') {
                $this->requests[] = ['key' => $idempotencyKey, 'xml' => $xml];
            }

            if ($outcome === 'crash') {
                throw new RuntimeException('conexão perdida no meio do envio');
            }

            $failure = [
                'ok'          => false,
                'status_code' => match ($outcome) {
                    'timeout'      => 408,
                    'gateway'      => 422,
                    'lost'         => null,
                    'no_endpoint'  => null,
                    'unauthorized' => 401,
                    'server_error' => 500,
                    default        => 422,
                },
                'response_body'   => null,
                'protocol_number' => null,
                'protocol_status' => null,
                'error_code'      => match ($outcome) {
                    'timeout'      => 'http_timeout',
                    'gateway'      => 'upstream_timeout',
                    'lost'         => 'transport_exception',
                    'no_endpoint'  => 'missing_endpoint',
                    'unauthorized' => 'http_401',
                    'server_error' => 'http_500',
                    default        => 'http_422',
                },
                'error_message' => match ($outcome) {
                    'timeout'      => 'tempo esgotado',
                    'gateway'      => 'timeout no servidor da operadora',
                    'lost'         => 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
                    'no_endpoint'  => 'Endpoint de submissão TISS não configurado para a operadora.',
                    'unauthorized' => 'credencial recusada',
                    'server_error' => 'erro interno do servidor',
                    default        => 'erro interno',
                },
                'response_time_ms' => 12,
            ];

            if ($outcome !== 'ok') {
                return $failure;
            }

            return [
                'ok'               => true,
                'status_code'      => 200,
                'response_body'    => '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>',
                'protocol_number'  => 'PRT-1',
                'protocol_status'  => 'accepted',
                'error_code'       => null,
                'error_message'    => null,
                'response_time_ms' => 12,
            ];
        }
    };

    app()->instance(TissTransportContract::class, $transport);

    return $transport;
}

/**
 * Lote de faturamento TISS de verdade (convênio com registro ANS) com
 * $claims guias, relógio congelado ao meio-dia (longe da virada do dia).
 */
function btrBatch(int $claims = 1): BillingBatch
{
    Carbon::setTestNow('2026-10-12 12:00:00');

    // CNES da clínica e CRM do médico: sem eles o XSD rejeita o XML e o
    // documento nasce "inválido" — que o transporte real nem envia. O teste
    // precisa do caminho de produção (documento validado).
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true, 'cnes' => '1234567', 'state' => 'SP']);
    actingAsFinancialEntityUser($entity);

    $first = createBillableSchedule($entity);
    $first->doctor->update(['record' => '123456']);

    for ($i = 1; $i < $claims; $i++) {
        $patient = Patient::factory()->create([
            'entity_id'   => $entity->id,
            'covenant_id' => $first->covenant_id,
            'card_number' => '98765432' . $i,
        ]);

        Schedule::create([
            'entity_id'   => $entity->id,
            'doctor_id'   => $first->doctor_id,
            'patient_id'  => $patient->id,
            'covenant_id' => $first->covenant_id,
            'full_name'   => $patient->person->name ?? 'Outro Paciente',
            'date_time'   => now()->subHours(2 + $i),
            'situation'   => ScheduleSituation::Attended->value,
            'active'      => true,
        ]);
    }

    return app(BillingService::class)->createBatch([
        'covenant_id'           => $first->covenant_id,
        'date_from'             => now()->subDay()->toDateString(),
        'date_until'            => now()->toDateString(),
        'unit_price'            => 150,
        'tuss_code'             => '10101012',
        'procedure_description' => 'CONSULTA OFTALMOLOGICA',
        'clinical_indication'   => 'H52.1',
    ]);
}

function btrTissBatch(BillingBatch $batch): TissBatch
{
    return TissBatch::query()->findOrFail($batch->fresh()->tiss_batch_id);
}

/** Falha UMA vez ao gravar $column do lote de faturamento (a gravação seguinte passa). */
function btrFailOnce(string $column): void
{
    $armed = true;

    BillingBatch::updating(function (BillingBatch $batch) use (&$armed, $column): void {
        if ($armed && $batch->isDirty($column)) {
            $armed = false;

            throw new RuntimeException('falha simulada ao gravar o lote de faturamento');
        }
    });
}

/** @return list<TissXmlDocument> */
function btrSubmissionDocuments(BillingBatch $batch): array
{
    return TissXmlDocument::query()
        ->where('batch_id', $batch->fresh()->tiss_batch_id)
        ->where('document_type', 'batch_submission')
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all();
}

function btrXmlTime(string $xml): string
{
    preg_match('#<horaRegistroTransacao>(.*?)</horaRegistroTransacao>#', $xml, $m);

    return $m[1] ?? '';
}

function btrGuideCount(string $xml): int
{
    return substr_count($xml, '<guiaConsulta>');
}

// ─────────────────────────────────────────────────────────────────────────────
// Operadora JÁ confirmou, o fechamento do faturamento é que falhou
// ─────────────────────────────────────────────────────────────────────────────

it('[IDEMPOTÊNCIA] operadora confirmou e o lote de faturamento falhou ao fechar: tentar de novo NÃO manda outra requisição', function (string $column) {
    $batch     = btrBatch();
    $transport = btrTransport();

    btrFailOnce($column);

    expect(fn () => app(BillingService::class)->submitBatch($batch->fresh()))
        ->toThrow(RuntimeException::class, 'falha simulada');

    // O que o TISS comitou antes da falha: lote enviado, confirmação gravada.
    expect($transport->requests)->toHaveCount(1)
        ->and(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sent)
        ->and(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($batch->fresh()->status)->toBe(BillingBatchStatus::Draft);

    $sentDocument = btrSubmissionDocuments($batch)[0];

    $this->travel(15)->seconds(); // outro segundo no cabeçalho do XML, se ele fosse regerado

    $submitted = app(BillingService::class)->submitBatch($batch->fresh());

    expect($transport->requests)->toHaveCount(1)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and(TissIdempotencyKey::query()->count())->toBe(1)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed')
        ->and(btrSubmissionDocuments($batch))->toHaveCount(1)
        ->and($submitted->status)->toBe(BillingBatchStatus::Submitted)
        ->and($submitted->xml_path)->toBe($sentDocument->file_path);

    // Regerar o XML também desfazia o "enviado" do lote e das guias TISS.
    expect(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sent)
        ->and(btrTissBatch($batch)->guides()->pluck('tiss_guides.status')->map(fn ($s) => $s->value)->unique()->all())
        ->toBe([TissGuideStatus::Sent->value]);
})->with(['xml_path', 'status']);

it('[IDEMPOTÊNCIA] dado cadastral da clínica mudou entre a falha e a nova tentativa: lote já confirmado continua sem reenvio', function () {
    $batch     = btrBatch();
    $transport = btrTransport();

    btrFailOnce('status');

    expect(fn () => app(BillingService::class)->submitBatch($batch->fresh()))->toThrow(RuntimeException::class);

    // O CNES da clínica entra no XML (contratadoExecutante): regerar agora
    // sairia com OUTRO conteúdo — a confirmação da operadora vale para o que
    // ela recebeu, não para uma mensagem nova.
    $batch->fresh()->entity->update(['cnes' => '7654321']);
    $this->travel(15)->seconds();

    app(BillingService::class)->submitBatch($batch->fresh());

    expect($transport->requests)->toHaveCount(1)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and(btrSubmissionDocuments($batch))->toHaveCount(1)
        ->and(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sent);
});

// ─────────────────────────────────────────────────────────────────────────────
// Tentativa em dúvida: a retomada reaproveita a MESMA chave e o MESMO XML
// ─────────────────────────────────────────────────────────────────────────────

it('[IDEMPOTÊNCIA] falha de transporte: o reenvio pelo faturamento usa a MESMA chave de idempotência e o MESMO XML', function (string $outcome, TissBatchStatus $batchStatus) {
    $batch     = btrBatch();
    $transport = btrTransport([$outcome, 'ok']);

    expect(fn () => app(BillingService::class)->submitBatch($batch->fresh()))
        ->toThrow(ValidationException::class);

    // Sem resposta da operadora (timeout, conexão perdida) a entrega fica EM
    // DÚVIDA: o lote segue "enviando" (travado). Erro devolvido pela operadora
    // é definitivo: "erro".
    expect(btrTissBatch($batch)->status)->toBe($batchStatus)
        ->and($batch->fresh()->status)->toBe(BillingBatchStatus::Draft);

    $this->travel(15)->seconds();

    $submitted = app(BillingService::class)->submitBatch($batch->fresh());

    $transmission = TissTransmission::query()->sole();

    expect($transport->requests)->toHaveCount(2)
        ->and($transport->requests[1]['key'])->toBe($transport->requests[0]['key'])
        ->and($transport->requests[1]['xml'])->toBe($transport->requests[0]['xml'])
        ->and($transmission->attempt)->toBe(2)
        ->and($transmission->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(btrSubmissionDocuments($batch))->toHaveCount(1)
        ->and($submitted->status)->toBe(BillingBatchStatus::Submitted);
})->with([
    'timeout no código do erro (HTTP 422)' => ['gateway', TissBatchStatus::Sending],
    'timeout (408)'                        => ['timeout', TissBatchStatus::Sending],
    'resposta perdida (exceção)'           => ['lost', TissBatchStatus::Sending],
    'erro do servidor (HTTP 500)'          => ['server_error', TissBatchStatus::Sending],
    'erro devolvido pela operadora (422)'  => ['error', TissBatchStatus::Error],
]);

// ─────────────────────────────────────────────────────────────────────────────
// Entrega EM DÚVIDA (achado via revisão adversarial Codex, 16ª rodada): a
// operadora pode ter aceitado o lote e a resposta se perdeu. Mudar o dado
// antes do reenvio (CNES da clínica, guia) gerava XML novo e chave nova — a
// operadora recebia o lote DE NOVO, sem a deduplicação da chave original.
// ─────────────────────────────────────────────────────────────────────────────

it('[IDEMPOTÊNCIA] a operadora recebeu, a resposta se perdeu (ou o servidor falhou com HTTP 500 ao responder) e o CNES da clínica mudou antes do reenvio: sai o MESMO XML (CNES antigo) com a MESMA chave', function (string $outcome, string $reason) {
    $batch     = btrBatch();
    $transport = btrTransport([$outcome, 'ok']);

    expect(fn () => app(BillingService::class)->submitBatch($batch->fresh()))
        ->toThrow(ValidationException::class, __('tiss_send.delivery_unknown', ['reason' => $reason]));

    // Entrega em dúvida: lote travado, tentativa marcada como sem resposta.
    expect(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sending)
        ->and(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Timeout);

    // O dado mudou ENQUANTO a entrega estava em dúvida.
    $batch->fresh()->entity->update(['cnes' => '7654321']);
    $this->travel(15)->seconds();

    $submitted = app(BillingService::class)->submitBatch($batch->fresh());

    expect($transport->requests)->toHaveCount(2)
        ->and($transport->requests[1]['key'])->toBe($transport->requests[0]['key'])
        ->and($transport->requests[1]['xml'])->toBe($transport->requests[0]['xml'])
        ->and($transport->requests[1]['xml'])->toContain('<CNES>1234567</CNES>')
        ->and($transport->requests[1]['xml'])->not->toContain('7654321')
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and(btrSubmissionDocuments($batch))->toHaveCount(1)
        ->and(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sent)
        ->and($submitted->status)->toBe(BillingBatchStatus::Submitted);
})->with([
    'resposta perdida (exceção de transporte)' => ['lost', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'],
    'HTTP 500 ao montar a resposta'            => ['server_error', 'erro interno do servidor'],
]);

it('[IDEMPOTÊNCIA] enquanto a entrega está em dúvida o lote não aceita alterações (guia cancelada, lote cancelado) e o reenvio leva TODAS as guias originais', function () {
    $batch     = btrBatch(claims: 2);
    $transport = btrTransport(['lost', 'ok']);

    expect(fn () => app(BillingService::class)->submitBatch($batch->fresh()))->toThrow(ValidationException::class);

    $claim = BillingClaim::query()->where('batch_id', $batch->id)->orderBy('id')->firstOrFail();

    // Cancelar a guia agora faria o faturamento divergir do XML que a operadora talvez já tenha.
    expect(fn () => app(BillingAdjustmentService::class)->cancelClaim($claim, 'Guia lançada por engano'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(BillingAdjustmentService::class)->cancelBatch($batch->fresh(), 'Cancelar tudo'))
        ->toThrow(ValidationException::class);

    expect($claim->fresh()->status->value)->toBe('draft');

    $this->travel(15)->seconds();
    app(BillingService::class)->submitBatch($batch->fresh());

    expect($transport->requests)->toHaveCount(2)
        ->and($transport->requests[1]['xml'])->toBe($transport->requests[0]['xml'])
        ->and(btrGuideCount($transport->requests[1]['xml']))->toBe(2)
        ->and(TissTransmission::query()->count())->toBe(1);
});

it('[IDEMPOTÊNCIA] depois da dúvida, uma retomada que falha SEM prova de recusa (erro da operadora, 401, endpoint ausente) não libera o lote: o CNES muda e o reenvio ainda leva o XML e a chave originais', function (string $retryOutcome, string $reason, string $messageKey) {
    $batch     = btrBatch(claims: 2);
    $transport = btrTransport(['lost', $retryOutcome, 'ok']);

    // 1ª: a resposta se perdeu — a operadora pode ter o lote.
    expect(fn () => app(BillingService::class)->submitBatch($batch->fresh()))->toThrow(ValidationException::class);
    expect(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sending);

    // 2ª (mesma chave): falha que NÃO diz nada sobre o 1º POST — nem uma recusa dele nem a ausência dele.
    $this->travel(15)->seconds();
    expect(fn () => app(BillingService::class)->submitBatch($batch->fresh()))
        ->toThrow(ValidationException::class, __($messageKey, ['reason' => $reason]));

    expect(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sending)
        ->and(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Timeout)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('uncertain');

    // Continua sem aceitar alteração: cancelar guia/lote faria o faturamento divergir do XML que a operadora talvez tenha.
    $claim = BillingClaim::query()->where('batch_id', $batch->id)->orderBy('id')->firstOrFail();
    expect(fn () => app(BillingAdjustmentService::class)->cancelClaim($claim, 'Guia lançada por engano'))->toThrow(ValidationException::class);
    expect(fn () => app(BillingAdjustmentService::class)->cancelBatch($batch->fresh(), 'Cancelar tudo'))->toThrow(ValidationException::class);

    // E o dado cadastral mudar também não gera XML novo.
    $batch->fresh()->entity->update(['cnes' => '7654321']);
    $this->travel(15)->seconds();

    $submitted = app(BillingService::class)->submitBatch($batch->fresh());

    // 'no_endpoint' nunca saiu da clínica: só a 1ª e a 3ª chegaram ao transporte.
    $left = $retryOutcome === 'no_endpoint' ? 2 : 3;

    expect($transport->requests)->toHaveCount($left)
        ->and(collect($transport->requests)->pluck('key')->unique())->toHaveCount(1)
        ->and(collect($transport->requests)->pluck('xml')->unique())->toHaveCount(1)
        ->and($transport->requests[$left - 1]['xml'])->toContain('<CNES>1234567</CNES>')
        ->and(btrGuideCount($transport->requests[$left - 1]['xml']))->toBe(2)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and(TissTransmission::query()->sole()->attempt)->toBe(3)
        ->and(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(btrSubmissionDocuments($batch))->toHaveCount(1)
        ->and(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sent)
        ->and($submitted->status)->toBe(BillingBatchStatus::Submitted);
})->with([
    'a operadora recusou com erro (HTTP 422)' => ['error', 'erro interno', 'tiss_send.delivery_still_unknown'],
    'a operadora recusou a credencial (401)'  => ['unauthorized', 'credencial recusada', 'tiss_send.delivery_still_unknown'],
    'endpoint removido (falha local)'         => ['no_endpoint', 'Endpoint de submissão TISS não configurado para a operadora.', 'tiss_send.delivery_still_unknown'],
    'o servidor falhou de novo (HTTP 500)'    => ['server_error', 'erro interno do servidor', 'tiss_send.delivery_unknown'],
]);

it('sem dúvida anterior a resposta de erro da operadora continua sendo definitiva: o lote volta a ser editável e o XML novo sai com chave nova', function () {
    $batch     = btrBatch(claims: 2);
    $transport = btrTransport(['error', 'ok']);

    expect(fn () => app(BillingService::class)->submitBatch($batch->fresh()))->toThrow(ValidationException::class);
    expect(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Error);

    $claim = BillingClaim::query()->where('batch_id', $batch->id)->orderBy('id')->firstOrFail();
    app(BillingAdjustmentService::class)->cancelClaim($claim, 'Guia lançada por engano');

    $this->travel(15)->seconds();
    app(BillingService::class)->submitBatch($batch->fresh());

    expect($transport->requests)->toHaveCount(2)
        ->and($transport->requests[1]['key'])->not->toBe($transport->requests[0]['key'])
        ->and(btrGuideCount($transport->requests[1]['xml']))->toBe(1);
});

it('[IDEMPOTÊNCIA] tentativa INTERROMPIDA (worker morto, lease expirado): o faturamento retoma com a mesma chave, sem regerar o XML', function () {
    $batch     = btrBatch();
    $transport = btrTransport(['crash', 'ok']);

    expect(fn () => app(BillingService::class)->submitBatch($batch->fresh()))
        ->toThrow(ValidationException::class);

    // Morreu sem gravar resposta: transmissão e lote ficaram "enviando".
    expect(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Sending)
        ->and(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sending);

    $this->travel((int) config('tiss.transport.lease_seconds') + 60)->seconds();

    app(BillingService::class)->submitBatch($batch->fresh());

    expect($transport->requests)->toHaveCount(2)
        ->and($transport->requests[1]['key'])->toBe($transport->requests[0]['key'])
        ->and($transport->requests[1]['xml'])->toBe($transport->requests[0]['xml'])
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and(TissTransmission::query()->sole()->attempt)->toBe(2)
        ->and(btrSubmissionDocuments($batch))->toHaveCount(1)
        ->and(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sent);
});

// ─────────────────────────────────────────────────────────────────────────────
// Reenvio FORÇADO de lote já ENTREGUE (achado via revisão adversarial Codex, 18ª
// rodada): se falhava (401, endpoint ausente...), a transmissão confirmada virava
// "falhou", o lote "erro" (editável) e a chave "failed" — mudar o dado e gerar o XML
// de novo saía com chave nova, e a operadora que já tinha o lote o receberia outra
// vez. E, dando certo ou não, o reenvio rebaixava lote e guias já processados/pagos.
// ─────────────────────────────────────────────────────────────────────────────

it('[CONFIRMADO] reenvio forçado (dê certo ou falhe) nunca rebaixa lote nem guias já entregues e o XML enviado segue imutável mesmo com o dado cadastral mudado', function (TissBatchStatus $status, TissGuideStatus $guideStatus, string $outcome) {
    $batch     = btrBatch(claims: 2);
    $transport = btrTransport(['ok', $outcome]);

    app(BillingService::class)->submitBatch($batch->fresh());

    // O lote andou depois do aceite (retorno processado, pagamento).
    btrTissBatch($batch)->update(['status' => $status->value]);
    btrTissBatch($batch)->guides()->update(['status' => $guideStatus->value]);

    $sent           = btrSubmissionDocuments($batch)[0];
    $transmissionId = TissTransmission::query()->sole()->id;

    $this->travel(15)->seconds();

    $forced = fn () => app(TissWorkflowService::class)->sendBatchNow(btrTissBatch($batch), force: true);

    if ($outcome === 'ok') {
        $forced();
    } else {
        expect($forced)->toThrow(RuntimeException::class);
    }

    expect(btrTissBatch($batch)->status)->toBe($status)
        ->and(btrTissBatch($batch)->guides()->pluck('tiss_guides.status')->map(fn ($s) => $s->value)->unique()->all())->toBe([$guideStatus->value])
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and(TissTransmission::query()->sole()->id)->toBe($transmissionId)
        ->and(TissTransmission::query()->sole()->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(TissIdempotencyKey::query()->sole()->status)->toBe('completed');

    // O dado cadastral muda e o XML é pedido de novo: o documento continua sendo o enviado.
    $batch->fresh()->entity->update(['cnes' => '7654321']);
    $this->travel(15)->seconds();

    $downloaded = app(BillingService::class)->generateBatchXml($batch->fresh());

    $left = $outcome === 'no_endpoint' ? 1 : 2; // 'no_endpoint' nunca saiu da clínica

    expect($downloaded->xml_path)->toBe($sent->file_path)
        ->and(btrSubmissionDocuments($batch))->toHaveCount(1)
        ->and($transport->requests)->toHaveCount($left)
        ->and(collect($transport->requests)->pluck('key')->unique())->toHaveCount(1)
        ->and(btrTissBatch($batch)->status)->toBe($status);
})->with(function () {
    $cases = [
        'enviado'           => [TissBatchStatus::Sent, TissGuideStatus::Sent],
        'confirmado'        => [TissBatchStatus::Acknowledged, TissGuideStatus::Acknowledged],
        'processado'        => [TissBatchStatus::Processed, TissGuideStatus::Paid],
        'parcialmente pago' => [TissBatchStatus::PartiallyPaid, TissGuideStatus::PartiallyDenied],
        'pago'              => [TissBatchStatus::Paid, TissGuideStatus::Paid],
    ];

    $outcomes = ['ok' => 'reenvio dá certo', 'unauthorized' => 'HTTP 401', 'error' => 'HTTP 422', 'server_error' => 'HTTP 500', 'lost' => 'resposta perdida', 'no_endpoint' => 'endpoint ausente'];

    foreach ($cases as $label => [$status, $guideStatus]) {
        foreach ($outcomes as $outcome => $outcomeLabel) {
            yield "{$label} + {$outcomeLabel}" => [$status, $guideStatus, $outcome];
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// O que NÃO pode mudar: dado novo = identidade nova; 1º envio = XML fresco
// ─────────────────────────────────────────────────────────────────────────────

it('guia retirada do lote entre as tentativas: o XML é regerado e a chave é outra (só o conteúdo IGUAL reaproveita)', function () {
    $batch     = btrBatch(claims: 2);
    $transport = btrTransport(['error', 'ok']);

    expect(fn () => app(BillingService::class)->submitBatch($batch->fresh()))->toThrow(ValidationException::class);

    // Lote TISS em erro ainda aceita tirar guia (DetachGuideFromBatchAction).
    $claim = BillingClaim::query()->where('batch_id', $batch->id)->orderBy('id')->firstOrFail();
    app(BillingAdjustmentService::class)->cancelClaim($claim, 'Guia lançada por engano');

    $this->travel(15)->seconds();

    app(BillingService::class)->submitBatch($batch->fresh());

    expect($transport->requests)->toHaveCount(2)
        ->and($transport->requests[1]['key'])->not->toBe($transport->requests[0]['key'])
        ->and(btrGuideCount($transport->requests[0]['xml']))->toBe(2)
        ->and(btrGuideCount($transport->requests[1]['xml']))->toBe(1)
        ->and(btrSubmissionDocuments($batch))->toHaveCount(2)
        ->and(TissTransmission::query()->count())->toBe(2);
});

it('antes de QUALQUER envio o faturamento continua regerando o XML (horário fresco no cabeçalho)', function () {
    $batch     = btrBatch();
    $transport = btrTransport();

    // "Baixar XML" antes de enviar: gera o documento A, que nunca foi à operadora.
    app(BillingService::class)->generateBatchXml($batch->fresh());
    $first = btrSubmissionDocuments($batch);

    $this->travel(15)->seconds();

    app(BillingService::class)->submitBatch($batch->fresh());

    $documents = btrSubmissionDocuments($batch);

    expect($first)->toHaveCount(1)
        ->and($documents)->toHaveCount(2)
        ->and(btrXmlTime($transport->requests[0]['xml']))->toBe('12:00:15')
        ->and($batch->fresh()->xml_path)->toBe($documents[1]->file_path);
});

// ─────────────────────────────────────────────────────────────────────────────
// Baixar o XML de um lote já enviado devolve O QUE FOI ENVIADO
// ─────────────────────────────────────────────────────────────────────────────

it('"Baixar XML" de lote já enviado devolve o XML enviado e não desfaz o "enviado" do lote TISS nem das guias', function () {
    $batch = btrBatch();
    btrTransport();

    $submitted = app(BillingService::class)->submitBatch($batch->fresh());
    $sent      = btrSubmissionDocuments($batch)[0];

    $this->travel(15)->seconds();

    $downloaded = app(BillingService::class)->generateBatchXml($submitted->fresh());

    expect(btrSubmissionDocuments($batch))->toHaveCount(1)
        ->and($downloaded->xml_path)->toBe($sent->file_path)
        ->and(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sent)
        ->and(btrTissBatch($batch)->guides()->pluck('tiss_guides.status')->map(fn ($s) => $s->value)->unique()->all())
        ->toBe([TissGuideStatus::Sent->value]);
});

it('"Baixar XML" de lote já enviado ignora dado cadastral alterado depois: o documento é o que a operadora recebeu', function () {
    $batch = btrBatch();
    btrTransport();

    $submitted = app(BillingService::class)->submitBatch($batch->fresh());
    $sent      = btrSubmissionDocuments($batch)[0];

    // O CNES da clínica entra no XML (contratadoExecutante): regerar agora sairia diferente.
    $submitted->entity->update(['cnes' => '7654321']);
    $this->travel(15)->seconds();

    $downloaded = app(BillingService::class)->generateBatchXml($submitted->fresh());

    expect(btrSubmissionDocuments($batch))->toHaveCount(1)
        ->and($downloaded->xml_path)->toBe($sent->file_path)
        ->and(btrTissBatch($batch)->status)->toBe(TissBatchStatus::Sent);
});
