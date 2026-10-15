<?php

declare(strict_types=1);

namespace App\Domains\Recall\Models;

use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Protocolo de retorno de UMA condição (glaucoma, DMRI…): global, sem entity_id.
 * A regra em si vive nas versões (RecallProtocolVersion); aqui ficam a identidade
 * (`key`) e os textos de exibição, que não entram no hash.
 *
 * @property string                               $id
 * @property string                               $key
 * @property array<string, array<string, string>> $translations
 */
class RecallProtocol extends Model
{
    use HasAuditColumns;
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = ['key', 'translations', 'status', 'sort_order'];

    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'sort_order'   => 'integer',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecallProtocolVersion::class, 'protocol_id')->orderBy('version');
    }

    /** A versão liberada (no máximo uma: índice único parcial). */
    public function releasedVersion(): ?RecallProtocolVersion
    {
        return $this->versions()->where('state', ProtocolVersionState::Released->value)->first();
    }

    /** A versão em andamento (rascunho ou em revisão; no máximo uma). */
    public function openVersion(): ?RecallProtocolVersion
    {
        return $this->versions()->whereIn('state', [ProtocolVersionState::Draft->value, ProtocolVersionState::InReview->value])->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Texto de exibição no idioma pedido, com queda para pt_BR. */
    public function text(string $field, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->translations[$locale][$field] ?? $this->translations['pt_BR'][$field] ?? '');
    }

    public function title(?string $locale = null): string
    {
        return $this->text('title', $locale) ?: $this->key;
    }
}
