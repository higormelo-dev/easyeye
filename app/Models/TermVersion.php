<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class TermVersion extends Model
{
    use HasUuids;

    /** Idioma do texto oficial (`content`): o que vale e o que os usuários aceitam. */
    public const OFFICIAL_LOCALE = 'pt_BR';

    protected $fillable = [
        'type',
        'version',
        'content',
        'translations',
        'summary',
        'effective_from',
        'active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'translations'   => 'array',
            'active'         => 'boolean',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(UserTermAcceptance::class, 'term_version_id');
    }

    /**
     * Texto para exibir no idioma pedido: a tradução de cortesia, quando
     * existe, ou o original em português. Nunca altera o texto oficial.
     *
     * @return array{content: string, locale: string, is_translation: bool}
     */
    public function contentFor(string $locale): array
    {
        $translation = $locale === self::OFFICIAL_LOCALE ? null : ($this->translations[$locale] ?? null);

        if (is_string($translation) && trim($translation) !== '') {
            return ['content' => $translation, 'locale' => $locale, 'is_translation' => true];
        }

        return ['content' => (string) $this->content, 'locale' => self::OFFICIAL_LOCALE, 'is_translation' => false];
    }

    /**
     * Retorna a versão ativa mais recente de um tipo de documento.
     */
    public static function currentFor(string $type): ?self
    {
        return static::query()
            ->where('type', $type)
            ->where('active', true)
            ->where('effective_from', '<=', now()->toDateString())
            ->orderBy('effective_from', 'desc')
            ->orderBy('version', 'desc')
            ->first();
    }
}
