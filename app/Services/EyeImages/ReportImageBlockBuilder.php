<?php

declare(strict_types=1);

namespace App\Services\EyeImages;

use App\Models\{MedicalRecordDocumentation, PatientExam};
use App\Services\RasterMemoryBudget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

/**
 * Resolve metadata.image_report (Fase 5 / 5b — EyeImageReportController,
 * EyeImageReportDraftController) pra bytes embutidos no PDF assinado.
 *
 * Base64 embutido, NUNCA URL remota: wkhtmltopdf não tem acesso de rede ao
 * bucket S3-compatível — mesma decisão (e mesmo motivo) do MontageService,
 * que já lê bytes direto por isso.
 *
 * Tolerante a qualquer coisa que tenha mudado DEPOIS da emissão (exame
 * excluído, desabilitado, arquivo ilegível) — nunca quebra a reimpressão de
 * um laudo antigo; o item simplesmente não aparece.
 */
class ReportImageBlockBuilder
{
    // Mesmo teto do Montage (MontageService::MAX_COLUMNS) — a partir daqui
    // prioriza thumb_archive (menor) em vez de display_archive.
    private const THUMB_FROM_COLUMNS = 4;

    private const EYE_ORDER = [1 => 0, 2 => 1];

    /**
     * @return array{columns:int, images:list<array{data_uri:string,caption:?string}>}|null
     */
    public function build(MedicalRecordDocumentation $documentation): ?array
    {
        $block = $documentation->imageReportBlock();

        return $block === null ? null : $this->resolveBlock($block);
    }

