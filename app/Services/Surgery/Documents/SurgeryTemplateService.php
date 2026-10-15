<?php

declare(strict_types=1);

namespace App\Services\Surgery\Documents;

use App\Enums\Surgery\SurgeryDocumentType;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Surgery\{SurgeryConsentTemplate, SurgicalCase};
use App\Models\SurgeryType;
use App\Support\Database\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mews\Purifier\Facades\Purifier;
use Throwable;

/**
 * Modelos de TCLE e de orientações pré-operatórias da clínica.
 *
 * O corpo é sempre sanitizado (Purifier 'medical') ao gravar. `version` sobe só
 * quando o CORPO muda — a emissão guarda a versão usada. Modelo apagado
 * (soft delete) nunca "ressuscita" pela adoção dos padrões: a chave do padrão
 * fica reservada à clínica.
 */
class SurgeryTemplateService
{
    public function __construct(private readonly SurgeryTemplateVariables $variables)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(string $entityId, array $data): SurgeryConsentTemplate
    {
        $this->assertType($data['type'] ?? null);
        $this->assertCategory($data['category'] ?? null);
        $this->assertVariables((string) $data['body']);

        return SurgeryConsentTemplate::query()->create([
            'entity_id' => $entityId,
            'type'      => $data['type'],
            'category'  => $data['category'] ?? null,
            'name'      => trim((string) $data['name']),
            'body'      => $this->sanitize((string) $data['body']),
            'active'    => (bool) ($data['active'] ?? true),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(SurgeryConsentTemplate $template, array $data): SurgeryConsentTemplate
    {
        return DB::transaction(function () use ($template, $data): SurgeryConsentTemplate {
            /** @var SurgeryConsentTemplate $locked */
            $locked = SurgeryConsentTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

            if (array_key_exists('category', $data)) {
                $this->assertCategory($data['category']);
                $locked->category = $data['category'];
            }

            if (array_key_exists('name', $data)) {
                $locked->name = trim((string) $data['name']);
            }

            if (array_key_exists('active', $data)) {
                $locked->active = (bool) $data['active'];
            }

            // O tipo não muda depois de criado: um TCLE não vira orientação.
            if (array_key_exists('body', $data)) {
                $this->assertVariables((string) $data['body']);
                $body = $this->sanitize((string) $data['body']);

                if ($body !== $locked->body) {
                    $locked->body    = $body;
                    $locked->version = $locked->version + 1;
                }
            }

            $locked->save();

            return $locked;
        });
    }

    public function setActive(SurgeryConsentTemplate $template, bool $active): SurgeryConsentTemplate
    {
        $template->update(['active' => $active]);

        return $template;
    }

    public function duplicate(SurgeryConsentTemplate $template): SurgeryConsentTemplate
    {
        return SurgeryConsentTemplate::query()->create([
            'entity_id' => $template->entity_id,
            'type'      => $template->type,
            'category'  => $template->category,
            'name'      => mb_substr(trim($template->name) . ' ' . (string) __('surgery_documents.copy_suffix'), 0, 150),
            'body'      => $template->body,
            'active'    => true,
        ]);
    }

    public function delete(SurgeryConsentTemplate $template): void
    {
        $template->delete();
    }

    /**
     * Adota os modelos padrão que a clínica ainda não tem. Idempotente e
     * não destrutivo: uma chave já presente (inclusive personalizada, inativa
     * ou apagada) nunca é recriada nem alterada.
     *
     * @return array{created: int, skipped: int}
     */
    public function adoptDefaults(string $entityId, ?string $locale = null): array
    {
        $created = 0;
        $skipped = 0;

        $existing = SurgeryConsentTemplate::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('entity_id', $entityId)
            ->whereNotNull('default_key')
            ->pluck('default_key')
            ->flip();

        foreach (SurgeryDefaultTemplates::catalog($locale) as $entry) {
            if ($existing->has($entry['key'])) {
                $skipped++;

                continue;
            }

            try {
                // Savepoint: duas adoções ao mesmo tempo colidem no índice único
                // (clínica, chave) — a perdedora só pula.
                DB::transaction(function () use ($entityId, $entry): void {
                    $template = new SurgeryConsentTemplate([
                        'entity_id' => $entityId,
                        'type'      => $entry['type'],
                        'category'  => $entry['category'],
                        'name'      => $entry['name'],
                        'body'      => $this->sanitize($entry['body']),
                        'active'    => true,
                    ]);
                    $template->default_key = $entry['key'];
                    $template->save();
                });

                $created++;
            } catch (QueryException $e) {
                if (! UniqueViolation::isUniqueViolation($e)) {
                    throw $e;
                }

                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Pré-visualização de um corpo (ainda não salvo) com dados de exemplo.
     *
     * @return array{html: string, pending: list<string>, unknown: list<string>}
     */
    public function preview(string $body): array
    {
        $resolved = $this->variables->resolve($this->sanitize($body), $this->variables->sample());

        return [
            'html'    => $this->sanitize($resolved['html']),
            'pending' => $resolved['pending'],
            'unknown' => $resolved['unknown'],
        ];
    }

    /** Quantos modelos padrão ainda faltam adotar nesta clínica. */
    public function missingDefaults(string $entityId): int
    {
        $have = SurgeryConsentTemplate::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('entity_id', $entityId)
            ->whereNotNull('default_key')
            ->pluck('default_key')
            ->all();

        return count(array_diff(SurgeryDefaultTemplates::keys(), $have));
    }

    /**
     * Modelos ativos de um tipo, com os mais adequados ao caso primeiro: mesma
     * categoria da cirurgia → modelos gerais (sem categoria) → o resto.
     *
     * @return Collection<int, SurgeryConsentTemplate>
     */
    public function suggestionsFor(SurgicalCase $case, SurgeryDocumentType $type): Collection
    {
        $category = $case->surgeryType?->category;

        return SurgeryConsentTemplate::query()
            ->where('entity_id', $case->entity_id)
            ->where('type', $type->value)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->sortBy(fn (SurgeryConsentTemplate $t) => match (true) {
                $category !== null && $t->category === (int) $category => 0,
                $t->category === null                                  => 1,
                default                                                => 2,
            })
            ->values();
    }

    public function isSuggested(SurgicalCase $case, SurgeryConsentTemplate $template): bool
    {
        $category = $case->surgeryType?->category;

        return $category !== null && $template->category === (int) $category;
    }

    /** @return array<int, string> categorias de cirurgia (id → nome). */
    public static function categoryOptions(): array
    {
        return SurgeryType::$categories;
    }

    private function sanitize(string $html): string
    {
        return Purifier::clean($html, 'medical');
    }

    private function assertType(mixed $type): void
    {
        $valid = array_map(fn (SurgeryDocumentType $t) => $t->value, SurgeryConsentTemplate::TYPES);

        if (! in_array($type instanceof SurgeryDocumentType ? $type->value : $type, $valid, true)) {
            throw SurgeryRuleException::invalid('surgery_documents.errors.template_type');
        }
    }

    private function assertCategory(mixed $category): void
    {
        if ($category !== null && ! array_key_exists((int) $category, SurgeryType::$categories)) {
            throw SurgeryRuleException::invalid('surgery_documents.errors.template_category');
        }
    }

    /** Variável que o sistema não conhece estraga o documento: barra ao salvar o modelo. */
    private function assertVariables(string $body): void
    {
        try {
            $unknown = $this->variables->unknownIn($body);
        } catch (Throwable) {
            $unknown = [];
        }

        if ($unknown !== []) {
            throw SurgeryRuleException::invalid('surgery_documents.errors.unknown_variables', ['variables' => '{{' . implode('}}, {{', $unknown) . '}}']);
        }
    }
}
