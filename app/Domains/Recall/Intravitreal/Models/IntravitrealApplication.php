<?php

declare(strict_types=1);

namespace App\Domains\Recall\Intravitreal\Models;

use App\Domains\Recall\Intravitreal\Enums\{ApplicationStatus, AuthorizationStatus};
use App\Models\Concerns\BelongsToEntity;
use App\Models\{Doctor, MedicalRecordProcedure, Schedule, StockLot};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma aplicação do curso: planejada (data prevista, agendamento) e depois executada (lote e validade, autorização do
 * convênio e o intervalo decidido até a próxima). A execução entra pelo `markDone` do procedimento do prontuário, na
 * mesma transação (nada novo em `medical_record_procedures`). Lote/validade são o registro de rastreabilidade do
 * medicamento (RDC 556/2021): copiados na hora, não referenciam o cadastro que pode mudar.
 *
 * @property string            $id
 * @property string            $course_id
 * @property int               $sequence_no
 * @property ApplicationStatus $status
 */
class IntravitrealApplication extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    public const PHASE_LOADING = 'loading';

    public const PHASE_MAINTENANCE = 'maintenance';

    protected $fillable = [
        'entity_id', 'patient_id', 'course_id', 'sequence_no', 'phase', 'status', 'schedule_id', 'medical_record_procedure_id',
        'planned_for', 'performed_at', 'performed_by_doctor_id', 'eye', 'drug_label', 'lot_number', 'lot_expiry', 'stock_lot_id',
        'authorization_required', 'authorization_status', 'authorization_number', 'authorization_requested_on',
        'authorization_valid_until', 'authorization_notes', 'decided_interval_weeks', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'                     => ApplicationStatus::class,
            'authorization_status'       => AuthorizationStatus::class,
            'authorization_required'     => 'boolean',
            'planned_for'                => 'date',
            'performed_at'               => 'datetime',
            'lot_expiry'                 => 'date',
            'authorization_requested_on' => 'date',
            'authorization_valid_until'  => 'date',
            'sequence_no'                => 'integer',
            'decided_interval_weeks'     => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(IntravitrealCourse::class, 'course_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(MedicalRecordProcedure::class, 'medical_record_procedure_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'performed_by_doctor_id');
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }
}
