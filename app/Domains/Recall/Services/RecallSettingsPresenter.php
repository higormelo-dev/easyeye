<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Enums\{ProtocolVersionState as State, RecallTaskKind};
use App\Domains\Recall\Models\{RecallClinicProtocol, RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Support\{ProtocolContentDiff, ProtocolContentSchema, ValidationEvidence};
use Carbon\CarbonImmutable;

/**
 * O que a tela de configuração dos retornos mostra de cada protocolo: a regra que a clínica pode adotar (a versão
 * LIBERADA, com a prova de quem validou), o que ela já adotou (versão, parâmetros, quem ativou) e o que mudou numa
 * versão nova — tudo montado no servidor, com o conteúdo NORMALIZADO que o mesmo `RecallRuleCard` do Manager e da
 * página do Revisor renderiza (o que o Revisor aprovou é o que a clínica lê).
 *
 * Estado do protocolo na clínica:
 *  - `available`        — liberado e validado, ainda não ativado;
 *  - `active`           — ativado e rodando;
 *  - `update_available` — ativado, e há versão nova liberada (a clínica vê o que mudou e decide trocar);
 *  - `paused`           — ativado, mas a versão está suspensa/aposentada/sem integridade: nada novo é calculado
 *                         (se há versão liberada, a clínica vê o que mudou e pode trocar para ela);
 *  - `unavailable`      — nenhuma versão liberada (nada a ativar).
 */
class RecallSettingsPresenter
{
    /**
     * @return list<array<string, mixed>>
     */
    public function protocols(string $entityId, string $locale, ?CarbonImmutable $today = null): array
    {
        $today     = ($today ?? CarbonImmutable::now())->startOfDay();
        $adoptions = RecallClinicProtocol::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->whereNull('deactivated_at')
            ->with('version')
            ->get()
            ->keyBy(static fn (RecallClinicProtocol $adoption): string => (string) $adoption->protocol_id);

        $out = [];

        foreach (RecallProtocol::query()->active()->orderBy('sort_order')->orderBy('key')->with('versions')->get() as $protocol) {
            /** @var RecallProtocolVersion|null $released */
            $released = $protocol->versions->first(static fn (RecallProtocolVersion $version): bool => $version->state === State::Released);
            $adoption = $adoptions->get((string) $protocol->id);

            if ($released === null && $adoption === null) {
                continue; // nada liberado e nada adotado: a clínica não tem o que ver aqui
            }

            $out[] = $this->protocol($protocol, $released, $adoption, $locale, $today);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function protocol(RecallProtocol $protocol, ?RecallProtocolVersion $released, ?RecallClinicProtocol $adoption, string $locale, CarbonImmutable $today): array
    {
        $translations = (array) $protocol->translations;
        $texts        = $translations[$locale] ?? $translations['pt_BR'] ?? [];

        $releasedView = $released !== null && ValidationEvidence::holds($released) ? $this->version($released, $today) : null;
        $adoptionView = $adoption !== null ? $this->adoption($adoption, $today) : null;

        $status = match (true) {
            $adoptionView !== null && ! $adoptionView['usable']                                                     => 'paused',
            $adoptionView !== null && $releasedView !== null && $releasedView['id'] !== $adoptionView['version_id'] => 'update_available',
            $adoptionView !== null                                                                                  => 'active',
            $releasedView !== null                                                                                  => 'available',
            default                                                                                                 => 'unavailable',
        };

        // A versão que o formulário ativa: a liberada (ativação nova ou troca); sem ela, a que a clínica já usa.
        $target = $releasedView ?? ($adoptionView !== null && $adoptionView['usable'] ? $adoptionView['version'] : null);

        // O que mudou existe sempre que há versão liberada DIFERENTE da adotada — inclusive com a adoção parada
        // (versão suspensa/aposentada): é a hora em que a clínica mais precisa ver o que vai assumir.
        $changed = $adoptionView !== null && $releasedView !== null && $releasedView['id'] !== $adoptionView['version_id'];

        return [
            'id'       => (string) $protocol->id,
            'key'      => (string) $protocol->key,
            'title'    => (string) ($texts['title'] ?? $protocol->key),
            'summary'  => (string) ($texts['summary'] ?? ''),
            'status'   => $status,
            'released' => $releasedView,
            'adoption' => $adoptionView,
            'diff'     => $changed ? ProtocolContentDiff::between($adoptionView['version']['content'] ?? null, $releasedView['content']) : [],
            'tasks'    => $target !== null ? $this->tasks($target['content'], $adoptionView !== null && $adoptionView['version_id'] === $target['id'] ? (array) $adoptionView['parameters'] : []) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function version(RecallProtocolVersion $version, CarbonImmutable $today): array
    {
        return [
            'id'       => (string) $version->id,
            'version'  => (int) $version->version,
            'content'  => ProtocolContentSchema::normalize($version->contentArray()),
            'evidence' => ValidationEvidence::summary($version, $today),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adoption(RecallClinicProtocol $adoption, CarbonImmutable $today): array
    {
        $version = $adoption->version;
        $reason  = match (true) {
            ! $version->state->isInUse()                               => $version->state->value,
            ! $version->integrityOk()                                  => 'integrity',
            $version->content_hash !== $adoption->adopted_content_hash => 'hash',
            default                                                    => null,
        };

        return [
            'id'           => (string) $adoption->id,
            'version_id'   => (string) $version->id,
            'version'      => $this->version($version, $today),
            'usable'       => $reason === null,
            'pause_reason' => $reason,
            'parameters'   => (array) $adoption->parameters,
            'auto_contact' => (bool) $adoption->auto_contact,
            'activated_at' => $adoption->activated_at?->toDateString(),
            'activated_by' => $adoption->activation_snapshot['doctor']['name'] ?? null,
            'adopted_hash' => substr((string) $adoption->adopted_content_hash, 2, 12),
        ];
    }

    /**
     * As tarefas que a clínica ajusta no formulário (o curso de tratamento tem a própria tela), com a faixa validada
     * e o valor atual (o da adoção na mesma versão, senão o padrão validado).
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function tasks(array $content, array $parameters): array
    {
        $rows = [];

        foreach ((array) $content['tasks'] as $task) {
            if ($task['kind'] === RecallTaskKind::TreatmentCourse->value) {
                continue;
            }

            $current = (array) ($parameters['tasks'][$task['key']] ?? []);
            $range   = (array) $task['interval'];

            $rows[] = [
                'key'      => (string) $task['key'],
                'kind'     => (string) $task['kind'],
                'exam_key' => $task['exam_key'] ?? null,
                'required' => (bool) ($task['required'] ?? false),
                'unit'     => (string) $range['unit'],
                'default'  => (int) $range['default'],
                'min'      => (int) $range['min'],
                'max'      => (int) $range['max'],
                'interval' => (int) ($current['interval'] ?? $range['default']),
                'enabled'  => (bool) ($task['required'] ?? false) || (bool) ($current['enabled'] ?? false),
            ];
        }

        return $rows;
    }
}
