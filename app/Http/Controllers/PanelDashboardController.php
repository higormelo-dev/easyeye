<?php

namespace App\Http\Controllers;

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\AiRunStatus;
use App\Enums\{ClientRule, EntityGate, FeatureKey, Permission, ScheduleSituation};
use App\Models\{Doctor, Entity, EntityProduct, MedicalRecord, Patient, PatientExam, Schedule};
use App\Services\{ActivationService, FeatureGateService};
use App\Services\Dashboard\{ClinicOperationsService, DashboardInsightsService};
use App\Support\BrazilianFormat;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response};

/**
 * Dashboard do painel da clínica — "painel por função": cada perfil abre no
 * seu posto de trabalho e o SERVIDOR decide o que ele recebe:
 *
 *  - médico ("Meu consultório"): SÓ o que é dele (próximo paciente, agenda de
 *    hoje, meu mês × mês anterior, laudos de IA a revisar, prontuários sem
 *    assinatura, exames sem laudo, pacientes atendidos por ele) — mesma regra
 *    da Agenda: schedules.doctor_id = o Doctor do usuário nesta clínica;
 *  - secretária ("Recepção"): agenda da clínica, sala de espera, confirmações
 *    de hoje e de amanhã (com telefone para ligar), lista de espera e
 *    aniversariantes do dia;
 *  - administrador ("Gestão"): indicadores do mês × mesmo período do mês
 *    anterior, tendências (30 dias e 6 meses), atendimentos por médico e a
 *    operação de hoje;
 *  - financeiro: caixa de hoje, a receber, glosas, faturado × recebido do mês
 *    e tendência — só agregados, nenhum nome/telefone de paciente;
 *  - usuário: só contagens e atalhos.
 *
 * Dados financeiros só com o Gate ViewFinancial (mesmo critério do BI);
 * telefones e aniversariantes só para quem abre Pacientes. Operação de hoje
 * vai no polling (30 s); os números de gestão (`insights`) não — chegam na
 * abertura e no "Atualizar" (header X-Dashboard-Refresh descarta o cache).
 *
 * O isolamento é aqui, no servidor: o front só esconde o que já não recebeu.
 */
class PanelDashboardController extends Controller
{
    /** Janela do indicador "Exames pendentes" — um dos períodos do Gerenciador de Imagens. */
    public const int EXAMS_PENDING_DAYS = 30;

    /** Teto de consultas de hoje enviadas ao card (agrupadas por turno no front). */
    public const int SCHEDULE_TODAY_LIMIT = 200;

    /** Janela de "Prontuários sem assinatura" (mesma dos exames; a lista não cresce para sempre). */
    public const int UNSIGNED_RECORDS_DAYS = 30;

    /** Itens das listas curtas do médico (IA a revisar, prontuários sem assinatura). */
    public const int PENDING_LIST_LIMIT = 5;

    /** "Atualizar" do painel: recalcula os números de gestão (descarta o cache). */
    public const string REFRESH_HEADER = 'X-Dashboard-Refresh';

    /** Paciente chegou e espera: pronto para o médico ou em preparo (dilatação/exame). */
    private const array READY_SITUATIONS = ClinicOperationsService::READY_SITUATIONS;

    private const array PREPARING_SITUATIONS = ClinicOperationsService::PREPARING_SITUATIONS;

    /**
     * Fluxos clínicos que esperam a revisão do médico. Chat livre (aprovado
     * sozinho pelo widget) e fluxos da plataforma (posologia/finanças do
     * manager) não são laudo da clínica.
     */
    private const array REVIEWABLE_AI_WORKFLOWS = [
        'exam_assistant',
        'report_drafting',
        'consensus_review',
        'eye_image_analysis',
        'record_assist',
    ];

    /** Clínica da sessão, lida uma vez por requisição (vários blocos usam). */
    private ?Entity $entity = null;

    /** @var array<string, bool> permissões já resolvidas nesta requisição */
    private array $allowed = [];

