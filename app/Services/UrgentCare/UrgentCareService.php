<?php

declare(strict_types=1);

namespace App\Services\UrgentCare;

use App\Enums\{ScheduleAttendanceType, ScheduleSituation, UrgentCarePriority, UrgentCareReason, UrgentCareStatus};
use App\Exceptions\UrgentCareUnavailableException;
use App\Models\{Doctor, Patient, PatientCall, Schedule, UrgentCareEntry, VisitType};
use App\Services\ScheduleService;
use App\Support\BrazilianFormat;
use Carbon\{Carbon, CarbonInterface};
use Illuminate\Database\Eloquent\{Builder, Collection};
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Pronto atendimento (Fase 1 / B1): fila de demanda espontânea do dia por
 * clínica — quem chega sem hora marcada.
 *
 *  - Fila = aguardando + chamado com chegada HOJE, por prioridade (imediata,
 *    prioritária por lei, normal) e depois por chegada.
 *  - Escopo de médico (App\Support\CurrentDoctor no controller): o médico vê
 *    os itens dele + os sem médico preferido.
 *  - "Atender": cria o agendamento de urgência no horário atual com origem
 *    `urgent_care` (fora da grade e FORA do teto de encaixes — nunca passa
 *    por validateFitIn/assertFitInCapacity) já "Em consulta", e o controller
 *    abre o prontuário normal do agendamento.
 *  - Toda mudança de andamento trava a linha (dois "Atender" simultâneos não
 *    criam dois atendimentos).
 */
class UrgentCareService
{
    public function __construct(private readonly ScheduleService $schedules)
    {
    }

    /**
     * Itens do pronto atendimento visíveis: clínica + (médico: dele ou sem
     * médico preferido).
     *
     * @return Builder<UrgentCareEntry>
     */
    public function visible(string $entityId, ?string $doctorId): Builder
    {
        return UrgentCareEntry::query()
            ->where('urgent_care_entries.entity_id', $entityId)
            ->when($doctorId, fn (Builder $q) => $q->where(fn (Builder $own) => $own
                ->where('urgent_care_entries.preferred_doctor_id', $doctorId)
                ->orWhereNull('urgent_care_entries.preferred_doctor_id')));
    }

    /** Médico (perfil médico) pode ver/mexer neste item? null = perfil da clínica (tudo). */
    public function isVisibleTo(UrgentCareEntry $entry, ?string $doctorId): bool
    {
        return $doctorId === null
            || $entry->preferred_doctor_id === null
            || (string) $entry->preferred_doctor_id === $doctorId;
    }

    /**
     * Fila do dia (aguardando + chamado), na ordem de atendimento.
     *
     * @return Collection<int, UrgentCareEntry>
     */
    public function queue(string $entityId, ?string $doctorId, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();

        return $this->visible($entityId, $doctorId)
            ->with(['patient.person', 'preferredDoctor.entityUser.user', 'covenant'])
            ->whereIn('status', UrgentCareStatus::queuedValues())
            ->where('arrived_at', '>=', $now->copy()->startOfDay())
            ->orderBy('priority')
            ->orderBy('arrived_at')
            ->get();
    }

    /**
     * Encerrados hoje (em atendimento, desistiu, removido) — o mais recente
     * primeiro. Médico: os dele, os sem médico ainda não atendidos por colega
     * e os que ele mesmo atendeu.
     *
     * @return Collection<int, UrgentCareEntry>
     */
    public function closedToday(string $entityId, ?string $doctorId, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();

        return UrgentCareEntry::query()
            ->with(['patient.person', 'preferredDoctor.entityUser.user', 'attendedByDoctor.entityUser.user', 'covenant'])
            ->where('entity_id', $entityId)
            ->whereNotIn('status', UrgentCareStatus::queuedValues())
            ->where('arrived_at', '>=', $now->copy()->startOfDay())
            ->when($doctorId, fn (Builder $q) => $q->where(fn (Builder $own) => $own
                ->where('preferred_doctor_id', $doctorId)
                ->orWhere('attended_by_doctor_id', $doctorId)
                ->orWhere(fn (Builder $free) => $free->whereNull('preferred_doctor_id')->whereNull('attended_by_doctor_id'))))
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();
    }

    /**
     * Pessoas na fila agora — o número que a Agenda e o Dashboard mostram
     * (mesma regra de visibilidade da tela).
     */
    public function queuedCount(string $entityId, ?string $doctorId, ?CarbonInterface $now = null): int
    {
        $now ??= now();

        return $this->visible($entityId, $doctorId)
            ->whereIn('status', UrgentCareStatus::queuedValues())
            ->where('arrived_at', '>=', $now->copy()->startOfDay())
            ->count();
    }

