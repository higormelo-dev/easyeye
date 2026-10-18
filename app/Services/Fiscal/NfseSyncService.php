<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Enums\Fiscal\{NfseEnvironment, NfseStatus};
use App\Exceptions\Fiscal\FocusNfeException;
use App\Models\Fiscal\NfseDocument;
use App\Support\Fiscal\{NfsePayloadSanitizer, NfseSettings, NfseUrlGuard};
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

/**
 * Traz para a nota o que a Focus sabe dela. Única porta de entrada do estado vindo de fora: o webhook (`apply` pelo job),
 * a varredura `fiscal:nfse-sync` e a ação "consultar agora" (`syncDocument`, GET na Focus) passam todos por `apply`.
 *
 *  - Idempotente por conteúdo: a entrega repetida (ou a consulta que traz o que o webhook já trouxe) não grava nada e não
 *    gera linha de auditoria — só campos que realmente mudaram são escritos;
 *  - Só avança: pending_send/send_failed → processando → autorizado → cancelado. Entrega fora de ordem (um "processando"
 *    que chega depois do "autorizado") é ignorada; erro_autorizacao e cancelado são finais;
 *  - A linha é travada (`FOR UPDATE`) só pelo tempo de gravar; a fatura nunca é travada aqui;
 *  - XML (nota e cancelamento) e e-mail de cópia são buscados DEPOIS do commit — falha de rede nunca desfaz o estado nem
 *    segura trava, e o XML que faltar é buscado de novo na próxima entrega, consulta ou download;
 *  - `ref` desconhecida (ou de outro ambiente) só vira log: a Focus nunca recebe erro por causa disso.
 */
final class NfseSyncService
{
    /** Transições aceitas. Mesmo estado = só completa campos (ex.: número, XML, erros). */
    private const FORWARD = [
        'pending_send'            => ['processando_autorizacao', 'autorizado', 'erro_autorizacao', 'cancelado'],
        'send_failed'             => ['processando_autorizacao', 'autorizado', 'erro_autorizacao', 'cancelado'],
        'processando_autorizacao' => ['autorizado', 'erro_autorizacao', 'cancelado'],
        'autorizado'              => ['cancelado'],
        'erro_autorizacao'        => [],
        'cancelado'               => [],
    ];

