<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

/**
 * Diferença entre dois conteúdos de protocolo NORMALIZADOS — o que o Revisor lê junto
 * da regra ("o que mudou desde a versão que você já validou"). Fonte única: o
 * Manager, a página do Revisor e a tela de adoção da clínica usam esta lista, então
 * ninguém vê uma diferença que o hash não cobre.
 *
 * Cada mudança é um item achatado, sem texto de tela (a interface traduz pelo
 * `section` + `field`): {section, op, key?, field?, value?, from?, to?}.
 * `section`: cid.strong|cid.weak|cid.exclude, medication, task, doctor_order,
 * reference, rationale, change_note. `op`: added | removed | changed.
 *
 * Sem versão anterior (primeira versão) não há diferença: devolve lista vazia.
 */
final class ProtocolContentDiff
{
    private const TASK_FIELDS = [
        'kind', 'exam_key', 'interval.unit', 'interval.default', 'interval.min', 'interval.max',
        'lead_days', 'grace_days', 'lost_after_days', 'starts_after_months', 'recurs', 'required',
    ];

    private const REFERENCE_FIELDS = ['year', 'evidence', 'note'];

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>      $new
     *
     * @return list<array<string, mixed>>
     */
    public static function between(?array $old, array $new): array
    {
        if ($old === null) {
            return [];
        }

        $changes = [];

        foreach (['strong', 'weak', 'exclude'] as $list) {
            $changes = [...$changes, ...self::sets("cid.{$list}", (array) $old['entry']['cid'][$list], (array) $new['entry']['cid'][$list])];
        }

        $changes = [...$changes, ...self::sets('medication', (array) $old['entry']['medications'], (array) $new['entry']['medications'])];
        $changes = [...$changes, ...self::tasks((array) $old['tasks'], (array) $new['tasks'])];

        if ($old['doctor_order']['cap_to_max'] !== $new['doctor_order']['cap_to_max']) {
            $changes[] = ['section' => 'doctor_order', 'op' => 'changed', 'field' => 'cap_to_max', 'from' => $old['doctor_order']['cap_to_max'], 'to' => $new['doctor_order']['cap_to_max']];
        }

        $changes = [...$changes, ...self::references((array) $old['references'], (array) $new['references'])];

        foreach (['rationale', 'change_note'] as $text) {
            if ($old[$text] !== $new[$text]) {
                $changes[] = ['section' => $text, 'op' => 'changed', 'from' => $old[$text], 'to' => $new[$text]];
            }
        }

        return $changes;
    }

    /**
     * @param list<string> $old
     * @param list<string> $new
     *
     * @return list<array<string, mixed>>
     */
    private static function sets(string $section, array $old, array $new): array
    {
        $changes = [];

        foreach (array_diff($new, $old) as $value) {
            $changes[] = ['section' => $section, 'op' => 'added', 'value' => $value];
        }

        foreach (array_diff($old, $new) as $value) {
            $changes[] = ['section' => $section, 'op' => 'removed', 'value' => $value];
        }

        return $changes;
    }

    /**
     * Tarefas casadas por `key`.
     *
     * @param list<array<string, mixed>> $old
     * @param list<array<string, mixed>> $new
     *
     * @return list<array<string, mixed>>
     */
    private static function tasks(array $old, array $new): array
    {
        $oldByKey = array_column($old, null, 'key');
        $changes  = [];

        foreach ($new as $task) {
            $key = (string) $task['key'];

            if (! isset($oldByKey[$key])) {
                $changes[] = ['section' => 'task', 'op' => 'added', 'key' => $key, 'value' => $task];

                continue;
            }

            foreach (self::TASK_FIELDS as $field) {
                $before = self::dig($oldByKey[$key], $field);
                $after  = self::dig($task, $field);

                if ($before !== $after) {
                    $changes[] = ['section' => 'task', 'op' => 'changed', 'key' => $key, 'field' => $field, 'from' => $before, 'to' => $after];
                }
            }

            unset($oldByKey[$key]);
        }

        foreach ($oldByKey as $key => $task) {
            $changes[] = ['section' => 'task', 'op' => 'removed', 'key' => (string) $key, 'value' => $task];
        }

        return $changes;
    }

    /**
     * Referências casadas pelo título.
     *
     * @param list<array<string, mixed>> $old
     * @param list<array<string, mixed>> $new
     *
     * @return list<array<string, mixed>>
     */
    private static function references(array $old, array $new): array
    {
        $oldByTitle = array_column($old, null, 'title');
        $changes    = [];

        foreach ($new as $reference) {
            $title = (string) $reference['title'];

            if (! isset($oldByTitle[$title])) {
                $changes[] = ['section' => 'reference', 'op' => 'added', 'key' => $title, 'value' => $reference];

                continue;
            }

            foreach (self::REFERENCE_FIELDS as $field) {
                if ($oldByTitle[$title][$field] !== $reference[$field]) {
                    $changes[] = ['section' => 'reference', 'op' => 'changed', 'key' => $title, 'field' => $field, 'from' => $oldByTitle[$title][$field], 'to' => $reference[$field]];
                }
            }

            unset($oldByTitle[$title]);
        }

        foreach ($oldByTitle as $title => $reference) {
            $changes[] = ['section' => 'reference', 'op' => 'removed', 'key' => (string) $title, 'value' => $reference];
        }

        return $changes;
    }

    /** @param array<string, mixed> $array */
    private static function dig(array $array, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            $array = $array[$segment] ?? null;
        }

        return $array;
    }
}
