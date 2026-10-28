<?php

declare(strict_types=1);

namespace App\Domains\Recall\Models;

use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Domains\Recall\Exceptions\ProtocolVersionException;
use App\Domains\Recall\Support\ProtocolContentSchema;
use App\Support\CanonicalJson;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma VERSÃO de protocolo: conteúdo (JSON canônico) + hash + estado.
 *
 * Só `draft` muda o conteúdo. O trigger do PostgreSQL é a garantia de verdade; os
 * ganchos `updating`/`deleting` daqui dão a mesma recusa antes de ir ao banco, com
 * erro que o código sabe tratar. As mudanças de ESTADO e os marcos (frozen_at,
 * released_at…) passam pelos serviços de governança, que gravam o evento na mesma
 * transação (ADR 0002).
 *
 * @property string               $id
 * @property string               $protocol_id
 * @property int                  $version
 * @property string               $content
 * @property string               $content_hash
 * @property ProtocolVersionState $state
 * @property int                  $lock_version
 */
class RecallProtocolVersion extends Model
{
    use HasAuditColumns;
    use HasUuids;

    /** Colunas que a versão congelada nunca muda. */
    private const FROZEN_COLUMNS = ['content', 'content_hash', 'schema_version', 'version', 'protocol_id', 'parent_version_id'];

    protected $fillable = ['protocol_id', 'version', 'parent_version_id', 'content', 'content_hash', 'schema_version', 'state'];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('state') === ProtocolVersionState::Draft->value) {
                return;
            }

            foreach (self::FROZEN_COLUMNS as $column) {
                if ($version->isDirty($column)) {
                    throw ProtocolVersionException::frozenContent($column);
                }
            }
        });

        static::deleting(function (self $version): void {
            if ($version->getRawOriginal('state') !== ProtocolVersionState::Draft->value) {
                throw ProtocolVersionException::frozenContent('delete');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'state'          => ProtocolVersionState::class,
            'version'        => 'integer',
            'schema_version' => 'integer',
            'lock_version'   => 'integer',
            'frozen_at'      => 'datetime',
            'validated_at'   => 'datetime',
            'released_at'    => 'datetime',
            'suspended_at'   => 'datetime',
            'retired_at'     => 'datetime',
            'review_due_at'  => 'date',
        ];
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(RecallProtocol::class, 'protocol_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    /**
     * O conteúdo como array (decodificado do texto gravado).
     *
     * @return array<string, mixed>
     */
    public function contentArray(): array
    {
        return (array) CanonicalJson::decode($this->content);
    }

    /** O hash gravado bate com o texto gravado? (a verificação de integridade) */
    public function integrityOk(): bool
    {
        return CanonicalJson::matches(ProtocolContentSchema::HASH_DOMAIN, (string) $this->content, (string) $this->content_hash);
    }

    /** Primeiros caracteres do hash para exibir ("1:" omitido). */
    public function shortHash(int $length = 12): string
    {
        return substr((string) $this->content_hash, 2, $length);
    }

    public function scopeInState(Builder $query, ProtocolVersionState ...$states): Builder
    {
        return $query->whereIn('state', array_map(static fn (ProtocolVersionState $state): string => $state->value, $states));
    }
}
