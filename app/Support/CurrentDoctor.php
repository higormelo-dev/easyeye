<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ClientRule;
use App\Models\{Doctor, User};

/**
 * "Médico logado" — regra ÚNICA usada pela Agenda, Relatórios de agenda,
 * Dashboard e escala do médico (antes eram 3 cópias, duas lendo a chave de
 * sessão `user_rule` e uma `selected_entity_user_rule`).
 *
 *  - Perfil médico = `selected_entity_user_rule` da sessão (a mesma chave do
 *    resto do painel; login/troca de clínica gravam as duas juntas).
 *  - Cadastro de médico = Doctor do vínculo da sessão NESTA clínica.
 *  - Perfil médico SEM cadastro (`isMissing()`) não pode cair na visão da
 *    clínica inteira: quem chama devolve vazio / 403 com `missingMessage()`.
 */
final class CurrentDoctor
{
    public static function isDoctorProfile(): bool
    {
        return session('selected_entity_user_rule') === ClientRule::Doctor->value;
    }

    /**
     * Doctor do vínculo da sessão nesta clínica — null quando o perfil não é
     * médico ou quando não há cadastro de médico ligado ao vínculo. Traz
     * `user_name`/`user_id` (mesma forma das listas de médicos da Agenda).
     */
    public static function doctor(): ?Doctor
    {
        if (! self::isDoctorProfile()) {
            return null;
        }

        $entityUserId = session('selected_entity_user_id');
        $entityId     = session('selected_entity_id');

        if (! $entityUserId || ! $entityId) {
            return null;
        }

        return Doctor::query()
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->join('users', 'users.id', '=', 'entity_users.user_id')
            ->where('doctors.entity_user_id', $entityUserId)
            ->where('entity_users.entity_id', $entityId)
            ->select('doctors.*', 'users.name as user_name', 'users.id as user_id')
            ->first();
    }

    /**
     * Cadastro de médico ATIVO do usuário nesta clínica — quem pode emitir
     * documento clínico (Gate IssueReport) e assinar prontuário. Um vínculo
     * por clínica (entity_users), então usuário + clínica = o vínculo.
     */
    public static function activeFor(?User $user, ?string $entityId): ?Doctor
    {
        if ($user === null || blank($entityId)) {
            return null;
        }

        return Doctor::query()
            ->select('doctors.*')
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('entity_users.entity_id', $entityId)
            ->where('entity_users.user_id', $user->getKey())
            ->where('doctors.active', true)
            ->first();
    }

    /**
     * Médico que emite AGORA (usuário logado, clínica da sessão), ativo. Todo
     * documento/evolução/procedimento/prontuário sai no nome dele — nunca no
     * do prontuário ou do exame (seria assinar com o nome/CRM de um colega).
     */
    public static function issuer(): ?Doctor
    {
        $user = auth()->user();

        return self::activeFor($user instanceof User ? $user : null, (string) session('selected_entity_id'));
    }

    /** Perfil médico COM cadastro de médico ativo: pode emitir/registrar ato clínico. */
    public static function canIssue(): bool
    {
        return self::isDoctorProfile() && self::issuer() !== null;
    }

    /** Perfil médico SEM cadastro de médico ativo: as telas clínicas mostram o aviso. */
    public static function issuerMissing(): bool
    {
        return self::isDoctorProfile() && self::issuer() === null;
    }

    /** Perfil médico sem cadastro de médico nesta clínica. */
    public static function isMissing(): bool
    {
        return self::isDoctorProfile() && self::doctor() === null;
    }

    public static function missingMessage(): string
    {
        return __('schedules.doctor_missing');
    }

    /**
     * Pode ver/alterar um registro do médico `$doctorId` (agendamento,
     * compromisso, horários)? Fora do perfil médico, sempre (a tela é da
     * clínica). No perfil médico, só o PRÓPRIO cadastro — e nada sem cadastro.
     * `$allowClinicWide` libera registros sem médico (compromisso da clínica).
     */
    public static function canAccessDoctorId(?string $doctorId, bool $allowClinicWide = false): bool
    {
        if (! self::isDoctorProfile()) {
            return true;
        }

        $own = self::doctor();

        if ($own === null) {
            return false;
        }

        if ($doctorId === null || $doctorId === '') {
            return $allowClinicWide;
        }

        return (string) $own->id === (string) $doctorId;
    }
}
