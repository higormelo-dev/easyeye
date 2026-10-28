<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EntityUserIntegrator;
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
    public static function integratorActor(): array
    {
        $user       = request()->user();
        $integrator = request()->attributes->get('integrator');

        if (! $user instanceof EntityUserIntegrator || ! $integrator || $integrator->entity_user_integrator_id !== $user->id) {
            return [];
        }
        $token        = $user->currentAccessToken();
        $installation = null;

        foreach ((array) ($token?->abilities ?? []) as $ability) {
            if (str_starts_with($ability, 'installation_id:')) {
                $installation = substr($ability, 16);
            }
        }

        return ['entity_id' => $integrator->user->entity_id, 'actor_type' => 'integrator', 'entity_user_integrator_id' => $user->id, 'integrator_id' => $integrator->id, 'token_id' => $token?->id, 'installation_id' => $installation];
    }

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

    /**
     * Coluna do ator paciente pra INSERT em audit_logs/data_access_logs — só
     * quando há paciente logado (o null já é o default da coluna).
     *
     * A coluna nasceu em 2026_09_06; migrations de dados mais antigas que
     * gravam por model auditado (ex.: 2026_08_31 refresh_ai_model_price_catalog
     * → AiModelPriceSeeder) rodam ANTES dela num `migrate` que aplica as duas
     * no mesmo lote (banco novo, ambiente atrasado). Mandar a chave com null
     * quebrava o INSERT ("column patient_account_id does not exist") e a
     * trilha daqueles registros se perdia (Sentry EASY-EYE-TESTING-12).
     *
     * @return array{patient_account_id?: string}
     */
    public static function patientAccountActor(): array
    {
        $patientAccountId = self::patientAccountId();

        return $patientAccountId !== null ? ['patient_account_id' => $patientAccountId] : [];
    }
}
