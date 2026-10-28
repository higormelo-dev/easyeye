<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DraftStatus;
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rascunho de laudo de imagem (Fase 5 — fluxo secretária-rascunha /
 * médico-emite). Secretária/admin preparam; só o médico emite de fato
 * (sempre via MedicalRecordDocumentationService::store(), doctor-only,
 * inalterado) — este rascunho nunca vira documento assinado sozinho.
 *
 * @property array<int, string>|null   $exam_ids
 * @property array<string, mixed>|null $image_report_payload
 */
class EyeImageReportDraft extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    /**
     * Nasce com revision=1 no PHP, não só no banco (migration default(1)):
     * create() nunca relê o valor que o banco aplicou sozinho, então sem
     * isto $draft->revision ficava null logo após criar — increment()
     * calculava null+1=1 em memória (errado) enquanto a coluna de verdade
     * virava 2, e a tela via a revisão ERRADA no 1º save (achado na 2ª
     * rodada de revisão adversarial Codex). Mesmo padrão de PatientExam::
     * $attributes['active'].
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revision' => 1,
    ];

    protected $fillable = [
        'entity_id',
        'patient_id',
        'exam_ids',
        'report_setting_content_id',
        // Médico usado na ÚLTIMA pré-visualização (Fase 5 / revisão Codex) —
        // só pra comparar contra quem de fato emite e avisar (nunca editar
        // o texto já resolvido); ver ExamManagerReportController::store().
        'resolved_doctor_id',
        'title',
        'content',
        'clinical_indication',
        'observations',
        'document_date',
        'requesting_doctor_name',
        'requesting_doctor_email',
        'image_report_payload',
        'status',
        'submitted_at',
        'return_reason',
        'returned_at',
        'returned_by',
        'issued_at',
        'issued_documentation_id',
    ];

    protected function casts(): array
    {
        return [
            'exam_ids'             => 'array',
            'image_report_payload' => 'array',
            'document_date'        => 'date',
            'status'               => DraftStatus::class,
            'submitted_at'         => 'datetime',
            'returned_at'          => 'datetime',
            'issued_at'            => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function reportSettingContent(): BelongsTo
    {
        return $this->belongsTo(ReportSettingContent::class);
    }

    public function issuedDocumentation(): BelongsTo
    {
        return $this->belongsTo(MedicalRecordDocumentation::class, 'issued_documentation_id');
    }

    public function resolvedDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'resolved_doctor_id');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /** Rascunho/enviado/devolvido ainda podem ser editados; emitido/descartado não. */
    public function isEditable(): bool
    {
        return in_array($this->status, [DraftStatus::Draft, DraftStatus::Submitted, DraftStatus::Returned], true);
    }

    /** O técnico pode (re)enviar ao médico: rascunho novo ou devolvido. */
    public function canBeSubmitted(): bool
    {
        return in_array($this->status, [DraftStatus::Draft, DraftStatus::Returned], true);
    }

    /** Reenviar limpa o motivo da devolução anterior (o histórico fica na auditoria). */
    public function markSubmitted(): void
    {
        $this->update([
            'status'        => DraftStatus::Submitted,
            'submitted_at'  => now(),
            'return_reason' => null,
            'returned_at'   => null,
            'returned_by'   => null,
        ]);
    }

    /**
     * O médico devolve o rascunho ao técnico. Sobe a revisão: quem estava com
     * o rascunho aberto (ainda "Enviado") não salva por cima sem ver o motivo.
     * Sempre chamado DENTRO do lockForUpdate() do controller.
     */
    public function markReturned(string $reason, string $userId): void
    {
        $this->update([
            'status'        => DraftStatus::Returned,
            'return_reason' => $reason,
            'returned_at'   => now(),
            'returned_by'   => $userId,
        ]);
        $this->incrementRevision();
    }

    public function markIssued(string $documentationId): void
    {
        $this->update([
            'status'                  => DraftStatus::Issued,
            'issued_at'               => now(),
            'issued_documentation_id' => $documentationId,
        ]);
    }

    /**
     * Token de concorrência pro front comparar antes de salvar/emitir (ver
     * ExamManagerReportDraftController::update() e ExamManagerReportController::
     * store()) — nunca updated_at: esta tabela usa timestamps() com o
     * precision padrão do Schema Builder (0, sem fração de segundo), então
     * 2 gravações no mesmo segundo gravariam o MESMO updated_at e uma
     * correção real passaria pela checagem de revisão obsoleta sem ser
     * detectada (achado via revisão adversarial Codex). increment() é uma
     * única instrução SQL atômica (revision = revision + 1), correta mesmo
     * fora de um lock explícito; aqui sempre chamada DENTRO do
     * lockForUpdate() do caller. Fora de $fillable de propósito — só este
     * método altera a coluna, nunca mass-assignment.
     */
    public function incrementRevision(): void
    {
        $this->increment('revision');
    }
}
