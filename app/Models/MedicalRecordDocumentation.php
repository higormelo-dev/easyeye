<?php

namespace App\Models;

use App\Domains\AI\Models\AiRun;
use App\Enums\DocumentationType;
use App\Traits\{Auditable, GuardedBySignedRecord, HasAuditColumns};
use Carbon\{CarbonImmutable, CarbonInterface};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany};

class MedicalRecordDocumentation extends Model
{
    use Auditable;
    use GuardedBySignedRecord;
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
        // Receita emitida apesar de alergia ativa (Fase 2 / A4): conflitos,
        // motivo, quem e quando — também na trilha de auditoria.
        'safety_override',
        // Data impressa escolhida pelo médico (data alternativa — Fase 2 / B3);
        // a emissão de fato fica em created_at.
        'document_date',
        // Dados estruturados do documento (atestados, CID autorizado, itens
        // do pedido de exames, modelo pessoal usado) — Fase 2 / B1-B3.
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type'            => DocumentationType::class,
            'safety_override' => 'array',
            'document_date'   => 'date',
            'metadata'        => 'array',
        ];
    }

    /**
     * Data IMPRESSA no documento: a alternativa escolhida pelo médico ou a da
     * emissão. Reimprimir um documento antigo mostra a data dele, não a de
     * hoje (Fase 2 / B3).
     */
    public function printedDate(): CarbonInterface
    {
        $timezone = (string) config('app.timezone');

        if ($this->document_date !== null) {
            return CarbonImmutable::parse($this->document_date->toDateString(), $timezone);
        }

        return $this->created_at !== null
            ? CarbonImmutable::instance($this->created_at)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);
    }

    /** Documento com data alternativa (impressa ≠ emissão). */
    public function hasAlternativeDate(): bool
    {
        return $this->document_date !== null
            && ($this->created_at === null
                || $this->document_date->toDateString() !== CarbonImmutable::instance($this->created_at)->setTimezone((string) config('app.timezone'))->toDateString());
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

    /**
     * Imagens de exame que fizeram parte deste laudo (manual ou de IA) — um
     * laudo pode cobrir vários exames do mesmo paciente. Ver
     * ReportExamSummary pra versão agrupada exibida no PDF.
     */
    public function patientExams(): BelongsToMany
    {
        return $this->belongsToMany(PatientExam::class, 'medical_record_documentation_exams')
            ->withPivot('entity_id');
    }

    /**
     * Vincula as imagens laudadas (substitui o vínculo anterior).
     *
     * @param list<string> $examIds
     */
    public function syncExams(array $examIds, string $entityId): void
    {
        $this->patientExams()->sync(
            collect($examIds)->mapWithKeys(fn (string $id) => [$id => [
                'entity_id'  => $entityId,
                'created_at' => now(),
            ]])->all(),
        );
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
