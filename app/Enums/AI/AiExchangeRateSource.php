<?php

declare(strict_types=1);

namespace App\Enums\AI;

/** Origem de uma cotação US$→R$ em `ai_exchange_rates`. PTAX/BCB só existe depois de autorizado como serviço externo. */
enum AiExchangeRateSource: string
{
    case Manual      = 'manual';
    case LegacyTopup = 'legacy_topup';
    case PtaxBcb     = 'ptax_bcb';
    /** Taxa diária do BCE via api.frankfurter.dev (sem chave), buscada pelo próprio sistema. */
    case Frankfurter = 'frankfurter';
}
