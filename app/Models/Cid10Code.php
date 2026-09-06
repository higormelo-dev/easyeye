<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Cid10Code extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'description', 'category'];

    /**
     * Busca por código OU nome da doença, insensível a acento nos dois lados
     * ("miopia" acha "Miopia"; "urgencia" acha "urgência") — TRANSLATE puro,
     * sem depender da extensão unaccent do Postgres. Código com prefixo
     * casando vem primeiro (H52 → H52.x antes de outros que contêm h52).
     */
    private const ACCENTS = 'áàâãäéèêëíìîïóòôõöúùûüçÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇ';

    private const UNACCENTS = 'aaaaaeeeeiiiiooooouuuucAAAAAEEEEIIIIOOOOOUUUUC';

    public function scopeSearch($query, string $term): void
    {
        $lower = mb_strtolower(strtr(trim($term), array_combine(
            mb_str_split(self::ACCENTS),
            str_split(self::UNACCENTS),
        )), 'UTF-8');

        $query->where(function ($q) use ($lower) {
            $q->whereLikeUnaccent('code', $lower)
                ->orWhereLikeUnaccent('description', $lower);
        })->orderByRaw('CASE WHEN LOWER(code) LIKE ? THEN 0 ELSE 1 END', ["{$lower}%"])
            ->orderBy('code');
    }
}