    public function __construct(
        private readonly NfseCredentialResolver $credentials,
        private readonly NfseDocumentStorage $storage,
        private readonly NfseSettings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $payload corpo da Focus (webhook ou consulta): status, numero, erros…
     * @param string               $origin  webhook | sync | send (só para o log)
     */
    public function apply(string $ref, NfseEnvironment $environment, array $payload, string $origin = 'webhook'): ?NfseDocument
    {
        $document = NfseDocument::query()->where('ref', $ref)->where('environment', $environment->value)->first();

        if ($document === null) {
            Log::info('nfse.sync.unknown_ref', ['ref' => $ref, 'environment' => $environment->value, 'origin' => $origin]);

            return null;
        }

        $target = NfseStatus::fromFocus(isset($payload['status']) ? (string) $payload['status'] : null);

        if ($target === null) {
            Log::info('nfse.sync.ignored_status', ['ref' => $ref, 'origin' => $origin]);

            return $document;
        }

        $transitioned = $this->persist($document->id, $target, NfseUrlGuard::clean(NfsePayloadSanitizer::clean($payload), $ref), $origin);

        $document = $document->fresh();

        $this->afterCommit($document, $transitioned);

        return $document;
    }

    /**
     * Consulta a nota na Focus agora e aplica o resultado. Nota que a Focus não conhece (404) fica como está.
     *
     * @throws FocusNfeException falha da consulta (token, rede, limite) — quem chama decide se repete
     */
    public function syncDocument(NfseDocument $document): NfseDocument
    {
        $remote = $this->credentials->client($document->environment, $document->correlation_id)->show($document->standard, $document->ref);

        if ($remote === null) {
            Log::info('nfse.sync.not_found_remote', ['ref' => $document->ref]);

            return $document;
        }

        return $this->apply($document->ref, $document->environment, $remote, 'sync') ?? $document;
    }

    /**
     * Grava o que mudou, com a linha travada. Devolve o estado novo quando a nota MUDOU de estado nesta chamada (para o
     * e-mail de cópia sair uma vez só), ou null.
     *
     * @param array<string, mixed> $payload já saneado
     */
    private function persist(string $documentId, NfseStatus $target, array $payload, string $origin): ?NfseStatus
    {
        try {
            return DB::transaction(function () use ($documentId, $target, $payload, $origin): ?NfseStatus {
                $locked = NfseDocument::query()->whereKey($documentId)->lockForUpdate()->first();

                if ($locked === null) {
                    return null;
                }

                $current = $locked->status;

                if ($current !== $target && ! in_array($target->value, self::FORWARD[$current->value], true)) {
                    Log::info('nfse.sync.ignored_transition', ['ref' => $locked->ref, 'from' => $current->value, 'to' => $target->value, 'origin' => $origin]);

                    return null;
                }

                // Estados finais não recebem mais campos além do que já têm (cancelado completa o XML de cancelamento).
                if ($current === NfseStatus::AuthorizationError && $target === NfseStatus::AuthorizationError) {
                    $changes = $this->changes($locked, $target, $payload);
                } elseif ($current === NfseStatus::Cancelled && $target !== NfseStatus::Cancelled) {
                    return null;
                } else {
                    $changes = $this->changes($locked, $target, $payload);
                }

                if ($changes === []) {
                    return null;
                }

                $locked->forceFill($changes)->save();

                return $current !== $target ? $target : null;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Outra nota já ocupa a fatura (linha antiga que a Focus resolveu depois): nunca duas valendo. Fica no log.
            Log::warning('nfse.sync.occupied_invoice', ['document_id' => $documentId, 'to' => $target->value, 'origin' => $origin]);

            return null;
        }
    }

    /**
     * Campos que mudariam. Compara por conteúdo (JSON sem depender da ordem das chaves, datas pelo instante), para a
     * entrega repetida ser um no-op de verdade.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function changes(NfseDocument $document, NfseStatus $target, array $payload): array
    {
        $candidate = ['status' => $target, 'response_payload' => $payload];

        switch ($target) {
            case NfseStatus::Authorized:
                $candidate += $this->noteFields($payload) + ['errors' => null];

                break;
            case NfseStatus::Cancelled:
                $candidate += $this->noteFields($payload) + [
                    'cancelled_at'         => $document->cancelled_at ?? now(),
                    'cancelled_externally' => false,
                ];

                break;
            case NfseStatus::AuthorizationError:
                $candidate['errors'] = FocusNfeException::errorsFrom($payload) ?: [['codigo' => 'erro_autorizacao', 'mensagem' => __('fiscal.errors.focus_rejected', ['detail' => '']), 'correcao' => null]];

                break;
            default:
                $candidate['errors'] = null;
        }

        $changes = [];

        foreach ($candidate as $column => $value) {
            if (! $this->same($document->{$column}, $value)) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    /**
     * Dados da nota que a Focus devolve quando autoriza/cancela. Só o que veio (campo ausente não apaga o que já temos).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function noteFields(array $payload): array
    {
        $fields = [];

        foreach (['numero' => 'numero', 'codigo_verificacao' => 'codigo_verificacao', 'url' => 'url', 'url_danfse' => 'pdf_url'] as $from => $column) {
            if (isset($payload[$from]) && is_scalar($payload[$from]) && trim((string) $payload[$from]) !== '') {
                $fields[$column] = (string) $payload[$from];
            }
        }

        if (isset($payload['data_emissao']) && is_string($payload['data_emissao'])) {
            try {
                $fields['issued_at'] = Carbon::parse($payload['data_emissao']);
            } catch (Throwable) {
                // data ilegível: segue sem ela
            }
        }

        return $fields;
    }

    private function same(mixed $current, mixed $new): bool
    {
        if ($current instanceof NfseStatus || $new instanceof NfseStatus) {
            return $current === $new;
        }

        if ($current instanceof DateTimeInterface || $new instanceof DateTimeInterface) {
            return $current instanceof DateTimeInterface && $new instanceof DateTimeInterface && $current->getTimestamp() === $new->getTimestamp();
        }

        if (is_array($current) || is_array($new)) {
            return is_array($current) && is_array($new) && self::canonical($current) === self::canonical($new);
        }

        return $current === $new;
    }

    /** Ordem das chaves não conta (o jsonb do Postgres não a guarda). */
    private static function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** XML e e-mail de cópia, fora da transação. Falha só vai ao log: a nota já está certa. */
    private function afterCommit(NfseDocument $document, ?NfseStatus $transitioned): void
    {
        try {
            if (in_array($document->status, [NfseStatus::Authorized, NfseStatus::Cancelled], true) && blank($document->xml_path)) {
                $this->storage->ensure($document, NfseDocumentStorage::KIND_AUTHORIZED);
            }

            if ($document->status === NfseStatus::Cancelled && blank($document->cancel_xml_path)) {
                $this->storage->ensure($document->fresh(), NfseDocumentStorage::KIND_CANCEL);
            }

            if ($transitioned === NfseStatus::Authorized) {
                // Fatura devolvida enquanto a nota estava em voo: autorizou agora, cancela agora.
                if ($document->cancel_required && in_array($document->cancel_required_reason, ['refund', 'chargeback'], true)) {
                    app(NfseCancellationService::class)->forReversal($document, (string) $document->cancel_required_reason);
                } else {
                    $this->sendCopy($document);
                }
            }
        } catch (Throwable $e) {
            Log::warning('nfse.sync.after_commit_failed', ['ref' => $document->ref, 'erro' => $e::class]);
        }
    }

    /** Cópia da nota para o e-mail configurado em Nota fiscal (uma vez: só na transição para autorizado). */
    private function sendCopy(NfseDocument $document): void
    {
        $copy = $this->settings->copyEmail();

        if ($copy === null || filter_var($copy, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        try {
            $this->credentials->client($document->environment, $document->correlation_id)->email($document->standard, $document->ref, [$copy]);
        } catch (FocusNfeException $e) {
            Log::warning('nfse.sync.copy_email_failed', ['ref' => $document->ref, 'codigo' => $e->errorCode]);
        }
    }
}
