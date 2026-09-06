<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * BUGFIX (achado de produto, sessão de auditoria de segurança): banco usa
 * collation 'C' (POSIX) — ILIKE só faz case-fold ASCII, não sabe que 'ã'='Ã'.
 * People::setAttribute() sempre salva full_name em CAIXA ALTA, então qualquer
 * busca digitada com acento minúsculo (o jeito natural de digitar) nunca
 * batia com nomes acentuados salvos. Registra whereLikeUnaccent/
 * orWhereLikeUnaccent como macro no query builder (Eloquent e base) — chamado
 * uma vez em AppServiceProvider::boot(). Requer a extensão unaccent do
 * Postgres (migration 2026_09_06_100002_enable_unaccent_extension).
 *
 * Uso: $query->whereLikeUnaccent('people.full_name', $search) no lugar de
 * ->where('people.full_name', 'ilike', '%'.$search.'%') ou
 * ->whereRaw('LOWER(people.full_name) LIKE ?', ["%{$lower}%"]).
 *
 * $column é sempre um literal fixo no código-fonte (nunca vindo de input do
 * usuário) em todo ponto de chamada — mesma premissa já usada pelos padrões
 * whereRaw('LOWER(coluna) LIKE ?', ...) preexistentes no projeto.
 */
class AccentInsensitiveSearch
{
    public static function register(): void
    {
        $where = function (string $column, string $term) {
            /** @var EloquentBuilder|QueryBuilder $this */
            return $this->whereRaw("unaccent({$column}) ILIKE unaccent(?)", ['%' . $term . '%']);
        };

        $orWhere = function (string $column, string $term) {
            /** @var EloquentBuilder|QueryBuilder $this */
            return $this->orWhereRaw("unaccent({$column}) ILIKE unaccent(?)", ['%' . $term . '%']);
        };

        QueryBuilder::macro('whereLikeUnaccent', $where);
        QueryBuilder::macro('orWhereLikeUnaccent', $orWhere);
        EloquentBuilder::macro('whereLikeUnaccent', $where);
        EloquentBuilder::macro('orWhereLikeUnaccent', $orWhere);
    }
}
