<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Lente de contato do prontuário (v2): refração dos óculos → cálculo →
 * POTÊNCIA DE LC SUGERIDA, respeitando os incrementos reais das lentes (não
 * arredondamento decimal comum). Espelho EXATO do contactLens.js (mesmas
 * contas, na mesma ordem, e o mesmo arredondamento), para o que fica gravado
 * ser o que o médico viu na tela. Os dois lados são conferidos com o mesmo
 * fixture (tests/fixtures/contact_lens_v2_cases.json).
 *
 * Fontes:
 *   - Fórmula do vértice e regra dos ±4,00 D por meridiano:
 *     https://www.odreference.com/contacts/conversions/calculator
 *     https://en.wikipedia.org/wiki/Vertex_distance
 *   - Parâmetros de mercado (esférico 0,25 D até ±6,00 e 0,50 D além; cilindros
 *     -0,75/-1,25/-1,75/-2,25/-2,75; eixo de 10° em 10°), ex. ACUVUE OASYS for
 *     ASTIGMATISM: https://www.odspecs.com/details/acuvueoasysastigmatism.html
 *   - Tórica indicada a partir de 0,75 DC:
 *     https://clspectrum.com/issues/2019/september/take-a-turn-with-soft-toric-lenses-for-astigmatism/
 *
 * Por olho (esférico S, cilindro C opcional, eixo A opcional):
 *   1. Cilindro positivo é transposto: S' = S + C, C' = −C, A' = A ± 90
 *      (eixo sempre 1..180; 0 vale 180). LC tórica usa cilindro negativo.
 *   2. Meridianos dos óculos: M1 = S (no eixo), M2 = S + C.
 *   3. Vértice só se max(|M1|, |M2|) > 4,00 D: Fc = F / (1 − d·F) em CADA
 *      meridiano (d em metros); até ±4,00 D a diferença é desprezível.
 *   4. Teóricos (2 casas, meio para cima): esférico = M1c,
 *      cilindro = M2c − M1c, equivalente esférico = M1c + cilindro / 2.
 *   5. Tipo: automático → tórica se |cilindro teórico| ≥ 0,75, senão esférica
 *      pelo equivalente esférico; "esférica" força o EE; "tórica" força
 *      quando há cilindro ≥ 0,25 na refração (sem cilindro → esférica + aviso).
 *   6. Sugerida = valor da grade do perfil MAIS PRÓXIMO do teórico exibido;
 *      empate → o mais positivo (máximo positivo); cilindro empatado → o de
 *      menor magnitude; eixo → múltiplo do passo mais próximo (empate sobe).
 *      Fora da grade (passa da ponta em mais de meio passo) → sem sugestão
 *      daquele componente + aviso.
 *
 * O servidor SEMPRE recalcula a partir das entradas: resultado vindo do
 * navegador é ignorado. Sem resultado em nenhum olho, nada é gravado (null).
 * Registros `version: 1` já gravados não são migrados (exibidos como estão).
 *
 * Fora de escopo de propósito: potência de LIO (depende de biometria e de
 * escolha de fórmula — risco ao paciente se parecer "a conta oficial") e
 * lentes rígidas (adaptação por curva base/lágrima, não por tabela).
 */
final class ContactLensCalculator
{
    public const VERSION = 2;

    public const DEFAULT_VERTEX_MM = 12.0;

    public const DEFAULT_PROFILE = 'standard';

    public const LENS_MODES = ['auto', 'spherical', 'toric'];

    /** Acima disto (D, em qualquer meridiano) aplica a compensação de vértice. */
    public const VERTEX_THRESHOLD = 4.0;

    /** A partir deste cilindro teórico (D, em módulo) o automático indica tórica. */
    public const TORIC_MIN_CYLINDER = 0.75;

    /** No modo "tórica", cilindro mínimo da refração para fazer tórica. */
    public const TORIC_FORCED_MIN_CYLINDER = 0.25;

