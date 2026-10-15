<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\ScheduleSituation;
use App\Enums\Surgery\{AuthorizationStatus, SurgicalCaseStatus};
use App\Exceptions\Surgery\{SurgeryLockSetChanged, SurgeryRuleException};
use App\Models\{ClinicResource, Doctor, ResourceBlock, ResourceWorkSchedule, Schedule, ScheduleBlock};
use App\Models\Surgery\{SurgeryFacility, SurgeryRoom, SurgicalCase, SurgicalTeamMember};
use App\Services\Schedules\ScheduleAvailabilityService;
use App\Support\CurrentDoctor;
use Carbon\{Carbon, CarbonInterface};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agenda cirúrgica (ADR 0003, D3): agendar, reagendar e liberar a vaga.
 *
 * A janela é o INTERVALO [início, fim) — sobreposição estrita; fim = início do
 * seguinte NÃO conflita. Conflito de sala, cirurgião (e médicos da equipe) e
 * paciente contra outros casos agendados/realizados, mais a escala e os
 * bloqueios da sala própria (conferidos pelo intervalo inteiro) e as consultas
 * ativas do cirurgião.
 *
 * Concorrência: tudo acontece numa transação que toma, ANTES da linha do caso,
 * um `pg_advisory_xact_lock` por recurso (sala, cada médico, paciente) em ordem
 * numérica crescente da chave — duas secretárias disputando a mesma sala/horário
 * (ou X→Y × Y→X) serializam sem deadlock e a segunda enxerga o commit da
 * primeira. Como a equipe pode mudar entre a leitura e a trava do caso, o
 * conjunto de chaves é recalculado sob a trava da linha; se cresceu, a tentativa
 * é desfeita (SAVEPOINT solta os locks) e refeita com o conjunto certo.
 *
 * A Agenda de consultas toma FOR SHARE na linha do médico (`lockDoctorWindows`)
 * antes de gravar um horário dele; esta classe toma FOR NO KEY UPDATE nas linhas
 * dos médicos da cirurgia: consulta e cirurgia no mesmo horário não passam as
 * duas. Esta classe nunca espera por nada que a Agenda segure (só lê `schedules`,
 * sem trava), então não há ciclo.
 */
class SurgeryScheduleService
{
    private const LOCK_ATTEMPTS = 3;

    public function __construct(private readonly SurgicalCaseService $cases)
    {
    }

    // ── Operações ───────────────────────────────────────────────────────────

    /**
     * Agenda um caso autorizado.
     *
     * @param array{starts_at: string, duration_minutes?: int|null, surgery_facility_id: string, surgery_room_id?: string|null} $data
     */
    public function schedule(SurgicalCase $case, array $data, ?int $expectedRevision = null): SurgicalCase
    {
        return $this->place($case, $data, $expectedRevision, reschedule: false);
    }

    /**
     * Muda a janela/sala de um caso JÁ agendado. Mesma regra de agendar; pedir
     * exatamente a janela atual não faz nada (idempotente).
     *
     * @param array{starts_at: string, duration_minutes?: int|null, surgery_facility_id: string, surgery_room_id?: string|null} $data
     */
    public function reschedule(SurgicalCase $case, array $data, ?int $expectedRevision = null): SurgicalCase
    {
        return $this->place($case, $data, $expectedRevision, reschedule: true);
    }

    /**
     * Libera a vaga: agendada → autorizada (ou parcialmente autorizada). Se a
     * autorização venceu enquanto isso, o caso segue para "aguardando
     * autorização" para registrar a renovação. Liberar um caso que já não
     * está agendado (e sem janela) devolve o caso como está.
     */
    public function release(SurgicalCase $case, ?string $reason = null, ?int $expectedRevision = null): SurgicalCase
    {
        return DB::transaction(function () use ($case, $reason, $expectedRevision): SurgicalCase {
            $locked = $this->cases->lock((string) $case->getKey());

            $this->cases->assertRevision($locked, $expectedRevision);

            if ($locked->status !== SurgicalCaseStatus::Scheduled) {
                if (in_array($locked->status, [SurgicalCaseStatus::Authorized, SurgicalCaseStatus::PartiallyAuthorized, SurgicalCaseStatus::AwaitingAuthorization], true) && $locked->scheduled_start === null) {
                    return $locked; // já liberado
                }

                throw SurgeryRuleException::state('surgery_schedule.errors.not_scheduled', ['status' => $locked->status->label()]);
            }

            $this->releaseLocked($locked, $reason);
            $this->bumpAvailability($locked);

            return $locked->refresh();
        });
    }

