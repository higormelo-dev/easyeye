<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Presenters\PatientPresenter;
use App\Support\Database\UniqueViolation;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Laracasts\Presenter\PresentableTrait;

class Patient extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use PresentableTrait;
    use SoftDeletes;

    protected $presenter = PatientPresenter::class;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_id',
        'person_id',
        'covenant_id',
        'skin_id',
        'iris_id',
        'code',
        'card_number',
        'active',
        'priority_rating',
    ];

    private const CODE_PREFIX = 'PAC';

    /** Largura fixa do sequencial (PAC-0000000001). */
    private const CODE_DIGITS = 10;

    /** Índice único (entity_id, code) — a colisão tratada é SÓ a dele. */
    private const CODE_UNIQUE_INDEX = 'patients_entity_id_code_unique';

    /** Namespace da chave do advisory lock da numeração (PostgreSQL). */
    private const CODE_LOCK_NAMESPACE = 'patient_code';

    /** Tentativas quando o código colide no índice (o lock já evita no fluxo normal). */
    private const MAX_CODE_ATTEMPTS = 3;

    /**
     * Código PAC-NNNNNNNNNN sequencial por clínica, gerado no INSERT quando
     * não informado (código explícito — seed/legado — é respeitado).
     */
    protected static function booted(): void
    {
        static::creating(function (self $patient) {
            if (blank($patient->code)) {
                $patient->code = static::nextCodeForEntity($patient->entity_id);
            }
        });
    }

    /**
     * INSERT com numeração segura sob concorrência.
     *
     * Antes: o `creating` lia o último código da clínica e somava 1 sem lock;
     * dois cadastros simultâneos (quick-create da agenda + importação em lote,
     * duas abas) calculavam o mesmo código e o segundo estourava o índice
     * único (23505) => HTTP 500 no cadastro / linha perdida na importação.
     *
     * Agora:
     *  1. cada tentativa roda em transação própria — savepoint quando o
     *     chamador já abriu uma (no PostgreSQL um erro aborta a transação
     *     inteira; o savepoint mantém a do chamador utilizável);
     *  2. a numeração da clínica é serializada (nextCodeForEntity) até o fim
     *     da transação do chamador;
     *  3. colisão residual NO ÍNDICE DO CÓDIGO (escritor fora do lock, ex.:
     *     instância antiga durante deploy) => nova tentativa com código novo,
     *     limitada. Código explícito nunca é trocado; outras violações de
     *     unicidade sobem como estão.
     *
     * @param Builder<static> $query
     */
    protected function performInsert(Builder $query)
    {
        $generatesCode = blank($this->code);

        for ($attempt = 1;; $attempt++) {
            try {
                return $this->getConnection()->transaction(fn () => parent::performInsert($query));
            } catch (UniqueConstraintViolationException $e) {
                if (! $generatesCode || ! static::isCodeCollision($e) || $attempt >= self::MAX_CODE_ATTEMPTS) {
                    throw $e;
                }

                Log::warning('patient.code_collision', [
                    'entity_id' => (string) $this->entity_id,
                    'attempt'   => $attempt,
                ]);

                // Rollback do savepoint desfez o INSERT: gera de novo na próxima volta.
                $this->code = null;
            }
        }
    }

    /**
     * Maior sequencial da clínica + 1. Considera pacientes excluídos (soft
     * delete) — o índice único também os vê — e ignora códigos fora da
     * largura padrão (legado), que venceriam na ordenação textual.
     */
    private static function nextCodeForEntity(?string $entityId): string
    {
        static::lockCodeNumbering($entityId);

        $last = static::withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('code', 'like', self::CODE_PREFIX . '-' . str_repeat('_', self::CODE_DIGITS))
            ->orderByDesc('code')
            ->value('code');

        $next = $last !== null ? ((int) substr($last, strlen(self::CODE_PREFIX) + 1)) + 1 : 1;

        return sprintf('%s-%0' . self::CODE_DIGITS . 'd', self::CODE_PREFIX, $next);
    }

    /**
     * Serializa o CADASTRO de pacientes da clínica até o COMMIT (mesmo lock da
     * numeração — reentrante na transação): duas telas cadastrando o mesmo CPF
     * ao mesmo tempo não criam dois People/Patient; a segunda já encontra o
     * cadastro gravado pela primeira. Chamar dentro de DB::transaction().
     */
    public static function lockRegistrations(?string $entityId): void
    {
        static::lockCodeNumbering($entityId);
    }

    /**
     * PostgreSQL: advisory lock de TRANSAÇÃO por clínica — só quem numera
     * paciente nesta clínica espera, e o lock vive até o COMMIT do chamador
     * (o próximo já lê o código gravado). A linha de `entities` fica livre.
     * Demais drivers: sem lock; a nova tentativa em performInsert() cobre.
     */
    private static function lockCodeNumbering(?string $entityId): void
    {
        $connection = (new static())->getConnection();

        if ($entityId === null || $connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->select('select pg_advisory_xact_lock(?)', [static::codeNumberingLockKey($entityId)]);
    }

    /**
     * Chave bigint estável por clínica (60 bits do sha1, sempre positiva).
     * Colisão entre clínicas só serializaria as duas — nunca mistura códigos.
     */
    private static function codeNumberingLockKey(string $entityId): int
    {
        return (int) hexdec(substr(sha1(self::CODE_LOCK_NAMESPACE . '|' . $entityId), 0, 15));
    }

    /**
     * PostgreSQL/MySQL citam o índice; SQLite cita as colunas. Só a mensagem do
     * DRIVER conta (UniqueViolation) — antes a mensagem inteira da exceção, que
     * inclui o SQL com os bindings: um texto digitado citando o índice fazia
     * outra violação parecer colisão de código.
     */
    private static function isCodeCollision(UniqueConstraintViolationException $e): bool
    {
        return UniqueViolation::violates($e, self::CODE_UNIQUE_INDEX)
            || UniqueViolation::violatesColumns($e, 'patients', 'entity_id', 'code');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
            'priority_rating' => 'integer',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(People::class, 'person_id');
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class, 'covenant_id');
    }

    public function skinType(): BelongsTo
    {
        return $this->belongsTo(SkinType::class, 'skin_id');
    }

    public function irisType(): BelongsTo
    {
        return $this->belongsTo(IrisType::class, 'iris_id');
    }

    // -------------------------------------------------------------------------
    // Compliance: LGPD + CFM
    // -------------------------------------------------------------------------

    /** Consentimentos do paciente — LGPD Art. 7/11 */
    public function consents(): HasMany
    {
        return $this->hasMany(PatientConsent::class, 'patient_id');
    }

    /** Prontuários do paciente — CFM Res. 2.227/2018 */
    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'patient_id');
    }

    /** Solicitações LGPD do titular — LGPD Art. 18 */
    public function lgpdRequests(): HasMany
    {
        return $this->hasMany(LgpdRequest::class, 'patient_id');
    }

    /** Log de acessos a dados do paciente — CFM + LGPD Art. 37 */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(DataAccessLog::class, 'patient_id');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(PatientExam::class, 'patient_id');
    }
}
