<?php

namespace App\Providers;

use App\Contracts\Billing\PaymentGatewayInterface;
use App\Enums\Billing\GatewayCode;
use App\Services\Billing\{BillingCancellationService, CircuitBreakerService, GatewayRegistry};
use App\Services\Billing\Gateways\{AsaasGateway, InfinitePayGateway, MercadoPagoGateway, PagBankGateway, PagarmeGateway, StripeBrGateway};
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Circuit breaker — singleton so state is shared within the same process
        $this->app->singleton(CircuitBreakerService::class, function ($app): CircuitBreakerService {
            return new CircuitBreakerService(
                threshold:       (int) config('billing.circuit_breaker.failure_threshold', 5),
                cooldownSeconds: (int) config('billing.circuit_breaker.cooldown_seconds', 300),
            );
        });

        $this->app->singleton(GatewayRegistry::class, function ($app): GatewayRegistry {
            return new GatewayRegistry([
                GatewayCode::InfinitePay->value => $app->make(InfinitePayGateway::class),
                GatewayCode::Asaas->value       => $app->make(AsaasGateway::class),
                GatewayCode::MercadoPago->value => $app->make(MercadoPagoGateway::class),
                GatewayCode::Pagarme->value     => $app->make(PagarmeGateway::class),
                GatewayCode::StripeBr->value    => $app->make(StripeBrGateway::class),
                GatewayCode::PagBank->value     => $app->make(PagBankGateway::class),
            ]);
        });

        $this->app->bind(PaymentGatewayInterface::class, function ($app): PaymentGatewayInterface {
            $default = config('billing.default_gateway', GatewayCode::Asaas->value);

            return $app->make(GatewayRegistry::class)->get((string) $default);
        });

        // BillingCancellationService resolved from container (its deps are all singletons/bound)
        $this->app->bind(BillingCancellationService::class);
    }
}