    public function __construct(
        private readonly ClinicOperationsService $operations,
        private readonly DashboardInsightsService $insights,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $entityId = (string) session('selected_entity_id');
        $today    = now()->toDateString();
        $profile  = ClientRule::tryFrom((string) session('selected_entity_user_rule')) ?? ClientRule::User;
        $isDoctor = $profile === ClientRule::Doctor;

        // Médico logado = Doctor com doctors.entity_user_id = vínculo da sessão
        // NESTA clínica (mesma regra de SchedulesController::loggedDoctor). Sem
        // cadastro de médico, nada da clínica é enviado — painel vazio com aviso.
        $doctor = $isDoctor ? $this->loggedDoctor($entityId) : null;

        $sections      = $this->sectionsFor($profile);
        $has           = fn (string $section): bool => in_array($section, $sections, true);
        $isClinicAdmin = $profile === ClientRule::Admin;

        return Inertia::render('Panel/Dashboard', [
            'profile'       => $profile->value,
            'doctorMissing' => $isDoctor && ! $doctor,
            // Seções que o perfil pode ver, na ordem padrão dele (o front só
            // ordena/oculta entre elas).
            'sections' => $sections,
            'stats'    => fn () => $isDoctor
                ? $this->buildDoctorStats($entityId, $doctor)
                : $this->buildStats($entityId, $profile),
            // Agenda nominal só para quem abre a Agenda; a do médico é só dele.
            'scheduleToday' => fn () => match (true) {
                $isDoctor      => $doctor ? $this->buildScheduleToday($entityId, $today, $doctor) : [],
                $has('agenda') => $this->buildScheduleToday($entityId, $today),
                default        => [],
            },
            'nextPatient'    => fn () => $doctor ? $this->buildNextPatient($entityId, $doctor) : null,
            'recentPatients' => fn () => match (true) {
                $isDoctor        => $doctor ? $this->buildDoctorRecentPatients($entityId, $doctor) : [],
                $has('patients') => $this->buildRecentPatients($entityId),
                default          => [],
            },
            'aiWaiting'       => fn () => $doctor ? $this->buildAiWaiting($entityId, $doctor) : null,
            'unsignedRecords' => fn () => $isDoctor ? $this->buildUnsignedRecords($entityId, $doctor) : null,
            // ── Recepção (secretária) — operação de hoje, no polling ──────────
            // Telefones (ligar para confirmar, lista de espera, aniversariantes)
            // só para quem abre Pacientes; a recepção inteira só com a Agenda.
            'reception' => fn () => $has('confirmations') && $this->can('schedules', $entityId)
                ? $this->operations->reception($entityId, $this->can('patients', $entityId))
                : null,
            'waitlist' => fn () => $has('waitlist') && $this->can('schedules', $entityId)
                ? $this->operations->waitlist($entityId, $this->can('patients', $entityId))
                : null,
            'birthdays' => fn () => $has('birthdays') && $this->can('patients', $entityId)
                ? $this->operations->birthdays($entityId, true)
                : null,
            // ── Gestão (administrador): atendimentos por médico hoje ──────────
            'doctorsToday' => fn () => $isClinicAdmin && $this->can('schedules', $entityId)
                ? $this->operations->doctorsToday($entityId)
                : null,
            // ── Financeiro: caixa de hoje (uma consulta agregada, no polling) ──
            'cashToday' => fn () => $has('finance') && $this->can('financial', $entityId)
                ? $this->insights->cashToday($entityId)
                : null,
            // ── Números de gestão: FORA do polling (abertura + "Atualizar") ────
            'insights' => fn () => $this->buildInsights($request, $entityId, $profile, $doctor),
            // "Configure sua clínica" (passos de ativação): só o ADMINISTRADOR
            // da clínica vê — é ele quem configura. Demais perfis recebem lista
            // vazia (o card some e nada da configuração da clínica é exposto).
            'activation'      => fn () => $isClinicAdmin ? $this->buildActivation($entityId) : [],
            'activationScore' => fn () => $isClinicAdmin ? app(ActivationService::class)->getScore($entityId) : 0,
            // GAP fechado (revisão pós-Fase 4): estoque tinha alerta próprio
            // (Notice + StockAlertService, ver stock:check-alerts) mas
            // nenhuma presença no Dashboard — quem não abre o mural de
            // recados nunca via nada. null quando a clínica não usa o
            // módulo, MESMO critério de visibilidade de
            // App\Support\PanelNavigation (admin OU permission stock.manage,
            // E feature has_inventory_module) — se o menu Estoque não
            // aparece pro usuário, o card também não aparece.
            'stockAlerts' => fn () => $this->buildStockAlerts($entityId),
            // Quais atalhos do Dashboard levam a telas que o usuário pode abrir
            // — mesmas regras do middleware das rotas; antes os botões
            // apareciam para todos e alguns perfis caíam num 403.
            'access' => fn () => $this->buildAccess($entityId),
            't'      => trans('dashboard'),
        ]);
    }

