<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\ScheduleSituation;
use App\Models\{Patient, Schedule, WaitingList};
use App\Models\WhatsApp\WhatsAppMessage;
use App\Support\BrazilianFormat;
use Carbon\{Carbon, CarbonInterface};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Operação de HOJE no Dashboard (dados que mudam durante o expediente e vão no
 * polling de 30 s): sala de espera, confirmações de hoje e de amanhã, lista de
 * espera, aniversariantes e atendimentos por médico.
 *
 * Tudo filtrado pela clínica (entity_id explícito, além do EntityScope) e em
 * poucas consultas agregadas/limitadas — quem decide QUEM recebe cada bloco é
 * o PanelDashboardController (perfil + acesso à tela de origem).
 */
class ClinicOperationsService
{
    /** Pessoas mostradas na sala de espera (o total vem à parte). */
    public const WAITING_ROOM_LIMIT = 12;

    /** Itens da lista "ligar para confirmar" por dia. */
    public const CALL_LIST_LIMIT = 8;

    /** Primeiros da lista de espera. */
    public const WAITLIST_LIMIT = 5;

    /** Aniversariantes listados (o total vem à parte). */
    public const BIRTHDAYS_LIMIT = 8;

    /** Médicos na tabela "Atendimentos por médico" (os de mais consultas). */
    public const DOCTORS_TODAY_LIMIT = 12;

    /** Teto de consultas de hoje + amanhã lidas para as confirmações. */
    public const CONFIRMATION_ROWS_LIMIT = 1000;

    /** Cache dos ids dos aniversariantes do dia (só ids — nada de dado pessoal no cache). */
    public const BIRTHDAYS_TTL_MINUTES = 30;

    /** Paciente chegou e espera: pronto para o médico ou em preparo (dilatação/exame). */
    public const READY_SITUATIONS = [ScheduleSituation::Waiting, ScheduleSituation::ReturningToDoctor];

    public const PREPARING_SITUATIONS = [ScheduleSituation::Dilating, ScheduleSituation::Exam];

    /**
     * Consulta confirmada: o paciente confirmou ou já chegou/foi atendido.
     * Agendado (e qualquer valor desconhecido) = sem confirmação; faltas e
     * cancelamentos ficam fora da conta.
     */
    public const CONFIRMED_SITUATIONS = [
        ScheduleSituation::Confirmed,
        ScheduleSituation::Waiting,
        ScheduleSituation::Dilating,
        ScheduleSituation::Exam,
        ScheduleSituation::ReturningToDoctor,
        ScheduleSituation::InProgress,
        ScheduleSituation::Attended,
    ];

    /** Fora da agenda do dia (não contam como consulta a confirmar/atender). */
    public const CLOSED_SITUATIONS = [ScheduleSituation::NoShow, ScheduleSituation::Cancelled];

    /**
     * Status da confirmação por WhatsApp (whatsapp_messages.status) → grupo do
     * painel. Mensagem respondida mas consulta ainda "Agendada" (resposta não
     * entendida, ou da data antiga) segue aguardando.
     */
    private const WHATSAPP_GROUPS = [
        WhatsAppMessage::STATUS_PENDING  => 'queued',
        WhatsAppMessage::STATUS_SENDING  => 'queued',
        WhatsAppMessage::STATUS_SENT     => 'awaiting',
        WhatsAppMessage::STATUS_ANSWERED => 'awaiting',
        WhatsAppMessage::STATUS_FAILED   => 'failed',
    ];

