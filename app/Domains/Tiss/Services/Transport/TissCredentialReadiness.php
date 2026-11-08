<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Transport;

use App\Domains\Tiss\Exceptions\TissCredentialIncompleteException;
use App\Domains\Tiss\Models\TissEntityOperatorCredential;

/**
 * Bloqueio do envio quando falta configuração na credencial TISS. Só vale para o transporte real (`http`): o mock
 * não usa nenhum dado da credencial. Roda antes de reservar a chave de idempotência e de reivindicar a transmissão,
 * então nada é gravado. Os dois remetentes (lote e guia cirúrgica avulsa) chamam daqui.
 */
final class TissCredentialReadiness
{
    public static function required(): bool
    {
        return config('tiss.transport.driver') === 'http';
    }

    /** @throws TissCredentialIncompleteException */
    public static function assertReady(TissEntityOperatorCredential $credential): void
    {
        if (! self::required()) {
            return;
        }

        $missing = $credential->missingForSubmission();

        if ($missing !== []) {
            throw new TissCredentialIncompleteException(self::message($credential, $missing));
        }
    }

    /** @param list<string> $missing */
    public static function message(TissEntityOperatorCredential $credential, array $missing): string
    {
        return (string) __('tiss_send.credential_incomplete', [
            'operator'    => (string) ($credential->operator?->trade_name ?: $credential->operator?->name ?: '—'),
            'environment' => (string) __('tiss_credentials.environments.' . $credential->environment),
            'missing'     => implode(', ', array_map(fn (string $item): string => (string) __('tiss_credentials.missing.' . $item), $missing)),
        ]);
    }
}
