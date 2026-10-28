<?php

namespace App\Enums\Billing;

enum CancellationReason: string
{
    case CustomerRequest  = 'customer_request';
    case AdminAction      = 'admin_action';
    case NonPayment       = 'non_payment';
    case FraudSuspected   = 'fraud_suspected';
    case PlanMigration    = 'plan_migration';
    case GatewayMigration = 'gateway_migration';
    case System           = 'system';
}