    /**
     * Linhas de lentes (grades de potências disponíveis). Dados num só lugar —
     * o JS tem a mesma constante (CONTACT_LENS_PROFILES) e os dois testes
     * conferem com o fixture compartilhado. Faixas em ordem crescente.
     * Genéricas de propósito: não substituem a tabela do fabricante.
     */
    public const PROFILES = [
        // Gelatinosa — padrão de mercado
        'standard' => [
            'spherical' => [
                'sphere_ranges' => [
                    ['from' => -12, 'to' => -6, 'step' => 0.5],
                    ['from' => -6, 'to' => 6, 'step' => 0.25],
                    ['from' => 6, 'to' => 8, 'step' => 0.5],
                ],
            ],
            'toric' => [
                'sphere_ranges' => [
                    ['from' => -9, 'to' => -6, 'step' => 0.5],
                    ['from' => -6, 'to' => 6, 'step' => 0.25],
                ],
                'cylinders' => [-0.75, -1.25, -1.75, -2.25, -2.75],
                'axis_step' => 10,
            ],
        ],
        // Faixa estendida / sob encomenda
        'extended' => [
            'spherical' => [
                'sphere_ranges' => [['from' => -20, 'to' => 20, 'step' => 0.25]],
            ],
            'toric' => [
                'sphere_ranges' => [['from' => -20, 'to' => 20, 'step' => 0.25]],
                'cylinders'     => [-0.75, -1.25, -1.75, -2.25, -2.75, -3.25, -3.75, -4.25, -4.75, -5.25, -5.75],
                'axis_step'     => 5,
            ],
        ],
    ];

    /** Códigos de aviso por olho (texto em actions.medical_records.contact_lens_note_*). */
    public const NOTES = ['no_cylinder', 'out_of_range', 'cylinder_out_of_range', 'axis_missing', 'plano_check'];

    /**
     * Regras das ENTRADAS (os resultados são recalculados aqui). Faixas
     * clínicas plausíveis barram valor digitado errado (ex.: -90); até 2 casas:
     * o valor exibido na tela e no PDF é o mesmo usado no cálculo. `version`
     * diferente de 2 = tela antiga aberta: recusa em vez de gravar errado.
     *
     * @return array<string, list<string>>
     */
    public static function validationRules(): array
    {
        $sphere   = ['nullable', 'numeric', 'decimal:0,2', 'min:-40', 'max:40'];
        $cylinder = ['nullable', 'numeric', 'decimal:0,2', 'min:-15', 'max:15'];
        $axis     = ['nullable', 'integer', 'min:0', 'max:180'];

        $rules = [
            'contact_lens_calculation.version'            => ['nullable', 'integer', 'in:' . self::VERSION],
            'contact_lens_calculation.vertex_distance_mm' => ['nullable', 'numeric', 'decimal:0,2', 'min:5', 'max:25'],
            'contact_lens_calculation.profile'            => ['nullable', 'string', 'in:' . implode(',', array_keys(self::PROFILES))],
            'contact_lens_calculation.lens_mode'          => ['nullable', 'string', 'in:' . implode(',', self::LENS_MODES)],
        ];

        foreach (['od', 'oe'] as $eye) {
            $rules["contact_lens_calculation.{$eye}"]          = ['nullable', 'array'];
            $rules["contact_lens_calculation.{$eye}.sphere"]   = $sphere;
            $rules["contact_lens_calculation.{$eye}.cylinder"] = $cylinder;
            $rules["contact_lens_calculation.{$eye}.axis"]     = $axis;
        }

        return $rules;
    }