    /**
     * Recepção: sala de espera agora + confirmações de hoje e amanhã (com a
     * lista "ligar para confirmar"), numa consulta só.
     *
     * @param bool $withPhones telefones só para quem abre Pacientes (LGPD)
     *
     * @return array<string, mixed>
     */
    public function reception(string $entityId, bool $withPhones): array
    {
        $today    = now()->startOfDay();
        $tomorrow = $today->copy()->addDay();

        $rows = $this->withDoctorName(Schedule::query())
            ->select([
                'schedules.id',
                'schedules.date_time',
                'schedules.full_name',
                'schedules.situation',
                'schedules.arrived_at',
                'schedules.cellphone',
                'schedules.telephone',
                'people.cellphone as person_cellphone',
                'people.telephone as person_telephone',
                'wm.status as whatsapp_status',
                'users.name as doctor_name',
            ])
            ->leftJoin('patients', fn (JoinClause $join) => $join
                ->on('patients.id', '=', 'schedules.patient_id')
                ->on('patients.entity_id', '=', 'schedules.entity_id'))
            ->leftJoin('people', 'people.id', '=', 'patients.person_id')
            // Uma confirmação de saída por consulta (índice único parcial
            // whatsapp_messages_outbound_once) — o join não duplica linhas.
            ->leftJoin('whatsapp_messages as wm', fn (JoinClause $join) => $join
                ->on('wm.schedule_id', '=', 'schedules.id')
                ->where('wm.direction', '=', 'out')
                ->where('wm.kind', '=', WhatsAppMessage::KIND_CONFIRMATION))
            ->where('schedules.entity_id', $entityId)
            ->whereBetween('schedules.date_time', [$today, $tomorrow->copy()->endOfDay()])
            ->whereNotIn('schedules.situation', self::values(self::CLOSED_SITUATIONS))
            ->orderBy('schedules.date_time')
            ->limit(self::CONFIRMATION_ROWS_LIMIT)
            ->toBase()
            ->get();

        $todayKey = $today->toDateString();
        $byDay    = $rows->groupBy(fn (object $row) => Carbon::parse($row->date_time)->toDateString());

        return [
            'waiting_room' => $this->waitingRoom($byDay->get($todayKey, collect())),
            'days'         => [
                'today'    => $this->confirmationDay($byDay->get($todayKey, collect()), $today, $withPhones, true),
                'tomorrow' => $this->confirmationDay($byDay->get($tomorrow->toDateString(), collect()), $tomorrow, $withPhones, false),
            ],
            'truncated'    => $rows->count() >= self::CONFIRMATION_ROWS_LIMIT,
            'schedule_url' => route('panel.schedules.index'),
        ];
    }

