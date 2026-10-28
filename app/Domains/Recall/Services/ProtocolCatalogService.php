<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\ClinicalReview\Services\GovernanceLog;
use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Domains\Recall\Exceptions\{InvalidProtocolContentException, ProtocolVersionException};
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Support\ProtocolContentSchema;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Catálogo de protocolos de retorno: cria protocolo, edita o rascunho, abre
 * revisão a partir de uma versão existente e CONGELA a versão para revisão.
 *
 * A passagem por revisão, aceite, liberação e suspensão é da governança
 * (ProtocolGovernanceService). Aqui, tudo que muda uma versão ocorre numa
 * transação com a linha travada (FOR UPDATE) e, quando é decisão, com o evento de
 * governança gravado na MESMA transação.
 *
 * Ordem de locks (global, ADR 0002): protocolo → versão.
 */
class ProtocolCatalogService
{
    public function __construct(private readonly GovernanceLog $log)
    {
    }

    /**
     * Cria o protocolo e sua versão 1 em rascunho.
     *
     * @param array<string, array<string, string>> $translations {"pt_BR": {"title","summary"}, "en": {...}}
     * @param array<string, mixed>                 $content      conteúdo do protocolo (será normalizado)
     *
     * @throws InvalidProtocolContentException
     */
    public function createProtocol(string $key, array $translations, array $content, int $sortOrder = 0, ?User $actor = null): RecallProtocol
    {
        $prepared = ProtocolContentSchema::prepare($content);

        return DB::transaction(function () use ($key, $translations, $sortOrder, $actor, $prepared): RecallProtocol {
            $protocol = RecallProtocol::query()->create([
                'key'          => $key,
                'translations' => $translations,
                'status'       => RecallProtocol::STATUS_ACTIVE,
                'sort_order'   => $sortOrder,
            ]);

            $version = $this->insertVersion($protocol, 1, null, $prepared);

            $this->log->record(
                GovernanceLog::SUBJECT_PROTOCOL_VERSION,
                (string) $version->id,
                'created',
                null,
                ProtocolVersionState::Draft->value,
                $actor,
                null,
                ['protocol' => $protocol->key, 'version' => 1],
            );

            return $protocol;
        });
    }

    /**
     * Salva o rascunho. `$expectedLockVersion` é o `lock_version` que a tela leu: se
     * outra sessão salvou antes, a edição é recusada (STALE) em vez de sobrescrever.
     *
     * @param array<string, mixed> $content
     *
     * @throws InvalidProtocolContentException
     * @throws ProtocolVersionException
     */
    public function saveDraft(RecallProtocolVersion $version, array $content, int $expectedLockVersion, ?User $actor = null): RecallProtocolVersion
    {
        $prepared = ProtocolContentSchema::prepare($content);

        return DB::transaction(function () use ($version, $expectedLockVersion, $prepared): RecallProtocolVersion {
            $locked = $this->lock($version);

            if ($locked->state !== ProtocolVersionState::Draft) {
                throw ProtocolVersionException::notDraft($locked->state);
            }

            if ($locked->lock_version !== $expectedLockVersion) {
                throw ProtocolVersionException::stale($expectedLockVersion, $locked->lock_version);
            }

            $locked->forceFill([
                'content'        => $prepared['canonical'],
                'content_hash'   => $prepared['hash'],
                'schema_version' => ProtocolContentSchema::VERSION,
                'lock_version'   => $locked->lock_version + 1,
            ])->save();

            return $locked;
        });
    }

