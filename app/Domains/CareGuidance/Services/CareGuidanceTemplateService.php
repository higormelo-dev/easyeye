<?php

declare(strict_types=1);

namespace App\Domains\CareGuidance\Services;

use App\Domains\CareGuidance\Exceptions\CareGuidanceException as Refused;
use App\Domains\CareGuidance\Models\{CareGuidanceAttachment, CareGuidanceTemplate};
use App\Models\Scopes\EntityScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;

/**
 * Modelos de orientação pós-procedimento da clínica (Fase 6 / S14): criar, editar, excluir e os PDFs anexos. O que o
 * paciente recebe é o retrato congelado da emissão (CareGuidanceIssueService), então editar ou excluir o modelo nunca
 * muda uma orientação já entregue.
 *
 * Modelo excluído (soft delete) sai de TODA busca: as consultas tiram só o escopo de tenant (`EntityScope`, que depende da
 * sessão) e filtram `entity_id` à mão; `withoutGlobalScopes()` levaria junto o filtro de excluídos.
 *
 * PDF anexo: só PDF de verdade (cabeçalho `%PDF-`, não só a extensão), até 5 MB e 5 por modelo (conferido sob a trava do
 * modelo: dois envios simultâneos não passam do teto).
 */
final class CareGuidanceTemplateService
{
    public const MAX_ATTACHMENTS = 5;

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_DROPS = 10;