    /**
     * Confere a janela SEM gravar nem travar (resposta antecipada do formulário;
     * a decisão que vale é a de schedule()/reschedule(), sob trava).
     *
     * @param array{starts_at: string, duration_minutes?: int|null, surgery_facility_id?: string|null, surgery_room_id?: string|null} $data
     *
     * @return list<array<string, mixed>>
     */
    public function check(SurgicalCase $case, array $data): array
    {
        [$start, $end] = $this->window($case, $data);
        $room          = $this->resolveRoomForCheck($case, $data);

        return $this->collectConflicts($case, $start, $end, $room, $this->doctorIds($case));
    }

    // ── Para a Agenda de consultas ──────────────────────────────────────────

    /**
     * Cirurgias AGENDADAS (ou já realizadas no horário) em que algum dos
     * médicos opera — cirurgião ou membro da equipe — sobrepostas ao intervalo.
     *
     * @param list<string> $doctorIds
     *
     * @return Collection<int, SurgicalCase>
     */
    public function windowsOverlapping(array $doctorIds, CarbonInterface $start, CarbonInterface $end, ?string $exceptCaseId = null): Collection
    {
        if ($doctorIds === []) {
            return new Collection();
        }

        return SurgicalCase::query()
            ->withoutEntityScope()
            ->occupyingWindow()
            ->when($exceptCaseId, fn ($q) => $q->whereKeyNot($exceptCaseId))
            ->where('scheduled_start', '<', $end)
            ->where('scheduled_end', '>', $start)
            ->where(function ($q) use ($doctorIds) {
                $q->whereIn('surgeon_doctor_id', $doctorIds)
                    ->orWhereIn('surgical_cases.id', SurgicalTeamMember::query()
                        ->withoutEntityScope()
                        ->whereIn('doctor_id', $doctorIds)
                        ->select('surgical_case_id'));
            })
            ->orderBy('scheduled_start')
            ->get();
    }

    /**
     * Janelas cirúrgicas por médico, no formato de bloqueio da Agenda
     * (ScheduleBlock NÃO persistido) — o calendário de vagas as trata como
     * horário bloqueado do médico.
     *
     * @param list<string> $doctorIds
     *
     * @return array<string, list<ScheduleBlock>> médico => janelas
     */
    public function windowsByDoctor(array $doctorIds, CarbonInterface $start, CarbonInterface $end): array
    {
        $cases = $this->windowsOverlapping($doctorIds, $start, $end);

        if ($cases->isEmpty()) {
            return [];
        }

        $team = SurgicalTeamMember::query()
            ->withoutEntityScope()
            ->whereIn('surgical_case_id', $cases->modelKeys())
            ->whereNotNull('doctor_id')
            ->get()
            ->groupBy('surgical_case_id');

        $out = [];

        foreach ($cases as $case) {
            $operating = array_unique([
                (string) $case->surgeon_doctor_id,
                ...$team->get($case->getKey(), collect())->pluck('doctor_id')->map(fn ($id) => (string) $id)->all(),
            ]);

            foreach ($operating as $doctorId) {
                if (in_array($doctorId, $doctorIds, true)) {
                    $out[$doctorId][] = new ScheduleBlock([
                        'doctor_id' => $doctorId,
                        'starts_at' => $case->scheduled_start,
                        'ends_at'   => $case->scheduled_end,
                        'type'      => 'surgery',
                    ]);
                }
            }
        }

        return $out;
    }

    /**
     * Lado "leitor" da trava das janelas cirúrgicas do médico: a Agenda de
     * consultas chama antes de gravar um horário dele. `FOR SHARE` na linha do
     * médico — consultas simultâneas do mesmo médico NÃO se bloqueiam entre si,
     * mas esperam (e são esperadas por) quem agenda uma cirurgia dele, que toma
     * `FOR NO KEY UPDATE` na mesma linha (lockDoctorRows). `FOR NO KEY UPDATE`
     * não conflita com a chave estrangeira dos agendamentos (FOR KEY SHARE).
     */
    public function lockDoctorWindows(string $doctorId): void
    {
        DB::table('doctors')->where('id', $doctorId)->sharedLock()->value('id');
    }

