<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\DTOs\ReceivedTissReturn;
use App\Domains\Tiss\Enums\{TissProtocolStatus, TissReturnStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Jobs\ProcessTissReturnJob;
use App\Domains\Tiss\Models\{TissBatch, TissProtocol, TissReturn, TissXmlDocument};
use App\Domains\Tiss\Parsers\TissReturnParser;
use App\Domains\Tiss\Surgical\SurgicalAuthorizationResponseService;
use Illuminate\Support\Facades\{DB, Log, Storage};

class ReceiveTissResponseService
{
    public function __construct(
        private readonly TissReturnParser $parser,
        private readonly ValidateTissXmlService $validateTissXmlService,
        private readonly ProcessTissReturnService $processTissReturnService,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
        private readonly SurgicalAuthorizationResponseService $authorizationResponses,
    ) {
    }

    /**
     * Recebe o XML de retorno da operadora: grava documento e retorno e processa
     * (ou despacha o processamento). IDEMPOTENTE POR ARQUIVO — a identidade é
     * (clínica, operadora, SHA-256 dos bytes), a mesma para a importação síncrona da
     * tela e para o caminho da fila:
     *
     * - retorno do arquivo já `processed`: nada é gravado, travado nem despachado e o
     *   resultado volta com `alreadyImported`;
     * - retorno do mesmo arquivo ainda `received`/`parsed`/`error` (a 1ª tentativa
     *   caiu depois do commit): é RETOMADO — o MESMO retorno é processado, nunca
     *   nasce o 2º (sem isso o "tente novamente" que a tela manda fazer não teria
     *   saída). `$protocol`, `$batch` e `$metadata` só valem para retorno novo;
     * - senão, importação normal.
     *
     * Por quê: ProcessGlosaReturnService SOMA em guide.denied_amount e chaveia a
     * glosa por return_id, então o mesmo arquivo importado duas vezes virava dois
     * retornos e uma glosa de 150,50 virava 301,00 (achado da 26ª rodada). A
     * identidade é de BYTES exatos: arquivo regravado (BOM, CRLF) ou reexportado
     * pela operadora com data/hora nova é outro arquivo.
     *
     * Serialização: pg_advisory_xact_lock sobre (clínica, operadora, hash), tomado
     * como a 1ª instrução da transação, e releitura sob a trava (READ COMMITTED
     * enxerga o commit do concorrente). Só PostgreSQL; nos demais drivers vale a
     * releitura, que barra a repetição sequencial. INVARIANTES — quebrar qualquer
     * uma reintroduz duplicata ou deadlock (40P01):
     *
     * 1. a chave é a 1ª trava da transação, antes de Storage::put (o rollback não
     *    desfaz arquivo em disco) e de qualquer INSERT/UPDATE;
     * 2. nenhum FOR UPDATE de lote/retorno/protocolo sob ela (se um dia for preciso,
     *    é o lote e ANTES do INSERT do documento);
     * 3. process() e o dispatch ficam FORA da transação: dois receive() de arquivos
     *    diferentes do mesmo lote seguram KEY SHARE nele e cada process() pede FOR
     *    UPDATE (deadlock de upgrade);
     * 4. receive() nunca roda dentro de transação que já segure lote ou protocolo
     *    (quem espera a chave segurando o lote x quem tem a chave esperando o KEY
     *    SHARE do lote = ciclo);
     * 5. nenhuma outra rotina toma esta chave (importLockKey()).
     *
     * @param array<string, mixed> $metadata
     */
    public function receive(
        string $entityId,
        string $operatorId,
        string $xmlContent,
        ?TissProtocol $protocol = null,
        ?TissBatch $batch = null,
        bool $processSynchronously = false,
        array $metadata = [],
    ): ReceivedTissReturn {
        $fileHash = hash('sha256', $xmlContent);

        // Atalho SEM trava, só para o estado final (`processed` não volta atrás):
        // poupa o parse e a validação XSD de até 10 MB e a fila da chave. A decisão
        // de verdade é a releitura sob a trava, abaixo.
        $known = $this->findImportedReturn($entityId, $operatorId, $fileHash);

        if ($known?->status === TissReturnStatus::Processed) {
            return $this->alreadyImported($known);
        }

        // Resposta de AUTORIZAÇÃO a uma guia cirúrgica (solicitação de internação / anexo de
        // OPME): vira evento no log do caso, não retorno de lote. Idempotente por arquivo
        // (mesma identidade e mesma chave de advisory lock, tratadas lá). Qualquer outro XML
        // segue o fluxo de retorno de lote, inalterado.
        $authorization = $this->authorizationResponses->maybeReceive($entityId, $operatorId, $xmlContent, $fileHash, $metadata);

        if ($authorization !== null) {
            return $authorization;
        }

        $parsed = $this->parser->parse($xmlContent);

        $protocol ??= $this->resolveProtocol($entityId, $operatorId, $parsed['protocol_number'] ?? null);
        $batch ??= $protocol?->batch;

        if (! $batch && ! empty($parsed['batch_number'])) {
            $batch = TissBatch::query()
                ->forEntity($entityId)
                ->where('operator_id', $operatorId)
                ->where('batch_number', $parsed['batch_number'])
                ->first();
        }

        $validation = $this->validateTissXmlService->validate($xmlContent, $batch?->version?->code);

        $return = DB::transaction(function () use ($entityId, $operatorId, $xmlContent, $fileHash, $parsed, $protocol, $batch, $validation, $metadata): TissReturn {
            $this->lockImport($entityId, $operatorId, $fileHash);

            // Relê SOB a trava: o concorrente que a tinha antes já comitou o retorno.
            // Nada é escrito aqui — nem o arquivo em disco, nem documento, protocolo,
            // histórico ou retorno.
            $existing = $this->findImportedReturn($entityId, $operatorId, $fileHash);

            if ($existing !== null) {
                return $existing;
            }

            // Nome pelo hash do conteúdo, nunca por texto vindo do XML: um numeroProtocolo
            // com ".." escreveria fora do prefixo da clínica. Mesmos bytes, mesmo caminho.
            $relativePath = sprintf('tiss/%s/returns/%s.xml', $entityId, $fileHash);

            Storage::disk()->put($relativePath, $xmlContent);

            $xmlDocument = TissXmlDocument::query()->create([
                'entity_id'         => $entityId,
                'operator_id'       => $operatorId,
                'batch_id'          => $batch?->id,
                'version_id'        => $batch?->version_id,
                'document_type'     => 'return_xml',
                'direction'         => TissXmlDirection::Inbound->value,
                'status'            => ($validation['valid'] ?? false) ? TissXmlStatus::Validated->value : TissXmlStatus::Invalid->value,
                'file_path'         => $relativePath,
                'file_hash'         => $fileHash,
                'xml_content'       => $xmlContent,
                'generated_at'      => now(),
                'validated_at'      => now(),
                'validation_errors' => array_values(array_merge($validation['errors'] ?? [], $validation['warnings'] ?? [])),
                'metadata'          => array_merge([
                    'source'          => 'receive_tiss_response',
                    'protocol_number' => $parsed['protocol_number'] ?? null,
                ], $metadata),
            ]);

            $resolvedProtocol = $protocol;

            if (! $resolvedProtocol && ! empty($parsed['protocol_number'])) {
                $resolvedProtocol = TissProtocol::query()->firstOrCreate(
                    [
                        'entity_id'       => $entityId,
                        'operator_id'     => $operatorId,
                        'protocol_number' => (string) $parsed['protocol_number'],
                    ],
                    [
                        'batch_id'        => $batch?->id,
                        'xml_document_id' => $xmlDocument->id,
                        'status'          => TissProtocolStatus::Received->value,
                        'acknowledged_at' => now(),
                        'details'         => ['source' => 'return_xml'],
                    ],
                );
            }

            if ($resolvedProtocol) {
                $protocolStatus = $this->mapProtocolStatus((string) ($parsed['status'] ?? 'received'));

                $resolvedProtocol->update([
                    'batch_id'        => $resolvedProtocol->batch_id ?: $batch?->id,
                    'xml_document_id' => $resolvedProtocol->xml_document_id ?: $xmlDocument->id,
                    'status'          => $protocolStatus->value,
                    'details'         => array_merge((array) ($resolvedProtocol->details ?? []), [
                        'last_return_received_at' => now()->toIso8601String(),
                    ]),
                ]);

                $this->logStatusTransitionService->record(
                    entityId: $entityId,
                    contextType: 'protocol',
                    contextId: (string) $resolvedProtocol->id,
                    currentStatus: $protocolStatus->value,
                    reason: 'Retorno XML recebido da operadora.',
                );
            }

            $return = TissReturn::query()->create([
                'entity_id'       => $entityId,
                'operator_id'     => $operatorId,
                'version_id'      => $batch?->version_id,
                'protocol_id'     => $resolvedProtocol?->id,
                'xml_document_id' => $xmlDocument->id,
                'return_type'     => 'batch_return',
                'status'          => TissReturnStatus::Parsed->value,
                'received_at'     => now(),
                'summary'         => $parsed,
                'raw_payload'     => $xmlContent,
                'error_message'   => ($validation['valid'] ?? false) ? null : implode(' | ', $validation['errors'] ?? []),
                'metadata'        => [
                    'validation_schema' => $validation['schema'] ?? null,
                    'batch_id'          => $batch?->id,
                    'protocol_number'   => $parsed['protocol_number'] ?? null,
                ],
            ]);

            $this->logStatusTransitionService->record(
                entityId: $entityId,
                contextType: 'return',
                contextId: (string) $return->id,
                currentStatus: TissReturnStatus::Parsed->value,
                reason: 'Retorno TISS parseado e persistido.',
            );

            return $return;
        });

        // Fora da transação (a chave já foi solta). O retorno recém-criado nasce
        // `parsed`, então isto só pega o que já existia e foi processado enquanto
        // esta chamada esperava a trava.
        if ($return->status === TissReturnStatus::Processed) {
            return $this->alreadyImported($return);
        }

        if ($processSynchronously) {
            return new ReceivedTissReturn($this->processTissReturnService->process($return));
        }

        if ((bool) config('tiss.returns.auto_process', true)) {
            ProcessTissReturnJob::dispatch((string) $return->id)
                ->onQueue((string) config('tiss.queues.returns', 'tiss-returns'));
        }

        return new ReceivedTissReturn($return);
    }

    /**
     * Chave do advisory lock da importação — pública para os testes disputarem o
     * MESMO lock. Bigint estável (60 bits do sha1, sempre positivo), como as demais
     * chaves do repositório.
     */
    public static function importLockKey(string $entityId, string $operatorId, string $fileHash): int
    {
        return (int) hexdec(substr(sha1(implode('|', ['tiss_return_import', $entityId, $operatorId, $fileHash])), 0, 15));
    }

    /**
     * PostgreSQL: advisory lock de transação até o commit do chamador. Demais
     * drivers: sem lock (vale a releitura em receive()).
     */
    private function lockImport(string $entityId, string $operatorId, string $fileHash): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('select pg_advisory_xact_lock(?)', [self::importLockKey($entityId, $operatorId, $fileHash)], false);
    }

    /**
     * O retorno que já existe para estes bytes nesta clínica e operadora. Parte do
     * RETORNO (é ele que carrega o efeito financeiro; só receive() liga retorno a
     * documento, então não há filtro de document_type). `forEntity` explícito porque
     * o escopo de clínica é inerte em job/CLI; o entity_id da subconsulta usa o
     * índice (entity_id, file_hash) e isola o tenant; `withoutGlobalScopes` mantém
     * documento apagado com retorno vivo barrando, e o SoftDeletes do retorno faz o
     * retorno apagado liberar a reimportação. Várias linhas (duplicata legada) são
     * toleradas: o `processed` primeiro, depois o mais antigo — nunca sole().
     */
    private function findImportedReturn(string $entityId, string $operatorId, string $fileHash): ?TissReturn
    {
        return TissReturn::query()
            ->forEntity($entityId)
            ->where('operator_id', $operatorId)
            ->whereIn('xml_document_id', TissXmlDocument::query()
                ->withoutGlobalScopes()
                ->where('entity_id', $entityId)
                ->where('file_hash', $fileHash)
                ->select('id'))
            ->orderByRaw('case when status = ? then 0 else 1 end', [TissReturnStatus::Processed->value])
            ->orderBy('received_at')
            ->orderBy('id')
            ->first();
    }

    private function alreadyImported(TissReturn $existing): ReceivedTissReturn
    {
        // Só ids, nunca conteúdo do XML (LGPD).
        Log::info('tiss.return.duplicate_import', [
            'entity_id'   => (string) $existing->entity_id,
            'operator_id' => (string) $existing->operator_id,
            'return_id'   => (string) $existing->id,
        ]);

        return new ReceivedTissReturn($existing, alreadyImported: true);
    }

    private function resolveProtocol(string $entityId, string $operatorId, ?string $protocolNumber): ?TissProtocol
    {
        if (! $protocolNumber) {
            return null;
        }

        return TissProtocol::query()
            ->forEntity($entityId)
            ->where('operator_id', $operatorId)
            ->where('protocol_number', $protocolNumber)
            ->first();
    }

    private function mapProtocolStatus(string $status): TissProtocolStatus
    {
        return match ($status) {
            'accepted'   => TissProtocolStatus::Accepted,
            'rejected'   => TissProtocolStatus::Rejected,
            'processing' => TissProtocolStatus::Processing,
            'error'      => TissProtocolStatus::Error,
            default      => TissProtocolStatus::Received,
        };
    }
}
