<?php

declare(strict_types=1);

/**
 * Bloqueio do envio sem configuração: com o transporte real (driver http), o lote só sai com endereço do
 * webservice e com a credencial revisada por um administrador (a provisória do 1º faturamento não vale). A trava
 * roda antes de reservar ou reivindicar qualquer coisa. Com o mock, nada é exigido, mas o protocolo é SIMULADO-.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Exceptions\TissCredentialIncompleteException;
use App\Domains\Tiss\Jobs\SendTissBatchJob;
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissIdempotencyKey, TissOperator, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\SendTissBatchService;
use App\Domains\Tiss\Services\Transport\{MockTissTransportClient, TissTransportContract};
use App\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Lote com XML validado e credencial ativa no ambiente do envio. */
function tcrBatch(array $credential = [], ?string $operatorBaseUrl = null): TissBatch
{
    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste', 'webservice_base_url' => $operatorBaseUrl]);

    TissEntityOperatorCredential::create([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
        'environment' => (string) config('tiss.transport.environment', 'production'),
        'active'      => true,
        ...$credential,
    ]);

    $batch = TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-CRED-0001',
        'reference_month' => now()->format('Y-m'),
        'status'          => TissBatchStatus::XmlReady->value,
    ]);

    $xml      = '<ansTISS><mensagemTISS>teste</mensagemTISS></ansTISS>';
    $document = TissXmlDocument::create([
        'entity_id'     => $entity->id,
        'operator_id'   => $operator->id,
        'batch_id'      => $batch->id,
        'document_type' => 'batch_submission',
        'direction'     => TissXmlDirection::Outbound->value,
        'status'        => TissXmlStatus::Validated->value,
        'file_path'     => "tiss/{$entity->id}/batches/{$batch->id}.xml",
        'file_hash'     => hash('sha256', $xml),
        'xml_content'   => $xml,
        'generated_at'  => now(),
        'validated_at'  => now(),
        'metadata'      => ['schema' => storage_path('app/tiss/schemas/202603/tissV4_03_00.xsd')],
    ]);

    $batch->update(['xml_document_id' => $document->id]);

    return $batch->fresh();
}

/** Transporte que conta as chamadas e confirma. */
function tcrTransport(): object
{
    $transport = new class() implements TissTransportContract {
        public int $calls = 0;

        public function sendBatch(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, array $context = []): array
        {
            $this->calls++;

            return ['ok' => true, 'status_code' => 200, 'response_body' => '<ansTISS/>', 'protocol_number' => 'PRT-1', 'protocol_status' => 'accepted', 'error_code' => null, 'error_message' => null, 'response_time_ms' => 1];
        }
    };

    app()->instance(TissTransportContract::class, $transport);

    return $transport;
}

it('transporte real bloqueia credencial sem endereço ou provisória, antes de reservar qualquer coisa', function (array $credential, ?string $baseUrl, array $missing) {
    config(['tiss.transport.driver' => 'http']);
    $batch     = tcrBatch($credential, $baseUrl);
    $transport = tcrTransport();

    try {
        app(SendTissBatchService::class)->send($batch);
        $this->fail('esperava TissCredentialIncompleteException');
    } catch (TissCredentialIncompleteException $e) {
        expect($e->getMessage())->toContain('Operadora Teste')
            ->and($e->getMessage())->toContain(__('tiss_credentials.environments.' . config('tiss.transport.environment', 'production')));

        foreach ($missing as $item) {
            expect($e->getMessage())->toContain(__('tiss_credentials.missing.' . $item));
        }
    }

    expect($transport->calls)->toBe(0)
        ->and(TissTransmission::query()->count())->toBe(0)
        ->and(TissIdempotencyKey::query()->count())->toBe(0)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::XmlReady);
})->with([
    'sem endereço'                   => [[], null, ['endpoint']],
    'provisória com endereço padrão' => [['metadata' => ['provisioned_by' => 'x']], 'https://ws.operadora.test', ['review']],
    'provisória sem endereço nenhum' => [['metadata' => ['provisioned_by' => 'x']], null, ['endpoint', 'review']],
]);

it('transporte real envia com endereço e credencial revisada, mesmo sem autenticação HTTP (operadora pode liberar por IP)', function () {
    config(['tiss.transport.driver' => 'http']);
    $batch     = tcrBatch(['endpoint_submission' => 'https://ws.operadora.test/tiss']);
    $transport = tcrTransport();

    app(SendTissBatchService::class)->send($batch);

    expect($transport->calls)->toBe(1)
        ->and(TissTransmission::query()->count())->toBe(1);
});

it('job: credencial incompleta falha já, sem retentativa, e leva o lote a erro com o motivo', function () {
    config(['tiss.transport.driver' => 'http']);
    $batch     = tcrBatch();
    $transport = tcrTransport();

    SendTissBatchJob::dispatch((string) $batch->id, (string) $batch->entity_id);

    $batch->refresh();

    expect($batch->status)->toBe(TissBatchStatus::Error)
        ->and((string) $batch->last_error)->toContain(__('tiss_credentials.missing.endpoint'))
        ->and($transport->calls)->toBe(0);
});

it('com o mock nada é exigido, mas o protocolo e a resposta dizem que é simulado', function () {
    $batch = tcrBatch();
    app()->instance(TissTransportContract::class, new MockTissTransportClient());

    $transmission = app(SendTissBatchService::class)->send($batch);
    $protocol     = (string) $transmission->fresh()->protocol?->protocol_number;

    expect($protocol)->toStartWith(MockTissTransportClient::PROTOCOL_PREFIX)
        ->and(MockTissTransportClient::isSimulatedProtocol($protocol))->toBeTrue()
        ->and(json_encode($transmission->fresh()->response_payload))->toContain('SIMULADO');
});
