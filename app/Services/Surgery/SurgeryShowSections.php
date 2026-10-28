<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\Sections\SurgeryShowSection;

/**
 * Resolve as abas da tela do caso a partir de `config('surgery.show_sections')`.
 */
final class SurgeryShowSections
{
    /** @return list<SurgeryShowSection> ordenadas por order() */
    public static function all(): array
    {
        $sections = array_map(
            fn (string $class): SurgeryShowSection => app($class),
            (array) config('surgery.show_sections', []),
        );

        usort($sections, fn (SurgeryShowSection $a, SurgeryShowSection $b) => $a->order() <=> $b->order());

        return $sections;
    }

    /**
     * Abas visíveis e as props de cada uma.
     *
     * @param array<string, bool> $can
     *
     * @return array{tabs: list<array{key: string, label: string}>, sections: array<string, array<string, mixed>>}
     */
    public static function build(SurgicalCase $case, array $can): array
    {
        $tabs     = [];
        $sections = [];

        foreach (self::all() as $section) {
            if (! $section->visible($case, $can)) {
                continue;
            }

            $tabs[] = [
                'key'   => $section->key(),
                'label' => $section->label(),
                // Seção pode destacar a aba (ex.: complicação grave) com `badge(): ?array{tone, label}`.
                'badge' => method_exists($section, 'badge') ? $section->badge($case) : null,
            ];
            $sections[$section->key()] = $section->props($case, $can);
        }

        return ['tabs' => $tabs, 'sections' => $sections];
    }
}
