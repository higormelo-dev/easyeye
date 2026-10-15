<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Surgical;

use App\Domains\Tiss\DTOs\ReceivedTissReturn;
use App\Domains\Tiss\Enums\{TissGuideStatus, TissReturnStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Models\{TissGuide, TissReturn, TissXmlDocument};
use App\Domains\Tiss\Parsers\TissReturnParser;
use App\Domains\Tiss\Services\{LogTissStatusTransitionService, ReceiveTissResponseService};
use App\Enums\Surgery\AuthorizationEventType;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Surgery\{SurgicalAuthorizationEvent, SurgicalCase};
use App\Services\Surgery\SurgeryAuthorizationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Log, Storage};
use Illuminate\Validation\ValidationException;

/**
 * Resposta de AUTORIZAÇÃO da operadora a uma guia cirúrgica (solicitação de
 * internação / anexo de OPME) vira EVENTO no log de autorização do caso
 * (`channel = tiss`, `tiss_guide_id`), pelo mesmo caminho da secretária
 * (SurgeryAuthorizationService::record). Duas portas de entrada:
 *
 *  - `applyFromXml()`: a resposta síncrona do envio, ou o XML anexado na aba;
 *  - `maybeReceive()`: a tela de importação de retorno (ReceiveTissResponseService), que
 *    passa por aqui quando o arquivo é uma resposta de autorização e não um retorno de lote.
 *
 * IDEMPOTENTE: o MESMO arquivo/resposta aplicado de novo (job duplicado, duplo clique,
 * reimportação) não duplica evento e não regride nada — o evento é conferido pela
 * identidade (guia + tipo + número + validade/motivo) antes de gravar, e o arquivo
 * importado tem identidade própria (clínica, operadora, SHA-256 dos bytes), como o
 * retorno de lote.
 *
 * Resposta que NÃO cabe no estado atual do caso (a secretária já registrou à mão, o caso
 * foi cancelado, a validade passou...) NUNCA muda o estado: vira anotação no log, uma vez.
 *
 * Locks: aqui o caso e a guia nunca são segurados juntos. O evento é gravado primeiro
 * (record() trava o caso e comita); a guia é atualizada depois, numa transação só dela.
 */
class SurgicalAuthorizationResponseService
{
    public function __construct(
        private readonly TissReturnParser $parser,
        private readonly SurgeryAuthorizationService $authorization,
        private readonly LogTissStatusTransitionService $history,
    ) {
    }

    // ── Porta 1: XML já associado a uma guia ────────────────────────────────

    /**
     * @return array{applied: bool, outcome: string}|null null = o XML não é uma resposta de autorização
     */
    public function applyFromXml(TissGuide $guide, string $xml, string $source): ?array
    {
        $parsed = $this->parser->parseAuthorization($xml);

        if ($parsed === null) {
            return null;
        }

        $number = trim((string) $parsed['guide_number_provider']);

        // XML anexado à mão (upload) SEM número de guia não prova de qual guia veio: recusado.
        if (($number === '' && $source === 'upload') || ($number !== '' && ! hash_equals(mb_strtolower((string) $guide->guide_number_provider), mb_strtolower($number)))) {
            throw SurgeryRuleException::invalid('surgery_tiss.errors.response_other_guide');
        }

        return $this->apply($guide, $parsed, $source);
    }

    // ── Porta 2: arquivo importado (sem guia escolhida) ─────────────────────

