<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cálculo de lentes de contato do prontuário — as MESMAS fórmulas e o MESMO
 * arredondamento do componente Vue (ContactLensCalculatorModal), para o que
 * fica gravado ser exatamente o que o médico viu na tela:
 *
 *   1. Distância ao vértice (óculos → lente de contato): F / (1 − d·F),
 *      d em metros (12 mm por padrão; vazio/0 = 12 mm, como na tela).
 *   2. Equivalente esférico: esférico + cilindro / 2 (cilindro vazio = 0;
 *      cilindro informado mas inválido = sem resultado, como na tela).
 *
 * Arredondamento = Math.round(x·100)/100 do JavaScript (meio para +∞).
 * O servidor SEMPRE recalcula a partir das entradas: resultado vindo do
 * navegador é ignorado. Sem nenhum resultado possível, nada é gravado (null).
 *
 * Fora de escopo de propósito: potência de LIO (depende de biometria e de
 * escolha de fórmula — risco ao paciente se parecer "a conta oficial").
 */
final class ContactLensCalculator
{
    public const VERSION = 1;

    public const DEFAULT_VERTEX_MM = 12.0;

    /**
     * @param array<string, mixed>|null $inputs vertex_distance_mm, vertex_od, vertex_oe,
     *                                          se_od_sphere, se_od_cylinder, se_oe_sphere, se_oe_cylinder
     *
     * @return array<string, float|int|null>|null
     */
    public function calculate(?array $inputs): ?array
    {
        $inputs ??= [];
        $value = fn (string $key): ?float => $this->number($inputs[$key] ?? null);

        $distance = $value('vertex_distance_mm');
        $distance = ($distance === null || $distance == 0.0) ? self::DEFAULT_VERTEX_MM : $distance;

        $vertexOd = $value('vertex_od');
        $vertexOe = $value('vertex_oe');
        $odSphere = $value('se_od_sphere');
        $odCyl    = $value('se_od_cylinder');
        $oeSphere = $value('se_oe_sphere');
        $oeCyl    = $value('se_oe_cylinder');

        $result = [
            'version'            => self::VERSION,
            'vertex_distance_mm' => $distance,
            'vertex_od'          => $vertexOd,
            'vertex_oe'          => $vertexOe,
            'vertex_od_result'   => $this->vertex($vertexOd, $distance),
            'vertex_oe_result'   => $this->vertex($vertexOe, $distance),
            'se_od_sphere'       => $odSphere,
            'se_od_cylinder'     => $odCyl,
            'se_od_result'       => $this->sphericalEquivalent($odSphere, $inputs['se_od_cylinder'] ?? null),
            'se_oe_sphere'       => $oeSphere,
            'se_oe_cylinder'     => $oeCyl,
            'se_oe_result'       => $this->sphericalEquivalent($oeSphere, $inputs['se_oe_cylinder'] ?? null),
        ];

        $hasAnyResult = collect(['vertex_od_result', 'vertex_oe_result', 'se_od_result', 'se_oe_result'])
            ->contains(fn (string $key): bool => $result[$key] !== null);

        return $hasAnyResult ? $result : null;
    }

    private function vertex(?float $sphere, float $distanceMm): ?float
    {
        if ($sphere === null) {
            return null;
        }

        if ($sphere == 0.0) {
            return 0.0;
        }

        $denominator = 1 - ($distanceMm / 1000) * $sphere;

        return $denominator == 0.0 ? null : $this->round($sphere / $denominator);
    }

    /** Cilindro vazio conta 0; informado mas inválido não gera SE (nunca um SE que ignora o cilindro). */
    private function sphericalEquivalent(?float $sphere, mixed $cylinder): ?float
    {
        if ($sphere === null) {
            return null;
        }

        $cyl = $this->isBlank($cylinder) ? 0.0 : $this->number($cylinder);

        return $cyl === null ? null : $this->round($sphere + $cyl / 2);
    }

    /**
     * Math.round(x·100)/100 do JavaScript, exato: .5 sobe (rumo a +∞).
     * floor(x + 0,5) erra quando a soma arredonda em ponto flutuante
     * (0,49999999999999994 + 0,5 = 1,0); a parte fracionária y − floor(y) é exata.
     */
    private function round(float $value): float
    {
        $scaled = $value * 100;
        $floor  = floor($scaled);

        return ($scaled - $floor >= 0.5 ? $floor + 1 : $floor) / 100;
    }

    /** Vazio é "nada digitado" (≠ 0, que é plano — valor clínico válido). */
    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function number(mixed $value): ?float
    {
        if ($this->isBlank($value)) {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