    /**
     * Lado "escritor": trava as linhas dos médicos da cirurgia (cirurgião e
     * equipe) em ordem crescente de id, DEPOIS dos advisory locks e ANTES da
     * linha do caso. Vê o commit de qualquer consulta que tenha entrado antes.
     *
     * @param list<string> $doctorIds
     */
    private function lockDoctorRows(array $doctorIds): void
    {
        sort($doctorIds, SORT_STRING);

        $query = DB::table('doctors')->whereIn('id', $doctorIds)->orderBy('id');

        DB::connection()->getDriverName() === 'pgsql'
            ? $query->lock('for no key update')->pluck('id')
            : $query->lockForUpdate()->pluck('id');
    }

    /** Chave bigint estável de um recurso da agenda cirúrgica (60 bits do sha1, sempre positiva). */
    public static function lockKey(string $kind, string $id): int
    {
        return (int) hexdec(substr(sha1("surgery_window|{$kind}|{$id}"), 0, 15));
    }

    // ── Núcleo: agendar/reagendar sob trava ─────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function place(SurgicalCase $case, array $data, ?int $expectedRevision, bool $reschedule): SurgicalCase
    {
        return DB::transaction(function () use ($case, $data, $expectedRevision, $reschedule): SurgicalCase {
            for ($attempt = 1;; $attempt++) {
                try {
                    // SAVEPOINT: refazer a tentativa solta os advisory locks tomados nela.
                    return DB::transaction(fn (): SurgicalCase => $this->placeLocked($case, $data, $expectedRevision, $reschedule));
                } catch (SurgeryLockSetChanged) {
                    if ($attempt >= self::LOCK_ATTEMPTS) {
                        throw SurgeryRuleException::state('surgery_schedule.errors.changed_concurrently');
                    }
                }
            }
        });
    }

    /** @param array<string, mixed> $data */
    private function placeLocked(SurgicalCase $case, array $data, ?int $expectedRevision, bool $reschedule): SurgicalCase
    {
        // 1) advisory locks (ordem crescente) com o que se vê agora…
        $held = $this->lockKeys($case, $data);
        $this->takeLocks($held);
        $this->lockDoctorRows($this->doctorIds($case));

        // 2) …depois a linha do caso, relida sob a trava…
        $locked = $this->cases->lock((string) $case->getKey());

        // 3) …e o conjunto de chaves recalculado: equipe/paciente podem ter mudado.
        if (array_diff($this->lockKeys($locked, $data), $held) !== []) {
            throw new SurgeryLockSetChanged();
        }

        $this->cases->assertRevision($locked, $expectedRevision);

        $expected = $reschedule ? [SurgicalCaseStatus::Scheduled] : [SurgicalCaseStatus::Authorized, SurgicalCaseStatus::PartiallyAuthorized];

        if (! in_array($locked->status, $expected, true)) {
            throw SurgeryRuleException::state(
                $reschedule ? 'surgery_schedule.errors.not_scheduled' : 'surgery_schedule.errors.not_schedulable',
                ['status' => $locked->status->label()],
            );
        }

        [$start, $end] = $this->window($locked, $data);

        $this->assertAuthorizationCovers($locked, $start);

        $facility = $this->resolveFacility($locked, $data);
        $room     = $this->resolveRoom($facility, $data);

        // Surgeon inativo/removido não opera.
        $this->cases->surgeon((string) $locked->entity_id, (string) $locked->surgeon_doctor_id);

        if ($reschedule && $this->sameWindow($locked, $start, $end, $facility, $room)) {
            return $locked; // idempotente
        }

        $conflicts = $this->collectConflicts($locked, $start, $end, $room, $this->doctorIds($locked));

        if ($conflicts !== []) {
            throw $this->conflictException($conflicts);
        }

        // Entrada e Pausa mostram o retrato de segurança do caso: renovado ao agendar/reagendar.
        $this->cases->captureSafetySnapshot($locked);

        if ($reschedule) {
            // Soltar a vaga antiga e ocupar a nova na mesma transação: o histórico
            // registra as duas passagens.
            $this->releaseLocked($locked, 'rescheduled');
        }

        $this->cases->changeStatus($locked, SurgicalCaseStatus::Scheduled, null, [
            'scheduled_start'            => $start,
            'scheduled_end'              => $end,
            'surgery_facility_id'        => $facility->getKey(),
            'surgery_room_id'            => $room?->getKey(),
            'estimated_duration_minutes' => (int) $start->diffInMinutes($end),
        ]);

        $this->bumpAvailability($locked);

        return $locked->refresh();
    }