    /**
     * Se o XML é uma resposta de autorização de uma guia cirúrgica desta clínica e
     * operadora, grava o arquivo/retorno e aplica; senão devolve null para o fluxo normal
     * de retorno de lote seguir.
     *
     * @param array<string, mixed> $metadata
     */
    public function maybeReceive(string $entityId, string $operatorId, string $xml, string $fileHash, array $metadata = []): ?ReceivedTissReturn
    {
        $parsed = $this->parser->parseAuthorization($xml);

        if ($parsed === null) {
            return null;
        }

        $number = trim((string) $parsed['guide_number_provider']);
        $guide  = $number === '' ? null : TissGuide::query()
            ->forEntity($entityId)
            ->where('operator_id', $operatorId)
            ->where('guide_number_provider', $number)
            ->whereNotNull('surgical_case_id')
            ->first();

        // Resposta de autorização de guia que NÃO é cirúrgica (SP-SADT de lote): outro fluxo.
        if ($guide === null && $number !== '' && $this->isBatchGuideNumber($entityId, $operatorId, $number)) {
            return null;
        }

        // Importação idempotente por ARQUIVO: advisory lock como 1ª instrução (mesma chave
        // do retorno de lote) e releitura sob a trava; nada de FOR UPDATE de caso/guia aqui.
        $return = DB::transaction(function () use ($entityId, $operatorId, $xml, $fileHash, $parsed, $guide, $metadata, $number): TissReturn {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [ReceiveTissResponseService::importLockKey($entityId, $operatorId, $fileHash)], false);
            }

            $existing = $this->findImported($entityId, $operatorId, $fileHash);

            if ($existing !== null) {
                return $existing;
            }

            $path = sprintf('tiss/%s/returns/auth_%s_%s.xml', $entityId, substr($fileHash, 0, 12), now()->format('YmdHis'));

            Storage::disk()->put($path, $xml);

            $document = TissXmlDocument::query()->create([
                'entity_id'     => $entityId,
                'operator_id'   => $operatorId,
                'guide_id'      => $guide?->id,
                'document_type' => 'authorization_response',
                'direction'     => TissXmlDirection::Inbound->value,
                'status'        => TissXmlStatus::Validated->value,
                'file_path'     => $path,
                'file_hash'     => $fileHash,
                'xml_content'   => $xml,
                'generated_at'  => now(),
                'validated_at'  => now(),
                'metadata'      => array_merge(['source' => 'authorization_response'], $metadata),
            ]);

            return TissReturn::query()->create([
                'entity_id'       => $entityId,
                'operator_id'     => $operatorId,
                'xml_document_id' => $document->id,
                'return_type'     => 'authorization_response',
                'status'          => TissReturnStatus::Parsed->value,
                'received_at'     => now(),
                'summary'         => ['status' => $parsed['status'], 'guide_id' => $guide?->id, 'glosa_count' => 0],
                'raw_payload'     => $xml,
                'metadata'        => ['guide_number' => $number],
            ]);
        });

        if ($return->status === TissReturnStatus::Processed) {
            Log::info('tiss.authorization_response.duplicate_import', ['entity_id' => $entityId, 'return_id' => (string) $return->id]);

            return new ReceivedTissReturn($return, alreadyImported: true);
        }

        // Fora da transação.
        if ($guide === null) {
            $return->update([
                'status'        => TissReturnStatus::Error->value,
                'error_message' => (string) __('surgery_tiss.errors.response_guide_not_found'),
            ]);

            return new ReceivedTissReturn($return->fresh());
        }

        $outcome = $this->apply($guide, $parsed, 'import');

        $return->update([
            'status'       => TissReturnStatus::Processed->value,
            'processed_at' => now(),
            'summary'      => array_merge((array) $return->summary, ['outcome' => $outcome['outcome'], 'glosa_count' => 0]),
        ]);

        return new ReceivedTissReturn($return->fresh());
    }

    // ── Aplicação ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $parsed
     *
     * @return array{applied: bool, outcome: string}
     */
    private function apply(TissGuide $guide, array $parsed, string $source): array
    {
        $case = SurgicalCase::query()->where('entity_id', $guide->entity_id)->find($guide->surgical_case_id);

        if ($case === null) {
            return ['applied' => false, 'outcome' => 'no_case'];
        }

        $outcome = match ($parsed['kind']) {
            'error' => $this->recordNote($case, $guide, $this->errorText($parsed), $source),
            default => match ($parsed['status']) {
                'authorized'           => $this->recordAuthorized($case, $guide, $parsed, partial: false, source: $source),
                'partially_authorized' => $this->recordAuthorized($case, $guide, $parsed, partial: true, source: $source),
                'denied'               => $this->recordDenied($case, $guide, $parsed, $source),
                default                => $this->recordNote($case, $guide, $this->pendingText($parsed), $source),
            },
        };

        $this->updateGuide($guide, $parsed);

        return ['applied' => $outcome !== 'duplicate', 'outcome' => $outcome];
    }

    /** @param array<string, mixed> $parsed */
    private function recordAuthorized(SurgicalCase $case, TissGuide $guide, array $parsed, bool $partial, string $source): string
    {
        $number = trim((string) ($parsed['guide_number_operator'] ?: $parsed['password']));

        if ($number === '') {
            return $this->recordNote($case, $guide, (string) __('surgery_tiss.response.authorized_without_number'), $source);
        }

        if ($this->answerExists($guide, $number, $parsed['valid_until'])) {
            return 'duplicate';
        }

        $data = [
            'type'                   => ($partial ? AuthorizationEventType::PartiallyAuthorized : AuthorizationEventType::Authorized)->value,
            'channel'                => 'tiss',
            'tiss_guide_id'          => (string) $guide->id,
            'authorization_number'   => $number,
            'authorization_password' => $parsed['password'],
            'valid_until'            => $parsed['valid_until'],
            'occurred_at'            => $this->occurredAt($parsed['authorization_date']),
            'note'                   => $parsed['observation'],
        ];

        // A resposta é DESTA guia: vale para os itens que ela leva (internação = procedimentos;
        // anexo de OPME = materiais), não para o pedido inteiro.
        $scope = $this->guideScope($case, $guide, onlyWithCode: $partial);

        if ($scope === []) {
            return $this->recordNote($case, $guide, (string) __('surgery_tiss.response.unmapped', ['number' => $number]), $source);
        }

        if ($partial) {
            $items = $this->partialItems($scope, $parsed);

            if ($items === null) {
                return $this->recordNote($case, $guide, (string) __('surgery_tiss.response.partial_unmapped', ['number' => $number]), $source);
            }

            $data['authorized_items'] = $items;
        }

        $data['scope'] = array_map(fn (array $row) => ['kind' => $row['kind'], 'id' => $row['id']], $scope);

        return $this->recordEvent($case, $guide, $data, $source);
    }

    /** @param array<string, mixed> $parsed */
    private function recordDenied(SurgicalCase $case, TissGuide $guide, array $parsed, string $source): string
    {
        $reason = trim(implode(' · ', array_filter([
            implode(', ', (array) $parsed['denial_codes']),
            implode(' / ', (array) $parsed['denial_descriptions']),
            $parsed['observation'],
        ])));

        $reason = $reason !== '' ? $reason : (string) __('surgery_tiss.response.denied_without_reason');

        if ($this->eventExists($guide, AuthorizationEventType::Denied, null, null, $reason)) {
            return 'duplicate';
        }

        return $this->recordEvent($case, $guide, [
            'type'          => AuthorizationEventType::Denied->value,
            'channel'       => 'tiss',
            'tiss_guide_id' => (string) $guide->id,
            'denial_reason' => mb_substr($reason, 0, 2000),
            'occurred_at'   => $this->occurredAt($parsed['authorization_date']),
        ], $source);
    }

    /**
     * Grava pelo MESMO caminho da secretária. Resposta que não cabe no estado atual do
     * caso (409), ou dado que o registro manual recusaria (422), vira anotação.
     *
     * @param array<string, mixed> $data
     */
    private function recordEvent(SurgicalCase $case, TissGuide $guide, array $data, string $source): string
    {
        try {
            $this->authorization->record($case, $data);

            return 'recorded';
        } catch (SurgeryRuleException|ValidationException $e) {
            $reason = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();

            return $this->recordNote($case, $guide, (string) __('surgery_tiss.response.not_applied', [
                'type'   => AuthorizationEventType::from((string) $data['type'])->label(),
                'number' => (string) ($data['authorization_number'] ?? '—'),
                'reason' => (string) $reason,
            ]), $source);
        }
    }

    private function recordNote(SurgicalCase $case, TissGuide $guide, string $text, string $source): string
    {
        $text = mb_substr(trim($text), 0, 2000);

        if ($this->eventExists($guide, AuthorizationEventType::Note, null, null, null, $text)) {
            return 'duplicate';
        }

        try {
            $this->authorization->record($case, [
                'type'          => AuthorizationEventType::Note->value,
                'channel'       => 'tiss',
                'tiss_guide_id' => (string) $guide->id,
                'note'          => $text,
            ]);
        } catch (SurgeryRuleException) {
            // Caso encerrado: sem onde anotar; o histórico da guia guarda o fato.
            return 'unrecorded';
        }

        return 'noted';
    }

    /**
     * O evento já existe para esta guia? (identidade: tipo + número + validade, ou motivo, ou texto).
     */
    private function eventExists(TissGuide $guide, AuthorizationEventType $type, ?string $number, ?string $validUntil, ?string $denialReason = null, ?string $note = null): bool
    {
        return SurgicalAuthorizationEvent::query()
            ->where('entity_id', $guide->entity_id)
            ->where('surgical_case_id', $guide->surgical_case_id)
            ->where('tiss_guide_id', $guide->id)
            ->where('type', $type->value)
            ->when($number !== null, fn ($q) => $q->where('authorization_number', $number))
            ->when($validUntil !== null, fn ($q) => $q->whereDate('valid_until', $validUntil))
            ->when($denialReason !== null, fn ($q) => $q->where('denial_reason', mb_substr($denialReason, 0, 2000)))
            ->when($note !== null, fn ($q) => $q->where('note', $note))
            ->exists();
    }

    /**
     * Esta guia já teve a resposta com este número e validade registrada? Vale o evento de
     * autorização/parcial OU a anotação que o caso grava quando a resposta cobre só parte do pedido
     * (a outra guia ainda aguarda) — a mesma resposta importada de novo não duplica nenhum dos dois.
     */
    private function answerExists(TissGuide $guide, string $number, ?string $validUntil): bool
    {
        return SurgicalAuthorizationEvent::query()
            ->where('entity_id', $guide->entity_id)
            ->where('surgical_case_id', $guide->surgical_case_id)
            ->where('tiss_guide_id', $guide->id)
            ->whereIn('type', [AuthorizationEventType::Authorized->value, AuthorizationEventType::PartiallyAuthorized->value, AuthorizationEventType::Note->value])
            ->where('authorization_number', $number)
            ->when($validUntil !== null, fn ($q) => $q->whereDate('valid_until', $validUntil))
            ->exists();
    }

    /**
     * Itens do pedido que a guia leva (`source_kind`/`source_id` gravados na geração da guia), com o
     * código TUSS dela. `onlyWithCode`: só os que têm código — a resposta parcial casa por código.
     *
     * @return list<array{kind: string, id: string, code: ?string, quantity: int}>
     */
    private function guideScope(SurgicalCase $case, TissGuide $guide, bool $onlyWithCode = false): array
    {
        $requested = [];

        foreach ($case->procedures()->get() as $procedure) {
            $requested['procedure:' . $procedure->getKey()] = (int) $procedure->quantity;
        }

        foreach ($case->materials()->get() as $material) {
            $requested['material:' . $material->getKey()] = (int) $material->quantity;
        }

        $scope = [];

        foreach ($guide->items()->get() as $item) {
            $meta = (array) ($item->metadata ?? []);
            $key  = (string) ($meta['source_kind'] ?? '') . ':' . (string) ($meta['source_id'] ?? '');
            $code = filled($item->tuss_code) ? trim((string) $item->tuss_code) : null;

            if (! isset($requested[$key]) || ($onlyWithCode && $code === null)) {
                continue;
            }

            $scope[$key] = ['kind' => (string) $meta['source_kind'], 'id' => (string) $meta['source_id'], 'code' => $code, 'quantity' => $requested[$key]];
        }

        return array_values($scope);
    }

    /**
     * Itens autorizados quando a operadora respondeu "parcial": cada item da guia é casado por
     * código TUSS com o que a resposta liberou (o que ela não cita ficou sem autorização). O que
     * a guia NÃO leva não entra: é de outra guia e segue sem resposta. null = nada para casar —
     * quem chama anota em vez de inventar quantidades.
     *
     * @param list<array{kind: string, id: string, code: ?string, quantity: int}> $scope
     * @param array<string, mixed>                                                $parsed
     *
     * @return list<array{kind: string, id: string, quantity: int}>|null
     */
    private function partialItems(array $scope, array $parsed): ?array
    {
        $byCode = [];

        foreach ((array) $parsed['authorized_services'] as $service) {
            $code = trim((string) ($service['code'] ?? ''));

            if ($code !== '') {
                $byCode[$code] = (int) $service['authorized'];
            }
        }

        $items = [];

        foreach ($scope as $row) {
            $items[] = [
                'kind'     => $row['kind'],
                'id'       => $row['id'],
                'quantity' => min($row['quantity'], max(0, (int) ($byCode[(string) $row['code']] ?? 0))),
            ];
        }

        return $items === [] ? null : $items;
    }

    /** A guia acompanha a resposta (número da operadora e estado), só para frente. @param array<string, mixed> $parsed */
    private function updateGuide(TissGuide $guide, array $parsed): void
    {
        DB::transaction(function () use ($guide, $parsed): void {
            /** @var TissGuide $locked */
            $locked = TissGuide::query()->forEntity((string) $guide->entity_id)->whereKey($guide->getKey())->lockForUpdate()->firstOrFail();

            $changes = [];

            if (filled($parsed['guide_number_operator'])) {
                $changes['guide_number_operator'] = mb_substr((string) $parsed['guide_number_operator'], 0, 64);
            }

            $target = match ($parsed['status'] ?? null) {
                'authorized', 'partially_authorized' => TissGuideStatus::Authorized,
                'denied' => TissGuideStatus::Denied,
                default  => null,
            };

            // Só avança: guia já respondida (autorizada/negada/cancelada) não é reaberta por
            // resposta repetida ou antiga.
            if ($target !== null && ! in_array($locked->status, [TissGuideStatus::Authorized, TissGuideStatus::Denied, TissGuideStatus::Cancelled], true)) {
                $changes['status'] = $target->value;

                if ($target === TissGuideStatus::Authorized) {
                    $changes['authorization_number'] = mb_substr((string) ($parsed['guide_number_operator'] ?: $parsed['password']), 0, 64);
                }
            }

            if ($changes === []) {
                return;
            }

            $previous = $locked->status->value;
            $locked->update($changes);

            if (isset($changes['status'])) {
                $this->history->record(
                    entityId: (string) $locked->entity_id,
                    contextType: 'guide',
                    contextId: (string) $locked->id,
                    currentStatus: $changes['status'],
                    previousStatus: $previous,
                    reason: 'Resposta de autorização da operadora.',
                );
            }
        });
    }

    // ── Textos e utilitários ────────────────────────────────────────────────

    /** @param array<string, mixed> $parsed */
    private function errorText(array $parsed): string
    {
        $error = (array) $parsed['error'];

        return (string) __('surgery_tiss.response.operator_error', [
            'code'        => (string) ($error['code'] ?? '—'),
            'description' => (string) ($error['description'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $parsed */
    private function pendingText(array $parsed): string
    {
        $status = (string) ($parsed['status'] ?? 'unknown');

        return trim((string) __('surgery_tiss.response.pending.' . $status) . ' ' . (string) ($parsed['observation'] ?? ''));
    }

    private function occurredAt(?string $date): string
    {
        // Data da autorização informada pela operadora; futura ou ausente vale "agora".
        if ($date === null) {
            return now()->toDateTimeString();
        }

        $parsed = Carbon::parse($date)->startOfDay();

        return $parsed->isFuture() ? now()->toDateTimeString() : $parsed->toDateTimeString();
    }

    private function isBatchGuideNumber(string $entityId, string $operatorId, string $number): bool
    {
        return TissGuide::query()
            ->forEntity($entityId)
            ->where('operator_id', $operatorId)
            ->where('guide_number_provider', $number)
            ->whereNull('surgical_case_id')
            ->exists();
    }

    private function findImported(string $entityId, string $operatorId, string $fileHash): ?TissReturn
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
}
