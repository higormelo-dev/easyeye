<?php

namespace App\Models;

use App\Enums\{CashEntryReferenceType, MedicalSpecialty, PatientMood, ScheduleAttendanceType, ScheduleSituation};
use App\Models\Concerns\BelongsToEntity;
use App\Models\WhatsApp\WhatsAppMessage;
use App\Presenters\SchedulePresenter;
use App\Support\Database\UniqueViolation;
use App\Traits\{Auditable, HasAuditColumns};
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\{Builder, Casts\Attribute, Collection, Model, Relations\BelongsTo, Relations\BelongsToMany, Relations\HasMany, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Laracasts\Presenter\PresentableTrait;

class Schedule extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use PresentableTrait;
    use SoftDeletes;

    protected $presenter = SchedulePresenter::class;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_id',
        'doctor_id',
        'patient_id',
        'covenant_id',
        'visit_id',
        'attendance_type',
        'specialty_area',
        'code',
        'full_name',
        'date_time',
        'telephone',
        'cellphone',
        'cellphone_whatsapp',
        'notes',
        'cancellation_reason',
        'situation',
        'arrived_at',
        'confirmed_at',
        'patient_mood',
        'active',
        'recurrence_group_id',
        'recurrence_type',
        'recurrence_until',
        'is_fit_in',
        'origin',
        'series_id',
        'session_number',
        'session_total',
    ];

    /** Prefixo do código sequencial por clínica (SDL-0000000042). */
    public const CODE_PREFIX = 'SDL';

    /**
     * `origin` do agendamento criado pelo "Atender" do pronto atendimento
     * (App\Services\UrgentCare\UrgentCareService). null = agenda normal.
     * Não ocupa horário da grade nem conta no teto de encaixes.
     */
    public const ORIGIN_URGENT_CARE = 'urgent_care';

    /**
     * Índice único parcial (doctor_id, date_time) de agendamentos ativos — ver
     * migration 2026_03_24_000010_fix_schedules_unique_index_exclude_terminals.
     * Só a violação DELE significa "horário ocupado".
     */
    public const DOCTOR_SLOT_UNIQUE_INDEX = 'schedules_doctor_datetime_active_unique';

    /** Namespace da chave do advisory lock da numeração SDL (PostgreSQL). */
    private const CODE_LOCK_NAMESPACE = 'schedule_code';

    private const PATIENT_DAY_LOCK_NAMESPACE = 'schedule_patient_day';

    /**
     * Generated code for the entity_id field.
     */
    protected static function booted(): void
    {
        static::creating(function (self $schedule) {
            if (blank($schedule->code)) {
                $schedule->assignNextCode();
            }
        });

        // Remarcou (data/hora mudou): a confirmação do WhatsApp com a data
        // antiga deixa de valer — o comando manda outra com a nova data.
        static::updated(function (self $schedule) {
            if ($schedule->wasChanged('date_time')) {
                WhatsAppMessage::supersedeConfirmationsOf((string) $schedule->id);
            }
        });
    }

    /**
     * INSERT sempre dentro de uma transação: o lock da numeração
     * (pg_advisory_xact_lock, tomado no `creating`) só é liberado no COMMIT,
     * então o próximo agendamento da mesma clínica já enxerga o código gravado.
     * Dentro de uma transação do chamador (ex.: import, uma por linha) vira
     * SAVEPOINT e o lock vale até o commit externo. Update não muda.
     *
     * @param array<string, mixed> $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    /**
     * Próximo SDL da clínica, serializado por clínica.
     *
     * Antes: lia o último código e somava 1 sem lock — dois agendamentos
     * simultâneos (duas recepcionistas, ou import + tela) recebiam o MESMO
     * código em silêncio (não há índice único em `code`), e a API de
     * integradores passava a resolver o número para um agendamento arbitrário.
     *
     * PostgreSQL: advisory lock de transação por clínica — só quem cria
     * agendamento nesta clínica espera; demais drivers: lock na linha da
     * entidade (mesmo padrão de OpenGlosaAppealAction).
     */
    private function assignNextCode(): void
    {
        $entityId   = (string) $this->entity_id;
        $connection = $this->getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('select pg_advisory_xact_lock(?)', [static::codeLockKey($entityId)]);
        } else {
            Entity::query()->withoutGlobalScopes()->whereKey($entityId)->lockForUpdate()->first();
        }

        // withoutGlobalScopes: conta também os excluídos por soft delete (o código deles não é reaproveitado).
        $lastCode = static::withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('code', 'like', self::CODE_PREFIX . '-%')
            ->orderBy('code', 'desc')
            ->value('code');

        $newNumber = $lastCode !== null
            ? ((int) substr($lastCode, strlen(self::CODE_PREFIX) + 1)) + 1
            : 1;

        $this->code = static::formatCode($newNumber);
    }

    /**
     * Chave bigint estável por clínica para o advisory lock da numeração
     * (60 bits do sha1, sempre positiva). Colisão entre clínicas só
     * serializaria as duas — nunca mistura números. Pública para os testes de
     * concorrência disputarem o mesmo lock.
     */
    public static function codeLockKey(string $entityId): int
    {
        return (int) hexdec(substr(sha1(self::CODE_LOCK_NAMESPACE . '|' . $entityId), 0, 15));
    }

    /**
     * Serializa, até o fim da transação corrente, tudo que decide o vínculo
     * "este paciente foi atendido neste dia": a transição de um agendamento
     * para Atendido (que reconcilia capturas órfãs do paciente/dia —
     * ExternalExamImportService::relinkBrowserCapturesForSchedule) e a
     * importação de captura (ExternalExamImportService::import).
     *
     * REGRA DE ORDEM (achado via revisão adversarial Codex): este lock sai
     * ANTES de qualquer lock de linha de agendamento da transação. A
     * reconciliação trava TODOS os agendamentos do paciente naquele dia;
     * duas transições simultâneas, cada uma já segurando a linha do PRÓPRIO
     * agendamento, esperavam a do outro — deadlock, e o PostgreSQL abortava
     * uma delas (um "Atendido" válido virava erro). Com este lock primeiro,
     * quem chega depois espera sem segurar linha nenhuma.
     *
     * PostgreSQL: advisory lock de transação (só quem disputa o MESMO
     * paciente/dia espera); demais drivers: lock na linha do paciente.
     */
    public static function lockPatientDay(string $entityId, string $patientId, DateTimeInterface|string $day): void
    {
        $connection = (new static())->getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('select pg_advisory_xact_lock(?)', [static::patientDayLockKey($entityId, $patientId, $day)]);

            return;
        }

        Patient::query()->withoutGlobalScopes()->whereKey($patientId)->where('entity_id', $entityId)->lockForUpdate()->first();
    }

    /**
     * Vários locks paciente/dia de uma vez, em ORDEM DETERMINÍSTICA (pela chave).
     * Quem muda um agendamento Atendido de paciente ou de dia precisa travar a
     * identidade de ORIGEM e a de DESTINO antes de qualquer linha: a
     * reconciliação das capturas trava todos os agendamentos do paciente/dia
     * pela identidade já comitada, então duas edições em sentidos opostos
     * (X→Y e Y→X), cada uma só com o lock do próprio destino, seguravam a linha
     * do PRÓPRIO agendamento e esperavam a do outro — deadlock (achado via
     * revisão adversarial Codex). Na mesma ordem em todo mundo, a segunda
     * espera a primeira terminar, sem segurar linha nenhuma.
     *
     * @param list<array{0: ?string, 1: DateTimeInterface|string|null}> $patientDays pares [paciente, dia]; par sem paciente ou sem dia é ignorado
     *
     * @return list<int> as chaves travadas, em ordem
     */
    public static function lockPatientDays(string $entityId, array $patientDays): array
    {
        $pairs = static::patientDayPairs($entityId, $patientDays);

        foreach ($pairs as [$patientId, $day]) {
            static::lockPatientDay($entityId, $patientId, $day);
        }

        return array_keys($pairs);
    }

    /**
     * Os pares [paciente, dia] sem repetição e ordenados pela chave do lock.
     *
     * @param list<array{0: ?string, 1: DateTimeInterface|string|null}> $patientDays
     *
     * @return array<int, array{0: string, 1: DateTimeInterface|string}> chave => [paciente, dia]
     */
    public static function patientDayPairs(string $entityId, array $patientDays): array
    {
        $byKey = [];

        foreach ($patientDays as [$patientId, $day]) {
            if ($patientId === null || $day === null) {
                continue;
            }

            $byKey[static::patientDayLockKey($entityId, (string) $patientId, $day)] = [(string) $patientId, $day];
        }

        ksort($byKey);

        return $byKey;
    }

    /**
     * Chave bigint estável do lock paciente/dia (60 bits do sha1, sempre
     * positiva). Colisão só serializaria dois paciente/dia — nunca mistura
     * vínculos. Pública para os testes disputarem o mesmo lock.
     */
    public static function patientDayLockKey(string $entityId, string $patientId, DateTimeInterface|string $day): int
    {
        $date = Carbon::parse($day)->toDateString();

        return (int) hexdec(substr(sha1(self::PATIENT_DAY_LOCK_NAMESPACE . '|' . $entityId . '|' . $patientId . '|' . $date), 0, 15));
    }

    /**
     * Chave do lock paciente/dia DESTE agendamento: a identidade (clínica,
     * paciente, dia) com que ele se reconcilia com as capturas ao virar
     * Atendido. Null = sem paciente ou sem data — nada a travar nem a
     * reconciliar. Compara-se a chave de ANTES e de DEPOIS de reler a linha
     * travada para saber se o lock tomado ainda é o certo.
     */
    public function attendanceLockKey(): ?int
    {
        if ($this->patient_id === null || $this->date_time === null) {
            return null;
        }

        return static::patientDayLockKey((string) $this->entity_id, (string) $this->patient_id, $this->date_time);
    }

    public static function formatCode(int $number): string
    {
        return sprintf('%s-%010d', self::CODE_PREFIX, $number);
    }

    /**
     * Agendamentos da clínica que casam com um identificador externo (API de
     * integradores): UUID, código (SDL-0000000042 ou sdl-42), número puro (42)
     * ou `import_code` do sistema anterior.
     *
     * Nunca escolhe entre agendamentos diferentes: devolve no máximo 2 —
     * vazio = não encontrado, 1 = resolvido, 2 = AMBÍGUO (o chamador recusa,
     * senão o exame cairia no paciente de outro agendamento).
     *  - UUID: só `id`.
     *  - Código explícito (SDL-N ou qualquer texto): `code` exato tem
     *    precedência; `import_code` só é consultado se nenhum `code` casar.
     *  - Número puro: vale tanto como SDL-N quanto como import_code numérico de
     *    sistema legado; se os dois apontarem para agendamentos diferentes, é
     *    ambíguo.
     *
     * @param list<string> $with relações para eager loading
     *
     * @return Collection<int, self>
     */
    public static function identifierMatches(string $entityId, string $identifier, array $with = []): Collection
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return new Collection();
        }

        $scoped = static fn (): Builder => static::query()->with($with)->where('entity_id', $entityId);

        if (Str::isUuid($identifier)) {
            return $scoped()->whereKey($identifier)->limit(1)->get();
        }

        if (ctype_digit($identifier)) {
            $code = static::formatCode((int) $identifier);

            return $scoped()
                ->where(fn (Builder $query) => $query->where('code', $code)->orWhere('import_code', $identifier))
                ->limit(2)
                ->get();
        }

        $code = preg_match('/^' . self::CODE_PREFIX . '-(\d{1,10})$/i', $identifier, $match) === 1
            ? static::formatCode((int) $match[1])
            : $identifier;

        $byCode = $scoped()->where('code', $code)->limit(2)->get();

        return $byCode->isNotEmpty()
            ? $byCode
            : $scoped()->where('import_code', $identifier)->limit(2)->get();
    }

    /**
     * A violação de unicidade no INSERT/UPDATE do agendamento é o conflito de
     * horário do médico? Olha só a mensagem do DRIVER (errorInfo — sem SQL nem
     * bindings): PostgreSQL traz o nome do índice; SQLite traz as colunas.
     * Qualquer outra violação NÃO pode virar "horário ocupado".
     */
    public static function isDoctorSlotConflict(UniqueConstraintViolationException $e): bool
    {
        return UniqueViolation::violates($e, self::DOCTOR_SLOT_UNIQUE_INDEX)
            || UniqueViolation::violatesColumns($e, 'schedules', 'doctor_id', 'date_time');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'situation'       => ScheduleSituation::class,
            'patient_mood'    => PatientMood::class,
            'attendance_type' => ScheduleAttendanceType::class,
            'specialty_area'  => MedicalSpecialty::class,
            'arrived_at'      => 'datetime',
            'confirmed_at'    => 'datetime',
            'date_time'       => 'datetime',
            'is_fit_in'       => 'boolean',
            'session_number'  => 'integer',
            'session_total'   => 'integer',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
        ];
    }

    /**
     * Scope route model binding to the current entity (tenant guard).
     * Prevents users of one clinic from accessing another clinic's schedules
     * via URL manipulation.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $query = static::where($field ?? $this->getRouteKeyName(), $value);

        if ($entityId = session('selected_entity_id')) {
            $query->where('entity_id', $entityId);
        }

        return $query->firstOrFail();
    }

    /** Atendimento aberto pelo pronto atendimento (fora da grade). */
    public function isUrgentCare(): bool
    {
        return $this->origin === self::ORIGIN_URGENT_CARE;
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class, 'covenant_id');
    }

    public function visitType(): BelongsTo
    {
        return $this->belongsTo(VisitType::class, 'visit_id');
    }

    public function situationLogs(): HasMany
    {
        return $this->hasMany(ScheduleSituationLog::class)->orderBy('created_at');
    }

    /**
     * Mensagens WhatsApp (confirmação/pesquisa) vinculadas a este agendamento.
     */
    public function whatsappMessages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'schedule_id');
    }

    /**
     * Lançamentos de caixa vinculados a este agendamento.
     * O vínculo é polimórfico via reference_type/reference_id no modelo
     * FinancialCashEntry (tipo CashEntryReferenceType::Schedule) e só conta
     * lançamento da MESMA clínica do agendamento — ver
     * FinancialCashEntry::scopeReferencingSameEntity().
     */
    public function financialEntries(): HasMany
    {
        return $this->hasMany(FinancialCashEntry::class, 'reference_id')
            ->referencingSameEntity(CashEntryReferenceType::Schedule);
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(
            ClinicResource::class,
            'schedule_resources',
            'schedule_id',
            'resource_id',
        )->using(ScheduleResource::class)->withTimestamps();
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value) => $value !== null
                ? mb_convert_case($value, MB_CASE_UPPER, 'UTF-8')
                : null,
        );
    }
}
