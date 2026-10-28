<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Domains\CareGuidance\Exceptions\CareGuidanceException;
use App\Domains\CareGuidance\Models\{CareGuidanceAttachment, CareGuidanceTemplate};
use App\Domains\CareGuidance\Services\CareGuidanceTemplateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CareGuidance\{CareGuidanceAttachmentRequest, CareGuidanceTemplateRequest};
use App\Models\Scopes\EntityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\{Inertia, Response as InertiaResponse};
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Configurações › Orientações pós-procedimento (Fase 6 / S14): modelos da clínica, com texto, local, colírios e PDFs
 * anexos. Rotas no grupo `permission:settings.manage` (admin; permissão customizada também libera). Sem route model
 * binding: o modelo é sempre buscado DESTA clínica (de outra → 404).
 */
class CareGuidanceTemplatesController extends Controller
{
    public function __construct(private readonly CareGuidanceTemplateService $templates)
    {
    }

    public function index(): InertiaResponse
    {
        return Inertia::render('Panel/Settings/CareGuidance/Index', [
            'templates'      => $this->templates->list($this->entityId()),
            'maxAttachments' => CareGuidanceTemplateService::MAX_ATTACHMENTS,
            'maxDrops'       => CareGuidanceTemplateService::MAX_DROPS,
            't'              => trans('care_guidance'),
        ]);
    }

    public function store(CareGuidanceTemplateRequest $request): JsonResponse
    {
        try {
            $template = $this->templates->save(null, $this->entityId(), $request->validated());
        } catch (CareGuidanceException $e) {
            return $this->refused($e);
        }

        return response()->json(['message' => __('care_guidance.settings.saved'), 'data' => $this->templates->serialize($template)], 201);
    }

    public function update(CareGuidanceTemplateRequest $request, string $template): JsonResponse
    {
        try {
            $model = $this->templates->save($this->owned($template), $this->entityId(), $request->validated());
        } catch (CareGuidanceException $e) {
            return $this->refused($e);
        }

        return response()->json(['message' => __('care_guidance.settings.saved'), 'data' => $this->templates->serialize($model)]);
    }

    public function destroy(string $template): JsonResponse
    {
        $this->templates->delete($this->owned($template));

        return response()->json(['message' => __('care_guidance.settings.deleted')]);
    }

    public function storeAttachment(CareGuidanceAttachmentRequest $request, string $template): JsonResponse
    {
        $model = $this->owned($template);

        try {
            $this->templates->addAttachment($model, $request->file('file'), (string) $request->user()?->getKey());
        } catch (CareGuidanceException $e) {
            return $this->refused($e);
        }

        return response()->json([
            'message' => __('care_guidance.settings.attachment_added'),
            'data'    => $this->templates->serialize($model->load(['attachments' => static fn ($query) => $query->withoutGlobalScopes(), 'procedure:id,name,code'])),
        ], 201);
    }

    public function destroyAttachment(string $template, string $attachment): JsonResponse
    {
        $model = $this->owned($template);

        try {
            $this->templates->removeAttachment($model, $attachment);
        } catch (CareGuidanceException $e) {
            return $this->refused($e);
        }

        return response()->json([
            'message' => __('care_guidance.settings.attachment_removed'),
            'data'    => $this->templates->serialize($model->load(['attachments' => static fn ($query) => $query->withoutGlobalScopes(), 'procedure:id,name,code'])),
        ]);
    }

    public function downloadAttachment(string $template, string $attachment): StreamedResponse
    {
        $model = $this->owned($template);
        $file  = CareGuidanceAttachment::query()->withoutGlobalScopes()->where('template_id', $model->id)->findOrFail($attachment);

        abort_unless(Storage::disk()->exists($file->path), 404);

        return Storage::disk()->download($file->path, $file->original_name, ['Content-Type' => 'application/pdf']);
    }

    private function owned(string $id): CareGuidanceTemplate
    {
        return CareGuidanceTemplate::query()
            ->withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $this->entityId())
            ->findOrFail($id);
    }

    private function refused(CareGuidanceException $e): JsonResponse
    {
        return response()->json([
            'message' => __('care_guidance.errors.' . $e->reason()),
            'errors'  => $e->field() !== null ? [$e->field() => [__('care_guidance.errors.' . $e->reason())]] : null,
        ], 422);
    }

    private function entityId(): string
    {
        return (string) session('selected_entity_id');
    }
}