    /** @return list<array<string, mixed>> */
    public function list(string $entityId): array
    {
        return CareGuidanceTemplate::query()->withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entityId)
            ->with(['attachments' => static fn ($query) => $query->withoutGlobalScopes(), 'procedure:id,name,code'])
            ->orderByDesc('active')
            ->orderBy('title')
            ->get()
            ->map(fn (CareGuidanceTemplate $template): array => $this->serialize($template))
            ->values()
            ->all();
    }

    /**
     * @param array{title: string, procedure_id?: ?string, location_label?: ?string, body: string, eye_drops?: ?array<int, array<string, mixed>>, active?: bool} $data
     *
     * @throws Refused
     */
    public function save(?CareGuidanceTemplate $template, string $entityId, array $data): CareGuidanceTemplate
    {
        $payload = [
            'title'          => trim((string) $data['title']),
            'procedure_id'   => filled($data['procedure_id'] ?? null) ? (string) $data['procedure_id'] : null,
            'location_label' => filled($data['location_label'] ?? null) ? mb_substr(trim((string) $data['location_label']), 0, 160) : null,
            'body'           => trim((string) $data['body']),
            'eye_drops'      => $this->normalizeDrops($data['eye_drops'] ?? []),
            'active'         => (bool) ($data['active'] ?? true),
        ];

        if ($template === null) {
            return CareGuidanceTemplate::query()->withoutGlobalScopes()->create($payload + ['entity_id' => $entityId])->load(['attachments', 'procedure:id,name,code']);
        }

        $template->fill($payload)->save();

        return $template->load(['attachments' => static fn ($query) => $query->withoutGlobalScopes(), 'procedure:id,name,code']);
    }

    /** Exclui o modelo (soft) e os PDFs dele; as emissões já feitas guardam a PRÓPRIA cópia. */
    public function delete(CareGuidanceTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            $files = CareGuidanceAttachment::query()->withoutGlobalScopes()->where('template_id', $template->id)->pluck('path')->all();

            CareGuidanceAttachment::query()->withoutGlobalScopes()->where('template_id', $template->id)->delete();
            $template->delete();

            // Arquivo só sai do disco depois do commit (rollback não pode deixar linha sem arquivo).
            DB::afterCommit(static fn () => Storage::disk()->delete($files));
        });
    }

    /** @throws Refused */
    public function addAttachment(CareGuidanceTemplate $template, UploadedFile $file, ?string $userId = null): CareGuidanceAttachment
    {
        $this->assertPdf($file);

        return DB::transaction(function () use ($template, $file, $userId): CareGuidanceAttachment {
            // Trava o modelo: dois envios ao mesmo tempo contam o teto um depois do outro.
            CareGuidanceTemplate::query()->withoutGlobalScope(EntityScope::class)->whereKey($template->id)->lockForUpdate()->firstOrFail();

            $count = CareGuidanceAttachment::query()->withoutGlobalScopes()->where('template_id', $template->id)->count();

            if ($count >= self::MAX_ATTACHMENTS) {
                throw Refused::make(Refused::ATTACHMENT_LIMIT, 'Limite de ' . self::MAX_ATTACHMENTS . ' PDFs por modelo.', 'file');
            }

            $sha  = hash_file('sha256', $file->getRealPath());
            $path = Storage::disk()->putFileAs(
                "care-guidance/{$template->entity_id}/templates/{$template->id}",
                $file,
                Str::uuid() . '.pdf',
                'private',
            );

            if ($path === false) {
                throw Refused::make(Refused::ATTACHMENT_MISSING_FILE, 'Não foi possível guardar o arquivo.', 'file');
            }

            // Linha que não vingar (rollback) não pode deixar o arquivo órfão.
            DB::afterRollBack(static fn () => Storage::disk()->delete($path));

            return CareGuidanceAttachment::query()->withoutGlobalScopes()->create([
                'entity_id'     => $template->entity_id,
                'template_id'   => $template->id,
                'original_name' => $this->safeName($file->getClientOriginalName()),
                'path'          => $path,
                'size_bytes'    => (int) $file->getSize(),
                'sha256'        => $sha,
                'uploaded_by'   => $userId,
            ]);
        });
    }

    /** @throws Refused */
    public function removeAttachment(CareGuidanceTemplate $template, string $attachmentId): void
    {
        $attachment = CareGuidanceAttachment::query()->withoutGlobalScopes()
            ->where('template_id', $template->id)
            ->whereKey($attachmentId)
            ->first();

        if ($attachment === null) {
            throw Refused::make(Refused::ATTACHMENT_NOT_FOUND, 'Anexo não encontrado neste modelo.');
        }

        $path = $attachment->path;

        DB::transaction(function () use ($attachment, $path): void {
            $attachment->delete();
            DB::afterCommit(static fn () => Storage::disk()->delete($path));
        });
    }

    /**
     * Colírios do esquema: tira linhas vazias, apara o texto, limita o tamanho e a quantidade.
     *
     * @param array<int, array<string, mixed>>|null $drops
     *
     * @return list<array{name: string, how: ?string, times_per_day: ?int, days: ?int}>
     *
     * @throws Refused
     */
    public function normalizeDrops(?array $drops): array
    {
        $rows = [];

        foreach ((array) $drops as $drop) {
            $name = mb_substr(trim((string) ($drop['name'] ?? '')), 0, 120);

            if ($name === '') {
                continue;
            }

            $times = $drop['times_per_day'] ?? null;
            $days  = $drop['days'] ?? null;

            $rows[] = [
                'name'          => $name,
                'how'           => filled($drop['how'] ?? null) ? mb_substr(trim((string) $drop['how']), 0, 200) : null,
                'times_per_day' => $times === null || $times === '' ? null : max(1, min(12, (int) $times)),
                'days'          => $days === null || $days === '' ? null : max(1, min(90, (int) $days)),
            ];
        }

        if (count($rows) > self::MAX_DROPS) {
            throw Refused::make(Refused::TOO_MANY_DROPS, 'No máximo ' . self::MAX_DROPS . ' colírios por orientação.', 'eye_drops');
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function serialize(CareGuidanceTemplate $template): array
    {
        return [
            'id'             => (string) $template->id,
            'title'          => $template->title,
            'procedure_id'   => $template->procedure_id !== null ? (string) $template->procedure_id : null,
            'procedure_name' => $template->procedure?->name,
            'location_label' => $template->location_label,
            'body'           => $template->body,
            'eye_drops'      => $template->eye_drops ?? [],
            'active'         => (bool) $template->active,
            'attachments'    => $template->attachments->map(static fn (CareGuidanceAttachment $attachment): array => [
                'id'         => (string) $attachment->id,
                'name'       => $attachment->original_name,
                'size_bytes' => $attachment->size_bytes,
            ])->values()->all(),
        ];
    }

    /** @throws Refused */
    private function assertPdf(UploadedFile $file): void
    {
        if (! $file->isValid() || $file->getRealPath() === false) {
            throw Refused::make(Refused::ATTACHMENT_MISSING_FILE, 'Envio inválido.', 'file');
        }

        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw Refused::make(Refused::ATTACHMENT_TOO_LARGE, 'O PDF passa de 5 MB.', 'file');
        }

        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 5);

        if ($head !== '%PDF-') {
            throw Refused::make(Refused::ATTACHMENT_NOT_PDF, 'O arquivo não é um PDF.', 'file');
        }
    }

    private function safeName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $name) ?? '');

        return mb_substr($name !== '' ? $name : 'anexo.pdf', 0, 200);
    }
}