    /**
     * @param array<string, mixed>|null $inputs vertex_distance_mm, profile, lens_mode,
     *                                          od/oe {sphere, cylinder, axis}
     *
     * @return array<string, mixed>|null
     */
    public function calculate(?array $inputs): ?array
    {
        $inputs ??= [];

        $distance = $this->number($inputs['vertex_distance_mm'] ?? null);
        $options  = [
            'vertex_distance_mm' => ($distance === null || $distance == 0.0) ? self::DEFAULT_VERTEX_MM : $distance,
            'profile'            => $this->profileOf($inputs['profile'] ?? null),
            'lens_mode'          => in_array($inputs['lens_mode'] ?? null, self::LENS_MODES, true) ? $inputs['lens_mode'] : 'auto',
        ];

        $odEye = is_array($inputs['od'] ?? null) ? $inputs['od'] : [];
        $oeEye = is_array($inputs['oe'] ?? null) ? $inputs['oe'] : [];
        $od    = $this->calculateEye($odEye, $options);
        $oe    = $this->calculateEye($oeEye, $options);

        if ($od === null && $oe === null) {
            return null;
        }

        return [
            'version' => self::VERSION,
            ...$options,
            'od'      => $this->eyeInputs($odEye),
            'oe'      => $this->eyeInputs($oeEye),
            'results' => ['od' => $od, 'oe' => $oe],
        ];
    }

    /**
     * Um olho: refração dos óculos → type, theoretical, suggested, vertex_applied, notes.
     * null quando não há esférico (ou o cilindro digitado não é número — nunca
     * um cálculo que ignore o cilindro).
     *
     * @param array<string, mixed> $eye     sphere, cylinder, axis
     * @param array<string, mixed> $options vertex_distance_mm, profile, lens_mode
     *
     * @return array<string, mixed>|null
     */
    public function calculateEye(array $eye, array $options = []): ?array
    {
        $sphere = $this->number($eye['sphere'] ?? null);

        if ($sphere === null) {
            return null;
        }

        $cylinder = $this->isBlank($eye['cylinder'] ?? null) ? 0.0 : $this->number($eye['cylinder']);

        if ($cylinder === null) {
            return null;
        }

        $distance = $this->number($options['vertex_distance_mm'] ?? null);
        $distance = ($distance === null || $distance == 0.0) ? self::DEFAULT_VERTEX_MM : $distance;
        $profile  = self::PROFILES[$this->profileOf($options['profile'] ?? null)];
        $mode     = in_array($options['lens_mode'] ?? null, self::LENS_MODES, true) ? $options['lens_mode'] : 'auto';

        // 1. Cilindro negativo (transposição).
        $s         = $this->hundredths($sphere);
        $c         = $this->hundredths($cylinder);
        $typedAxis = $this->axis($eye['axis'] ?? null);
        $axis      = $typedAxis === null ? null : $this->normalizeAxis($typedAxis);

        if ($c > 0) {
            $s += $c;
            $c    = -$c;
            $axis = $axis === null ? null : $this->normalizeAxis($axis + 90);
        }

        // 2–3. Meridianos e vértice (só acima de ±4,00 D em algum meridiano).
        $vertexApplied = max(abs($s), abs($s + $c)) > self::VERTEX_THRESHOLD * 100;
        $m1            = $s / 100.0;
        $m2            = ($s + $c) / 100.0;

        if ($vertexApplied) {
            $m1 = $this->vertexPower($m1, $distance);
            $m2 = $this->vertexPower($m2, $distance);

            if ($m1 === null || $m2 === null) {
                return null;
            }
        }

        // 4. Teóricos.
        $rawCylinder = $m2 - $m1;
        $theoretical = [
            'sphere'   => $this->round2($m1),
            'cylinder' => $this->round2($rawCylinder),
            'axis'     => $c === 0 ? null : $axis,
            'se'       => $this->round2($m1 + $rawCylinder / 2),
        ];

        // 5. Tipo de lente.
        $notes = [];

        if ($mode === 'spherical') {
            $type = 'spherical';
        } elseif ($mode === 'toric') {
            $type = abs($c) >= $this->hundredths(self::TORIC_FORCED_MIN_CYLINDER) ? 'toric' : 'spherical';

            if ($type === 'spherical') {
                $notes[] = 'no_cylinder';
            }
        } else {
            $type = abs($this->hundredths($theoretical['cylinder'])) >= $this->hundredths(self::TORIC_MIN_CYLINDER) ? 'toric' : 'spherical';
        }

        // 6–7. Grade do perfil.
        if ($type === 'spherical') {
            $power = $this->snapSphere($theoretical['se'], $profile['spherical']['sphere_ranges']);

            if ($power === null) {
                $notes[] = 'out_of_range';
            }

            $suggested = $power === null ? null : ['sphere' => $power, 'cylinder' => null, 'axis' => null];
        } else {
            $power = $this->snapSphere($theoretical['sphere'], $profile['toric']['sphere_ranges']);
            $cyl   = $this->snapCylinder($theoretical['cylinder'], $profile['toric']['cylinders']);
            $ax    = $theoretical['axis'] === null ? null : $this->snapAxis($theoretical['axis'], $profile['toric']['axis_step']);

            if ($power === null) {
                $notes[] = 'out_of_range';
            }

            if ($cyl === null) {
                $notes[] = 'cylinder_out_of_range';
            }

            if ($ax === null) {
                $notes[] = 'axis_missing';
            }

            $suggested = $power === null ? null : ['sphere' => $power, 'cylinder' => $cyl, 'axis' => $ax];
        }

        // 8. Plano: nem todo modelo tem 0,00.
        if ($suggested !== null && $suggested['sphere'] == 0.0) {
            $notes[] = 'plano_check';
        }

        return [
            'type'           => $type,
            'theoretical'    => $theoretical,
            'suggested'      => $suggested,
            'vertex_applied' => $vertexApplied,
            'notes'          => $notes,
        ];
    }