    /**
     * Seções do Dashboard por perfil, na ordem padrão de cada posto de
     * trabalho. Financeiro e usuário não abrem a Agenda e não atendem: nada de
     * agenda nominal nem lista de pacientes (LGPD — minimização).
     *
     * @return list<string>
     */
    private function sectionsFor(ClientRule $profile): array
    {
        return match ($profile) {
            ClientRule::Doctor    => ['next', 'kpis', 'agenda', 'pending', 'patients', 'shortcuts', 'stock'],
            ClientRule::Secretary => ['kpis', 'agenda', 'confirmations', 'waitlist', 'birthdays', 'patients', 'shortcuts', 'stock'],
            ClientRule::Admin     => ['kpis', 'trends', 'agenda', 'shortcuts', 'patients', 'stock'],
            ClientRule::Financial => ['finance', 'kpis', 'trends', 'shortcuts', 'stock'],
            default               => ['kpis', 'shortcuts', 'stock'],
        };
    }

    /**
     * Números de gestão por perfil (cache curto por clínica/médico — ver
     * DashboardInsightsService). Período: mês atual até hoje × o mesmo
     * intervalo do mês anterior. Financeiro só com o Gate ViewFinancial.
     *
     * @return array<string, mixed>|null
     */
    private function buildInsights(Request $request, string $entityId, ClientRule $profile, ?Doctor $doctor): ?array
    {
        $finance = in_array($profile, [ClientRule::Admin, ClientRule::Financial], true) && $this->can('financial', $entityId);

        $wanted = match ($profile) {
            ClientRule::Doctor    => $doctor !== null,
            ClientRule::Admin     => true,
            ClientRule::Financial => $finance,
            default               => false,
        };

        if (! $wanted) {
            return null;
        }

        if ($request->header(self::REFRESH_HEADER) === '1') {
            $this->insights->forget($entityId, $doctor ? (string) $doctor->id : null);
        }

        $base = ['period' => DashboardInsightsService::periods(), 'finance' => $finance];

        if ($profile === ClientRule::Doctor) {
            return [...$base, 'month' => $this->insights->doctorMonth($entityId, (string) $doctor->id)];
        }

        $month = $this->insights->clinicMonth($entityId, $finance);

        if ($profile === ClientRule::Admin) {
            return [
                ...$base,
                'month'       => $month,
                'daily'       => $this->insights->daily($entityId),
                'trend'       => $finance ? $this->insights->financeTrend($entityId) : null,
                'receivables' => $finance ? $this->insights->receivables($entityId) : null,
            ];
        }

        return [
            ...$base,
            'month'       => $month,
            'trend'       => $this->insights->financeTrend($entityId),
            'receivables' => $this->insights->receivables($entityId),
            'glosas'      => $this->insights->glosas($entityId),
        ];
    }

    private function loggedDoctor(string $entityId): ?Doctor
    {
        $entityUserId = session('selected_entity_user_id');

        if (! $entityUserId) {
            return null;
        }

        return Doctor::query()
            ->select('doctors.*')
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('doctors.entity_user_id', $entityUserId)
            ->where('entity_users.entity_id', $entityId)
            ->first();
    }

    /**
     * Espelha o middleware de cada rota de destino:
     *  - agenda: entity.role:admin,doctor,secretary;
     *  - pacientes: permission:patients.manage,admin,financial,doctor,secretary;
     *  - médicos: permission:patients.manage,admin,financial,secretary;
     *  - financeiro (caixa e faturamento TISS): a rota aceita
     *    permission:financial.manage, mas os controllers ainda exigem o Gate
     *    ViewFinancial (perfil fixo admin/financeiro) — vale a regra efetiva;
     *  - Gerenciador de Imagens: sem restrição de perfil (rota e menu abertos
     *    a todo membro da clínica).
     *
     * @return array{schedules: bool, patients: bool, doctors: bool, financial: bool, eye_images: bool}
     */
    private function buildAccess(string $entityId): array
    {
        return [
            'schedules'  => $this->can('schedules', $entityId),
            'patients'   => $this->can('patients', $entityId),
            'doctors'    => $this->can('doctors', $entityId),
            'financial'  => $this->can('financial', $entityId),
            'eye_images' => auth()->check() && $this->entity($entityId) !== null,
        ];
    }

