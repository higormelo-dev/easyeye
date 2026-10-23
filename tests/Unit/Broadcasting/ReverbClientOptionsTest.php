<?php

declare(strict_types=1);

namespace Tests\Unit\Broadcasting;

use GuzzleHttp\Client;
use Illuminate\Broadcasting\BroadcastManager;
use Pusher\Pusher;
use ReflectionProperty;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * F1: os eventos do painel usam ShouldBroadcastNow — o request (ou o worker) espera o Reverb responder. Sem teto, um
 * Reverb pendurado custa 30 s (padrão do Guzzle) por evento e o import cai no timeout "sem motivo".
 */
it('o cliente HTTP do Reverb desiste em 2 s (conexão em 1 s), não nos 30 s do Guzzle', function () {
    config([
        'broadcasting.connections.reverb.key'    => 'chave-de-teste',
        'broadcasting.connections.reverb.secret' => 'segredo-de-teste',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);

    $pusher = app(BroadcastManager::class)->pusher(config('broadcasting.connections.reverb'));
    $client = (new ReflectionProperty(Pusher::class, 'client'))->getValue($pusher);

    expect(config('broadcasting.connections.reverb.client_options'))->toBe(['timeout' => 2, 'connect_timeout' => 1])
        ->and($client)->toBeInstanceOf(Client::class)
        ->and($client->getConfig('timeout'))->toBe(2)
        ->and($client->getConfig('connect_timeout'))->toBe(1);
});
