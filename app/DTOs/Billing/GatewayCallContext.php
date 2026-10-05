<?php

namespace App\DTOs\Billing;

/**
 * Contexto de uma chamada ao gateway.
 *
 * `entityId` identifica a clínica envolvida (logs, auditoria). As credenciais
 * só vêm do gateway próprio da clínica (escopo tenant) quando
 * `useTenantCredentials` é true — cobrança da clínica aos pacientes. A
 * cobrança da assinatura do EasyEye à clínica usa sempre a credencial do
 * SaaS (escopo global, cadastrada no manager ou no .env).
 */
final readonly class GatewayCallContext
{
    public function __construct(
        public string $correlationId,
        public string $entityId,
        public bool $useTenantCredentials = false,
    ) {
    }
}