    /**
     * Uma regra de acesso de buildAccess(), resolvida uma vez por requisição
     * (os blocos da recepção/financeiro usam as mesmas regras para decidir o
     * que montar).
     */
    private function can(string $what, string $entityId): bool
    {
        return $this->allowed[$what] ??= (function () use ($what, $entityId): bool {
            $user   = auth()->user();
            $entity = $this->entity($entityId);

            if (! $user || ! $entity) {
                return false;
            }

            $byPermission = fn (Permission $permission, array $roles): bool => $user->hasPermissionInEntity($entity, $permission)
                || $user->hasAnyRoleInEntity($entity, $roles);

            return match ($what) {
                'schedules' => $user->hasAnyRoleInEntity($entity, [ClientRule::Admin, ClientRule::Doctor, ClientRule::Secretary]),
                'patients'  => $byPermission(Permission::PatientsManage, [ClientRule::Admin, ClientRule::Financial, ClientRule::Doctor, ClientRule::Secretary]),
                'doctors'   => $byPermission(Permission::PatientsManage, [ClientRule::Admin, ClientRule::Financial, ClientRule::Secretary]),
                'financial' => Gate::forUser($user)->allows(EntityGate::ViewFinancial->value, $entity),
                default     => false,
            };
        })();
    }

    private function entity(string $entityId): ?Entity
    {
        return $this->entity ??= Entity::find($entityId);
    }

    private function buildStockAlerts(string $entityId): ?array
    {
        $rule = session('selected_entity_user_rule');
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $isAdmin       = $rule === ClientRule::Admin->value;
        $entity        = $this->entity($entityId);
        $hasPermission = $entity && $user->hasPermissionInEntity($entity, Permission::StockManage);
        $hasFeature    = app(FeatureGateService::class)->can($entityId, FeatureKey::HasInventoryModule);

        if (! $entity || ! ($isAdmin || $hasPermission) || ! $hasFeature) {
            return null;
        }

        $belowMinimumCount = EntityProduct::where('entity_id', $entityId)->active()->belowMinimum()->count();
        $expiringLotsCount = EntityProduct::where('entity_id', $entityId)->active()->withExpiringLots(30)->count();

        if ($belowMinimumCount === 0 && $expiringLotsCount === 0) {
            return null; // nada crítico — card nem aparece, sem "0 alertas" vazio ocupando espaço
        }

        return [
            'below_minimum_count' => $belowMinimumCount,
            'expiring_lots_count' => $expiringLotsCount,
            // "Ver estoque" do cabeçalho: lista sem filtro (o alerta pode ser
            // só de validade); cada linha do card leva ao filtro dela.
            'list_url'     => route('panel.stock.products.index'),
            'products_url' => route('panel.stock.products.index', ['low_stock' => 1]),
            'expiring_url' => route('panel.stock.products.index', ['expiring_lots' => 1]),
        ];
    }

    /**
     * Indicadores da clínica (perfis não médicos) — só contagens, nenhum dado
     * de paciente. Cada perfil recebe o que os cards dele mostram.
     */
    private function buildStats(string $entityId, ClientRule $profile): array
    {
        $stats = [
            'entity_name'    => $this->entity($entityId)?->name ?? config('app.name'),
            'total_patients' => Patient::where('entity_id', $entityId)->where('active', true)->count(),
            ...$this->todayCounts(Schedule::query()->where('schedules.entity_id', $entityId)),
        ];

        if ($profile !== ClientRule::User) {
            $stats['total_doctors'] = Doctor::query()
                ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                ->where('entity_users.entity_id', $entityId)
                ->where('doctors.active', true)
                ->count();
        }

        if ($profile !== ClientRule::Financial) {
            // Exames do Gerenciador de Imagens dos últimos 30 dias ainda sem
            // laudo (manual, conjunto ou IA aprovada). Janela e critério iguais
            // ao filtro "Últimos 30 dias" + "Sem laudo" do módulo, para o número
            // do card bater com a lista que ele abre.
            $stats['exams_pending'] = $this->pendingExamsQuery($entityId)->count();
        }

        return $stats;
    }

    /**
     * Indicadores do médico: SÓ a agenda e os exames dele (nada da clínica
     * inteira — total de pacientes/médicos ficam de fora).
     */
    private function buildDoctorStats(string $entityId, ?Doctor $doctor): array
    {
        $entityName = $this->entity($entityId)?->name ?? config('app.name');

        if (! $doctor) {
            return [
                'entity_name'     => $entityName,
                'doctor_id'       => null,
                'today_count'     => 0,
                'attended_today'  => 0,
                'pending_today'   => 0,
                'cancelled_today' => 0,
                'waiting_now'     => 0,
                'exams_pending'   => 0,
            ];
        }

        return [
            'entity_name' => $entityName,
            // Id do próprio médico: o card "Exames sem laudo" abre o Gerenciador
            // de Imagens já filtrado por ele (doctor_id).
            'doctor_id' => (string) $doctor->id,
            ...$this->todayCounts(Schedule::query()
                ->where('schedules.entity_id', $entityId)
                ->where('schedules.doctor_id', $doctor->id)),
            'exams_pending' => $this->pendingExamsQuery($entityId)
                ->where('patient_exams.doctor_id', $doctor->id)
                ->count(),
        ];
    }

