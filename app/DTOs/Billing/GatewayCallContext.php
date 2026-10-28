<?php

namespace App\DTOs\Billing;

/**
 * Contexto de uma chamada ao gateway.
 *
 * `entityId` identifica a clínica cobrada (logs, auditoria). As credenciais
 * são sempre as do dono do SaaS (escopo global, cadastradas no manager ou no
 * .env): clínica não tem gateway próprio.
 */
final readonly class GatewayCallContext
{
    public function __construct(
        public string $correlationId,
        public string $entityId,
    ) {
    }
}
