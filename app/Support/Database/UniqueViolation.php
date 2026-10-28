<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;
use Throwable;

/**
 * Qual índice/constraint única uma exceção de banco violou — sem depender do
 * idioma das mensagens do servidor (lc_messages).
 *
 * Antes, cada detector procurava texto em inglês ("unique constraint") ou
 * fazia str_contains na mensagem INTEIRA da QueryException (que inclui o SQL
 * com os bindings: um valor digitado pelo usuário podia "conter" o nome do
 * índice). Com o PostgreSQL em pt_BR a mensagem vira
 * `duplicar valor da chave viola a restrição de unicidade "x"` e a detecção
 * em inglês falhava em silêncio.
 *
 * Aqui:
 *  1. é violação de unicidade? UniqueConstraintViolationException (o Laravel
 *     classifica por driver: SQLSTATE 23505 no PostgreSQL, 1062 no MySQL) ou
 *     SQLSTATE 23505 no errorInfo/código de qualquer PDOException da cadeia;
 *  2. qual constraint? o nome como identificador inteiro na LINHA PRINCIPAL da
 *     mensagem do driver (errorInfo[2]) — onde o nome é o único identificador,
 *     entre aspas de qualquer idioma ("x", «x», »x«, 'tabela.x'). O DETAIL
 *     (valores da chave) e o SQL/bindings anexados pelo Laravel ficam de fora.
 */
final class UniqueViolation
{
    /** SQLSTATE unique_violation (PostgreSQL; classe 23 do SQL padrão). */
    public const SQLSTATE = '23505';

    /** Caracteres de identificador SQL: delimitam o nome para não casar prefixo/sufixo de outro índice. */
    private const IDENTIFIER_CHARS = 'A-Za-z0-9_$';

    public static function isUniqueViolation(Throwable $e): bool
    {
        return $e instanceof UniqueConstraintViolationException || self::sqlState($e) === self::SQLSTATE;
    }

    /**
     * A exceção é violação de unicidade de alguma das constraints informadas?
     */
    public static function violates(Throwable $e, string ...$constraints): bool
    {
        if (! self::isUniqueViolation($e)) {
            return false;
        }

        $message = self::driverPrimaryMessage($e);

        foreach ($constraints as $constraint) {
            if ($constraint === '') {
                continue;
            }

            $pattern = sprintf('/(?<![%1$s])%2$s(?![%1$s])/u', self::IDENTIFIER_CHARS, preg_quote($constraint, '/'));

            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Formato do SQLite, que não cita o nome do índice, só as colunas:
     * "UNIQUE constraint failed: tabela.a, tabela.b". Exige a lista EXATA (entre
     * o ":" e o fim da linha principal) — prefixo, sufixo ou índice com colunas
     * a mais não contam.
     */
    public static function violatesColumns(Throwable $e, string $table, string ...$columns): bool
    {
        if ($table === '' || $columns === [] || ! self::isUniqueViolation($e)) {
            return false;
        }

        $list = implode(', ', array_map(fn (string $column): string => $table . '.' . $column, $columns));

        return preg_match(sprintf('/:\s*%s\s*$/u', preg_quote($list, '/')), self::driverPrimaryMessage($e)) === 1;
    }

    /** SQLSTATE do primeiro PDOException da cadeia (QueryException copia o errorInfo do PDO). */
    private static function sqlState(Throwable $e): ?string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof PDOException) {
                continue;
            }

            $state = $current->errorInfo[0] ?? $current->getCode();

            if (is_string($state) && strlen($state) === 5) {
                return $state;
            }
        }

        return null;
    }

    /**
     * Primeira linha da mensagem do driver. Sem errorInfo (exceção montada à
     * mão), usa a mensagem da exceção sem o sufixo "(Connection: ..., SQL: ...)".
     */
    private static function driverPrimaryMessage(Throwable $e): string
    {
        $message = null;

        for ($current = $e; $current !== null && $message === null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && is_string($current->errorInfo[2] ?? null)) {
                $message = $current->errorInfo[2];
            }
        }

        $message ??= (string) preg_replace('/\s\(Connection: .*$/s', '', $e->getPrevious()?->getMessage() ?? $e->getMessage());

        return strtok($message, "\r\n") ?: '';
    }
}