    /**
     * Contagens do dia numa consulta só (o Dashboard faz polling a cada 30s):
     * total, atendidos, em andamento/aguardando, faltas+cancelados e quem
     * chegou e espera agora (Aguardando, Retornando, Dilatando, Em exame).
     *
     * @return array{today_count: int, attended_today: int, pending_today: int, cancelled_today: int, waiting_now: int}
     */
    private function todayCounts(Builder $query): array
    {
        $attended = ScheduleSituation::Attended->value;
        $closed   = implode(',', [ScheduleSituation::NoShow->value, ScheduleSituation::Cancelled->value]);
        $waiting  = implode(',', array_map(
            fn (ScheduleSituation $s) => $s->value,
            [...self::READY_SITUATIONS, ...self::PREPARING_SITUATIONS],
        ));

        $row = $query
            ->whereBetween('schedules.date_time', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN schedules.situation = {$attended} THEN 1 ELSE 0 END) AS attended")
            ->selectRaw("SUM(CASE WHEN schedules.situation IN ({$closed}) THEN 1 ELSE 0 END) AS closed")
            ->selectRaw("SUM(CASE WHEN schedules.situation IN ({$waiting}) AND schedules.arrived_at IS NOT NULL THEN 1 ELSE 0 END) AS waiting")
            ->toBase()
            ->first();

        $total     = (int) ($row->total ?? 0);
        $attended  = (int) ($row->attended ?? 0);
        $cancelled = (int) ($row->closed ?? 0);

        return [
            'today_count'     => $total,
            'attended_today'  => $attended,
            'pending_today'   => $total - $attended - $cancelled,
            'cancelled_today' => $cancelled,
            'waiting_now'     => (int) ($row->waiting ?? 0),
        ];
    }

    /** Exames da clínica dos últimos 30 dias ainda sem laudo (PatientExam::scopePendingReport). */
    private function pendingExamsQuery(string $entityId): Builder
    {
        return PatientExam::query()
            ->whereIn('patient_exams.patient_id', Patient::where('entity_id', $entityId)->select('id'))
            ->where('patient_exams.created_at', '>=', now()->subDays(self::EXAMS_PENDING_DAYS)->startOfDay())
            ->pendingReport($entityId);
    }

