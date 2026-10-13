<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\PatientExam;
use Mews\Purifier\Facades\Purifier;

/**
 * Checagem de posse de tenant pra uma lista de exam_ids — compartilhada
 * entre controllers do Gerenciador de Imagens que recebem exam_ids[] no
 * payload (EyeImageReportController, EyeImageMontageController). Extraído
 * de EyeImageReportController::ownedExamIds() ao construir o Montage
 * (benchmark 18/09/2026) — mesmo comportamento, agora com 2 chamadores.
 */
trait AuthorizesEyeImageExams
{
    /**
     * @param array<int, mixed> $examIds
     * @param ?string           $patientId quando informado, todos os exames
     *                                     precisam ser desse paciente (laudo)
     *
     * @return list<string>
     */
    private function ownedExamIds(array $examIds, string $entityId, ?string $patientId = null): array
    {
        $examIds = array_values(array_unique(array_filter(array_map('strval', $examIds))));

        if ($examIds === []) {
            return [];
        }

        $owned = PatientExam::query()
            ->whereIn('patient_exams.id', $examIds)
            ->whereHas('patient', fn ($q) => $q->where('entity_id', $entityId))
            ->pluck('patient_id', 'patient_exams.id');

        // Nunca falha silenciosamente com um exam_id de outra clínica: 403
        // explícito (mesmo padrão de AiPayloadEnricher::authorizeExamIds).
        abort_if($owned->count() !== count($examIds), 403);

        // Exame de OUTRO paciente da mesma clínica num laudo: o documento
        // iria pro prontuário de um paciente citando exame de outro (LGPD).
        // 422 de regra, como o merge/split (ownedSamePatientExams).
        if ($patientId !== null) {
            abort_if(
                $owned->contains(fn ($examPatientId) => (string) $examPatientId !== $patientId),
                422,
                __('eye_images.report_same_patient'),
            );
        }

        return $owned->keys()->map(fn ($id) => (string) $id)->all();
    }

    /**
     * Bloco de imagens a embutir no laudo (Fase 5 / 5b) — usado tanto pela
     * emissão direta (EyeImageReportController::store()) quanto pelo
     * rascunho (EyeImageReportDraftController), mesma validação. Nunca
     * confia cru no payload: só entram exam_id que já pertencem a ESTE
     * laudo/rascunho ($examIds, já validado por ownedExamIds() antes de
     * chegar aqui). `order` é recalculado pela posição no array, não pelo
     * valor enviado — a ordem de verdade é a ordem em que o médico/
     * secretária organizou a lista no modal. Vazio ou desabilitado -> null
     * (sem bloco no metadata/payload).
     *
     * @param list<string> $examIds
     */
    private function resolveImageReportBlock(?array $input, array $examIds): ?array
    {
        if (! ($input['enabled'] ?? false)) {
            return null;
        }

        $rawImages = collect($input['images'] ?? []);

        // Imagem referenciando um exam_id fora da seleção principal (ex.:
        // usuário desmarcou o exame DEPOIS de montar o bloco de imagens, e
        // a galeria não se resincronizou) nunca pode ser descartada em
        // silêncio: resolveForIssuance() conta "faltando" DEPOIS deste
        // ponto, então o aviso de laudo incompleto nunca disparava pra essa
        // imagem — o laudo saía com uma imagem a menos do que a tela
        // mostrava, sem nenhum aviso (achado via revisão adversarial Codex).
        abort_if(
            $rawImages->contains(fn (array $item) => ! in_array($item['exam_id'] ?? null, $examIds, true)),
            422,
            __('eye_images.report_image_not_selected'),
        );

        $images = $rawImages
            ->values()
            ->map(fn (array $item, int $idx) => [
                'exam_id' => $item['exam_id'],
                'caption' => $item['caption'] ?? null,
                'order'   => $idx,
            ])
            ->all();

        if ($images === []) {
            return null;
        }

        return [
            'enabled'    => true,
            'columns'    => $input['columns'] ?? (int) config('eye_image_capture.image_report.default_columns'),
            'pair_od_oe' => (bool) ($input['pair_od_oe'] ?? false),
            'images'     => $images,
        ];
    }

    /**
     * Sanitiza o conteúdo (Purifier, profile 'eye_image_report') e revalida
     * toda referência inline <img data-exam-image-id> contra $examIds —
     * mesma regra/mensagem de resolveImageReportBlock() acima: imagem fora
     * da seleção principal do laudo nunca é aceita em silêncio (um exame
     * desmarcado DEPOIS de inserir a imagem no texto, por exemplo).
     *
     * O profile permite <img src=...> (src é atributo obrigatório pro
     * HTMLPurifier — sem ele no HTML.Allowed, o elemento inteiro cai), mas
     * a src que sobrevive à purificação NUNCA é confiável (pode ser a URL
     * assinada crua que o cliente mandou) — removida sempre aqui. Content
     * guarda só a referência (data-exam-image-id); quem precisa do byte de
     * verdade resolve de novo (ver ReportImageBlockBuilder::
     * freezeInlineImages(), MedicalRecordDocumentation::contentForRender()).
     *
     * @param list<string> $examIds
     */
    private function sanitizeReportContent(?string $html, array $examIds): ?string
    {
        if ($html === null) {
            return null;
        }

        $cleaned = Purifier::clean($html, 'eye_image_report');

        if (! str_contains($cleaned, '<img')) {
            return $cleaned;
        }

        $invalidRef = false;

        $result = preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $match) use ($examIds, &$invalidRef): string {
                $tag = preg_replace('/\ssrc="[^"]*"/i', '', $match[0]) ?? $match[0];

                if (
                    ! preg_match('/\sdata-exam-image-id="([^"]+)"/i', $tag, $idMatch)
                    || ! in_array($idMatch[1], $examIds, true)
                ) {
                    $invalidRef = true;
                }

                return $tag;
            },
            $cleaned,
        ) ?? $cleaned;

        abort_if($invalidRef, 422, __('eye_images.report_image_not_selected'));

        return $result;
    }
}
