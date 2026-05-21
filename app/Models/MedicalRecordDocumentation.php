<?php

namespace App\Models;

use App\Domains\AI\Models\AiRun;
use App\Enums\DocumentationType;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecordDocumentation extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'medical_record_id',
        'patient_id',
        'doctor_id',
        'report_setting_id',
        'report_setting_content_id',
        'template_version',
        'ai_run_id',
        'type',
        'title',
        'content',
    ];

    protected function casts(): array
    {
        return ['type' => DocumentationType::class];
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function reportSetting(): BelongsTo
    {
        return $this->belongsTo(ReportSetting::class);
    }

    public function reportSettingContent(): BelongsTo
    {
        return $this->belongsTo(ReportSettingContent::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Execução de IA que originou esta documentação (quando aplicável).
     * Permite auditoria CFM/LGPD: ver prompt, modelo, custo e médico que aprovou.
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    public function getTypeLabel(): string
    {
        return $this->type instanceof DocumentationType
            ? $this->type->label()
            : (string) $this->type;
    }

    /**
     * Retorna o `content` HTML para renderização em PDF, removendo o cabeçalho
     * de data resolvido (`<p text-align:right>CIDADE/UF, DD de MÊS de YYYY</p>`
     * + `<p>&nbsp;</p>` espaçador).
     *
     * Motivo: a data e localização já são renderizadas no bloco de assinatura
     * do PDF (`_signature.blade.php`) via `Carbon::isoFormat('LL')` +
     * `LocationFormatter`. Manter no topo duplica a informação e polui.
     *
     * O campo `content` no banco fica intocado (preserva trilha de auditoria);
     * o strip só acontece na hora de renderizar.
     *
     * Idempotente: se o conteúdo já não tem o cabeçalho, retorna inalterado.
     */
    public function contentForRender(): string
    {
        $html = (string) ($this->content ?? '');

        // Pareia: <p ... text-align:right ...>...</p> seguido opcionalmente
        // do <p>&nbsp;</p> espaçador, apenas no INÍCIO do conteúdo.
        $pattern = '/^\s*<p\s+[^>]*text-align\s*:\s*right[^>]*>[^<]*<\/p>\s*(?:<p[^>]*>&nbsp;<\/p>)?\s*/iu';

        return preg_replace($pattern, '', $html, 1) ?? $html;
    }

    /**
     * Retorna dados da tonometria decodificados do campo content (JSON).
     *
     * @return array{od: string|null, oe: string|null, time: string|null}
     */
    public function tonometryData(): array
    {
        if ($this->type !== DocumentationType::Tonometry) {
            return ['od' => null, 'oe' => null, 'time' => null];
        }
        $data = json_decode($this->content ?? '{}', true);

        return ['od' => $data['od'] ?? null, 'oe' => $data['oe'] ?? null, 'time' => $data['time'] ?? null];
    }
}