    /**
     * Esférico teórico → potência disponível mais próxima nas faixas do perfil
     * (empate → a mais positiva). null = fora da faixa: passa da ponta em mais
     * de meio passo (na ponta negativa o empate fica na ponta; na positiva, sai).
     *
     * @param list<array{from: int|float, to: int|float, step: float}> $ranges
     */
    public function snapSphere(float $value, array $ranges): ?float
    {
        $h    = $this->hundredths($value);
        $grid = $this->expandRanges($ranges);
        $low  = $grid[0];
        $high = $grid[count($grid) - 1];

        $lowStep  = 0;
        $highStep = 0;

        foreach ($ranges as $range) {
            if ($this->hundredths((float) $range['from']) === $low && $lowStep === 0) {
                $lowStep = $this->hundredths((float) $range['step']);
            }

            if ($this->hundredths((float) $range['to']) === $high && $highStep === 0) {
                $highStep = $this->hundredths((float) $range['step']);
            }
        }

        if ($h < $low && 2 * ($low - $h) > $lowStep) {
            return null;
        }

        if ($h > $high && 2 * ($h - $high) >= $highStep) {
            return null;
        }

        return $this->fromHundredths($this->nearest($h, $grid));
    }

    /**
     * Cilindro teórico (≤ 0) → cilindro de estoque mais próximo (empate → o de
     * menor magnitude). null = acima do maior cilindro do perfil por mais de
     * meio passo. Abaixo do menor (ex.: -0,25 forçado como tórica) fica no menor.
     *
     * @param list<float> $cylinders
     */
    public function snapCylinder(float $value, array $cylinders): ?float
    {
        $h    = $this->hundredths($value);
        $list = array_map(fn ($cyl): int => $this->hundredths((float) $cyl), $cylinders);
        sort($list);

        $strongest = $list[0];
        $step      = count($list) > 1 ? $list[1] - $list[0] : 0;

        if ($h < $strongest && 2 * ($strongest - $h) > $step) {
            return null;
        }

        return $this->fromHundredths($this->nearest($h, $list));
    }