    /** O paciente já está na fila de hoje desta clínica? */
    public function isQueued(string $entityId, string $patientId, ?string $ignoreId = null): bool
    {
        return UrgentCareEntry::query()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->whereIn('status', UrgentCareStatus::queuedValues())
            ->where('arrived_at', '>=', now()->startOfDay())
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /** @param array<string, mixed> $data validado por UrgentCareEntryRequest */
    public function add(string $entityId, array $data): UrgentCareEntry
    {
        return DB::transaction(function () use ($entityId, $data): UrgentCareEntry {
            // Mesmo paciente duas vezes na fila (duplo clique, duas recepções):
            // trava o paciente e confere de novo dentro da transação.
            Patient::query()->whereKey($data['patient_id'])->lockForUpdate()->first();

            if ($this->isQueued($entityId, (string) $data['patient_id'])) {
                throw new UrgentCareUnavailableException(__('urgent_care.already_queued'));
            }

            return UrgentCareEntry::query()->create([
                'entity_id'           => $entityId,
                'patient_id'          => $data['patient_id'],
                'covenant_id'         => $data['covenant_id'] ?? null,
                'preferred_doctor_id' => $data['preferred_doctor_id'] ?? null,
                'reason'              => (int) $data['reason'],
                'reason_details'      => $data['reason_details'] ?? null,
                'priority'            => (int) $data['priority'],
                'status'              => UrgentCareStatus::Waiting->value,
                'arrived_at'          => now(),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(UrgentCareEntry $entry, array $data): UrgentCareEntry
    {
        return $this->whileQueued($entry, function (UrgentCareEntry $locked) use ($data): UrgentCareEntry {
            $locked->update([
                'covenant_id'         => $data['covenant_id'] ?? null,
                'preferred_doctor_id' => $data['preferred_doctor_id'] ?? null,
                'reason'              => (int) $data['reason'],
                'reason_details'      => $data['reason_details'] ?? null,
                'priority'            => (int) $data['priority'],
            ]);

            return $locked;
        });
    }

    /**
     * "Chamar": vai para o painel de chamadas da sala de espera (snapshot do
     * nome, como o "Chamar paciente" da Agenda) e marca o item como chamado.
     */
    public function call(UrgentCareEntry $entry, ?string $doctorName, ?string $entityUserId): UrgentCareEntry
    {
        return $this->whileQueued($entry, function (UrgentCareEntry $locked) use ($doctorName, $entityUserId): UrgentCareEntry {
            $locked->loadMissing('patient.person');

            PatientCall::query()->create([
                'entity_id'                => $locked->entity_id,
                'schedule_id'              => null,
                'patient_name'             => $locked->patient?->person?->full_name ?? '—',
                'doctor_name'              => $doctorName,
                'called_by_entity_user_id' => $entityUserId,
                'created_at'               => now(),
            ]);

            $locked->update([
                'status'     => UrgentCareStatus::Called->value,
                'called_at'  => now(),
                'call_count' => $locked->call_count + 1,
            ]);

            return $locked;
        });
    }

    /**
     * "Atender": agendamento de urgência AGORA para o médico que atende
     * (origem pronto atendimento, chegada = chegada na fila), já "Em
     * consulta" (histórico Aguardando → Em consulta = início do cronômetro do
     * prontuário). Não é encaixe e não passa pelas regras de grade.
     */
    public function attend(UrgentCareEntry $entry, Doctor $doctor, ?string $entityUserId): Schedule
    {
        return $this->whileQueued($entry, function (UrgentCareEntry $locked) use ($doctor, $entityUserId): Schedule {
            $patient = Patient::query()->with('person')->findOrFail($locked->patient_id);
            $person  = $patient->person;

            $schedule = Schedule::query()->create([
                'entity_id'       => $locked->entity_id,
                'doctor_id'       => $doctor->id,
                'patient_id'      => $patient->id,
                'covenant_id'     => $locked->covenant_id ?? $patient->covenant_id,
                'visit_id'        => $this->urgencyVisitTypeId((string) $locked->entity_id),
                'attendance_type' => ScheduleAttendanceType::Urgency->value,
                'full_name'       => $person?->full_name ?? $patient->code,
                'telephone'       => $person?->telephone,
                'cellphone'       => $person?->cellphone,
                'date_time'       => now()->startOfMinute(),
                'situation'       => ScheduleSituation::Waiting->value,
                'arrived_at'      => $locked->arrived_at,
                'notes'           => $this->scheduleNotes($locked),
                'is_fit_in'       => false,
                'origin'          => Schedule::ORIGIN_URGENT_CARE,
                'active'          => true,
            ]);

            $this->schedules->changeSituation($schedule, ScheduleSituation::InProgress, $entityUserId);

            $locked->update([
                'status'                => UrgentCareStatus::InCare->value,
                'attended_at'           => now(),
                'schedule_id'           => $schedule->id,
                'attended_by_doctor_id' => $doctor->id,
            ]);

            Cache::forget("waiting_room:{$locked->entity_id}");

            return $schedule;
        });
    }

    /** Desistiu / removido da fila, com o motivo. */
    public function close(UrgentCareEntry $entry, UrgentCareStatus $status, string $reason): UrgentCareEntry
    {
        return $this->whileQueued($entry, function (UrgentCareEntry $locked) use ($status, $reason): UrgentCareEntry {
            $locked->update([
                'status'        => $status->value,
                'closed_at'     => now(),
                'closed_reason' => $reason,
            ]);

            return $locked;
        });
    }

    /**
     * Linha da fila para a tela (sem nada além do que a recepção/médico usa).
     *
     * @return array<string, mixed>
     */
    public function toRow(UrgentCareEntry $entry, int $position = 0): array
    {
        $person = $entry->patient?->person;
        $birth  = $person?->birth_date;
        $locale = app()->getLocale();

        return [
            'id'                  => (string) $entry->id,
            'position'            => $position,
            'patient_id'          => (string) $entry->patient_id,
            'patient_name'        => $person?->full_name ?? '—',
            'patient_code'        => $entry->patient?->code,
            'age'                 => $birth ? Carbon::parse($birth)->age : null,
            'reason'              => $entry->reason->value,
            'reason_label'        => $entry->reason->label(),
            'reason_details'      => $entry->reason_details,
            'priority'            => $entry->priority->value,
            'priority_key'        => $entry->priority->key(),
            'priority_label'      => $entry->priority->label(),
            'status'              => $entry->status->value,
            'status_key'          => $entry->status->key(),
            'status_label'        => $entry->status->label(),
            'preferred_doctor_id' => $entry->preferred_doctor_id ? (string) $entry->preferred_doctor_id : null,
            'preferred_doctor'    => $entry->preferredDoctor?->entityUser?->user?->name,
            'attended_by'         => $entry->relationLoaded('attendedByDoctor') ? $entry->attendedByDoctor?->entityUser?->user?->name : null,
            'attended_by_id'      => $entry->attended_by_doctor_id ? (string) $entry->attended_by_doctor_id : null,
            'covenant_id'         => $entry->covenant_id ? (string) $entry->covenant_id : null,
            'covenant'            => $entry->covenant?->name,
            'arrived_at'          => $entry->arrived_at?->toIso8601String(),
            'arrived_time'        => $entry->arrived_at?->locale($locale)->isoFormat('LT'),
            'called_at'           => $entry->called_at?->toIso8601String(),
            'called_time'         => $entry->called_at?->locale($locale)->isoFormat('LT'),
            'call_count'          => $entry->call_count,
            'attended_time'       => $entry->attended_at?->locale($locale)->isoFormat('LT'),
            'closed_time'         => $entry->closed_at?->locale($locale)->isoFormat('LT'),
            'closed_reason'       => $entry->closed_reason,
            'schedule_id'         => $entry->schedule_id ? (string) $entry->schedule_id : null,
            'phone'               => null,
        ];
    }

    /** Telefone formatado (só para quem tem acesso a Pacientes — decidido no controller). */
    public static function phoneOf(UrgentCareEntry $entry): ?string
    {
        $person = $entry->patient?->person;

        return BrazilianFormat::phone($person?->cellphone ?: $person?->telephone);
    }

    /**
     * Roda `$change` com a linha travada (FOR UPDATE) e só se o item ainda
     * estiver na fila — outra recepção/médico pode tê-lo atendido/removido.
     *
     * @template T
     *
     * @param callable(UrgentCareEntry): T $change
     *
     * @return T
     */
    private function whileQueued(UrgentCareEntry $entry, callable $change): mixed
    {
        return DB::transaction(function () use ($entry, $change) {
            $locked = UrgentCareEntry::query()->whereKey($entry->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->status->isQueued()) {
                throw new UrgentCareUnavailableException(__('urgent_care.no_longer_queued', [
                    'status' => $locked?->status->label() ?? '—',
                ]));
            }

            return $change($locked);
        });
    }

    /** "URGÊNCIA" do catálogo de tipos de consulta (da clínica antes do global). */
    private function urgencyVisitTypeId(string $entityId): ?string
    {
        $name = ScheduleAttendanceType::Urgency->visitTypeName();

        $id = VisitType::query()
            ->withoutGlobalScopes()
            ->where(fn (Builder $q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->whereNull('deleted_at')
            ->whereRaw('upper(unaccent(btrim(name))) = upper(unaccent(?))', [$name])
            ->orderByRaw('entity_id IS NULL')
            ->value('id');

        return $id ? (string) $id : null;
    }

    private function scheduleNotes(UrgentCareEntry $entry): string
    {
        $reason = $entry->reason instanceof UrgentCareReason ? $entry->reason->label() : '';
        $notes  = __('urgent_care.schedule_note', ['reason' => $reason]);

        if (filled($entry->reason_details)) {
            $notes .= ' — ' . $entry->reason_details;
        }

        if ($entry->priority === UrgentCarePriority::Immediate) {
            $notes .= ' (' . UrgentCarePriority::Immediate->label() . ')';
        }

        return $notes;
    }
}
