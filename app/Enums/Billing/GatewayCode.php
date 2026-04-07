<?php

namespace App\Enums\Billing;

enum GatewayCode: string
{
    case InfinitePay = 'infinitepay';
    case Asaas       = 'asaas';
    case MercadoPago = 'mercadopago';
    case Pagarme     = 'pagarme';
    case StripeBr    = 'stripe_br';
    case PagBank     = 'pagbank';
}
