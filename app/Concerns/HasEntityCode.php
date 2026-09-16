<?php

namespace App\Concerns;

use Illuminate\Database\QueryException;

trait HasEntityCode
{
    protected static function bootHasEntityCode(): void
    {
        static::creating(function (self $model) {
            if (blank($model->code)) {
                $model->code = $model->nextEntityCode();
            }
        });
    }

    /**
     * `code` é gerado lendo o último já existente + 1 — sem lock real
     * (a linha "última" pode nem existir ainda pra corrida entre o
     * primeiro/segundo registro de uma entidade) nenhuma leitura sozinha
     * evita duas criações concorrentes computarem o mesmo próximo número.
     * A proteção de verdade é o índice único (entity_id, code) no banco
     * (quando presente na tabela — nem toda tabela HasEntityCode tem esse
     * índice ainda, ver migrations) + o retry aqui: se o INSERT colidir,
     * `code` é limpo e recalculado antes de tentar de novo. Sem índice
     * único a colisão simplesmente não é detectada (comportamento
     * inalterado nas tabelas que ainda não têm o índice).
     */
    public function save(array $options = [])
    {
        $attempts = 0;

        while (true) {
            try {
                return parent::save($options);
            } catch (QueryException $e) {
                $attempts++;

                if ($this->exists || $attempts >= 3 || ! $this->isEntityCodeUniqueViolation($e)) {
                    throw $e;
                }

                $this->code = null;
            }
        }
    }

    protected function nextEntityCode(): string
    {
        $prefix = $this->entity_id
            ? $this->codePrefix
            : $this->codePrefixGlobal;

        $last = static::withoutGlobalScopes()
            ->when(
                $this->entity_id !== null,
                fn ($q) => $q->where('entity_id', $this->entity_id),
                fn ($q) => $q->whereNull('entity_id'),
            )
            ->where('code', 'like', $prefix . '-%')
            ->orderBy('code', 'desc')
            ->first();

        $newNumber = $last
            ? ((int) substr($last->code, strlen($prefix) + 1)) + 1
            : 1;

        return sprintf('%s-%010d', $prefix, $newNumber);
    }

    private function isEntityCodeUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true)
            && str_contains($e->getMessage(), 'code');
    }
}