    /** O calendário de vagas da Agenda (cache) muda com a janela: invalida depois do commit. */
    private function bumpAvailability(SurgicalCase $locked): void
    {
        $entityId = (string) $locked->entity_id;

        DB::afterCommit(fn () => ScheduleAvailabilityService::bumpVersion($entityId));
    }

    /** Volta o caso (travado) de agendado para autorizado/aguardando e limpa a janela. */
    private function releaseLocked(SurgicalCase $locked, ?string $reason): void
    {
        $partial = $locked->authorization_status === AuthorizationStatus::PartiallyAuthorized;

        $this->cases->changeStatus(
            $locked,
            $partial ? SurgicalCaseStatus::PartiallyAuthorized : SurgicalCaseStatus::Authorized,
            $reason,
            ['scheduled_start' => null, 'scheduled_end' => null, 'surgery_room_id' => null],
        );

        // Autorização vencida enquanto agendado: precisa de renovação registrada.
        if ($locked->authorization_status === AuthorizationStatus::Expired) {
            $this->cases->changeStatus($locked, SurgicalCaseStatus::AwaitingAuthorization, 'authorization_expired');
        }
    }

    // ── Janela, autorização, local ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(SurgicalCase $case, array $data): array
    {
        $start = Carbon::parse((string) $data['starts_at']);

        if ($start->lt(now()->subMinutes(5))) {
            throw SurgeryRuleException::invalid('surgery_schedule.errors.start_in_past');
        }

        $minutes = (int) ($data['duration_minutes'] ?? $case->estimated_duration_minutes ?? config('surgery.default_duration', 60));

        if ($minutes < 5 || $minutes > 720) {
            throw SurgeryRuleException::invalid('surgery_schedule.errors.duration_range');
        }

        return [$start, $start->copy()->addMinutes($minutes)];
    }

