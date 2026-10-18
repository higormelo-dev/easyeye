<?php

declare(strict_types=1);

namespace App\Contracts\Fiscal;

use App\DTOs\Fiscal\NfsePayloadContext;

/**
 * Monta o corpo JSON de emissão da NFS-e para um padrão da Focus (municipal ou nacional). Função pura do contexto:
 * só inclui os campos que existem, respeita os limites da API (a Focus recusa de forma síncrona o que passa do tamanho)
 * e nunca leva token — o token vai só no cabeçalho Basic do client.
 */
interface NfsePayloadBuilder
{
    /** @return array<string, mixed> */
    public function build(NfsePayloadContext $context): array;
}
