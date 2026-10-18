<?php

declare(strict_types=1);

namespace App\Enums\Fiscal;

/**
 * Ambiente da Focus NFe. Homologação e produção têm hosts e tokens diferentes;
 * nota emitida em homologação não tem validade fiscal.
 */
enum NfseEnvironment: string
{
    case Homologation = 'homologacao';
    case Production   = 'producao';

    public function label(): string
    {
        return __("fiscal.environment.{$this->value}");
    }

    public function baseUrl(): string
    {
        return (string) config("fiscal.base_urls.{$this->value}");
    }
}
