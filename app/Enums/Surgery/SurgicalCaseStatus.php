<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Máquina de estados do caso cirúrgico (ADR 0003, §4).
 *
 *   draft → awaiting_authorization → authorized | partially_authorized | denied
 *   authorized | partially_authorized → scheduled → performed → discharged
 *   denied → awaiting_authorization (recurso) | cancelled
 *   scheduled → authorized | partially_authorized (libera a vaga)
 *   suspended: a partir de qualquer estado não final; volta ao estado de onde saiu
 *   cancelled / discharged: finais
 *
 * Particular / sem exigência de operadora passa por `waived` (evento
 * "dispensada" no log de autorização), que leva o caso a `authorized` — sem
 * atalho especial na máquina.
 */
enum SurgicalCaseStatus: string
{
    case Draft                 = 'draft';
    case AwaitingAuthorization = 'awaiting_authorization';
    case Authorized            = 'authorized';
    case PartiallyAuthorized   = 'partially_authorized';
    case Denied                = 'denied';
    case Scheduled             = 'scheduled';
    case Performed             = 'performed';
    case Discharged            = 'discharged';
    case Cancelled             = 'cancelled';
    case Suspended             = 'suspended';

    public function label(): string
    {
        return __('surgery_enums.surgical_case_status.' . $this->value);
    }

    /** Cor semântica do selo na interface (classe `bg-*` do Bootstrap). */
    public function tone(): string
    {
        return match ($this) {
            self::Draft                 => 'secondary',
            self::AwaitingAuthorization => 'warning',
            self::Authorized, self::PartiallyAuthorized => 'info',
            self::Denied, self::Cancelled => 'danger',
            self::Scheduled => 'primary',
            self::Performed, self::Discharged => 'success',
            self::Suspended => 'dark',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Discharged, self::Cancelled], true);
    }

    /** Caso ainda ocupa a fila de trabalho (não é final nem negado/suspenso). */
    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * O cadastro do caso ainda aceita ALGUMA alteração? Só o encerrado e o já realizado não: nos demais
     * estados ao menos a equipe, os exames de apoio e a parte clínica seguem editáveis, mesmo que o pedido
     * (procedimentos/OPME) esteja travado — `allowsRequestChanges()` cuida desse grupo.
     */
    public function allowsAnyChange(): bool
    {
        return ! $this->isFinal() && ! $this->wasPerformed();
    }

    /** Conteúdo do pedido (procedimentos/OPME) pode mudar? Depois de autorizado, só com novo pedido. */
    public function allowsRequestChanges(): bool
    {
        return in_array($this, [self::Draft, self::Denied], true);
    }

    /** Já houve a cirurgia (ato consumado). */
    public function wasPerformed(): bool
    {
        return in_array($this, [self::Performed, self::Discharged], true);
    }

    /**
     * Destinos permitidos a partir deste estado (exceto "voltar da suspensão",
     * que depende do estado de origem gravado no caso — ver SurgicalCaseService).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft                 => [self::AwaitingAuthorization, self::Authorized, self::Cancelled, self::Suspended],
            self::AwaitingAuthorization => [self::Authorized, self::PartiallyAuthorized, self::Denied, self::Draft, self::Cancelled, self::Suspended],
            self::Authorized            => [self::Scheduled, self::AwaitingAuthorization, self::Cancelled, self::Suspended],
            self::PartiallyAuthorized   => [self::Scheduled, self::AwaitingAuthorization, self::Cancelled, self::Suspended],
            self::Denied                => [self::AwaitingAuthorization, self::Authorized, self::Cancelled, self::Suspended], // Authorized só por "dispensada" (virou particular)
            self::Scheduled             => [self::Performed, self::Authorized, self::PartiallyAuthorized, self::Cancelled, self::Suspended],
            self::Performed             => [self::Discharged],
            self::Suspended             => [self::Cancelled], // a volta usa status_before_suspension (SurgicalCaseService::resume)
            self::Discharged, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> estados em que o caso ainda NÃO foi realizado e segue na fila. */
    public static function preOperativeValues(): array
    {
        return [
            self::Draft->value,
            self::AwaitingAuthorization->value,
            self::Authorized->value,
            self::PartiallyAuthorized->value,
            self::Denied->value,
            self::Scheduled->value,
            self::Suspended->value,
        ];
    }
}