    /** O pedido autorizado vale na data da cirurgia (validade ≥ dia da cirurgia). */
    private function assertAuthorizationCovers(SurgicalCase $locked, Carbon $start): void
    {
        if (! $locked->authorization_status->allowsScheduling()) {
            throw SurgeryRuleException::state('surgery_schedule.errors.authorization_not_valid', [
                'status' => $locked->authorization_status->label(),
            ]);
        }

        $validUntil = $locked->authorization_valid_until;

        if ($validUntil !== null && $validUntil->toDateString() < $start->toDateString()) {
            throw SurgeryRuleException::state('surgery_schedule.errors.authorization_expires_before', [
                'valid_until' => $validUntil->format((string) __('surgery_operation.date_format')),
                'date'        => $start->format((string) __('surgery_operation.date_format')),
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function resolveFacility(SurgicalCase $locked, array $data): SurgeryFacility
    {
        // FOR SHARE: desativar/apagar o local (SurgeryFacilityService) pede FOR UPDATE na mesma
        // linha e só decide depois de enxergar o caso agendado aqui — nenhum dos dois passa por cima.
        $facility = SurgeryFacility::query()
            ->where('entity_id', $locked->entity_id)
            ->where('active', true)
            ->whereKey((string) ($data['surgery_facility_id'] ?? ''))
            ->sharedLock()
            ->first();

        if ($facility === null) {
            throw SurgeryRuleException::invalid('surgery_schedule.errors.facility_not_found');
        }

        return $facility;
    }

    /**
     * Sala do local. Local com salas ativas exige sala; local sem sala (hospital
     * em que a clínica não controla a sala) aceita agendar sem ela — a janela
     * então só disputa cirurgião e paciente.
     *
     * @param array<string, mixed> $data
     */
    private function resolveRoom(SurgeryFacility $facility, array $data): ?SurgeryRoom
    {
        $rooms  = SurgeryRoom::query()->where('surgery_facility_id', $facility->getKey())->where('active', true)->get();
        $wanted = $data['surgery_room_id'] ?? null;

        if (blank($wanted)) {
            if ($rooms->isNotEmpty()) {
                throw SurgeryRuleException::invalid('surgery_schedule.errors.room_required');
            }

            return null;
        }

        // Só a sala pedida, com FOR SHARE (mesma razão do local): o filtro `active` e o soft delete
        // são reavaliados DEPOIS da espera, então sala apagada/desativada no meio não passa.
        $room = $rooms->contains('id', (string) $wanted)
            ? SurgeryRoom::query()
                ->where('surgery_facility_id', $facility->getKey())
                ->where('active', true)
                ->whereKey((string) $wanted)
                ->sharedLock()
                ->first()
            : null;

        if ($room === null) {
            throw SurgeryRuleException::invalid('surgery_schedule.errors.room_not_found');
        }

        return $room;
    }

    /** @param array<string, mixed> $data */
    private function resolveRoomForCheck(SurgicalCase $case, array $data): ?SurgeryRoom
    {
        if (blank($data['surgery_room_id'] ?? null)) {
            return null;
        }

        return SurgeryRoom::query()
            ->where('entity_id', $case->entity_id)
            ->where('active', true)
            ->whereKey((string) $data['surgery_room_id'])
            ->first();
    }

    private function sameWindow(SurgicalCase $locked, Carbon $start, Carbon $end, SurgeryFacility $facility, ?SurgeryRoom $room): bool
    {
        return $locked->scheduled_start?->equalTo($start) === true
            && $locked->scheduled_end?->equalTo($end) === true
            && (string) $locked->surgery_facility_id === (string) $facility->getKey()
            && (string) ($locked->surgery_room_id ?? '') === (string) ($room?->getKey() ?? '');
    }

    // ── Travas ──────────────────────────────────────────────────────────────

    /**
     * Chaves dos recursos da janela, em ordem crescente: sala pedida, cirurgião,
     * médicos da equipe e paciente.
     *
     * @param array<string, mixed> $data
     *
     * @return list<int>
     */
    private function lockKeys(SurgicalCase $case, array $data): array
    {
        $keys = [self::lockKey('patient', (string) $case->patient_id)];

        foreach ($this->doctorIds($case) as $doctorId) {
            $keys[] = self::lockKey('doctor', $doctorId);
        }

        if (filled($data['surgery_room_id'] ?? null)) {
            $keys[] = self::lockKey('room', (string) $data['surgery_room_id']);
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_NUMERIC);

        return $keys;
    }

    /** @param list<int> $keys */
    private function takeLocks(array $keys): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return; // sem advisory lock: a trava da linha do caso é o que resta
        }

        foreach ($keys as $key) {
            $connection->select('select pg_advisory_xact_lock(?)', [$key]);
        }
    }

    /** Cirurgião + médicos da equipe. @return list<string> */
    private function doctorIds(SurgicalCase $case): array
    {
        $team = SurgicalTeamMember::query()
            ->withoutEntityScope()
            ->where('surgical_case_id', $case->getKey())
            ->whereNotNull('doctor_id')
            ->pluck('doctor_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        return array_values(array_unique([(string) $case->surgeon_doctor_id, ...$team]));
    }

    // ── Conflitos ───────────────────────────────────────────────────────────

    /**
     * @param list<string> $doctorIds
     *
     * @return list<array<string, mixed>>
     */
    private function collectConflicts(SurgicalCase $case, Carbon $start, Carbon $end, ?SurgeryRoom $room, array $doctorIds): array
    {
        $conflicts = [];
        $self      = (string) $case->getKey();

        // Médico (perfil) só vê código/título/horário de conflito com caso EM QUE ATUA; dos demais
        // recebe só "ocupado neste horário" — o título é texto livre e pode citar o paciente.
        $viewer = CurrentDoctor::isDoctorProfile() ? (CurrentDoctor::doctor() ?? false) : null;

        $overlapping = fn () => SurgicalCase::query()
            ->withoutEntityScope()
            ->where('surgical_cases.entity_id', $case->entity_id)
            ->occupyingWindow()
            ->whereKeyNot($self)
            ->where('scheduled_start', '<', $end)
            ->where('scheduled_end', '>', $start);

        if ($room !== null) {
            foreach ($overlapping()->where('surgery_room_id', $room->getKey())->get() as $other) {
                $conflicts[] = $this->caseConflict('room', $other, $room->name, $viewer);
            }
        }

        foreach ($this->windowsOverlapping($doctorIds, $start, $end, $self) as $other) {
            if ((string) $other->entity_id !== (string) $case->entity_id) {
                continue;
            }

            $conflicts[] = $this->caseConflict(
                (string) $other->surgeon_doctor_id === (string) $case->surgeon_doctor_id ? 'surgeon' : 'team',
                $other,
                null,
                $viewer,
            );
        }

        foreach ($overlapping()->where('patient_id', $case->patient_id)->get() as $other) {
            $conflicts[] = $this->caseConflict('patient', $other, null, $viewer);
        }

        if ($room?->clinic_resource_id !== null) {
            array_push($conflicts, ...$this->resourceConflicts((string) $room->clinic_resource_id, (string) $case->entity_id, $room->name, $start, $end));
        }

        array_push($conflicts, ...$this->consultationConflicts($doctorIds, (string) $case->entity_id, $start, $end));

        return $conflicts;
    }

    /**
     * @param Doctor|false|null $viewer null = não é perfil médico (vê tudo); false = médico sem cadastro
     *
     * @return array<string, mixed>
     */
    private function caseConflict(string $kind, SurgicalCase $other, ?string $label = null, Doctor|false|null $viewer = null): array
    {
        if ($viewer !== null && ($viewer === false || ! app(SurgeryParticipants::class)->isMember($other, $viewer))) {
            return ['kind' => $kind, 'label' => $label, 'case_code' => null, 'title' => null, 'start' => null, 'end' => null, 'masked' => true];
        }

        return [
            'kind'      => $kind,
            'label'     => $label,
            'case_code' => $other->code,
            'title'     => $other->title,
            'start'     => $other->scheduled_start?->toIso8601String(),
            'end'       => $other->scheduled_end?->toIso8601String(),
        ];
    }

    /**
     * Escala semanal e bloqueios da sala da própria clínica (clinic_resource),
     * checados pelo INTERVALO inteiro — não só pelo instante inicial.
     *
     * @return list<array<string, mixed>>
     */
    private function resourceConflicts(string $resourceId, string $entityId, string $roomName, Carbon $start, Carbon $end): array
    {
        $resource = ClinicResource::query()->where('entity_id', $entityId)->whereKey($resourceId)->first();
        $out      = [];

        if ($resource === null || ! $resource->active) {
            return [['kind' => 'resource_inactive', 'label' => $roomName, 'case_code' => null, 'title' => null, 'start' => null, 'end' => null]];
        }

        // Escala: sem nenhuma faixa cadastrada = sem restrição. Com faixas, cada dia
        // tocado pela janela precisa estar inteiro dentro de faixas CONTÍGUAS.
        $ranges = ResourceWorkSchedule::query()->where('resource_id', $resourceId)->get();

        if ($ranges->isNotEmpty()) {
            for ($day = $start->copy()->startOfDay(); $day->lt($end); $day->addDay()) {
                $from = $start->gt($day) ? $start->copy() : $day->copy();
                $to   = $end->lt($day->copy()->addDay()) ? $end->copy() : $day->copy()->addDay();

                if (! $this->dayCovered($ranges->where('day_of_week', $day->dayOfWeek), $day, $from, $to)) {
                    $out[] = [
                        'kind'      => 'resource_schedule',
                        'label'     => $roomName,
                        'case_code' => null,
                        'title'     => null,
                        'start'     => $from->toIso8601String(),
                        'end'       => $to->toIso8601String(),
                    ];

                    break;
                }
            }
        }

        $blocks = ResourceBlock::query()
            ->where('resource_id', $resourceId)
            ->where('starts_at', '<', $end->toDateTimeString())
            ->where('ends_at', '>', $start->toDateTimeString())
            ->orderBy('starts_at')
            ->get();

        foreach ($blocks as $block) {
            $out[] = [
                'kind'      => 'resource_block',
                'label'     => $roomName,
                'case_code' => null,
                'title'     => $block->reason ?: $block->typeLabel(),
                'start'     => $block->starts_at->toIso8601String(),
                'end'       => $block->ends_at->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * O trecho [from, to) do dia cabe numa sequência contígua de faixas da escala?
     * Faixas que se encostam (12:00–12:00) contam como uma só.
     *
     * @param \Illuminate\Support\Collection<int, ResourceWorkSchedule> $ranges
     */
    private function dayCovered($ranges, Carbon $day, Carbon $from, Carbon $to): bool
    {
        $merged = [];

        foreach ($ranges->sortBy('starts_at') as $range) {
            $rs = $day->copy()->setTimeFromTimeString((string) $range->starts_at);
            $re = $day->copy()->setTimeFromTimeString((string) $range->ends_at);

            if ($merged !== [] && $rs->lte(end($merged)[1])) {
                $last             = array_key_last($merged);
                $merged[$last][1] = $re->gt($merged[$last][1]) ? $re : $merged[$last][1];

                continue;
            }

            $merged[] = [$rs, $re];
        }

        foreach ($merged as [$rs, $re]) {
            if ($from->gte($rs) && $to->lte($re)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Consultas ATIVAS (não atendidas/faltou/canceladas) dos médicos que se
     * sobrepõem à janela: o horário da consulta dura o intervalo do médico.
     *
     * @param list<string> $doctorIds
     *
     * @return list<array<string, mixed>>
     */
    private function consultationConflicts(array $doctorIds, string $entityId, Carbon $start, Carbon $end): array
    {
        $terminal = array_map(fn (ScheduleSituation $s) => $s->value, array_filter(ScheduleSituation::cases(), fn ($s) => $s->isTerminal()));

        $rows = Schedule::query()
            ->join('doctors', 'doctors.id', '=', 'schedules.doctor_id')
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->join('entities', 'entities.id', '=', 'entity_users.entity_id')
            ->where('schedules.entity_id', $entityId)
            ->whereIn('schedules.doctor_id', $doctorIds)
            ->whereNotIn('schedules.situation', $terminal)
            ->where('schedules.date_time', '<', $end->toDateTimeString())
            ->whereRaw(
                "schedules.date_time + (COALESCE(doctors.schedule_interval, entities.schedule_interval, 15) * interval '1 minute') > ?",
                [$start->toDateTimeString()],
            )
            ->orderBy('schedules.date_time')
            ->get(['schedules.id', 'schedules.date_time', 'schedules.doctor_id']);

        return $rows->map(fn ($row) => [
            'kind'      => 'consultation',
            'label'     => null,
            'case_code' => null,
            'title'     => null,
            'start'     => Carbon::parse($row->date_time)->toIso8601String(),
            'end'       => null,
        ])->all();
    }

    /** Texto do conflito no idioma atual (usado na mensagem e na resposta do formulário). @param array<string, mixed> $conflict */
    public function describeConflict(array $conflict): string
    {
        if (! empty($conflict['masked'])) {
            return (string) __('surgery_schedule.conflict_masked.' . $conflict['kind'], ['label' => $conflict['label'] ?? '']);
        }

        return (string) __('surgery_schedule.conflict.' . $conflict['kind'], [
            'label' => $conflict['label'] ?? '',
            'case'  => $conflict['case_code'] ?? '',
            'start' => filled($conflict['start'] ?? null) ? Carbon::parse($conflict['start'])->format(__('surgery_operation.date_format') . ' H:i') : '',
            'end'   => filled($conflict['end'] ?? null) ? Carbon::parse($conflict['end'])->format('H:i') : '',
            'title' => $conflict['title'] ?? '',
        ]);
    }

    /** @param list<array<string, mixed>> $conflicts */
    private function conflictException(array $conflicts): SurgeryRuleException
    {
        $text = $this->describeConflict($conflicts[0]);

        $message = count($conflicts) > 1
            ? (string) __('surgery_schedule.conflict.summary_many', ['first' => $text, 'count' => count($conflicts) - 1])
            : $text;

        $conflicts = array_map(fn (array $c) => [...$c, 'text' => $this->describeConflict($c)], $conflicts);

        return new SurgeryRuleException($message, 409, ['conflicts' => $conflicts]);
    }
}
