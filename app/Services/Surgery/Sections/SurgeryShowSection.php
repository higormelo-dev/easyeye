<?php

declare(strict_types=1);

namespace App\Services\Surgery\Sections;

use App\Models\Surgery\SurgicalCase;

/**
 * Uma aba da tela do caso cirúrgico. As seções são registradas em
 * `config('surgery.show_sections')`; cada uma entrega as props da SUA aba —
 * assim a tela do caso não vira um controller de milhares de linhas e cada
 * etapa do módulo evolui sem encostar nas demais.
 *
 * O componente Vue da aba fica em
 * `resources/js/Components/Surgery/Tabs/<Key>Tab.vue` (ex.: key `request` →
 * `RequestTab.vue`) e recebe `section` (as props abaixo), `case`, `can`,
 * `urls` e `t`.
 */
interface SurgeryShowSection
{
    /** Chave estável da aba (snake_case); nome da prop e do componente. */
    public function key(): string;

    /** Posição da aba (menor primeiro). */
    public function order(): int;

    /** Texto da aba no idioma atual. */
    public function label(): string;

    /**
     * A aba aparece para este caso e este usuário?
     *
     * @param array<string, bool> $can permissões já resolvidas (manage, perform...)
     */
    public function visible(SurgicalCase $case, array $can): bool;

    /**
     * Props da aba (já serializáveis). Chamado só para abas visíveis.
     *
     * @param array<string, bool> $can
     *
     * @return array<string, mixed>
     */
    public function props(SurgicalCase $case, array $can): array;
}
