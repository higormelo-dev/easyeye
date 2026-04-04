<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, MockTissTransportClient, TissTransportContract};
use Illuminate\Support\ServiceProvider;

class TissServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Driver de transporte TISS: mock (padrão) ou http.
        // Controlado por TISS_TRANSPORT_DRIVER no .env.
        $this->app->bind(TissTransportContract::class, function (): TissTransportContract {
            return config('tiss.transport.driver') === 'http'
                ? $this->app->make(HttpTissTransportClient::class)
                : $this->app->make(MockTissTransportClient::class);
        });
    }
}
