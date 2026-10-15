<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

/**
 * Estado de uma VERSÃO de protocolo de retorno (recall_protocol_versions.state).
 *
 *   draft ──freeze──▶ in_review ──aceite──▶ validated ──release──▶ released ──▶ superseded
 *                         │                      │                     │  ▲
 *                         ├─▶ declined           └─▶ withdrawn         ▼  │ resume
 *                         └─▶ withdrawn                              suspended
 *
 * Qualquer estado (menos `draft`) pode ir a `retired`. `draft` é o único estado em
 * que o conteúdo muda; o trigger do banco recusa alterar conteúdo, hash, versão ou
 * protocolo de qualquer outro estado e recusa voltar a `draft` (ADR 0002, item 5).
 *
 * `superseded` continua VÁLIDA para a clínica que já a adotou (a versão nova não
 * troca a regra por baixo dela); correção clínica é feita SUSPENDENDO a antiga.
 */
enum ProtocolVersionState: string
{
    case Draft      = 'draft';
    case InReview   = 'in_review';
    case Validated  = 'validated';
    case Released   = 'released';
    case Superseded = 'superseded';
    case Suspended  = 'suspended';
    case Declined   = 'declined';
    case Withdrawn  = 'withdrawn';
    case Retired    = 'retired';

    /** @return array<string, array<int, self>> estado de origem → destinos permitidos */
    public static function transitions(): array
    {
        return [
            self::Draft->value      => [self::InReview],
            self::InReview->value   => [self::Validated, self::Declined, self::Withdrawn, self::Retired],
            self::Validated->value  => [self::Released, self::Withdrawn, self::Retired],
            self::Released->value   => [self::Superseded, self::Suspended, self::Retired],
            self::Superseded->value => [self::Suspended, self::Retired],
            // Suspensa volta a `released` ou `superseded` (a que estava antes, ver
            // suspended_from_state) ou é aposentada.
            self::Suspended->value => [self::Released, self::Superseded, self::Retired],
            self::Declined->value  => [self::Retired],
            self::Withdrawn->value => [self::Retired],
            self::Retired->value   => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, self::transitions()[$this->value], true);
    }

    /** O conteúdo está congelado (qualquer estado fora de `draft`). */
    public function isFrozen(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Uma clínica pode ADOTAR esta versão? Só a liberada — a anterior (superseded)
     * continua em uso por quem já a adotou, mas não é oferecida a quem ainda não.
     */
    public function isAdoptable(): bool
    {
        return $this === self::Released;
    }

    /**
     * O avaliador pode aplicar as regras desta versão a uma clínica que a adotou?
     * `suspended` e `retired` não: é o freio de mão da governança clínica.
     */
    public function isInUse(): bool
    {
        return in_array($this, [self::Released, self::Superseded], true);
    }

    /** A versão está parada aguardando alguém (revisor ou Manager)? */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::InReview], true);
    }

    public function label(): string
    {
        return __('recall_protocol.states.' . $this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
