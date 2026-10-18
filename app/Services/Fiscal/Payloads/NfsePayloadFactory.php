<?php

declare(strict_types=1);

namespace App\Services\Fiscal\Payloads;

use App\Contracts\Fiscal\NfsePayloadBuilder;
use App\Enums\Fiscal\NfseStandard;

/** O builder do padrão da nota (a linha guarda o padrão com que nasceu: reenvio nunca troca de padrão). */
final class NfsePayloadFactory
{
    public function for(NfseStandard $standard): NfsePayloadBuilder
    {
        return match ($standard) {
            NfseStandard::Municipal => new MunicipalNfsePayloadBuilder(),
            NfseStandard::National  => new NationalNfsePayloadBuilder(),
        };
    }
}
