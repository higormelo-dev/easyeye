<?php

namespace App\Enums\Billing;

enum CredentialScope: string
{
    case Global = 'global';
    case Tenant = 'tenant';
}
