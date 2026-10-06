<?php

namespace App\Enums\Billing;

/**
 * Escopo de gateway_credentials.scope. Só existe o global: os gateways são do
 * dono do SaaS (cobram as clínicas). O antigo 'tenant' (gateway próprio da
 * clínica) saiu do produto e as linhas foram apagadas pela migration
 * 2026_10_11_000100_delete_tenant_gateway_credentials — a coluna `scope` fica
 * só por compatibilidade de schema.
 */
enum CredentialScope: string
{
    case Global = 'global';
}
