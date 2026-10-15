<?php

declare(strict_types=1);

namespace App\Services\Surgery\Guards;

use App\Enums\Surgery\AuthorizationStatus;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Doctor;
use App\Models\Surgery\SurgicalCase;
use App\Support\Surgery\LocalTime;
use Carbon\Carbon;

/**
 * A autorização do caso ainda vale na data da cirurgia? Autorizada, parcial ou
 * dispensada com validade em dia passa. Vencida (status ou validade) só passa
 * com a justificativa do cirurgião (`authorization_override_reason`, mínimo de
 * 10 caracteres), que o serviço de "realizada" grava no registro da cirurgia.
 * Pedido sem autorização (não pedida, pendente, negada) nunca passa: o caso
 * nem deveria estar agendado.
 *
 * Executada SEMPRE pelo SurgeryPerformService (não depende de config) e
 * devolve se a exceção foi usada, para a justificativa ser registrada.
 */
final class AuthorizationStillValidGuard implements SurgeryPerformGuard
{
    public const MIN_REASON = 10;

    /** @var bool a última chamada precisou da justificativa */
    private bool $overrideUsed = false;

    public function overrideUsed(): bool
    {
        return $this->overrideUsed;
    }

    /**
     * Situação da autorização na data da cirurgia: `valid`, `expired` (precisa
     * de justificativa) ou `invalid` (nunca passa: pedido sem autorização).
     */
    public static function evaluate(SurgicalCase $case, ?Carbon $on = null): string
    {
        $status = $case->authorization_status;
        $on ??= now();

        if (! in_array($status, [AuthorizationStatus::Authorized, AuthorizationStatus::PartiallyAuthorized, AuthorizationStatus::Waived, AuthorizationStatus::Expired], true)) {
            return 'invalid';
        }

        $expired = $status === AuthorizationStatus::Expired
            || ($case->authorization_valid_until !== null && $case->authorization_valid_until->toDateString() < $on->toDateString());

        return $expired ? 'expired' : 'valid';
    }

    public function assert(SurgicalCase $locked, array $input, Doctor $performer): void
    {
        $this->overrideUsed = false;

        $on    = LocalTime::parse(filled($input['performed_at'] ?? null) ? (string) $input['performed_at'] : null) ?? now();
        $state = self::evaluate($locked, $on);

        if ($state === 'invalid') {
            throw SurgeryRuleException::state('surgery_operation.errors.authorization_not_valid', ['status' => $locked->authorization_status->label()]);
        }

        if ($state === 'valid') {
            return;
        }

        $reason = trim((string) ($input['authorization_override_reason'] ?? ''));

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw SurgeryRuleException::state('surgery_operation.errors.authorization_expired', ['min' => self::MIN_REASON]);
        }

        $this->overrideUsed = true;
    }
}
