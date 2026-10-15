<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Enums\ConsentType;
use App\Services\WhatsApp\WhatsAppService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * O que a tela "Retornos" mostra do convite por WhatsApp: se o canal da clínica está aberto, e — por linha — se o
 * paciente autorizou lembretes, se respondeu SAIR e o que aconteceu com o último convite. SÓ leitura, em lote (uma
 * consulta por assunto para a página inteira, sem N+1). A decisão de enviar não vem daqui: é do RecallInviteGuard,
 * que a prévia e o envio consultam de novo.
 */
final class RecallInviteOverview
{
    public function __construct(private readonly RecallInviteGuard $guard)
    {
    }

    /**
     * O canal da clínica: aberto (os três níveis de chave ligados, número que envia, assinatura em dia) ou o motivo.
     *
     * @return array{available: bool, blocker: ?string, label: ?string}
     */
    public function channel(string $entityId): array
    {
        $blocker = $this->guard->channelBlocker($entityId);

        return ['available' => $blocker === null, 'blocker' => $blocker?->value, 'label' => $blocker?->label()];
    }

    /**
     * Acrescenta `invite` a cada linha apresentada pela lista.
     *
     * @param list<array<string, mixed>> $rows linhas de RecallListService::present()
     * @param bool                       $open o canal da clínica está aberto
     *
     * @return list<array<string, mixed>>
     */
    public function decorate(array $rows, string $entityId, bool $open): array
    {
        if ($rows === []) {
            return $rows;
        }

        $patientIds = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['patient']['id'], $rows)));
        $recallIds  = array_map(static fn (array $row): string => (string) $row['id'], $rows);

        $consents           = $this->consentedPatients($entityId, $patientIds);
        $optedOut           = $this->optedOutPatients($entityId, $patientIds);
        [$last, $requested] = $this->lastInvites($entityId, $recallIds);

        return array_map(function (array $row) use ($open, $consents, $optedOut, $last, $requested): array {
            $patientId = (string) $row['patient']['id'];
            $consent   = isset($consents[$patientId]);
            $opted     = isset($optedOut[$patientId]);
            $blocked   = ($row['blocks'] ?? []) !== [];

            $row['invite'] = [
                'consent'   => $consent,
                'opted_out' => $opted,
                // Vale a pena oferecer o botão: o canal está aberto e o paciente pode, em princípio, receber. A prévia
                // refaz a decisão completa (ritmo, regra em uso, telefone…) antes de a pessoa confirmar.
                'can_try' => $open && $consent && ! $opted && ! $blocked && (bool) ($row['patient']['whatsapp'] ?? false),
                'last'    => $last[(string) $row['id']] ?? null,
                // O paciente tocou "Quero agendar" no prazo de validade do botão: a lista o põe no topo.
                'requested' => isset($requested[(string) $row['id']]),
            ];

            return $row;
        }, $rows);
    }

    /**
     * @param list<string> $patientIds
     *
     * @return array<string, true>
     */
    private function consentedPatients(string $entityId, array $patientIds): array
    {
        return DB::table('patient_consents')
            ->where('entity_id', $entityId)
            ->whereIn('patient_id', $patientIds)
            ->where('consent_type', ConsentType::CareReminders->value)
            ->where('status', 'granted')
            ->whereNull('revoked_at')
            ->pluck('patient_id')
            ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
            ->all();
    }

    /**
     * Pacientes cujo celular respondeu SAIR ao número pelo qual a clínica envia.
     *
     * @param list<string> $patientIds
     *
     * @return array<string, true>
     */
    private function optedOutPatients(string $entityId, array $patientIds): array
    {
        $phones = DB::table('patients as p')
            ->join('people as pe', 'pe.id', '=', 'p.person_id')
            ->where('p.entity_id', $entityId)
            ->whereIn('p.id', $patientIds)
            ->pluck('pe.cellphone', 'p.id');

        $out = WhatsAppService::optedOutPhones($entityId, $phones->values()->all());

        $result = [];

        foreach ($phones as $patientId => $cellphone) {
            $normalized = WhatsAppService::normalizePhone($cellphone);

            if ($normalized !== null && isset($out[$normalized])) {
                $result[(string) $patientId] = true;
            }
        }

        return $result;
    }

    /**
     * O último convite que cobriu cada retorno e o que aconteceu com ele, e quais retornos o paciente pediu para agendar.
     *
     * @param list<string> $recallIds
     *
     * @return array{0: array<string, array{at: string, origin: string, status: string, response: ?string, responded_at: ?string}>, 1: array<string, true>}
     */
    private function lastInvites(string $entityId, array $recallIds): array
    {
        $rows = DB::table('recall_invite_items as it')
            ->join('recall_invites as ri', 'ri.id', '=', 'it.recall_invite_id')
            ->leftJoin('whatsapp_messages as m', static function ($join): void {
                $join->on('m.recall_invite_id', '=', 'ri.id')->where('m.direction', '=', 'out');
            })
            ->where('ri.entity_id', $entityId)
            ->whereIn('it.patient_recall_id', $recallIds)
            ->orderByDesc('ri.created_at')
            ->get(['it.patient_recall_id', 'ri.created_at', 'ri.origin', 'ri.response', 'ri.responded_at', 'm.status', 'm.delivered_at', 'm.read_at']);

        $last      = [];
        $requested = [];
        $since     = CarbonImmutable::now()->subDays((int) config('recall.invites.reply_valid_days', 30));

        foreach ($rows as $row) {
            $key = (string) $row->patient_recall_id;

            if ($row->response === 'wants_to_schedule' && $row->responded_at !== null && CarbonImmutable::parse($row->responded_at)->greaterThanOrEqualTo($since)) {
                $requested[$key] = true;
            }

            if (isset($last[$key])) {
                continue;
            }

            $last[$key] = [
                'at'           => CarbonImmutable::parse($row->created_at)->toIso8601String(),
                'origin'       => (string) $row->origin,
                'status'       => self::deliveryStatus($row->status, $row->delivered_at, $row->read_at),
                'response'     => $row->response !== null ? (string) $row->response : null,
                'responded_at' => $row->responded_at !== null ? CarbonImmutable::parse($row->responded_at)->toIso8601String() : null,
            ];
        }

        return [$last, $requested];
    }

    /** queued · sent · delivered · read · failed · cancelled — do status da mensagem e dos carimbos de entrega. */
    public static function deliveryStatus(mixed $status, mixed $deliveredAt, mixed $readAt): string
    {
        return match ((string) $status) {
            'sent', 'answered' => $readAt !== null ? 'read' : ($deliveredAt !== null ? 'delivered' : 'sent'),
            'failed' => 'failed',
            'skipped', 'suppressed' => 'cancelled',
            default => 'queued',
        };
    }
}
