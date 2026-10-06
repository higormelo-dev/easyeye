<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catálogo GLOBAL da CID-10 (sem entity_id): lista oficial do DATASUS +
 * códigos criados pelo manager (Manager → CID-10).
 *
 * - description: o que médicos veem na busca; official_description: texto
 *   oficial, sempre atualizado pela importação (Cid10CatalogImporter).
 * - description_edited_at: o manager editou o texto de um código oficial —
 *   a importação deixa de trocar description.
 * - source: datasus | custom (fora da tabela oficial — TISS pode recusar).
 */
class Cid10Code extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;

    public const SOURCE_DATASUS = 'datasus';

    public const SOURCE_CUSTOM = 'custom';

    /** Formato aceito: letra + 2 dígitos + opcional ponto e 1 dígito (H25 ou H25.1), maiúsculo. */
    public const CODE_PATTERN = '/^[A-Z]\d{2}(\.\d)?$/';

    protected $fillable = [
        'code',
        'description',
        'official_description',
        'category',
        'source',
        'chapter',
        'group_name',
        'description_edited_at',
        'description_edited_by',
    ];

    /**
     * Busca por código OU nome da doença, insensível a acento nos dois lados
     * ("miopia" acha "Miopia"; "urgencia" acha "urgência") — TRANSLATE puro,
     * sem depender da extensão unaccent do Postgres. Código com prefixo
     * casando vem primeiro (H52 → H52.x antes de outros que contêm h52);
     * depois o capítulo do olho (H00–H59).
     */
    private const ACCENTS = 'áàâãäéèêëíìîïóòôõöúùûüçÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇ';

    private const UNACCENTS = 'aaaaaeeeeiiiiooooouuuucAAAAAEEEEIIIIOOOOOUUUUC';

    protected function casts(): array
    {
        return [
            'description_edited_at' => 'datetime',
        ];
    }

    /** Quem editou a descrição de um código oficial por último. */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'description_edited_by');
    }

    public function isCustom(): bool
    {
        return $this->source === self::SOURCE_CUSTOM;
    }

    public function scopeSearch($query, string $term): void
    {
        $lower = self::normalizeTerm($term);

        $query->matching($term)
            ->orderByRaw('CASE WHEN LOWER(code) LIKE ? THEN 0 ELSE 1 END', ["{$lower}%"])
            // Catálogo completo (DATASUS): o capítulo VII — doenças do olho e
            // anexos (H00–H59) — vem antes dos demais capítulos na busca por nome.
            ->orderByRaw("CASE WHEN code >= 'H00' AND code < 'H60' THEN 0 ELSE 1 END")
            ->orderBy('code');
    }

    /** Só o filtro da busca (sem ordenação) — a tela do manager ordena pela coluna escolhida. */
    public function scopeMatching($query, string $term): void
    {
        $lower = self::normalizeTerm($term);
        $code  = $this->qualifyColumn('code');
        $text  = $this->qualifyColumn('description');

        $query->where(function ($q) use ($lower, $code, $text) {
            $q->whereLikeUnaccent($code, $lower)
                ->orWhereLikeUnaccent($text, $lower);
        });
    }

    private static function normalizeTerm(string $term): string
    {
        return mb_strtolower(strtr(trim($term), array_combine(
            mb_str_split(self::ACCENTS),
            str_split(self::UNACCENTS),
        )), 'UTF-8');
    }
}