    private function buildRecentPatients(string $entityId): array
    {
        return Patient::with('person')
            ->where('entity_id', $entityId)
            ->where('active', true)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn ($p) => [
                ...$this->patientRow($p),
                'phone' => BrazilianFormat::phone($p->person?->cellphone ?: $p->person?->telephone) ?? '—',
            ])
            ->values()
            ->toArray();
    }

    /**
     * Pacientes do médico: os últimos distintos com consulta DELE (por data da
     * consulta, sem faltas/cancelamentos). Sem telefone — o painel do médico
     * não precisa dele (minimização); o cadastro completo segue a um clique.
     */
    private function buildDoctorRecentPatients(string $entityId, Doctor $doctor): array
    {
        $visits = Schedule::query()
            ->where('schedules.entity_id', $entityId)
            ->where('schedules.doctor_id', $doctor->id)
            ->whereNotNull('schedules.patient_id')
            ->where('schedules.date_time', '<=', now())
            ->whereNotIn('schedules.situation', [ScheduleSituation::NoShow->value, ScheduleSituation::Cancelled->value])
            ->groupBy('schedules.patient_id')
            ->selectRaw('schedules.patient_id, MAX(schedules.date_time) AS last_visit')
            ->orderByDesc('last_visit')
            ->limit(8)
            ->toBase()
            ->get();

        if ($visits->isEmpty()) {
            return [];
        }

        $patients = Patient::with('person')
            ->where('entity_id', $entityId)
            ->where('active', true)
            ->whereIn('id', $visits->pluck('patient_id'))
            ->get()
            ->keyBy('id');

        return $visits
            ->filter(fn ($v) => $patients->has($v->patient_id))
            ->map(fn ($v) => [
                ...$this->patientRow($patients[$v->patient_id]),
                'last_visit' => $this->localDate($v->last_visit),
            ])
            ->values()
            ->toArray();
    }

    private function patientRow(Patient $p): array
    {
        return [
            'id'      => $p->id,
            'name'    => $p->person?->full_name ?? '—',
            'code'    => $p->code,
            'initial' => mb_strtoupper(mb_substr($p->person?->full_name ?? '?', 0, 1)),
            'color'   => '#' . substr(md5($p->person?->full_name ?? '?'), 0, 6),
            // Abre o cadastro na lista (deep-link ?open=, como a Agenda);
            // panel.patients.show fora de JSON só redireciona para a lista.
            'url' => route('panel.patients.index', ['open' => $p->id]),
        ];
    }

    /** Agenda de hoje — da clínica, ou só do médico (sem a coluna "médico"). */
    private function buildScheduleToday(string $entityId, string $today, ?Doctor $doctor = null): array
    {
        $query = Schedule::query()
            ->select([
                'schedules.id',
                'schedules.date_time',
                'schedules.full_name',
                'schedules.situation',
                'schedules.arrived_at',
                // Linha de apoio sob o nome (tipo de consulta · convênio):
                // a linha deixa de ser só "nome ... situação".
                'visit_types.name as visit_name',
                'covenants.name as covenant_name',
            ])
            ->leftJoin('visit_types', 'visit_types.id', '=', 'schedules.visit_id')
            ->leftJoin('covenants', 'covenants.id', '=', 'schedules.covenant_id')
            ->where('schedules.entity_id', $entityId)
            ->whereDate('schedules.date_time', $today)
            ->whereNull('schedules.deleted_at')
            ->orderBy('schedules.date_time')
            // Separada em abas por turno no front: com 25 linhas a tarde sumia
            // numa clínica cheia. Teto ainda limita o payload do polling (30s).
            ->limit(self::SCHEDULE_TODAY_LIMIT);

        if ($doctor) {
            $query->where('schedules.doctor_id', $doctor->id);
        } else {
            $query->addSelect('users.name as doctor_name')
                ->leftJoin('doctors', 'doctors.id', '=', 'schedules.doctor_id')
                ->leftJoin('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                ->leftJoin('users', 'users.id', '=', 'entity_users.user_id');
        }

        return $query->get()
            ->map(function ($s) use ($doctor) {
                $sit = $this->situationOf($s);

                $at = Carbon::parse($s->date_time);

                return [
                    'id'   => $s->id,
                    'time' => $this->localTime($s->date_time),
                    // Turno e hora para as abas/grupos do card (mesmos limites
                    // da Agenda — ver self::shiftOf()).
                    'shift'      => self::shiftOf($at->hour),
                    'hour'       => $at->hour,
                    'hour_label' => $this->localTime($at->copy()->startOfHour()),
                    // Mesmo agrupamento dos números do resumo do dia (buildStats).
                    'group' => self::summaryGroupOf($sit),
                    'name'  => $s->full_name ?? '—',
                    ...($doctor ? [] : ['doctor' => $s->doctor_name ?? '—']),
                    'visit'        => $s->visit_name,
                    'covenant'     => $s->covenant_name,
                    'arrived_time' => $this->localTime($s->arrived_at),
                    'situation'    => $sit?->value,
                    'label'        => $sit?->label() ?? '—',
                    'badge'        => $sit?->badgeClass() ?? 'bg-secondary',
                    'icon'         => $sit?->icon() ?? 'fa-circle',
                    'arrived'      => ! is_null($s->arrived_at),
                    'is_active'    => $sit?->isActive() ?? false,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Próximo paciente do médico, hoje:
     *  1. quem já chegou e espera — primeiro os prontos para ele (Aguardando,
     *     Retornando à consulta), depois os em preparo (Dilatando, Em exame);
     *     em cada grupo, quem chegou primeiro;
     *  2. ninguém esperando: o próximo horário Agendado/Confirmado a partir de agora.
     */
    private function buildNextPatient(string $entityId, Doctor $doctor): ?array
    {
        $base = fn () => Schedule::query()
            ->select(['schedules.id', 'schedules.patient_id', 'schedules.full_name', 'schedules.date_time', 'schedules.situation', 'schedules.arrived_at'])
            ->where('schedules.entity_id', $entityId)
            ->where('schedules.doctor_id', $doctor->id)
            ->whereBetween('schedules.date_time', [now()->startOfDay(), now()->endOfDay()]);

        $ready = implode(',', array_map(fn (ScheduleSituation $s) => $s->value, self::READY_SITUATIONS));

        $waiting = $base()
            ->whereNotNull('schedules.arrived_at')
            ->whereIn('schedules.situation', array_map(
                fn (ScheduleSituation $s) => $s->value,
                [...self::READY_SITUATIONS, ...self::PREPARING_SITUATIONS],
            ))
            ->orderByRaw("CASE WHEN schedules.situation IN ({$ready}) THEN 0 ELSE 1 END")
            ->orderBy('schedules.arrived_at')
            ->orderBy('schedules.date_time')
            ->first();

        $schedule = $waiting ?? $base()
            ->whereIn('schedules.situation', [ScheduleSituation::Scheduled->value, ScheduleSituation::Confirmed->value])
            ->where('schedules.date_time', '>=', now())
            ->orderBy('schedules.date_time')
            ->first();

        if (! $schedule) {
            return null;
        }

        $sit     = $this->situationOf($schedule);
        $isReady = $waiting && in_array($sit, self::READY_SITUATIONS, true);

        return [
            'id'              => $schedule->id,
            'name'            => $schedule->full_name ?? '—',
            'state'           => $waiting ? 'waiting' : 'scheduled',
            'time'            => $this->localTime($schedule->date_time),
            'arrived_time'    => $waiting ? $this->localTime($schedule->arrived_at) : null,
            'waiting_minutes' => $waiting ? max(0, (int) Carbon::parse($schedule->arrived_at)->diffInMinutes(now())) : null,
            'label'           => $sit?->label() ?? '—',
            'badge'           => $sit?->badgeClass() ?? 'bg-secondary',
            'icon'            => $sit?->icon() ?? 'fa-circle',
            // "Iniciar atendimento" abre o prontuário do agendamento (mesma ação
            // da Agenda do médico) — só para quem está pronto para ele.
            'attend_url'   => $isReady && $schedule->patient_id ? route('panel.schedules.attend', $schedule->id) : null,
            'patient_url'  => $schedule->patient_id ? route('panel.patients.index', ['open' => $schedule->patient_id]) : null,
            'schedule_url' => route('panel.schedules.index'),
        ];
    }

    /**
     * Laudos de IA aguardando aprovação que são do médico: pedidos por ele OU
     * de prontuário/exame dele (a aprovação é de qualquer médico da clínica —
     * Gate IssueReport em AiRunsController::approve —, mas a pendência é de
     * quem pediu ou de quem é o caso). null quando a clínica não tem IA: sem o
     * recurso, a aprovação dá 403 (AiRunsController::assertAiFeatureEnabled).
     *
     * @return array{count: int, items: list<array>, list_url: string}|null
     */
    private function buildAiWaiting(string $entityId, Doctor $doctor): ?array
    {
        $gate     = app(FeatureGateService::class);
        $features = [FeatureKey::HasAiExamAssistant, FeatureKey::HasAiReportDrafting, FeatureKey::HasAiEyeImageAnalysis, FeatureKey::HasAiChatAssistant];

        if (! collect($features)->contains(fn (FeatureKey $f) => $gate->can($entityId, $f))) {
            return null;
        }

        $userId = (string) auth()->id();

        $query = AiRun::query()
            ->where('ai_runs.entity_id', $entityId)
            ->where('ai_runs.status', AiRunStatus::WaitingApproval->value)
            ->whereIn('ai_runs.workflow', self::REVIEWABLE_AI_WORKFLOWS)
            ->where(fn (Builder $q) => $q
                ->where('ai_runs.requested_by', $userId)
                ->orWhereIn('ai_runs.medical_record_id', MedicalRecord::query()
                    ->where('medical_records.entity_id', $entityId)
                    ->where('medical_records.doctor_id', $doctor->id)
                    ->select('medical_records.id'))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('ai_run_patient_exam as arpe')
                    ->join('patient_exams as pe', 'pe.id', '=', 'arpe.patient_exam_id')
                    ->whereColumn('arpe.ai_run_id', 'ai_runs.id')
                    ->where('arpe.entity_id', $entityId)
                    ->where('pe.doctor_id', $doctor->id)));

        $listUrl = route('panel.ai-runs.index', ['status' => AiRunStatus::WaitingApproval->value]);

        $items = (clone $query)
            ->with(['patient.person:id,full_name', 'exams:patient_exams.id,patient_exams.patient_id'])
            ->orderByDesc('ai_runs.created_at')
            ->limit(self::PENDING_LIST_LIMIT)
            ->get()
            ->map(function (AiRun $run) use ($listUrl) {
                $exam = $run->exams->first();

                return [
                    'id'       => $run->id,
                    'patient'  => $run->patient?->person?->full_name ?? $run->patient?->code ?? '—',
                    'workflow' => __('ai.workflow_' . $run->workflow),
                    'created'  => $this->localDateTime($run->created_at),
                    // Laudo de imagem: abre o exame no Gerenciador de Imagens
                    // (reviewTarget), onde o médico revisa e aprova; os demais,
                    // a tela de IA filtrada por "Aguardando aprovação".
                    'url' => $exam && $run->patient_id
                        ? route('panel.eye-images.index', ['patient_id' => $run->patient_id, 'exam_id' => $exam->id])
                        : $listUrl,
                ];
            })
            ->values()
            ->all();

        return [
            'count'    => $query->count(),
            'items'    => $items,
            'list_url' => $listUrl,
        ];
    }

    /**
     * Prontuários do médico ainda não assinados (Signable: signed_at nulo e
     * is_locked falso), dos últimos 30 dias, excluídos fora.
     *
     * @return array{count: int, items: list<array>, days: int}
     */
    private function buildUnsignedRecords(string $entityId, ?Doctor $doctor): array
    {
        if (! $doctor) {
            return ['count' => 0, 'items' => [], 'days' => self::UNSIGNED_RECORDS_DAYS];
        }

        $query = MedicalRecord::query()
            ->where('medical_records.entity_id', $entityId)
            ->where('medical_records.doctor_id', $doctor->id)
            ->whereNull('medical_records.signed_at')
            ->where('medical_records.is_locked', false)
            ->where('medical_records.created_at', '>=', now()->subDays(self::UNSIGNED_RECORDS_DAYS)->startOfDay());

        $items = (clone $query)
            ->with('patient.person:id,full_name')
            ->orderByDesc('medical_records.created_at')
            ->limit(self::PENDING_LIST_LIMIT)
            ->get(['medical_records.id', 'medical_records.patient_id', 'medical_records.code', 'medical_records.created_at'])
            ->map(fn (MedicalRecord $r) => [
                'id'      => $r->id,
                'patient' => $r->patient?->person?->full_name ?? $r->patient?->code ?? '—',
                'code'    => $r->code,
                'date'    => $this->localDateTime($r->created_at),
                'url'     => route('panel.patients.medicalrecords.edit', [$r->patient_id, $r->id]),
            ])
            ->values()
            ->all();

        return [
            'count' => $query->count(),
            'items' => $items,
            'days'  => self::UNSIGNED_RECORDS_DAYS,
        ];
    }

    private function situationOf(Schedule $s): ?ScheduleSituation
    {
        return $s->situation instanceof ScheduleSituation
            ? $s->situation
            : ScheduleSituation::tryFrom((int) ($s->situation ?? 0));
    }

    /**
     * Grupo do resumo do dia: atendido; cancelado/faltou; pendente (todo o
     * resto, inclusive situação desconhecida) — mesma divisão de
     * attended_today / cancelled_today / pending_today.
     */
    public static function summaryGroupOf(?ScheduleSituation $situation): string
    {
        return match ($situation) {
            ScheduleSituation::Attended => 'attended',
            ScheduleSituation::NoShow, ScheduleSituation::Cancelled => 'cancelled',
            default => 'pending',
        };
    }

    /**
     * Turno pelo horário da consulta — mesmos limites do filtro de turno da
     * Agenda (SchedulesController::buildScheduleItems, `bout` 2/3/4): manhã
     * antes das 13h, tarde das 13h às 18h, noite a partir das 18h.
     */
    public static function shiftOf(int $hour): string
    {
        return match (true) {
            $hour < 13 => 'morning',
            $hour < 18 => 'afternoon',
            default    => 'evening',
        };
    }

    private function localTime(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->locale(app()->getLocale())->isoFormat('LT') : null;
    }

    private function localDate(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->locale(app()->getLocale())->isoFormat('L') : null;
    }

    private function localDateTime(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->locale(app()->getLocale())->isoFormat('L LT') : null;
    }

    private function buildActivation(string $entityId): array
    {
        return array_map(
            fn ($s) => [
                'key'      => $s['step'],
                'label'    => $s['label'],
                'done'     => $s['completed'],
                'weight'   => $s['weight'],
                'required' => $s['required'],
            ],
            app(ActivationService::class)->getProgress($entityId),
        );
    }
}