    /**
     * Abre a próxima versão em rascunho a partir da mais recente do protocolo (que
     * nunca é rascunho: só existe uma versão em andamento por vez). O conteúdo é
     * copiado, a `change_note` é zerada (a nova versão tem que dizer o que mudou) e
     * a versão nova aponta a de origem em `parent_version_id`.
     *
     * @throws ProtocolVersionException
     */
    public function startRevision(RecallProtocol $protocol, ?User $actor = null): RecallProtocolVersion
    {
        return DB::transaction(function () use ($protocol, $actor): RecallProtocolVersion {
            $lockedProtocol = RecallProtocol::query()->whereKey($protocol->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedProtocol->openVersion() !== null) {
                throw ProtocolVersionException::openVersionExists($lockedProtocol->key);
            }

            /** @var RecallProtocolVersion $base */
            $base = RecallProtocolVersion::query()
                ->where('protocol_id', $lockedProtocol->id)
                ->orderByDesc('version')
                ->firstOrFail();

            $content                = $base->contentArray();
            $content['change_note'] = '';

            $version = $this->insertVersion($lockedProtocol, $base->version + 1, $base, ProtocolContentSchema::prepare($content));

            $this->log->record(
                GovernanceLog::SUBJECT_PROTOCOL_VERSION,
                (string) $version->id,
                'revision_started',
                null,
                ProtocolVersionState::Draft->value,
                $actor,
                null,
                ['protocol' => $lockedProtocol->key, 'version' => $version->version, 'parent_version' => $base->version],
            );

            return $version;
        });
    }

    /**
     * Congela o rascunho para revisão (`draft → in_review`): revalida o conteúdo, grava
     * o texto canônico e o hash FINAIS e fecha a edição. Versão derivada de outra
     * precisa dizer o que mudou (o revisor lê isso junto do diff).
     *
     * @throws InvalidProtocolContentException
     * @throws ProtocolVersionException
     */
    public function freeze(RecallProtocolVersion $version, ?User $actor = null): RecallProtocolVersion
    {
        return DB::transaction(function () use ($version, $actor): RecallProtocolVersion {
            $locked = $this->lock($version);

            if ($locked->state !== ProtocolVersionState::Draft) {
                throw ProtocolVersionException::notDraft($locked->state);
            }

            $prepared = ProtocolContentSchema::prepare($locked->contentArray());

            if ($locked->parent_version_id !== null && $prepared['content']['change_note'] === '') {
                throw new InvalidProtocolContentException(['change_note' => ['change_note_required', []]]);
            }

            $locked->forceFill([
                'content'        => $prepared['canonical'],
                'content_hash'   => $prepared['hash'],
                'schema_version' => ProtocolContentSchema::VERSION,
                'state'          => ProtocolVersionState::InReview->value,
                'frozen_at'      => now(),
                'frozen_by'      => $actor?->getKey(),
                'lock_version'   => $locked->lock_version + 1,
            ])->save();

            $this->log->record(
                GovernanceLog::SUBJECT_PROTOCOL_VERSION,
                (string) $locked->id,
                'frozen',
                ProtocolVersionState::Draft->value,
                ProtocolVersionState::InReview->value,
                $actor,
                null,
                ['version' => $locked->version, 'content_hash' => $locked->content_hash],
            );

            return $locked;
        });
    }

    /** Relê a versão sob FOR UPDATE (dentro da transação do chamador). */
    private function lock(RecallProtocolVersion $version): RecallProtocolVersion
    {
        return RecallProtocolVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param array{content: array<string, mixed>, canonical: string, hash: string} $prepared
     */
    private function insertVersion(RecallProtocol $protocol, int $number, ?RecallProtocolVersion $parent, array $prepared): RecallProtocolVersion
    {
        // Confere o próprio hash antes de gravar: se CanonicalJson e o esquema divergirem,
        // o erro aparece aqui e não só na verificação noturna.
        if (! CanonicalJson::matches(ProtocolContentSchema::HASH_DOMAIN, $prepared['canonical'], $prepared['hash'])) {
            throw new LogicException('Hash do conteúdo não confere com o texto canônico.');
        }

        $version = new RecallProtocolVersion([
            'protocol_id'       => $protocol->id,
            'version'           => $number,
            'parent_version_id' => $parent?->id,
            'content'           => $prepared['canonical'],
            'content_hash'      => $prepared['hash'],
            'schema_version'    => ProtocolContentSchema::VERSION,
            'state'             => ProtocolVersionState::Draft->value,
        ]);

        // lock_version não é preenchível em massa; sem isto o objeto devolvido não traria o 0 do banco.
        $version->forceFill(['lock_version' => 0])->save();

        return $version;
    }
}
