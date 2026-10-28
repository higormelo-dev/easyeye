<?php

declare(strict_types=1);

namespace App\Enums\Fiscal;

/**
 * Situação de uma NFS-e do SaaS. Os quatro valores da Focus NFe
 * (processando_autorizacao, autorizado, erro_autorizacao, cancelado) mantêm o
 * texto da API; pending_send e send_failed são nossos: a nota já tem linha e
 * `ref`, mas a Focus ainda não aceitou (ou não recebeu) o pedido de emissão.
 */
enum NfseStatus: string
{
    case PendingSend        = 'pending_send';
    case SendFailed         = 'send_failed';
    case Processing         = 'processando_autorizacao';
    case Authorized         = 'autorizado';
    case AuthorizationError = 'erro_autorizacao';
    case Cancelled          = 'cancelado';

    public function label(): string
    {
        return __("fiscal.status.{$this->value}");
    }

    /** Classe do badge no painel (padrão `badge-soft-*`). */
    public function badge(): string
    {
        return match ($this) {
            self::Authorized => 'badge-soft-success',
            self::PendingSend,
            self::Processing => 'badge-soft-info',
            self::SendFailed,
            self::AuthorizationError => 'badge-soft-danger',
            self::Cancelled          => 'badge-soft-secondary',
        };
    }

    /**
     * A nota "ocupa" a fatura: está a caminho ou valendo, então nenhuma outra
     * nota pode ser emitida para a mesma fatura. É a mesma lista do índice
     * único parcial `nfse_documents_invoice_occupying_unique`.
     */
    public function isOccupying(): bool
    {
        return in_array($this, [self::PendingSend, self::Processing, self::Authorized], true);
    }

    /** @return list<string> */
    public static function occupyingValues(): array
    {
        return array_values(array_map(
            static fn (self $status) => $status->value,
            array_filter(self::cases(), static fn (self $status) => $status->isOccupying()),
        ));
    }

    /**
     * Status que a Focus devolve na consulta/webhook (o resto é nosso). `negado` aparece na consulta do padrão nacional
     * (o ambiente nacional recusou a DPS) e, para nós, é o mesmo desfecho de `erro_autorizacao`. Qualquer outro valor,
     * inclusive os que a API passar a criar, devolve null: o chamador ignora em vez de inventar um estado.
     */
    public static function fromFocus(?string $status): ?self
    {
        return match ($status) {
            'processando_autorizacao'    => self::Processing,
            'autorizado'                 => self::Authorized,
            'erro_autorizacao', 'negado' => self::AuthorizationError,
            'cancelado'                  => self::Cancelled,
            default                      => null,
        };
    }
}
