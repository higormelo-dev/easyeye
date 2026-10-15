<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Models\SubscriptionSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * A PAUSA GLOBAL dos convites de retorno por WhatsApp — o 1º dos três níveis de chave (ADR 0002, item 9): o dono do
 * SaaS desliga o canal para TODAS as clínicas na hora, com motivo registrado (número de WhatsApp queimado, template
 * reprovado, denúncia de spam…). Os outros dois são a chave da clínica no Manager (`whatsapp_settings.recall_enabled`)
 * e a chave da clínica nas configurações do recall (`recall_clinic_settings.whatsapp_invites_enabled`).
 *
 * Mora em `system_settings` e é lida SEM cache: a pausa precisa valer no próximo envio, em qualquer servidor.
 * O envio já enfileirado revalida a pausa (RecallInviteGuard::stillEligible) e é cancelado.
 */
final class RecallKillSwitch
{
    public const KEY = 'recall.invites_paused';

    /** @return array{reason: string, by: ?string, by_name: ?string, at: string}|null nulo = canal liberado */
    public function state(): ?array
    {
        $row = SubscriptionSetting::query()->whereKey(self::KEY)->first();

        if ($row === null) {
            return null;
        }

        $data = json_decode((string) $row->value, true);

        return is_array($data) ? [
            'reason'  => (string) ($data['reason'] ?? ''),
            'by'      => isset($data['by']) ? (string) $data['by'] : null,
            'by_name' => isset($data['by_name']) ? (string) $data['by_name'] : null,
            'at'      => (string) ($data['at'] ?? ''),
        ] : ['reason' => '', 'by' => null, 'by_name' => null, 'at' => ''];
    }

    public function isPaused(): bool
    {
        return SubscriptionSetting::query()->whereKey(self::KEY)->exists();
    }

    public function pause(string $reason, ?string $userId, ?string $userName, ?CarbonImmutable $at = null): void
    {
        SubscriptionSetting::query()->updateOrCreate(['key' => self::KEY], [
            'value' => (string) json_encode([
                'reason'  => $reason,
                'by'      => $userId,
                'by_name' => $userName,
                'at'      => ($at ?? CarbonImmutable::now())->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE),
            'type'        => 'json',
            'group'       => 'recall',
            'label'       => 'Pausa global dos convites de retorno por WhatsApp',
            'description' => 'Presente = convites de retorno pausados para todas as clínicas (kill-switch do Manager).',
            'is_public'   => false,
        ]);
    }

    public function resume(): void
    {
        SubscriptionSetting::query()->whereKey(self::KEY)->delete();
    }

    /** UUID estável para a trilha de auditoria (`audit_logs.auditable_id` é uuid). */
    public static function auditId(): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_OID, self::KEY)->toString();
    }

    /**
     * O que a pausa (ou a retomada) atinge: as clínicas com o canal ligado nos dois níveis da clínica e os retornos
     * que hoje já poderiam receber convite — para a confirmação mostrar "N clínicas e M retornos afetados".
     *
     * @return array{clinics: int, recalls: int}
     */
    public function impact(?CarbonImmutable $today = null): array
    {
        $day = ($today ?? CarbonImmutable::now())->toDateString();

        $clinics = DB::table('whatsapp_settings as ws')
            ->join('recall_clinic_settings as rs', 'rs.entity_id', '=', 'ws.entity_id')
            ->where('ws.recall_enabled', true)
            ->where('ws.active', true)
            ->where('rs.whatsapp_invites_enabled', true)
            ->pluck('ws.entity_id')
            ->all();

        if ($clinics === []) {
            return ['clinics' => 0, 'recalls' => 0];
        }

        $recalls = DB::table('patient_recalls')
            ->whereIn('entity_id', $clinics)
            ->where('lifecycle', 'open')
            ->where('rule_suspended', false)
            ->whereNull('scheduled_schedule_id')
            ->where('invite_from', '<=', $day)
            ->where('lost_from', '>', $day)
            ->count();

        return ['clinics' => count($clinics), 'recalls' => $recalls];
    }
}
