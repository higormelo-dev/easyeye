<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Transport;

use App\Domains\Tiss\Exceptions\TissSimulatedTransportException;

/**
 * Trava do envio TISS em produção com transporte simulado. A decisão é só de configuração (ambiente + driver),
 * então vale ANTES de qualquer escrita: nenhuma transmissão é reivindicada, nenhum protocolo é gravado.
 * Os dois remetentes (lote e guia cirúrgica avulsa) chamam daqui.
 */
final class SimulatedTransportGuard
{
    public static function blocked(): bool
    {
        return app()->isProduction()
            && config('tiss.transport.driver') !== 'http'
            && ! config('tiss.transport.allow_simulated_in_production');
    }

    /** @throws TissSimulatedTransportException */
    public static function assertAllowed(): void
    {
        if (self::blocked()) {
            throw new TissSimulatedTransportException((string) __('tiss_send.simulated_blocked'));
        }
    }
}
