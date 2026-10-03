<?php

declare(strict_types=1);

namespace App\Services\EyeImages;

use App\Models\PatientExam;
use App\Services\{BoundedExamArchive, BoundedPdfProcess};
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extração de texto puro do PDF nativo de um equipamento (Pentacam etc.)
 * pro editor de laudo — benchmark contra concorrente (Ger Exames/
 * iWayBrasil, "Extraindo texto do PDF..." no vídeo de referência do
 * ticket). Extração LITERAL do texto já presente no PDF do fabricante —
 * nunca interpretação/resumo (isso seria fabricar conteúdo clínico).
 */
class PdfTextExtractionService
{
    public function __construct(
        private readonly Parser $parser,
    ) {
    }

    public function isPdf(PatientExam $exam): bool
    {
        return $exam->archive !== null && str_ends_with(strtolower($exam->archive), '.pdf');
    }

    /**
     * Extrai o texto de um exame cujo archive é um PDF. Retorna null se o
     * arquivo não existir mais no storage ou não puder ser parseado (PDF
     * escaneado como imagem, corrompido, protegido por senha etc.) — nunca
     * lança pra não derrubar a extração de outros exam_ids no lote.
     */
    public function extract(PatientExam $exam): ?string
    {
        if (! $this->isPdf($exam) || ! Storage::disk('s3')->exists($exam->archive)) {
            return null;
        }

        try {
            $content = app(BoundedExamArchive::class)->read($exam->archive);
            $tmp     = tempnam(sys_get_temp_dir(), 'pdf-text-');

            if ($tmp === false) {
                return null;
            }

            try {
                file_put_contents($tmp, $content);
                $text = app(BoundedPdfProcess::class)->run('text', $tmp);
            } finally {
                @unlink($tmp);
            }
        } catch (Throwable) {
            return null;
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * @param list<PatientExam> $exams
     *
     * @return list<string> um item de texto por exame que extraiu algo
     *                      (exames sem PDF/sem texto são omitidos, não
     *                      viram string vazia no meio do array)
     */
    public function extractMany(array $exams): array
    {
        $texts = [];

        foreach ($exams as $exam) {
            $text = $this->extract($exam);

            if ($text !== null) {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
