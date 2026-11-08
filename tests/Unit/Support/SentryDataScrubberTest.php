<?php

declare(strict_types=1);

/**
 * LGPD (arts. 6º, III e 33): o que vai ao Sentry não leva dado pessoal — corpo
 * e query string da requisição, valores do SQL/DETAIL do banco e logs como
 * breadcrumb ficam no servidor.
 */

use App\Support\Sentry\SentryDataScrubber;
use Illuminate\Database\{QueryException, UniqueConstraintViolationException};
use Sentry\{Breadcrumb, Event, EventHint, ExceptionDataBag, Options};
use Sentry\DataCollection\{DataCollectionPolicy, HttpHeaderCollector, HttpMessageType, HttpUrlCollector};

function sdsQueryException(string $class = QueryException::class): QueryException
{
    $pdo = new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "people_email_unique" DETAIL:  Key (email)=(maria@exemplo.test) already exists.');

    return new $class('pgsql', 'insert into "people" ("full_name", "email") values (?, ?)', ['MARIA SOUZA', 'maria@exemplo.test'], $pdo);
}

function sdsEvent(Throwable $exception): Event
{
    $event = Event::createEvent();
    $event->setExceptions(array_map(
        fn (Throwable $e) => new ExceptionDataBag($e),
        array_values(array_filter([$exception->getPrevious(), $exception])),
    ));
    $event->setRequest([
        'url'          => 'https://easyeye.app/painel/pacientes?search=MARIA%20SOUZA',
        'method'       => 'POST',
        'query_string' => 'search=MARIA%20SOUZA',
        'data'         => ['name' => 'MARIA SOUZA', 'national_registry' => '52998224725'],
        'cookies'      => ['easyeye_session' => 'x'],
    ]);

    return $event;
}

it('tira corpo, query string e cookies da requisição e a query da URL', function (): void {
    $exception = new RuntimeException('falhou');
    $event     = SentryDataScrubber::beforeSend(sdsEvent($exception), EventHint::fromArray(['exception' => $exception]));

    expect($event->getRequest())->toBe([
        'url'    => 'https://easyeye.app/painel/pacientes',
        'method' => 'POST',
    ]);
});

it('troca a mensagem da QueryException e do PDOException por SQLSTATE + SQL com placeholders', function (string $class): void {
    $exception = sdsQueryException($class);
    $event     = SentryDataScrubber::beforeSend(sdsEvent($exception), EventHint::fromArray(['exception' => $exception]));

    $values = array_map(fn (ExceptionDataBag $bag) => $bag->getValue(), $event->getExceptions());

    expect($values)->toHaveCount(2);

    foreach ($values as $value) {
        expect($value)->toStartWith('SQLSTATE[23505]')
            ->not->toContain('MARIA')
            ->not->toContain('maria@exemplo.test')
            ->not->toContain('DETAIL')
            ->toContain('insert into "people" ("full_name", "email") values (?, ?)');
    }
})->with([QueryException::class, UniqueConstraintViolationException::class]);

it('não mexe na mensagem de exceção que não é de banco', function (): void {
    $exception = new RuntimeException('Arquivo de exame ausente');
    $event     = SentryDataScrubber::beforeSend(sdsEvent($exception), EventHint::fromArray(['exception' => $exception]));

    expect($event->getExceptions()[0]->getValue())->toBe('Arquivo de exame ausente');
});

it('descarta breadcrumbs de log da aplicação e mantém os demais', function (): void {
    $log   = new Breadcrumb(Breadcrumb::LEVEL_WARNING, Breadcrumb::TYPE_DEFAULT, 'log.warning', 'Paciente MARIA sem convênio', ['cpf' => '52998224725']);
    $query = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'db.sql.query', 'select * from "patients" where "id" = ?');

    expect(SentryDataScrubber::beforeBreadcrumb($log))->toBeNull()
        ->and(SentryDataScrubber::beforeBreadcrumb($query))->toBe($query);
});

it('config/sentry.php liga o filtro e desliga o corpo da requisição (sobrevive ao config:cache)', function (): void {
    $config = require dirname(__DIR__, 3) . '/config/sentry.php';

    expect($config['max_request_body_size'])->toBe('never')
        ->and($config['before_send'])->toBe([SentryDataScrubber::class, 'beforeSend'])
        ->and($config['before_send_transaction'])->toBe([SentryDataScrubber::class, 'beforeSend'])
        ->and($config['before_breadcrumb'])->toBe([SentryDataScrubber::class, 'beforeBreadcrumb'])
        ->and(is_callable($config['before_send']))->toBeTrue()
        ->and(is_callable($config['before_breadcrumb']))->toBeTrue();
});

it('o SDK aceita o data_collection e não coleta IP, cookies, corpo, query string, SQL, argumentos da pilha, fila nem IA', function (): void {
    $config  = require dirname(__DIR__, 3) . '/config/sentry.php';
    $options = new Options($config);
    $policy  = DataCollectionPolicy::fromOptions($options);
    $dc      = $options->getDataCollection();

    expect($policy->isLegacyMode())->toBeFalse()
        ->and($policy->shouldCollectUserInfo())->toBeFalse()
        ->and($policy->shouldCollectDatabaseQueryData())->toBeFalse()
        ->and($policy->getHttpBodyLimit(HttpMessageType::incomingRequest()))->toBeNull()
        ->and($dc->getCookies()->getMode())->toBe('off')
        ->and($dc->getUrlQueryParams()->getMode())->toBe('off')
        ->and($dc->getStackFrameVariables()->getMode())->toBe('off')
        ->and($dc->shouldCollectQueues())->toBeFalse()
        ->and($dc->getGenAi())->toBe(['inputs' => false, 'outputs' => false])
        // URL de requisição (erro, transação, breadcrumb HTTP): sem a query (busca por nome, CPF do Asaas…).
        ->and(HttpUrlCollector::collect($policy, HttpMessageType::outgoingRequest(), 'https://api.asaas.com/v3/customers?cpfCnpj=52998224725'))
        ->toBe('https://api.asaas.com/v3/customers')
        ->and(HttpUrlCollector::collectQueryString($policy, 'search=MARIA'))->toBeNull()
        // Cabeçalhos: só os técnicos; o referer (URL com a busca) sai filtrado.
        ->and(HttpHeaderCollector::collect($policy, HttpMessageType::incomingRequest(), [
            'referer'    => ['https://easyeye.app/painel/pacientes?search=MARIA'],
            'user-agent' => ['Mozilla/5.0'],
        ]))
        ->not->toHaveKey('referer', ['https://easyeye.app/painel/pacientes?search=MARIA']);
});
