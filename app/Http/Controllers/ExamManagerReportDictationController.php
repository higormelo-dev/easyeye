<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\AI\Exceptions\SpeechTranscriptionException;
use App\Domains\AI\Services\SpeechTranscriptionService;
use App\Enums\AI\AiProvider;
use App\Enums\EntityGate;
use App\Models\Entity;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Gate, Log};
use Throwable;

/**
 * Ditado por voz do laudo de imagem (Fase 5). Recebe um WAV curto, transcreve
 * no provedor aprovado pela política LGPD (SpeechTranscriptionService) e
 * devolve só o texto. Mesmo allowlist de quem prepara o laudo (Gate
 * DraftImageReport — admin, doctor, secretary): o texto é inserido no editor
 * do laudo, nunca vira ato clínico por si.
 *
 * O áudio não é gravado. A trilha de auditoria registra QUE um áudio saiu
 * (quem, provedor, modelo, duração, tamanho e SHA-256) — nunca o áudio nem o
 * texto — para a clínica provar, em pedido do titular, o que foi enviado e a
 * quem. A trilha é gravada ANTES de o áudio sair, numa transação já comitada, e
 * falha fechado: se não gravou, o áudio não é enviado (o AuditLogger comum
 * engole erro de banco; aqui isso entregaria dado de saúde sem registro). O
 * resultado (transcrito, sem fala, falha do provedor) completa a mesma linha
 * depois.
 */
class ExamManagerReportDictationController extends Controller
{
    public const AUDIT_EVENT = 'eye_images.report.dictation';

    public function __construct(
        private readonly SpeechTranscriptionService $transcription,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        Gate::authorize(EntityGate::DraftImageReport->value, Entity::findOrFail($entityId));

        // O cliente decide a duração que declara; o servidor mede no cabeçalho do WAV.
        $request->validate([
            'audio' => ['required', 'file', 'max:' . (int) config('ai.transcription.max_kb', 6144)],
        ]);

        $path    = (string) $request->file('audio')?->getRealPath();
        $auditId = null;

        try {
            $result = $this->transcription->transcribe($path, function (array $dispatch) use ($request, $entityId, $path, &$auditId) {
                $auditId = $this->recordDispatch($request, $entityId, $path, $dispatch);
            });
        } catch (SpeechTranscriptionException $e) {
            // Sem $auditId nada saiu (áudio inválido, recurso indisponível, trilha que não gravou).
            if ($auditId !== null) {
                $this->audit->completeAdminAction($auditId, ['outcome' => $this->outcomeFor($e)]);
            }

            return response()->json(['message' => $e->userMessage()], $e->httpStatus());
        }

        $this->audit->completeAdminAction($auditId, [
            'outcome'         => 'transcribed',
            'text_characters' => mb_strlen($result['text']),
        ]);

        return response()->json(['text' => $result['text']]);
    }

    /**
     * Grava a intenção de envio (comitada antes da transferência). Qualquer
     * falha vira SpeechTranscriptionException::AUDIT_FAILED e o áudio não sai.
     *
     * @param array{provider: AiProvider, model: string, seconds: float} $dispatch
     */
    private function recordDispatch(Request $request, string $entityId, string $path, array $dispatch): string
    {
        try {
            return DB::transaction(fn () => $this->audit->recordAdminActionOrFail(
                event: self::AUDIT_EVENT,
                targetEntityId: $entityId,
                targetUserId: null,
                auditableType: 'entity',
                auditableId: $entityId,
                reason: __('eye_images.dictation_audit_reason'),
                newValues: [
                    'provider'      => $dispatch['provider']->value,
                    'model'         => $dispatch['model'],
                    'audio_seconds' => round($dispatch['seconds'], 1),
                    'audio_bytes'   => (int) filesize($path),
                    'audio_sha256'  => hash_file('sha256', $path),
                    'outcome'       => 'sending',
                ],
                request: $request,
            ));
        } catch (Throwable $e) {
            // Só a classe e o código: a mensagem do driver pode trazer valores da linha.
            Log::error('speech.transcription.audit_failed', ['exception' => $e::class, 'code' => $e->getCode()]);

            throw new SpeechTranscriptionException(SpeechTranscriptionException::AUDIT_FAILED);
        }
    }

    private function outcomeFor(SpeechTranscriptionException $e): string
    {
        return match ($e->translationKey) {
            SpeechTranscriptionException::NO_SPEECH       => 'no_speech',
            SpeechTranscriptionException::PROVIDER_FAILED => 'provider_failed',
            default                                       => 'not_sent',
        };
    }
}
