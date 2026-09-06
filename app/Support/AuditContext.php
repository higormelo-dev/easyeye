<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\{PatientAccount, User};
use Illuminate\Support\Facades\Auth;

/**
 * Contexto global de auditoria.
 *
 * Permite forçar um user_id fixo em contextos onde auth()->id() é null,
 * como seeders e comandos Artisan.
 *
 * Uso em seeders:
 *   AuditContext::setUserId($higor->id);
 *
 * Em contexto web não precisa chamar nada — o fallback é auth()->id().
 */
class AuditContext
{
    private static ?string $userId = null;

    private static ?string $patientAccountId = null;

    public static function setUserId(?string $userId): void
    {
        self::$userId = $userId;
    }

    public static function userId(): ?string
    {
        if (self::$userId !== null) {
            return self::$userId;
        }

        $user = auth()->user();

        return ($user instanceof User) ? $user->getKey() : null;
    }

    public static function setPatientAccountId(?string $patientAccountId): void
    {
        self::$patientAccountId = $patientAccountId;
    }

    /**
     * Ator do guard "patient" (Portal do Paciente) — coluna SEPARADA de
     * user_id (nunca reaproveitada): paciente e staff nunca se misturam.
     * Achado bloqueante da Fase 2 do plano "Portal do Paciente": sem isto,
     * toda leitura do PRÓPRIO paciente no portal gravava user_id = null em
     * audit_logs/data_access_logs, furando a trilha CFM/LGPD.
     */
    public static function patientAccountId(): ?string
    {
        if (self::$patientAccountId !== null) {
            return self::$patientAccountId;
        }

        $account = Auth::guard('patient')->user();

        return ($account instanceof PatientAccount) ? $account->getKey() : null;
    }
}
