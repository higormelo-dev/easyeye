<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Enums\Fiscal\NfseEnvironment;
use App\Exceptions\Fiscal\FocusNfeException;
use App\Models\Fiscal\NfseCredential;

/**
 * Credencial da Focus por ambiente. É o único ponto que lê os tokens: devolve o client já autenticado ou a exceção
 * `credencial_ausente` (traduzida) quando o ambiente não tem o token pedido — nenhum chamador monta cabeçalho à mão.
 */
final class NfseCredentialResolver
{
    public function find(NfseEnvironment $environment): ?NfseCredential
    {
        return NfseCredential::query()->where('environment', $environment->value)->first();
    }

    /** @throws FocusNfeException credencial_ausente */
    public function for(NfseEnvironment $environment): NfseCredential
    {
        return $this->find($environment) ?? throw FocusNfeException::notConfigured();
    }

    /** Client com o token da EMPRESA (emite, consulta, cancela, e-mail, gatilhos). */
    public function client(NfseEnvironment $environment, ?string $correlationId = null): FocusNfeClient
    {
        $credential = $this->for($environment);

        if (! $credential->hasToken()) {
            throw FocusNfeException::notConfigured();
        }

        return FocusNfeClient::make($environment, (string) $credential->token, $correlationId);
    }

    /** Client com o token MASTER (só as consultas de município). */
    public function masterClient(NfseEnvironment $environment, ?string $correlationId = null): FocusNfeClient
    {
        $credential = $this->for($environment);

        if (! $credential->hasMasterToken()) {
            throw FocusNfeException::notConfigured();
        }

        return FocusNfeClient::make($environment, (string) $credential->master_token, $correlationId);
    }

    public function hasToken(NfseEnvironment $environment): bool
    {
        return $this->find($environment)?->hasToken() ?? false;
    }

    public function hasMasterToken(NfseEnvironment $environment): bool
    {
        return $this->find($environment)?->hasMasterToken() ?? false;
    }
}
