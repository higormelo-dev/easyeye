<?php

declare(strict_types=1);

/**
 * send() exige validação XSD efetiva antes de usar o transporte REAL —
 * achado via auditoria de encerramento, EE-07 ("exigir validação efetiva
 * no transporte real") — e o XML precisa estar Validated/Signed depois da
 * (re)geração: um XML que o XSD REJEITOU tem metadata.schema preenchido
 * (achado via revisão adversarial Codex). Transporte mock (padrão do
 * projeto) nunca passa por estes guards — ver SendTissBatchIdempotencyTest.php,
 * que usa o mock e nunca aciona a exceção mesmo com metadata.schema ausente.
 *
 * A guarda só é exigida quando uma requisição vai sair, e o XML anterior a ela
 * (schema=null) é revalidado no lugar em vez de travar a retomada de uma entrega
 * em dúvida — ver SendTissBatchLegacyXmlUpgradeTest.php (24ª rodada).
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissTransmissionStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissOperator, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\{GenerateTissXmlService, SendTissBatchService, ValidateTissXmlService};
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, MockTissTransportClient, TissTransportContract};
use App\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function sendGuardFixture(?array $xmlMetadata): TissBatch
{
    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);

    TissEntityOperatorCredential::create([
        'entity_id'           => $entity->id,
        'operator_id'         => $operator->id,
        'environment'         => (string) config('tiss.transport.environment', 'production'),
        'endpoint_submission' => 'https://operadora.teste/tiss',
        'active'              => true,
    ]);

    $batch = TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-GUARD-0001',
        'reference_month' => now()->format('Y-m'),
        'status'          => TissBatchStatus::XmlReady->value,
    ]);

    $xmlContent  = '<ansTISS><mensagemTISS>teste</mensagemTISS></ansTISS>';
    $xmlDocument = TissXmlDocument::create([
        'entity_id'     => $entity->id,
        'operator_id'   => $operator->id,
        'batch_id'      => $batch->id,
        'document_type' => 'batch_submission',
        'direction'     => TissXmlDirection::Outbound->value,
        'status'        => TissXmlStatus::Validated->value,
        'file_path'     => "tiss/{$entity->id}/batches/{$batch->id}.xml",
        'file_hash'     => hash('sha256', $xmlContent),
        'xml_content'   => $xmlContent,
        'generated_at'  => now(),
        'validated_at'  => now(),
        'metadata'      => $xmlMetadata,
    ]);

    $batch->update(['xml_document_id' => $xmlDocument->id]);

    return $batch->fresh();
}

it('[GAP] transporte HTTP real bloqueia envio sem validação XSD efetiva — nenhuma requisição chega a sair (EE-07)', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fake();

    // metadata.schema ausente = validou só "bem formado" (schema não
    // encontrado ou require_schema_validation desligado), nunca
    // conformidade real contra o XSD.
    $batch = sendGuardFixture(['schema' => null]);

    expect(fn () => app(SendTissBatchService::class)->send($batch))
        ->toThrow(RuntimeException::class, 'validação efetiva');

    Http::assertNothingSent();
});

it('transporte HTTP real segue adiante quando a validação XSD foi efetiva (metadata.schema presente)', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fake(fn () => Http::response('<ansTISS></ansTISS>', 200));

    $batch = sendGuardFixture(['schema' => storage_path('app/tiss/schemas/202603/tissV4_03_00.xsd')]);

    // Não lança a exceção do guard — chega a tentar a requisição real (fake).
    app(SendTissBatchService::class)->send($batch, throwOnError: false);

    Http::assertSent(fn ($request) => $request->url() === 'https://operadora.teste/tiss');
});

/**
 * XML que o XSD REJEITA, no estado exato em que generateBatch() o persiste:
 * status Invalid + metadata.schema com o caminho do XSD que rejeitou.
 * Validador REAL contra o XSD real da versão 202603 — não um valor chutado.
 */
