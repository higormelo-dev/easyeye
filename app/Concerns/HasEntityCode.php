<?php

namespace App\Concerns;

use App\Support\Database\UniqueViolation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{Log, Schema};

/**
 * `code` sequencial por clínica (ex.: FLC-0000000001), gerado no `creating`
 * quando vier em branco.
 *
 * Concorrência (antes: último código lido sem lock + nova tentativa na MESMA
 * transação; no PostgreSQL o INSERT que colide aborta a transação (25P02),
 * então a nova tentativa nunca funcionava dentro de DB::transaction — todos
 * os fluxos reais — e virava HTTP 500):
 *  1. PostgreSQL: advisory lock de transação por tabela × clínica × prefixo
 *     antes de ler o último código. Só quem numera a mesma sequência espera,
 *     até o commit da transação do chamador (quando o número fica visível);
 *     rollback devolve o número.
 *  2. Cada tentativa de INSERT roda em transação própria (SAVEPOINT quando o
 *     chamador já abriu uma): uma colisão desfaz só a tentativa.
 *  3. Nova tentativa só para código GERADO aqui que colidiu no índice único
 *     de código da tabela (identificado pelo nome do índice na mensagem do
 *     driver, em qualquer idioma — ver UniqueViolation) — qualquer outra
 *     violação única (nome, agendamento, sku...) sobe intacta para o chamador
 *     tratar (ex.: createOrFirst).
 */
trait HasEntityCode
{
    /** Tentativas de INSERT quando o código gerado colide (o lock já evita no fluxo normal). */
    private const ENTITY_CODE_MAX_ATTEMPTS = 3;

    /** Dígitos do sequencial: largura fixa => ordem de texto igual à numérica. */
    private const ENTITY_CODE_DIGITS = 10;

    /** O `code` do INSERT em curso foi gerado aqui (código explícito nunca é trocado). */
    private bool $entityCodeGenerated = false;

    /** @var array<string, list<string>> índices únicos de código por conexão|tabela */
    private static array $entityCodeUniqueIndexes = [];

    protected static function bootHasEntityCode(): void
    {
        static::creating(function (self $model): void {
            if (blank($model->code)) {
                $model->lockEntityCodeSequence();
                $model->code                = $model->nextEntityCode();
                $model->entityCodeGenerated = true;
            }
        });
    }

    public function save(array $options = [])
    {
        if ($this->exists) {
            return parent::save($options);
        }

        for ($attempt = 1;; $attempt++) {
            $this->entityCodeGenerated = false;

            try {
                return $this->getConnection()->transaction(fn () => parent::save($options));
            } catch (UniqueConstraintViolationException $e) {
                if (
                    $this->exists
                    || ! $this->entityCodeGenerated
                    || $attempt >= self::ENTITY_CODE_MAX_ATTEMPTS
                    || ! $this->isEntityCodeUniqueViolation($e)
                ) {
                    throw $e;
                }

                Log::warning('entity_code.collision', [
                    'table'     => $this->getTable(),
                    'entity_id' => $this->entity_id,
                    'attempt'   => $attempt,
                ]);

                $this->discardGeneratedEntityCode();
            }
        }
    }

    /**
     * Atributos que o model copia do `code` gerado (ex.: BillingClaim::guide_number).
     * Numa nova tentativa, os que ainda tiverem o código descartado são limpos
     * para serem recalculados junto com o novo código.
     *
     * @return list<string>
     */
    protected function entityCodeMirrorAttributes(): array
    {
        return [];
    }

    /**
     * Maior sequencial + 1. Ignora escopos globais (inclusive soft delete) para
     * enxergar o mesmo que o índice único (entity_id, code). Só considera o
     * formato gerado aqui (prefixo + 10 dígitos): códigos fora do padrão nunca
     * colidem com um gerado e não podem desviar a sequência. A busca desce pelo
     * índice (entity_id, code) e para no primeiro registro.
     */
    protected function nextEntityCode(): string
    {
        $prefix = $this->entityCodePrefix() . '-';

        $query = static::withoutGlobalScopes()
            ->when(
                $this->entity_id !== null,
                fn ($q) => $q->where('entity_id', $this->entity_id),
                fn ($q) => $q->whereNull('entity_id'),
            );

        if ($this->getConnection()->getDriverName() === 'pgsql') {
            $query->where('code', '~', sprintf('^%s[0-9]{%d}$', preg_quote($prefix), self::ENTITY_CODE_DIGITS));
        } else {
            $query->where('code', 'like', $prefix . str_repeat('_', self::ENTITY_CODE_DIGITS));
        }

        $last = $query->orderBy('code', 'desc')->value('code');

        $newNumber = $last !== null
            ? ((int) substr((string) $last, strlen($prefix))) + 1
            : 1;

        return sprintf('%s%0' . self::ENTITY_CODE_DIGITS . 'd', $prefix, $newNumber);
    }

    private function entityCodePrefix(): string
    {
        return $this->entity_id ? $this->codePrefix : $this->codePrefixGlobal;
    }

    /**
     * Serializa a geração da mesma sequência até o fim da transação (PostgreSQL).
     * Demais drivers: sem lock — valem o índice único e as novas tentativas.
     */
    private function lockEntityCodeSequence(): void
    {
        $connection = $this->getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $key = implode('|', ['entity_code', $this->getTable(), (string) $this->entity_id, $this->entityCodePrefix()]);

        // Chave bigint estável (60 bits do sha1, sempre positiva). Colisão de
        // chave entre sequências só serializaria as duas — nunca mistura números.
        $connection->select('select pg_advisory_xact_lock(?)', [(int) hexdec(substr(sha1($key), 0, 15))], false);
    }

    private function discardGeneratedEntityCode(): void
    {
        $discarded = $this->code;

        foreach ($this->entityCodeMirrorAttributes() as $attribute) {
            if ($this->getAttribute($attribute) === $discarded) {
                $this->setAttribute($attribute, null);
            }
        }

        $this->code = null;
    }

    /**
     * A violação é do índice único de código desta tabela ((entity_id, code)
     * ou (code))? Procura os nomes reais desses índices (lidos uma vez por
     * processo) na mensagem do DRIVER, em qualquer idioma do servidor.
     *
     * Antes: exigia o texto em inglês `unique constraint "x"` — com
     * lc_messages=pt_BR (`viola a restrição de unicidade "x"`) a colisão nunca
     * era reconhecida e virava HTTP 500 em vez de nova tentativa.
     */
    private function isEntityCodeUniqueViolation(UniqueConstraintViolationException $e): bool
    {
        return UniqueViolation::violates($e, ...$this->entityCodeUniqueIndexes());
    }

    /** @return list<string> */
    private function entityCodeUniqueIndexes(): array
    {
        $cacheKey = $this->getConnectionName() . '|' . $this->getTable();

        return self::$entityCodeUniqueIndexes[$cacheKey] ??= collect(
            Schema::connection($this->getConnectionName())->getIndexes($this->getTable()),
        )
            ->filter(fn (array $index): bool => $index['unique']
                && in_array('code', $index['columns'], true)
                && array_diff($index['columns'], ['entity_id', 'code']) === [])
            ->pluck('name')
            ->values()
            ->all();
    }
}
