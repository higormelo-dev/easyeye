<?php

namespace App\Models;

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\AiRunStatus;
use App\Enums\ExamSource;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany};
use Illuminate\Support\Facades\{DB, Storage};

class PatientExam extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;

    protected $primaryKey = 'id';

    /**
     * Exame nasce habilitado (válido para laudo, IA e repasse); desabilitar é
     * decisão do médico no Gerenciador de Imagens. Default no model, não só no
     * banco: um ponto de criação que esqueça a chave não repete o bug de
     * 02/02/2026 (todo exame do integrador nascia "Desabilitada").
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'capture_id', 'capture_integrator_id', 'content_sha256', 'content_bytes',
        'patient_id',
        'doctor_id',
        'schedule_id',
        'entity_integrator_equipment_id',
        'exam_id',
        'code',
        'archive',
        'name',
        'laterality',
        'quality_rating',
        'exam_session_id',
        'diagnosis_cids',
        'active',
        'source',
        'external_origin',
        'exam_performed_at',
        'observation',
        'import_batch_id',
    ];

    /**
     * @var string[]
     */
    protected $appends = ['archive_url'];

    /**
     * Generated code for the entity_id field.
     */
    /** Serialize scoped EXM allocation for every producer, including web imports. */
    protected function performInsert(Builder $query): bool
    {
        return DB::transaction(function () use ($query): bool {
            Patient::whereKey($this->patient_id)->lockForUpdate()->firstOrFail();

            return parent::performInsert($query);
        });
    }

    protected static function booted(): void
    {
        static::creating(function (self $patientExam) {
            if (blank($patientExam->code)) {
                $prefix = 'EXM';

                $lastExam = static::withoutGlobalScopes()
                    ->where('patient_id', $patientExam->patient_id)
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                if ($lastExam) {
                    $lastNumber = (int) substr($lastExam->code, strlen($prefix) + 1);
                    $newNumber  = $lastNumber + 1;
                } else {
                    $newNumber = 1;
                }

                $patientExam->code = sprintf('%s-%010d', $prefix, $newNumber);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'laterality'        => 'integer',
            'content_bytes'     => 'integer',
            'quality_rating'    => 'integer',
            'active'            => 'boolean',
            'diagnosis_cids'    => 'array',
            'source'            => ExamSource::class,
            'exam_performed_at' => 'datetime',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }

    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class, 'exam_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(EntityIntegratorEquipment::class, 'entity_integrator_equipment_id');
    }

    /**
     * Execuções de IA (laudos) que analisaram este exame de imagem ocular.
     */
    public function aiRuns(): BelongsToMany
    {
        return $this->belongsToMany(AiRun::class, 'ai_run_patient_exam', 'patient_exam_id', 'ai_run_id')
            ->withPivot('entity_id');
    }

    public function archiveUrl(): Attribute
    {
        return new Attribute(
            get: fn () => $this->archive ? Storage::disk('s3')->temporaryUrl($this->archive, now()->addDay(1)) : null,
        );
    }

    /**
     * URL assinada de curta duração pro Portal do Paciente — NUNCA
     * reaproveitar archiveUrl() (TTL de 24h pensado pro staff): o link do
     * portal pode ser reencaminhado/vazado por e-mail, e o titular não
     * precisa de tanto tempo pra abrir uma imagem que acabou de pedir.
     */
    public function archiveUrlForPatientPortal(bool $forDownload = false): ?string
    {
        if (! $this->archive) {
            return null;
        }

        $options = $forDownload
            ? ['ResponseContentDisposition' => 'attachment; filename="' . basename($this->archive) . '"']
            : [];

        return Storage::disk('s3')->temporaryUrl($this->archive, now()->addMinutes(10), $options);
    }

    public function isExternal(): bool
    {
        return $this->source === ExamSource::ExternalImport;
    }

    /**
     * Exames com laudo na clínica: laudo manual/conjunto vigente (vínculo em
     * medical_record_documentation_exams, documentação não excluída) OU laudo
     * de IA aprovado. Mesmo critério do filtro "Laudado" do Gerenciador de
     * Imagens e do indicador "Exames pendentes" do Dashboard.
     */
    public function scopeReported(Builder $query, string $entityId): Builder
    {
        return $query->where(fn (Builder $q) => $this->reportedConstraint($q, $entityId));
    }

    /**
     * Exames habilitados ainda sem laudo — desabilitado não entra: não pode
     * ser laudado (bloqueio de laudo/PDF/IA), então não é pendência.
     */
    public function scopePendingReport(Builder $query, string $entityId): Builder
    {
        return $query->where('patient_exams.active', true)
            ->whereNot(fn (Builder $q) => $this->reportedConstraint($q, $entityId));
    }

    private function reportedConstraint(Builder $query, string $entityId): void
    {
        $query
            ->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('medical_record_documentation_exams as mrde')
                ->join('medical_record_documentations as mrd', 'mrd.id', '=', 'mrde.medical_record_documentation_id')
                ->whereColumn('mrde.patient_exam_id', 'patient_exams.id')
                ->where('mrde.entity_id', $entityId)
                ->whereNull('mrd.deleted_at'))
            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('ai_run_patient_exam as arpe')
                ->join('ai_runs', 'ai_runs.id', '=', 'arpe.ai_run_id')
                ->whereColumn('arpe.patient_exam_id', 'patient_exams.id')
                ->where('arpe.entity_id', $entityId)
                ->where('ai_runs.status', AiRunStatus::Approved->value));
    }

    /**
     * Exames importados manualmente de fonte externa (não capturados via integrador).
     */
    public function scopeExternal($query)
    {
        return $query->where('source', ExamSource::ExternalImport);
    }
}