function bindXmlGeneratorRejectedByXsd(): array
{
    $xml        = '<ansTISS><mensagemTISS>incompleto</mensagemTISS></ansTISS>';
    $validation = app(ValidateTissXmlService::class)->validate($xml, '202603');

    test()->mock(GenerateTissXmlService::class, function ($mock) use ($xml, $validation) {
        $mock->shouldReceive('generateBatch')->once()->andReturnUsing(function (TissBatch $batch) use ($xml, $validation) {
            $document = TissXmlDocument::create([
                'entity_id'         => $batch->entity_id,
                'operator_id'       => $batch->operator_id,
                'batch_id'          => $batch->id,
                'document_type'     => 'batch_submission',
                'direction'         => TissXmlDirection::Outbound->value,
                'status'            => TissXmlStatus::Invalid->value,
                'file_path'         => "tiss/{$batch->entity_id}/batches/{$batch->id}-invalid.xml",
                'file_hash'         => hash('sha256', $xml),
                'xml_content'       => $xml,
                'generated_at'      => now(),
                'validated_at'      => now(),
                'validation_errors' => $validation['errors'],
                'metadata'          => ['schema' => $validation['schema']],
            ]);

            $batch->update(['xml_document_id' => $document->id, 'status' => TissBatchStatus::Error->value]);

            return $document;
        });
    });

    return $validation;
}

it('[GAP] XML REJEITADO pelo XSD na regeneração (status invalid, metadata.schema preenchido) nunca chega ao transporte real — achado via revisão adversarial Codex', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fake();

    $validation = bindXmlGeneratorRejectedByXsd();

    // Causa raiz: o validador devolve o caminho do XSD mesmo quando o XSD
    // REJEITA o XML — "schema presente" nunca significou "validação passou".
    expect($validation['valid'])->toBeFalse()
        ->and($validation['schema'])->not->toBeNull()
        ->and($validation['errors'])->not->toBeEmpty();

    // O documento atual do lote não está validado → send() regenera, e a
    // regeneração volta Invalid.
    $batch = sendGuardFixture(['schema' => $validation['schema']]);
    $batch->xmlDocument->update(['status' => TissXmlStatus::Invalid->value]);

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, 'rejeitado na validação');

    Http::assertNothingSent();
    expect(TissTransmission::query()->count())->toBe(0);
});

it('transporte mock (padrão do projeto) segue simulando o envio mesmo com XML rejeitado — o guard de status vale só pro transporte real, como o do EE-07', function () {
    app()->bind(TissTransportContract::class, MockTissTransportClient::class);

    $validation = bindXmlGeneratorRejectedByXsd();

    $batch = sendGuardFixture(['schema' => $validation['schema']]);
    $batch->xmlDocument->update(['status' => TissXmlStatus::Invalid->value]);

    // Fixtures e demos com dados mínimos geram XML que o XSD rejeita e
    // dependem do mock pra fluir (Financeiro/Faturamento usam o mock por
    // padrão): nada é transmitido de verdade, então não há o que proteger.
    $transmission = app(SendTissBatchService::class)->send($batch->fresh());

    expect($transmission->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(TissTransmission::query()->count())->toBe(1);
});

it('mensagem do bloqueio traz os erros do XSD e respeita o idioma do usuário (pt_BR e en)', function () {
    app()->bind(TissTransportContract::class, HttpTissTransportClient::class);
    Http::fake();

    $validation = bindXmlGeneratorRejectedByXsd();
    $firstError = $validation['errors'][0];

    $batch = sendGuardFixture(['schema' => $validation['schema']]);
    $batch->xmlDocument->update(['status' => TissXmlStatus::Invalid->value]);

    try {
        app(SendTissBatchService::class)->send($batch->fresh());
        $this->fail('send() deveria bloquear o XML rejeitado.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Erros:')->toContain($firstError);
    }

    app()->setLocale('en');
    // Segunda regeneração (o mock acima aceita uma chamada só) — novo mock.
    bindXmlGeneratorRejectedByXsd();
    $batch->xmlDocument()->update(['status' => TissXmlStatus::Invalid->value]);

    try {
        app(SendTissBatchService::class)->send($batch->fresh());
        $this->fail('send() deveria bloquear o XML rejeitado.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Submission blocked')->toContain('Errors:');
    }

    Http::assertNothingSent();
});
