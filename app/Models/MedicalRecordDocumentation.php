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
        // Médico solicitante do laudo de imagem (texto livre, pode ser de
        // fora do EasyEye) — Fase 5.
        'requesting_doctor_name',
        'requesting_doctor_email',
        'requesting_doctor_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'type'                      => DocumentationType::class,
            'safety_override'           => 'array',
            'document_date'             => 'date',
            'metadata'                  => 'array',
            'requesting_doctor_sent_at' => 'datetime',
        ];
    }

    /** Indicação clínica do laudo de imagem (Fase 5) — null pros demais tipos de documento. */
    public function imageReportClinicalIndication(): ?string
    {
        return $this->metadata['clinical_indication'] ?? null;
    }

    /** Observações do laudo de imagem (Fase 5) — null pros demais tipos de documento. */
    public function imageReportObservations(): ?string
    {
        return $this->metadata['observations'] ?? null;
    }

    /**
     * Bloco de imagens a embutir no laudo (Fase 5): {enabled, columns,
     * pair_od_oe, images:[{exam_id, caption, order}]} — null quando o
     * médico não habilitou ou o documento não é de imagem.
     *
     * @return array<string, mixed>|null
     */
    public function imageReportBlock(): ?array
    {
        $block = $this->metadata['image_report'] ?? null;

        return is_array($block) && ($block['enabled'] ?? false) ? $block : null;
    }

    /**
     * Auditable::getAuditableAttributes() — troca o data_uri (bytes
     * congelados, até ~27 MiB em base64 por laudo com imagem embutida) por
     * um hash/tamanho. Mantém o resto do metadata intacto (indicação
     * clínica, observações, enabled/columns/pair_od_oe) — a trilha de
     * auditoria continua provando O QUE foi embutido (hash verificável,
     * nunca mexido depois de congelado) sem duplicar o peso inteiro em
     * audit_logs a cada emissão (achado via revisão adversarial Codex:
     * ComplianceController::exportAuditLogs() nunca usa old_values/
     * new_values, mas carregava 500 linhas inteiras por chunk e esgotava o
     * memory_limit).
     */
    public function getAuditableAttributes(): array
    {
        $attributes = $this->getAttributes();

        if (! isset($attributes['metadata']) || ! is_string($attributes['metadata'])) {
            return $attributes;
        }

        $metadata = self::redactImageReportBytes(json_decode($attributes['metadata'], true));

        if ($metadata !== null) {
            $attributes['metadata'] = json_encode($metadata);
        }

        return $attributes;
    }

    /**
     * metadata (já decodificado) com o data_uri do laudo de imagem (bytes
     * congelados, até ~27 MiB em base64) trocado por hash/tamanho — mesma
     * redação de getAuditableAttributes(), reaproveitada por quem precisa
     * do metadata fora do fluxo de auditoria sem duplicar o peso binário.
     * Usado pela exportação LGPD self-service (PatientDataExporter): o
     * resto do metadata (indicação clínica, observações, layout) continua
     * intacto, e foto/vídeo de exame já é tratado como metadado nesse
     * export (campo has_file, nunca os bytes) — mesma regra aqui, achado
     * via revisão adversarial Codex (10 laudos com imagem embutida
     * esgotavam o memory_limit do export).
     *
     * Cobre tanto metadata.image_report.images[].data_uri (bloco de
     * imagens no final do laudo) quanto metadata.inline_images (referências
     * <img data-exam-image-id> inseridas no meio do texto — ver
     * ReportImageBlockBuilder::freezeInlineImages()): os dois guardam bytes
     * congelados do mesmo jeito, achado via revisão adversarial Codex.
     *
     * @param array<string, mixed>|null $metadata
     *
     * @return array<string, mixed>|null
     */
    public static function redactImageReportBytes(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $redactDataUri = static fn (mixed $dataUri) => is_string($dataUri)
            ? sprintf('[omitido: %d bytes, sha256=%s]', strlen($dataUri), hash('sha256', $dataUri))
            : $dataUri;

        $images = $metadata['image_report']['images'] ?? null;

        if (is_array($images)) {
            $metadata['image_report']['images'] = array_map(
                static fn (array $image) => [...$image, 'data_uri' => $redactDataUri($image['data_uri'] ?? null)],
                $images,
            );
        }

        $inlineImages = $metadata['inline_images'] ?? null;

        if (is_array($inlineImages)) {
            $metadata['inline_images'] = array_map($redactDataUri, $inlineImages);
        }

        return $metadata;
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

    /**
     * O laudo pode sair do consultório — e-mail com link público pro médico
     * solicitante e a leitura por esse link? Só com o prontuário dono
     * ASSINADO: assinado é imutável (Signable + GuardedBySignedRecord — o
     * documento não é alterado nem excluído depois), então o que o solicitante
     * recebe continua sendo o que está no prontuário. Mesma exigência do
     * compartilhamento com o paciente (PatientDocumentShareService/
     * PatientDocumentAccessService). Vale a ASSINATURA (signed_at +
     * signature_hash), não só o cadeado is_locked.
     */
    public function isExternallyDeliverable(): bool
    {
        $this->loadMissing('medicalRecord');

        return (bool) $this->medicalRecord?->isSigned();
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
        return $this->injectInlineImages($this->contentWithoutDateHeader());
    }

    /**
     * content sem o cabeçalho de data resolvido duplicado (ver
     * contentForRender()) — mas SEM injectInlineImages(). Usado pela
     * exportação LGPD self-service (PatientDataExporter): o byte da
     * imagem embutida nunca pode entrar no export fora de
     * metadata.inline_images (único campo que passa por
     * redactImageReportBytes()) — contentForRender() injetava o byte de
     * volta no HTML, ignorando por completo a redação aplicada no
     * metadata (achado via revisão adversarial Codex: 10 laudos com a
     * MESMA imagem inline esgotavam o memory_limit do export mesmo com
     * metadata.details redigido).
     */
    public function contentWithoutDateHeader(): string
    {
        $html = (string) ($this->content ?? '');

        // Pareia: <p ... text-align:right ...>...</p> seguido opcionalmente
        // do <p>&nbsp;</p> espaçador, apenas no INÍCIO do conteúdo.
        $pattern = '/^\s*<p\s+[^>]*text-align\s*:\s*right[^>]*>[^<]*<\/p>\s*(?:<p[^>]*>&nbsp;<\/p>)?\s*/iu';

        return preg_replace($pattern, '', $html, 1) ?? $html;
    }

    /**
     * Mescla metadata.inline_images (bytes congelados na emissão — ver
     * ReportImageBlockBuilder::freezeInlineImages()) nas referências
     * <img data-exam-image-id> deixadas em content pelo botão "Inserir
     * imagem" (Fase 5). `content` por si só continua leve (sem base64) pro
     * audit log/exportação LGPD/eager load — só na hora de renderizar o
     * byte de verdade entra, igual ao bloco de imagens no final do laudo.
     */
    private function injectInlineImages(string $html): string
    {
        $images = $this->metadata['inline_images'] ?? null;

        if (! is_array($images) || $images === [] || ! str_contains($html, 'data-exam-image-id')) {
            return $html;
        }

        return preg_replace_callback(
            '/<img\b[^>]*\sdata-exam-image-id="([^"]+)"[^>]*>/i',
            static function (array $match) use ($images): string {
                $dataUri = $images[$match[1]] ?? null;

                return is_string($dataUri) ? str_replace('<img', '<img src="' . $dataUri . '"', $match[0]) : $match[0];
            },
            $html,
        ) ?? $html;
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
