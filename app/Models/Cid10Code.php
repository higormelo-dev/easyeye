<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Cid10Code extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'description', 'category'];

    /**
     * Busca por código ou descrição (case-insensitive, parcial).
     * Ordena: correspondências no código primeiro, depois por código.
     */
    public function scopeSearch($query, string $term): void
    {
        $lower = mb_strtolower(trim($term), 'UTF-8');

        $query->where(function ($q) use ($lower) {
            $q->whereRaw('LOWER(code) LIKE ?', ["%{$lower}%"])
                ->orWhereRaw('LOWER(description) LIKE ?', ["%{$lower}%"]);
        })->orderByRaw('CASE WHEN LOWER(code) LIKE ? THEN 0 ELSE 1 END', ["{$lower}%"])
            ->orderBy('code');
    }
}