    /** Eixo → múltiplo do passo mais próximo (empate sobe); 0 vira 180. */
    public function snapAxis(int $axis, int $step): int
    {
        $lower   = (int) floor($axis / $step) * $step;
        $snapped = ($axis - $lower) * 2 >= $step ? $lower + $step : $lower;

        return $this->normalizeAxis($snapped);
    }

    /** Eixo em 1..180 (0 e 180 são o mesmo meridiano; mostramos 180). */
    public function normalizeAxis(int $axis): int
    {
        $r = $axis % 180;

        return $r <= 0 ? $r + 180 : $r;
    }

    /** F / (1 − d·F), d em mm. Sem arredondar; null se o denominador zerar. */
    private function vertexPower(float $power, float $distanceMm): ?float
    {
        $denominator = 1 - ($distanceMm / 1000) * $power;

        return $denominator == 0.0 ? null : $power / $denominator;
    }

    /**
     * Grade de potências (centésimos, crescente, sem repetição) a partir das faixas.
     *
     * @param list<array{from: int|float, to: int|float, step: float}> $ranges
     *
     * @return list<int>
     */
    private function expandRanges(array $ranges): array
    {
        $values = [];

        foreach ($ranges as $range) {
            $end = $this->hundredths((float) $range['to']);
            $inc = $this->hundredths((float) $range['step']);

            for ($v = $this->hundredths((float) $range['from']); $v <= $end; $v += $inc) {
                $values[$v] = true;
            }
        }

        $grid = array_keys($values);
        sort($grid);

        return $grid;
    }

    /**
     * Valor mais próximo de $h (centésimos) na lista crescente; empate → o maior.
     *
     * @param list<int> $list
     */
    private function nearest(int $h, array $list): int
    {
        $best = $list[0];

        foreach ($list as $v) {
            if (abs($v - $h) <= abs($best - $h)) {
                $best = $v;
            }
        }

        return $best;
    }

    /** @return array{sphere: ?float, cylinder: ?float, axis: int|float|null} */
    private function eyeInputs(array $eye): array
    {
        $axis = $this->number($this->stripDegree($eye['axis'] ?? null));

        return [
            'sphere'   => $this->number($eye['sphere'] ?? null),
            'cylinder' => $this->number($eye['cylinder'] ?? null),
            'axis'     => $axis !== null && floor($axis) == $axis ? (int) $axis : $axis,
        ];
    }

    /** Eixo ("180", "95º", 0) → inteiro | null. Fração não é eixo válido. */
    private function axis(mixed $value): ?int
    {
        $n = $this->number($this->stripDegree($value));

        return $n !== null && floor($n) == $n ? (int) $n : null;
    }

    private function stripDegree(mixed $value): mixed
    {
        return is_string($value) ? str_replace(['°', 'º'], '', $value) : $value;
    }

    private function profileOf(mixed $name): string
    {
        return is_string($name) && isset(self::PROFILES[$name]) ? $name : self::DEFAULT_PROFILE;
    }

    /**
     * Math.round(x) do JavaScript, exato: .5 sobe (rumo a +∞). floor(x + 0,5)
     * erra quando a soma arredonda em ponto flutuante (0,49999999999999994 +
     * 0,5 = 1,0); a parte fracionária y − floor(y) é exata.
     */
    private function jsRound(float $value): int
    {
        $floor = floor($value);

        return (int) ($value - $floor >= 0.5 ? $floor + 1 : $floor);
    }

    /** Dioptria → centésimos inteiros (comparações exatas, sem ruído de ponto flutuante). */
    private function hundredths(float $value): int
    {
        return $this->jsRound($value * 100);
    }

    /** Math.round(x·100)/100 do JavaScript, sem "-0". */
    private function round2(float $value): float
    {
        return $this->fromHundredths($this->jsRound($value * 100));
    }

    private function fromHundredths(int $h): float
    {
        $value = $h / 100.0;

        return $value == 0.0 ? 0.0 : $value;
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