    /**
     * Lista de espera da clínica: total ativo + os primeiros (mesma ordem do
     * painel da Agenda: posição, depois chegada na lista).
     *
     * @return array{count: int, items: list<array<string, mixed>>, url: string}
     */
    public function waitlist(string $entityId, bool $withPhones): array
    {
        $base = WaitingList::query()
            ->where('waiting_list.entity_id', $entityId)
            ->where('waiting_list.active', true);

        $count = (clone $base)->count();

        $items = $count === 0 ? [] : $this->withDoctorName($base, 'waiting_list')
            ->select([
                'waiting_list.id',
                'waiting_list.full_name',
                'waiting_list.cellphone',
                'waiting_list.telephone',
                'waiting_list.preferred_date_from',
                'waiting_list.preferred_date_until',
                'waiting_list.created_at',
                'users.name as doctor_name',
            ])
            ->orderBy('waiting_list.position')
            ->orderBy('waiting_list.created_at')
            ->limit(self::WAITLIST_LIMIT)
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'id'     => $row->id,
                'name'   => $row->full_name ?? '—',
                'doctor' => $row->doctor_name ?? '—',
                'from'   => $this->localDate($row->preferred_date_from),
                'until'  => $this->localDate($row->preferred_date_until),
                'since'  => $this->localDate($row->created_at),
                'days'   => $row->created_at ? (int) Carbon::parse($row->created_at)->startOfDay()->diffInDays(now()->startOfDay()) : null,
                ...$this->phoneFields($withPhones, $row->cellphone, $row->telephone),
            ])
            ->values()
            ->all();

        return ['count' => $count, 'items' => $items, 'url' => route('panel.schedules.index')];
    }

    /**
     * Aniversariantes de hoje entre os pacientes ativos da clínica. Em ano
     * não bissexto, quem nasceu em 29/02 aparece em 28/02. O cache guarda só
     * os ids (o nome/telefone vem do banco a cada leitura).
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function birthdays(string $entityId, bool $withPhones): array
    {
        $today = now();

        $ids = Cache::remember(
            "dashboard.v1.{$entityId}.birthdays." . $today->toDateString(),
            now()->addMinutes(self::BIRTHDAYS_TTL_MINUTES),
            fn (): array => $this->birthdayQuery($entityId, $today)
                ->orderBy('people.full_name')
                ->pluck('patients.id')
                ->map(fn ($id) => (string) $id)
                ->all(),
        );

        if ($ids === []) {
            return ['count' => 0, 'items' => []];
        }

        $patients = Patient::query()
            ->with('person:id,full_name,birth_date,cellphone,telephone')
            ->where('patients.entity_id', $entityId)
            ->whereIn('patients.id', array_slice($ids, 0, self::BIRTHDAYS_LIMIT))
            ->get(['patients.id', 'patients.person_id', 'patients.code'])
            ->keyBy(fn (Patient $p) => (string) $p->id);

        $items = collect(array_slice($ids, 0, self::BIRTHDAYS_LIMIT))
            ->filter(fn (string $id) => $patients->has($id))
            ->map(function (string $id) use ($patients, $today, $withPhones) {
                $patient = $patients[$id];
                $birth   = $patient->person?->birth_date;

                return [
                    'id'   => $patient->id,
                    'name' => $patient->person?->full_name ?? '—',
                    'age'  => $birth ? $today->year - $birth->year : null,
                    'url'  => route('panel.patients.index', ['open' => $patient->id]),
                    ...$this->phoneFields($withPhones, $patient->person?->cellphone, $patient->person?->telephone),
                ];
            })
            ->values()
            ->all();

        return ['count' => count($ids), 'items' => $items];
    }

    /**
     * Atendimentos por médico hoje (visão da administração): uma consulta
     * agregada por médico. `expected` = agendadas − faltas/cancelamentos.
     *
     * @return array{items: list<array<string, mixed>>, others: int}
     */
    public function doctorsToday(string $entityId): array
    {
        $attended = ScheduleSituation::Attended->value;
        $closed   = implode(',', self::values(self::CLOSED_SITUATIONS));
        $waiting  = implode(',', self::values([...self::READY_SITUATIONS, ...self::PREPARING_SITUATIONS]));
        $inCare   = ScheduleSituation::InProgress->value;

        $rows = $this->withDoctorName(Schedule::query())
            ->where('schedules.entity_id', $entityId)
            ->whereBetween('schedules.date_time', [now()->startOfDay(), now()->endOfDay()])
            ->groupBy('schedules.doctor_id', 'users.name')
            ->select(['schedules.doctor_id', 'users.name as doctor_name'])
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN schedules.situation = {$attended} THEN 1 ELSE 0 END) AS attended")
            ->selectRaw("SUM(CASE WHEN schedules.situation IN ({$closed}) THEN 1 ELSE 0 END) AS missed")
            ->selectRaw("SUM(CASE WHEN schedules.situation IN ({$waiting}) AND schedules.arrived_at IS NOT NULL THEN 1 ELSE 0 END) AS waiting")
            ->selectRaw("SUM(CASE WHEN schedules.situation = {$inCare} THEN 1 ELSE 0 END) AS in_care")
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'id'       => (string) $row->doctor_id,
                'name'     => $row->doctor_name ?? '—',
                'total'    => (int) $row->total,
                'attended' => (int) $row->attended,
                'missed'   => (int) $row->missed,
                'waiting'  => (int) $row->waiting,
                'in_care'  => (int) $row->in_care,
                'expected' => (int) $row->total - (int) $row->missed,
            ])
            ->sortBy([['total', 'desc'], ['name', 'asc']])
            ->values();

        return [
            'items'  => $rows->take(self::DOCTORS_TODAY_LIMIT)->all(),
            'others' => max(0, $rows->count() - self::DOCTORS_TODAY_LIMIT),
        ];
    }

    // ── internos ─────────────────────────────────────────────────────────────

    /**
     * @param Collection<int, object> $rows consultas de hoje (sem faltas/cancelados)
     *
     * @return array{count: int, in_care: int, longest: ?int, items: list<array<string, mixed>>}
     */
    private function waitingRoom($rows): array
    {
        $ready     = self::values(self::READY_SITUATIONS);
        $preparing = self::values(self::PREPARING_SITUATIONS);

        $waiting = $rows
            ->filter(fn (object $r) => $r->arrived_at !== null && in_array((int) $r->situation, [...$ready, ...$preparing], true))
            ->sortBy(fn (object $r) => Carbon::parse($r->arrived_at)->getTimestamp())
            ->values();

        $items = $waiting->take(self::WAITING_ROOM_LIMIT)->map(function (object $r) use ($ready) {
            $situation = ScheduleSituation::tryFrom((int) $r->situation);

            return [
                'id'              => $r->id,
                'name'            => $r->full_name ?? '—',
                'doctor'          => $r->doctor_name ?? '—',
                'time'            => $this->localTime($r->date_time),
                'arrived_time'    => $this->localTime($r->arrived_at),
                'waiting_minutes' => max(0, (int) Carbon::parse($r->arrived_at)->diffInMinutes(now())),
                'state'           => in_array((int) $r->situation, $ready, true) ? 'ready' : 'preparing',
                'label'           => $situation?->label() ?? '—',
                'badge'           => $situation?->badgeClass() ?? 'bg-secondary',
                'icon'            => $situation?->icon() ?? 'fa-circle',
            ];
        })->all();

        return [
            'count'   => $waiting->count(),
            'in_care' => $rows->filter(fn (object $r) => (int) $r->situation === ScheduleSituation::InProgress->value)->count(),
            'longest' => $items[0]['waiting_minutes'] ?? null,
            'items'   => $items,
        ];
    }

    /**
     * Confirmações de um dia: confirmadas × sem confirmação, situação do
     * WhatsApp das que faltam, quebra por turno e quem ligar (hoje: só os
     * horários que ainda não passaram).
     *
     * @param Collection<int, object> $rows
     *
     * @return array<string, mixed>
     */
    private function confirmationDay($rows, CarbonInterface $day, bool $withPhones, bool $isToday): array
    {
        $confirmedValues = self::values(self::CONFIRMED_SITUATIONS);
        $whatsapp        = ['awaiting' => 0, 'queued' => 0, 'failed' => 0, 'none' => 0];
        $shifts          = [];
        $toCall          = [];
        $toCallTotal     = 0;
        $confirmed       = 0;
        $byWhatsApp      = 0;

        foreach ($rows as $row) {
            $at          = Carbon::parse($row->date_time);
            $shift       = self::shiftOf($at->hour);
            $isConfirmed = in_array((int) $row->situation, $confirmedValues, true);
            $group       = self::WHATSAPP_GROUPS[(string) $row->whatsapp_status] ?? null;

            $shifts[$shift] ??= ['key' => $shift, 'total' => 0, 'confirmed' => 0];
            $shifts[$shift]['total']++;

            if ($isConfirmed) {
                $confirmed++;
                $shifts[$shift]['confirmed']++;
                $byWhatsApp += $row->whatsapp_status === WhatsAppMessage::STATUS_ANSWERED ? 1 : 0;

                continue;
            }

            $whatsapp[$group ?? 'none']++;

            if ($isToday && $at->lt(now())) {
                continue; // horário já passou: ligar não confirma mais nada
            }

            $toCallTotal++;

            if (count($toCall) < self::CALL_LIST_LIMIT) {
                $toCall[] = [
                    'id'       => $row->id,
                    'time'     => $this->localTime($row->date_time),
                    'name'     => $row->full_name ?? '—',
                    'doctor'   => $row->doctor_name ?? '—',
                    'whatsapp' => $group,
                    ...$this->phoneFields(
                        $withPhones,
                        $row->cellphone ?: $row->person_cellphone,
                        $row->telephone ?: $row->person_telephone,
                    ),
                ];
            }
        }

        $order = ['morning' => 0, 'afternoon' => 1, 'evening' => 2];
        uksort($shifts, fn (string $a, string $b) => $order[$a] <=> $order[$b]);

        return [
            'date'               => $day->toDateString(),
            'total'              => $rows->count(),
            'confirmed'          => $confirmed,
            'unconfirmed'        => $rows->count() - $confirmed,
            'whatsapp_confirmed' => $byWhatsApp,
            'whatsapp'           => $whatsapp,
            'shifts'             => array_values($shifts),
            'to_call'            => $toCall,
            'to_call_total'      => $toCallTotal,
            'url'                => route('panel.schedules.index', ['date' => $day->toDateString()]),
        ];
    }

    /** Pacientes ativos da clínica que fazem aniversário hoje. */
    private function birthdayQuery(string $entityId, CarbonInterface $today): Builder
    {
        $feb29InCommonYear = ! $today->isLeapYear() && $today->month === 2 && $today->day === 28;

        return Patient::query()
            ->join('people', 'people.id', '=', 'patients.person_id')
            ->where('patients.entity_id', $entityId)
            ->where('patients.active', true)
            ->whereNull('people.deleted_at')
            ->whereNotNull('people.birth_date')
            ->whereMonth('people.birth_date', $today->month)
            ->where(fn (Builder $q) => $q
                ->whereDay('people.birth_date', $today->day)
                ->when($feb29InCommonYear, fn (Builder $leap) => $leap->orWhereDay('people.birth_date', 29)));
    }

    /**
     * Joins até o nome do médico (users.name, como na Agenda) — quem chama
     * seleciona `users.name as doctor_name`.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     *
     * @return Builder<TModel>
     */
    private function withDoctorName(Builder $query, string $table = 'schedules'): Builder
    {
        return $query
            ->leftJoin('doctors', 'doctors.id', '=', "{$table}.doctor_id")
            ->leftJoin('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->leftJoin('users', 'users.id', '=', 'entity_users.user_id');
    }

    /**
     * Telefone formatado + link de discagem — ou nada, sem acesso a Pacientes.
     *
     * @return array{phone?: ?string, phone_href?: ?string}
     */
    private function phoneFields(bool $withPhones, ?string $cellphone, ?string $telephone): array
    {
        if (! $withPhones) {
            return [];
        }

        $raw = $cellphone ?: $telephone;

        return [
            'phone'      => BrazilianFormat::phone($raw),
            'phone_href' => self::phoneHref($raw),
        ];
    }

    /** "tel:" com DDI: número brasileiro ganha +55; estrangeiro (+DDI) mantém o dele. */
    public static function phoneHref(?string $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        if (str_starts_with(ltrim((string) $raw), '+')) {
            $digits = BrazilianFormat::digits($raw);

            return $digits !== '' ? "tel:+{$digits}" : null;
        }

        $canonical = BrazilianFormat::canonicalPhone($raw);

        return $canonical ? "tel:+55{$canonical}" : null;
    }

    /** Mesmos turnos da Agenda (PanelDashboardController::shiftOf). */
    private static function shiftOf(int $hour): string
    {
        return match (true) {
            $hour < 13 => 'morning',
            $hour < 18 => 'afternoon',
            default    => 'evening',
        };
    }

    /**
     * @param list<ScheduleSituation> $situations
     *
     * @return list<int>
     */
    public static function values(array $situations): array
    {
        return array_map(fn (ScheduleSituation $s) => $s->value, $situations);
    }

    private function localTime(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->locale(app()->getLocale())->isoFormat('LT') : null;
    }

    private function localDate(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->locale(app()->getLocale())->isoFormat('L') : null;
    }
}
