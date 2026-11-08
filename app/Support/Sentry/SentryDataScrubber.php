<?php

declare(strict_types=1);

namespace App\Support\Sentry;

use Illuminate\Database\QueryException;
use PDOException;
use Sentry\{Breadcrumb, Event, EventHint};

/**
 * Filtro LGPD dos eventos enviados ao Sentry, que processa fora do Brasil
 * (arts. 6º, III e 33): diagnosticar um erro não exige dado pessoal nem de
 * saúde. Mesmo com `send_default_pii=false`, o SDK manda por padrão a URL
 * com query string, o corpo da requisição em erro (até 10 KB — o formulário
 * do paciente inteiro) e a mensagem da QueryException com os valores do SQL
 * e o DETAIL do PostgreSQL. Os logs da aplicação viram breadcrumbs com
 * mensagem e contexto livres.
 *
 * Registrado em config/sentry.php como callable de classe, que sobrevive ao
 * `config:cache` (closure não).
 */
final class SentryDataScrubber
{
    private const DATABASE_EXCEPTIONS = [QueryException::class, PDOException::class];

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            unset($request['query_string'], $request['data'], $request['cookies']);

            if (is_string($request['url'] ?? null)) {
                $request['url'] = explode('?', $request['url'], 2)[0];
            }

            $event->setRequest($request);
        }

        $sql = $hint?->exception instanceof QueryException ? $hint->exception->getSql() : null;

        foreach ($event->getExceptions() as $exception) {
            if (self::isDatabaseException($exception->getType())) {
                $exception->setValue(self::databaseMessage($exception->getValue(), $sql));
            }
        }

        return $event;
    }

    /**
     * Logs da aplicação (`log.*`) não viram breadcrumb: mensagem e contexto
     * são texto livre e podem levar nome, CPF ou dado clínico.
     */
    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        return str_starts_with($breadcrumb->getCategory(), 'log.') ? null : $breadcrumb;
    }

    private static function isDatabaseException(string $type): bool
    {
        foreach (self::DATABASE_EXCEPTIONS as $class) {
            if (is_a($type, $class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mantém o SQLSTATE e o SQL com placeholders (o que diagnostica) e descarta
     * a mensagem do banco, que pode trazer valores. O texto completo segue só
     * no log local do servidor.
     */
    private static function databaseMessage(string $message, ?string $sql): string
    {
        $state = preg_match('/SQLSTATE\[(\w+)\]/', $message, $match) === 1 ? $match[1] : 'unknown';

        return "SQLSTATE[{$state}] (database message removed: may contain personal data)"
            . ($sql !== null ? " SQL: {$sql}" : '');
    }
}