    /**
     * Resolve e CONGELA os bytes na emissão (EyeImageReportController::
     * store() — 2ª rodada de revisão adversarial Codex): sem isto, o laudo
     * assinado mudava sozinho depois de emitido — exame desabilitado/
     * excluído ou arquivo sumindo do S3 fazia a imagem desaparecer numa
     * reimpressão futura (inclusive pelo link público do médico
     * solicitante), sem nenhum aviso pro médico que assinou. $missing conta
     * quantas das imagens SELECIONADAS não puderam ser resolvidas agora —
     * o controller decide se bloqueia/pede confirmação antes de emitir.
     *
     * @param array<string, mixed> $block
     *
     * @return array{block: ?array, missing: int}
     */
    public function resolveForIssuance(array $block): array
    {
        $selected = count($block['images'] ?? []);
        $resolved = $this->resolveBlock($block);
        $missing  = max(0, $selected - count($resolved['images'] ?? []));

        return ['block' => $resolved, 'missing' => $missing];
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array{columns:int, images:list<array{data_uri:string,caption:?string}>}|null
     */
    private function resolveBlock(array $block): ?array
    {
        if (($block['images'] ?? []) === []) {
            return null;
        }

        $columns = $this->resolveColumns((int) ($block['columns'] ?? 0));

        // Já congelado numa emissão anterior (resolveForIssuance() acima) —
        // nunca reabre exam_id/Storage: garante que o laudo assinado NUNCA
        // muda depois de emitido. Documento emitido ANTES desta correção
        // continua no formato antigo (por exam_id) e cai no fluxo de baixo,
        // com o mesmo risco de sempre — não há como congelar
        // retroativamente algo que já foi assinado sem o byte resolvido.
        if (! array_key_exists('exam_id', $block['images'][0])) {
            return [
                'columns' => $columns,
                'images'  => array_map(
                    fn (array $i) => ['data_uri' => $i['data_uri'], 'caption' => $i['caption'] ?? null],
                    $block['images'],
                ),
            ];
        }

        $examIds = collect($block['images'])->pluck('exam_id')->all();
        // Exame desabilitado (active=false) desde a emissão: mesma regra de
        // assertExamsActive() no momento de laudar — não aparece aqui também.
        $exams = PatientExam::query()->whereIn('id', $examIds)->where('active', true)->get()->keyBy('id');

        $items = [];
        // Orçamento de bytes BRUTOS (config eye_image_capture.image_report.
        // max_embedded_bytes) somado entre todas as imagens — 50 imagens ×
        // 10 MB cada esgotava os 512 MB de memory_limit (achado via revisão Codex).
        $remainingBudget = (int) config('eye_image_capture.image_report.max_embedded_bytes');

        foreach ($block['images'] as $item) {
            $exam = $exams->get($item['exam_id']);

            if (! $exam) {
                continue;
            }

            $resolved = $this->resolveDataUri($exam, $columns, $remainingBudget);

            if ($resolved === null) {
                // Vídeo (Fase 5 / Entrega 6), PDF/SVG, arquivo corrompido ou
                // ausente no bucket, ou orçamento de bytes já esgotado —
                // nunca entra no bloco, só fica de fora.
                continue;
            }

            $remainingBudget -= $resolved['raw_size'];
            $items[] = ['exam' => $exam, 'data_uri' => $resolved['data_uri'], 'caption' => $item['caption'] ?? null];
        }

        if ($items === []) {
            return null;
        }

        if ($block['pair_od_oe'] ?? false) {
            $items = $this->pairOdOe($items);
        }

        return [
            'columns' => $columns,
            'images'  => array_map(fn (array $i) => ['data_uri' => $i['data_uri'], 'caption' => $i['caption']], $items),
        ];
    }

    /**
     * Resolve e CONGELA (mesmo motivo/comentário de resolveForIssuance()
     * acima) as referências inline <img data-exam-image-id> deixadas em
     * content pelo botão "Inserir imagem" (Fase 5 — distinto do bloco de
     * imagens no final do laudo, que usa metadata.image_report). NUNCA
     * grava o byte em content: ele fica em metadata.inline_images (keyed
     * por exam_id — dedup automático se a mesma imagem for inserida 2x) —
     * content continua leve (sem base64) pro audit log/exportação LGPD/
     * eager load, mesma razão de metadata.image_report (ver
     * MedicalRecordDocumentation::getAuditableAttributes()/
     * redactImageReportBytes(), PatientDataExporter::documentationData()).
     * contentForRender() mescla os dois de novo na hora de exibir/imprimir.
     *
     * Referência que não resolve (exame excluído/desabilitado/arquivo
     * ilegível desde a inserção) nunca fica pra trás como <img> quebrada no
     * laudo assinado — removida de content, contada em $missing pro
     * controller decidir se bloqueia/pede confirmação antes de emitir
     * (mesmo padrão de resolveForIssuance()).
     *
     * Orçamento de bytes (eye_image_capture.image_report.max_embedded_bytes)
     * próprio, independente do bloco de imagens — simplificação deliberada:
     * um laudo com os dois recursos pode somar até 2x o teto configurado.
     * Poucas imagens inline por natureza (inseridas uma a uma no texto, sem
     * seletor em massa como o bloco) tornam esse pior caso raro na prática.
     *
     * @return array{html: string, images: array<string, string>, missing: int}
     */
    public function freezeInlineImages(string $html): array
    {
        if (! str_contains($html, '<img')) {
            return ['html' => $html, 'images' => [], 'missing' => 0, 'expanded_bytes' => 0];
        }

        preg_match_all('/<img\b[^>]*\sdata-exam-image-id="([^"]+)"[^>]*>/i', $html, $matches);
        $allOccurrences = $matches[1];
        $examImageIds   = array_values(array_unique($allOccurrences));

        if ($examImageIds === []) {
            return ['html' => $html, 'images' => [], 'missing' => 0, 'expanded_bytes' => 0];
        }

        $exams = PatientExam::query()->whereIn('id', $examImageIds)->where('active', true)->get()->keyBy('id');

        $images          = [];
        $missing         = 0;
        $remainingBudget = (int) config('eye_image_capture.image_report.max_embedded_bytes');

        foreach ($examImageIds as $examImageId) {
            $exam     = $exams->get($examImageId);
            $resolved = $exam ? $this->resolveDataUri($exam, self::THUMB_FROM_COLUMNS, $remainingBudget) : null;

            if ($resolved === null) {
                $missing++;

                continue;
            }

            $remainingBudget -= $resolved['raw_size'];
            $images[$examImageId] = $resolved['data_uri'];
        }

        $html = preg_replace_callback(
            '/<img\b[^>]*\sdata-exam-image-id="([^"]+)"[^>]*>/i',
            static fn (array $match) => isset($images[$match[1]]) ? $match[0] : '',
            $html,
        ) ?? $html;

        // $remainingBudget acima só paga o CUSTO DE RESOLVER cada exame
        // único uma vez (correto — nunca decodifica a mesma imagem 2x).
        // Mas MedicalRecordDocumentation::contentForRender() expande CADA
        // ocorrência de <img data-exam-image-id> de volta pro data_uri
        // inteiro — a MESMA imagem referenciada N vezes no texto multiplica
        // o peso por N na hora de montar o HTML, mesmo pagando o orçamento
        // de resolução só 1 vez. Contado aqui (sobre $allOccurrences, COM
        // repetição) pra nunca comitar um laudo cujo HTML expandido vai
        // esgotar memória em TODA renderização futura (achado via revisão
        // adversarial Codex — reprodução isolada com 50 referências à mesma
        // imagem de 10 MiB esgotou os 512 MiB configurados).
        $expandedBytes = array_sum(array_map(
            static fn (string $id) => strlen($images[$id] ?? ''),
            $allOccurrences,
        ));

        return ['html' => $html, 'images' => $images, 'missing' => $missing, 'expanded_bytes' => $expandedBytes];
    }

    private function resolveColumns(int $requested): int
    {
        $allowed = config('eye_image_capture.image_report.allowed_columns');

        return in_array($requested, $allowed, true) ? $requested : (int) config('eye_image_capture.image_report.default_columns');
    }

    /**
     * Agrupa por exame (mesma chave de examGroupKey() em
     * Pages/Panel/EyeImages/Index.vue, espelhada em ReportExamSummary::summarize())
     * preservando a ordem de 1ª aparição de cada grupo; dentro do grupo, OD
     * antes de OE antes de AO. Heurística simples de propósito — lateralidade
     * ausente/ambígua cai no fim do grupo, sem forçar além disso.
     *
     * @param list<array{exam: PatientExam, data_uri: string, caption: ?string}> $items
     *
     * @return list<array{exam: PatientExam, data_uri: string, caption: ?string}>
     */
    private function pairOdOe(array $items): array
    {
        $groups = collect($items)->groupBy(function (array $item) {
            $exam = $item['exam'];
            $date = ($exam->exam_performed_at ?? $exam->created_at)?->toDateString() ?? '';

            return $exam->exam_session_id
                ? 's:' . $exam->exam_session_id
                : implode('|', [$date, $exam->entity_integrator_equipment_id, $exam->exam_id]);
        });

        return $groups
            ->map(fn (Collection $group) => $group
                ->sortBy(fn (array $item) => self::EYE_ORDER[$item['exam']->laterality ?? 0] ?? 2)
                ->values())
            ->flatten(1)
            ->all();
    }

    /**
     * @return array{data_uri:string, raw_size:int}|null
     */
    private function resolveDataUri(PatientExam $exam, int $columns, int $remainingBudget): ?array
    {
        // Orçamento já esgotado por imagens anteriores do mesmo laudo —
        // nem vale a pena chamar o storage pra esta.
        if ($remainingBudget <= 0) {
            return null;
        }

        $candidates = $columns >= self::THUMB_FROM_COLUMNS
            ? [$exam->thumb_archive, $exam->display_archive, $exam->archive]
            : [$exam->display_archive, $exam->archive];

        foreach (array_filter($candidates) as $path) {
            try {
                if (! Storage::disk('s3')->exists($path)) {
                    continue;
                }

                // size() também NUNCA respeita throw:false, pelo mesmo motivo
                // que exists() não respeita (ver catch abaixo) — por isso tem
                // que estar dentro do MESMO try/catch. Checa ANTES do get()
                // pra não gastar banda baixando uma imagem que de qualquer
                // forma não cabe no que resta do orçamento.
                if (Storage::disk('s3')->size($path) > $remainingBudget) {
                    continue;
                }

                $raw = Storage::disk('s3')->get($path);
            } catch (FilesystemException) {
                // exists() NUNCA respeita throw:false (config/filesystems.php):
                // Storage::exists() delega direto pro adapter via
                // Flysystem\Filesystem::has(), sem o try/catch que
                // FilesystemAdapter aplica em get()/put()/etc. (throwsExceptions()) —
                // S3 fora do ar, rede, ou política de bucket negando HeadObject
                // lança UnableToCheckFileExistence por cima do throw:false,
                // igual UnableToReadFile pode escapar de get() em condições
                // raras de adapter. Sem este catch, UMA imagem inacessível
                // quebrava o laudo inteiro, inclusive pelo link público
                // assinado do médico solicitante (achado via revisão Codex).
                continue;
            }

            // Disco S3 configurado com throw:false (config/filesystems.php) —
            // leitura que falhar (arquivo some entre exists() e get(), rede,
            // permissão) devolve null em vez de lançar. getimagesizefromstring()
            // é strict_types e recusa null (TypeError, que @ NÃO suprime —
            // suprime warning/notice, nunca exceção) — sem este guard, UMA
            // imagem ilegível quebrava o PDF inteiro, inclusive pelo link
            // público assinado do médico solicitante (achado via revisão Codex).
            if ($raw === null) {
                continue;
            }

            // getimagesizefromstring() só reconhece raster (jpg/png/gif/bmp/webp)
            // — vídeo, PDF e SVG (laudo .emr) voltam false e ficam de fora de
            // propósito: este bloco é só pra fotos, não pra qualquer arquivo.
            $dimensions = @getimagesizefromstring($raw);

            if (! $dimensions || ! isset($dimensions['mime'])) {
                continue;
            }

            // Mesmo teto de GenerateExamDerivatives::imageFromRaster() — o
            // orçamento de BYTES acima não protege contra "bomba de
            // descompressão": um PNG bem comprimido pode ocupar poucos KB
            // no disco (cabe fácil no orçamento) e ainda assim decodificar
            // pra uma imagem gigantesca. Quando o derivado (thumb_archive/
            // display_archive) é rejeitado por isso na importação, archive
            // (o original, nunca validado por dimensão) é o único candidato
            // que sobra aqui, e o wkhtmltopdf (processo externo, memória
            // própria) precisaria decodificar a imagem inteira pra montar o
            // PDF — achado via revisão Codex.
            if ($dimensions[0] > 16000 || $dimensions[1] > 16000 || $dimensions[0] * $dimensions[1] > 40000000) {
                continue;
            }

            // Mesma fórmula de GenerateExamDerivatives::imageFromRaster() —
            // o teto de pixels acima sozinho não basta (achado na verificação
            // adversarial da 11ª rodada): decodificar até 40 megapixels
            // dentro da MESMA requisição HTTP de emissão (não um job em
            // fila) pode faltar memória de verdade (memory_limit=512M,
            // .docker/php/custom.ini), e o fatal error de
            // imagecreatefromstring() por falta de memória NÃO é capturável
            // — quebraria a requisição INTEIRA, não só "esta imagem não
            // entra", a promessa do docblock desta classe.
            $pixels   = $dimensions[0] * $dimensions[1];
            $estimate = $pixels * 16 + min($pixels, 2560 * 2560) * 4 + strlen($raw) * 2 + 16777216;

            if ($estimate > app(RasterMemoryBudget::class)->available()) {
                continue;
            }

            // getimagesizefromstring() só lê o CABEÇALHO — nunca decodifica
            // pixel a pixel. Um arquivo truncado (ex.: upload interrompido,
            // objeto corrompido no S3) pode ter cabeçalho válido (dimensões
            // legíveis) e ainda assim não decodificar de verdade — sem esta
            // checagem, os bytes quebrados eram CONGELADOS no laudo assinado
            // (ver docblock da classe) sem nenhum aviso ao médico, e corrigir
            // o arquivo original no S3 depois nunca corrigia o que já foi
            // congelado (achado na 11ª rodada de revisão adversarial Codex).
            // @ (não throw:false) é o mesmo padrão já usado por
            // GenerateExamDerivatives::imageFromRaster() pro mesmo decode.
            $decoded = @imagecreatefromstring($raw);

            if ($decoded === false) {
                continue;
            }

            imagedestroy($decoded);

            return ['data_uri' => 'data:' . $dimensions['mime'] . ';base64,' . base64_encode($raw), 'raw_size' => strlen($raw)];
        }

        return null;
    }
}
