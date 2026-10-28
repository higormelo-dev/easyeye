<?php

declare(strict_types=1);

namespace App\Enums\Fiscal;

/**
 * Padrão da NFS-e na Focus NFe: o municipal (`/v2/nfse`) e o nacional
 * (`/v2/nfsen`, DPS) são endpoints diferentes com a mesma semântica. A empresa
 * na Focus só pode ter UM dos dois habilitado em produção.
 */
enum NfseStandard: string
{
    case Municipal = 'municipal';
    case National  = 'nacional';

    public function label(): string
    {
        return __("fiscal.standard.{$this->value}");
    }

    /** Segmento do endpoint: /v2/{nfse|nfsen}. */
    public function endpoint(): string
    {
        return match ($this) {
            self::Municipal => 'nfse',
            self::National  => 'nfsen',
        };
    }

    /** Valor do campo `event` ao registrar o webhook (POST /v2/hooks). */
    public function hookEvent(): string
    {
        return $this->endpoint();
    }
}
