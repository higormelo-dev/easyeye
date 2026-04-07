<?php

namespace App\Enums\Billing;

enum FallbackTriggerType: string
{
    case Timeout            = 'timeout';
    case Http5xx            = 'http_5xx';
    case GatewayUnavailable = 'gateway_unavailable';
    case ProviderRateLimit  = 'provider_rate_limit';
}
