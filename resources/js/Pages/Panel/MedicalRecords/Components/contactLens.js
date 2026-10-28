/**
 * Lente de contato do prontuário (v2): refração dos óculos → cálculo →
 * POTÊNCIA DE LC SUGERIDA, respeitando os incrementos reais das lentes (não
 * arredondamento decimal comum). Espelho exato de App\Services\ContactLensCalculator:
 * o servidor recalcula igual ao salvar (resultado do navegador é ignorado).
 * Os dois lados são conferidos com o mesmo fixture
 * (tests/fixtures/contact_lens_v2_cases.json).
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
 * Gravação versionada: `version: 2`. Registros `version: 1` (vértice só no
 * esférico + equivalente esférico separado) continuam exibidos como estão.
 */
export const CONTACT_LENS_VERSION = 2;
export const DEFAULT_VERTEX_MM = 12;
export const DEFAULT_PROFILE = 'standard';
export const LENS_MODES = Object.freeze(['auto', 'spherical', 'toric']);

/** Acima disto (D, em qualquer meridiano) aplica a compensação de vértice. */
export const VERTEX_THRESHOLD = 4;
/** A partir deste cilindro teórico (D, em módulo) o automático indica tórica. */
export const TORIC_MIN_CYLINDER = 0.75;
/** No modo "tórica", cilindro mínimo da refração para fazer tórica. */
export const TORIC_FORCED_MIN_CYLINDER = 0.25;

/**
 * Linhas de lentes (grades de potências disponíveis). Dados num só lugar —
 * o PHP tem a mesma constante (ContactLensCalculator::PROFILES) e os dois
 * testes conferem com o fixture compartilhado. Faixas em ordem crescente.
 * Genéricas de propósito: não substituem a tabela do fabricante.
 */
export const CONTACT_LENS_PROFILES = Object.freeze({
    // Gelatinosa — padrão de mercado
    standard: {
        spherical: {
            sphere_ranges: [
                { from: -12, to: -6, step: 0.5 },
                { from: -6, to: 6, step: 0.25 },
                { from: 6, to: 8, step: 0.5 },
            ],
        },
        toric: {
            sphere_ranges: [
                { from: -9, to: -6, step: 0.5 },
                { from: -6, to: 6, step: 0.25 },
            ],
            cylinders: [-0.75, -1.25, -1.75, -2.25, -2.75],
            axis_step: 10,
        },
    },
    // Faixa estendida / sob encomenda
    extended: {
        spherical: { sphere_ranges: [{ from: -20, to: 20, step: 0.25 }] },
        toric: {
            sphere_ranges: [{ from: -20, to: 20, step: 0.25 }],
            cylinders: [-0.75, -1.25, -1.75, -2.25, -2.75, -3.25, -3.75, -4.25, -4.75, -5.25, -5.75],
            axis_step: 5,
        },
    },
});

/** Códigos de aviso por olho (texto em actions.medical_records.contact_lens_note_*). */
export const CONTACT_LENS_NOTES = Object.freeze([
    'no_cylinder',
    'out_of_range',
    'cylinder_out_of_range',
    'axis_missing',
    'plano_check',
]);

/** Vazio é "nada digitado" (≠ 0, que é plano — valor clínico válido). */
export function isBlank(v) {
    return v === null || v === undefined || (typeof v === 'string' && v.trim() === '');
}

/** Número digitado ou vindo do prontuário ("-1,50", "0.00") → Number | null. */
export function toNumber(v) {
    if (isBlank(v)) return null;
    const n = Number(String(v).trim().replace(',', '.'));
    return Number.isFinite(n) ? n : null;
}

/** Eixo ("180", "95º", 0) → inteiro | null. Fração não é eixo válido. */
function toAxis(v) {
    const n = toNumber(isBlank(v) ? v : String(v).replace(/[°º]/g, ''));
    return n !== null && Number.isInteger(n) ? n : null;
}

/** Math.round(x·100)/100 sem "-0" (o PHP faz igual). */
const round2 = (x) => Math.round(x * 100) / 100 + 0;
/** Dioptria → centésimos inteiros (comparações exatas, sem ruído de ponto flutuante). */
const hundredths = (x) => Math.round(x * 100);

/** Eixo em 1..180 (0 e 180 são o mesmo meridiano; mostramos 180). */
export function normalizeAxis(axis) {
    let r = axis % 180;
    if (r <= 0) r += 180;
    return r;
}

/** F / (1 − d·F), d em mm. Sem arredondar; null se o denominador zerar. */
export function vertexPower(power, distanceMm) {
    const denominator = 1 - (distanceMm / 1000) * power;
    return denominator === 0 ? null : power / denominator;
}

/** Grade de potências (centésimos, crescente, sem repetição) a partir das faixas. */
function expandRanges(ranges) {
    const values = new Set();
    for (const { from, to, step } of ranges) {
        const end = hundredths(to);
        const inc = hundredths(step);
        for (let v = hundredths(from); v <= end; v += inc) values.add(v);
    }
    return [...values].sort((a, b) => a - b);
}

/** Valor mais próximo de `h` (centésimos) na lista crescente; empate → o maior. */
function nearest(h, list) {
    let best = list[0];
    for (const v of list) {
        if (Math.abs(v - h) <= Math.abs(best - h)) best = v;
    }
    return best;
}

/**
 * Esférico teórico → potência disponível mais próxima nas faixas do perfil
 * (empate → a mais positiva). null = fora da faixa: passa da ponta em mais de
 * meio passo (na ponta negativa o empate fica na ponta; na positiva, sai).
 */
export function snapSphere(value, ranges) {
    const h = hundredths(value);
    const grid = expandRanges(ranges);
    const low = grid[0];
    const high = grid[grid.length - 1];
    const lowStep = hundredths(ranges.find((r) => hundredths(r.from) === low).step);
    const highStep = hundredths(ranges.find((r) => hundredths(r.to) === high).step);
    if (h < low && 2 * (low - h) > lowStep) return null;
    if (h > high && 2 * (h - high) >= highStep) return null;
    return nearest(h, grid) / 100 + 0;
}

/**
 * Cilindro teórico (≤ 0) → cilindro de estoque mais próximo (empate → o de
 * menor magnitude). null = acima do maior cilindro do perfil por mais de meio
 * passo. Abaixo do menor (ex.: -0,25 forçado como tórica) fica no menor.
 */
export function snapCylinder(value, cylinders) {
    const h = hundredths(value);
    const list = cylinders.map(hundredths).sort((a, b) => a - b);
    const strongest = list[0];
    const step = list.length > 1 ? list[1] - list[0] : 0;
    if (h < strongest && 2 * (strongest - h) > step) return null;
    return nearest(h, list) / 100 + 0;
}

/** Eixo → múltiplo do passo mais próximo (empate sobe); 0 vira 180. */
export function snapAxis(axis, step) {
    const lower = Math.floor(axis / step) * step;
    const snapped = (axis - lower) * 2 >= step ? lower + step : lower;
    return normalizeAxis(snapped);
}

function profileOf(name) {
    return CONTACT_LENS_PROFILES[name] ? name : DEFAULT_PROFILE;
}

/**
 * Um olho: refração dos óculos → { type, theoretical, suggested, vertex_applied, notes }.
 * null quando não há esférico (ou o cilindro digitado não é número — nunca
 * um cálculo que ignore o cilindro).
 */
export function calculateEye(eye = {}, options = {}) {
    const sphere = toNumber(eye?.sphere);
    if (sphere === null) return null;
    const cylinder = isBlank(eye?.cylinder) ? 0 : toNumber(eye.cylinder);
    if (cylinder === null) return null;

    const distance = toNumber(options.vertex_distance_mm) || DEFAULT_VERTEX_MM;
    const profile = CONTACT_LENS_PROFILES[profileOf(options.profile)];
    const mode = LENS_MODES.includes(options.lens_mode) ? options.lens_mode : 'auto';

    // 1. Cilindro negativo (transposição).
    let s = hundredths(sphere);
    let c = hundredths(cylinder);
    const typedAxis = toAxis(eye?.axis);
    let axis = typedAxis === null ? null : normalizeAxis(typedAxis);
    if (c > 0) {
        s += c;
        c = -c;
        axis = axis === null ? null : normalizeAxis(axis + 90);
    }

    // 2–3. Meridianos e vértice (só acima de ±4,00 D em algum meridiano).
    const vertexApplied = Math.max(Math.abs(s), Math.abs(s + c)) > VERTEX_THRESHOLD * 100;
    let m1 = s / 100;
    let m2 = (s + c) / 100;
    if (vertexApplied) {
        m1 = vertexPower(m1, distance);
        m2 = vertexPower(m2, distance);
        if (m1 === null || m2 === null) return null;
    }

    // 4. Teóricos.
    const rawCylinder = m2 - m1;
    const theoretical = {
        sphere: round2(m1),
        cylinder: round2(rawCylinder),
        axis: c === 0 ? null : axis,
        se: round2(m1 + rawCylinder / 2),
    };

    // 5. Tipo de lente.
    const notes = [];
    let type;
    if (mode === 'spherical') {
        type = 'spherical';
    } else if (mode === 'toric') {
        type = Math.abs(c) >= hundredths(TORIC_FORCED_MIN_CYLINDER) ? 'toric' : 'spherical';
        if (type === 'spherical') notes.push('no_cylinder');
    } else {
        type = Math.abs(hundredths(theoretical.cylinder)) >= hundredths(TORIC_MIN_CYLINDER) ? 'toric' : 'spherical';
    }

    // 6–7. Grade do perfil.
    let suggested;
    if (type === 'spherical') {
        const power = snapSphere(theoretical.se, profile.spherical.sphere_ranges);
        if (power === null) notes.push('out_of_range');
        suggested = power === null ? null : { sphere: power, cylinder: null, axis: null };
    } else {
        const power = snapSphere(theoretical.sphere, profile.toric.sphere_ranges);
        const cyl = snapCylinder(theoretical.cylinder, profile.toric.cylinders);
        const ax = theoretical.axis === null ? null : snapAxis(theoretical.axis, profile.toric.axis_step);
        if (power === null) notes.push('out_of_range');
        if (cyl === null) notes.push('cylinder_out_of_range');
        if (ax === null) notes.push('axis_missing');
        suggested = power === null ? null : { sphere: power, cylinder: cyl, axis: ax };
    }

    // 8. Plano: nem todo modelo tem 0,00.
    if (suggested && suggested.sphere === 0) notes.push('plano_check');

    return { type, theoretical, suggested, vertex_applied: vertexApplied, notes };
}

function eyeInputs(eye) {
    return {
        sphere: toNumber(eye?.sphere),
        cylinder: toNumber(eye?.cylinder),
        axis: toNumber(isBlank(eye?.axis) ? eye?.axis : String(eye.axis).replace(/[°º]/g, '')),
    };
}

/**
 * Entradas da calculadora → objeto gravado no prontuário (entradas +
 * resultados, `version: 2`). null quando nenhum olho tem resultado.
 */
export function computeContactLens(inputs = {}) {
    const options = {
        vertex_distance_mm: toNumber(inputs.vertex_distance_mm) || DEFAULT_VERTEX_MM,
        profile: profileOf(inputs.profile),
        lens_mode: LENS_MODES.includes(inputs.lens_mode) ? inputs.lens_mode : 'auto',
    };
    const od = calculateEye(inputs.od, options);
    const oe = calculateEye(inputs.oe, options);
    if (!od && !oe) return null;

    return {
        version: CONTACT_LENS_VERSION,
        ...options,
        od: eyeInputs(inputs.od),
        oe: eyeInputs(inputs.oe),
        results: { od, oe },
    };
}

/** Gravado antes da v2 (vértice só no esférico + equivalente esférico separado). */
export function isLegacyContactLens(calc) {
    return !!calc && typeof calc === 'object' && calc.version !== CONTACT_LENS_VERSION;
}

/**
 * Payload da edição sem o cálculo quando ele não mudou desde que o prontuário
 * abriu: um valor gravado antes de uma regra nova (ex.: limite de 2 casas, ou
 * um cálculo v1) não pode travar com 422 o save do prontuário inteiro. Sem a
 * chave, o servidor mantém o que está gravado. `saved` = cálculo carregado (ou null).
 */
export function omitUnchangedContactLens(data, saved) {
    if (JSON.stringify(data.contact_lens_calculation ?? null) !== JSON.stringify(saved ?? null)) return data;
    const payload = { ...data };
    delete payload.contact_lens_calculation;
    return payload;
}

const LEGACY_RESULT_KEYS = ['vertex_od_result', 'vertex_oe_result', 'se_od_result', 'se_oe_result'];

export function hasContactLensResult(calc) {
    if (!calc || typeof calc !== 'object') return false;
    if (isLegacyContactLens(calc)) {
        return LEGACY_RESULT_KEYS.some((key) => calc[key] !== null && calc[key] !== undefined);
    }
    return !!(calc.results?.od || calc.results?.oe);
}

/**
 * Entradas da calculadora a partir do que está gravado. v1: traz o que der
 * (esférico/cilindro; eixo não existia) — ao usar, grava v2.
 */
export function contactLensInputs(calc) {
    const blank = () => ({ sphere: null, cylinder: null, axis: null });
    const base = {
        vertex_distance_mm: toNumber(calc?.vertex_distance_mm) || DEFAULT_VERTEX_MM,
        profile: profileOf(calc?.profile),
        lens_mode: LENS_MODES.includes(calc?.lens_mode) ? calc.lens_mode : 'auto',
        od: blank(),
        oe: blank(),
    };
    if (!calc || typeof calc !== 'object') return base;
    if (!isLegacyContactLens(calc)) {
        return { ...base, od: { ...blank(), ...eyeInputs(calc.od) }, oe: { ...blank(), ...eyeInputs(calc.oe) } };
    }
    const legacyEye = (side) => {
        const seSphere = toNumber(calc[`se_${side}_sphere`]);
        return {
            sphere: seSphere ?? toNumber(calc[`vertex_${side}`]),
            cylinder: seSphere === null ? null : toNumber(calc[`se_${side}_cylinder`]),
            axis: null,
        };
    };
    return { ...base, od: legacyEye('od'), oe: legacyEye('oe') };
}

/**
 * Faixas aceitas pelo servidor (Store/UpdateMedicalRecordRequest): o modal
 * avisa na hora, em vez de um 422 só ao salvar a consulta.
 */
export const CONTACT_LENS_LIMITS = Object.freeze({
    vertex_distance_mm: [5, 25],
    sphere: [-40, 40],
    cylinder: [-15, 15],
    axis: [0, 180],
});

/**
 * Campos fora da faixa, com mais de 2 casas decimais (o servidor exige o
 * mesmo — decimal:0,2) ou eixo não inteiro. Chaves: 'vertex_distance_mm',
 * 'od.sphere', 'oe.axis'… Distância vazia/0 vira 12 mm — não conta.
 */
export function contactLensInvalid(inputs = {}) {
    const bad = (n, [min, max], integer = false) =>
        n !== null && (n < min || n > max || (integer ? !Number.isInteger(n) : round2(n) !== n));
    const out = [];
    if (bad(toNumber(inputs.vertex_distance_mm) || DEFAULT_VERTEX_MM, CONTACT_LENS_LIMITS.vertex_distance_mm)) {
        out.push('vertex_distance_mm');
    }
    for (const side of ['od', 'oe']) {
        for (const field of ['sphere', 'cylinder', 'axis']) {
            if (bad(toNumber(inputs[side]?.[field]), CONTACT_LENS_LIMITS[field], field === 'axis')) {
                out.push(`${side}.${field}`);
            }
        }
    }
    return out;
}

// ─── Exibição ────────────────────────────────────────────────────────────

// Sinal de menos tipográfico (U+2212), da largura do "+": alinha e o leitor de tela lê "menos".
const MINUS = '\u2212';

/** Locale do Inertia ('pt_BR') ou do Intl ('pt-BR') → Intl. */
function intlLocale(locale) {
    return String(locale || 'pt-BR').replace('_', '-');
}

/**
 * Dioptria no idioma da tela, sinal sempre explícito e menos tipográfico:
 * pt-BR "−2,50" / "+5,25", en "−2.50"; zero sem sinal ("0,00"); vazio "—".
 */
export function formatPower(v, locale = 'pt-BR') {
    const n = toNumber(v);
    if (n === null) return '—';
    const abs = new Intl.NumberFormat(intlLocale(locale), {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        useGrouping: false,
    }).format(Math.abs(n));
    return `${n > 0 ? '+' : n < 0 ? MINUS : ''}${abs}`;
}

const tr = (t, key, fallback) => t?.[key] ?? fallback;

function sphereText(sphere, locale, t) {
    if (sphere === 0) return tr(t, 'contact_lens_plano', 'Plano (:value)').replace(':value', formatPower(0, locale));
    return formatPower(sphere, locale);
}

/**
 * Lente (sugerida ou teórica) por extenso: esférica "−2,50 D", tórica
 * "−4,75 / −1,75 × 180°", plano "Plano (0,00)". Componente sem sugestão
 * (cilindro fora da faixa, eixo não informado) aparece como "—".
 */
export function formatLens(lens, type, { locale = 'pt-BR', t = {}, unit = true } = {}) {
    if (!lens) return '—';
    const sphere = sphereText(lens.sphere, locale, t);
    if (type !== 'toric') return lens.sphere === 0 || !unit ? sphere : `${sphere} D`;
    const cylinder = lens.cylinder === null || lens.cylinder === undefined ? '—' : formatPower(lens.cylinder, locale);
    const axis = lens.axis === null || lens.axis === undefined ? '—' : `${lens.axis}°`;
    return `${sphere} / ${cylinder} × ${axis}`;
}

/** Cálculo teórico do olho: esférica pelo EE (com o rótulo quando havia cilindro); tórica completa. */
export function formatTheoretical(result, { locale = 'pt-BR', t = {} } = {}) {
    if (!result) return '—';
    const th = result.theoretical;
    if (result.type === 'toric') {
        const axis = th.axis === null || th.axis === undefined ? '—' : `${th.axis}°`;
        return `${formatPower(th.sphere, locale)} / ${formatPower(th.cylinder, locale)} × ${axis}`;
    }
    if (th.cylinder) {
        return `${formatPower(th.se, locale)} D (${tr(t, 'contact_lens_se_suffix', 'equivalente esférico')})`;
    }
    return `${formatPower(th.sphere, locale)} D`;
}

/** "vértice aplicado (acima de ±4,00 D)" / "sem compensação de vértice (até ±4,00 D)". */
export function vertexStatus(result, t = {}) {
    return result?.vertex_applied
        ? tr(t, 'contact_lens_vertex_applied', 'vértice aplicado (acima de ±4,00 D)')
        : tr(t, 'contact_lens_vertex_not_applied', 'sem compensação de vértice (até ±4,00 D)');
}

export function lensTypeLabel(type, t = {}) {
    return type === 'toric'
        ? tr(t, 'contact_lens_type_toric', 'Tórica')
        : tr(t, 'contact_lens_type_spherical', 'Esférica');
}

export function profileLabel(profile, t = {}) {
    return profile === 'extended'
        ? tr(t, 'contact_lens_profile_extended', 'Faixa estendida (sob encomenda)')
        : tr(t, 'contact_lens_profile_standard', 'Padrão de mercado');
}

const NOTE_FALLBACKS = {
    no_cylinder: 'Sem cilindro na refração: sugerida lente esférica.',
    out_of_range: 'Fora da faixa comum — considere a faixa estendida ou consulte o fabricante.',
    out_of_range_extended: 'Fora da faixa desta linha — consulte o fabricante.',
    cylinder_out_of_range: 'Cilindro acima da linha padrão — considere a faixa estendida ou consulte o fabricante.',
    cylinder_out_of_range_extended: 'Cilindro acima desta linha — consulte o fabricante.',
    axis_missing: 'Informe o eixo para completar a lente tórica.',
    plano_check: 'Plano: confira se o modelo escolhido tem potência 0,00.',
};

/** Texto curto de um aviso. Fora da faixa na linha estendida não sugere "considere a estendida". */
export function contactLensNoteText(code, profile, t = {}) {
    const key =
        profile === 'extended' && (code === 'out_of_range' || code === 'cylinder_out_of_range')
            ? `${code}_extended`
            : code;
    return tr(t, `contact_lens_note_${key}`, NOTE_FALLBACKS[key] ?? code);
}

/** "−2,50 (esférica)" / "−4,75 / −1,75 × 180° (tórica)"; sem sugestão → texto curto. */
function suggestionSummary(result, locale, t) {
    if (!result.suggested) return tr(t, 'contact_lens_no_lens', 'sem lente nesta linha');
    const type = lensTypeLabel(result.type, t).toLocaleLowerCase(intlLocale(locale));
    return `${formatLens(result.suggested, result.type, { locale, t, unit: false })} (${type})`;
}

function eyesOf(calc, t) {
    return [
        [tr(t, 'od', 'OD'), calc.results?.od],
        [tr(t, 'oe', 'OE'), calc.results?.oe],
    ].filter(([, result]) => !!result);
}

/** Distância ao vértice no idioma da tela, sem zeros à toa ("12", "12,5"). `locale` como 'pt-BR'. */
export function formatVertexMm(v, locale = 'pt-BR') {
    const n = toNumber(v) ?? DEFAULT_VERTEX_MM;
    return new Intl.NumberFormat(intlLocale(locale), { maximumFractionDigits: 2 }).format(n);
}

// ─── v1 (gravado antes da v2): exibido como está ─────────────────────────

/** "versão anterior" — marca os cálculos v1 onde aparecem. */
export function legacyTag(t = {}) {
    return tr(t, 'contact_lens_legacy', 'versão anterior');
}

/** v1: dioptria com sinal e 2 casas, como era ("-5.60", "+6.47", "0.00"); vazio = "—". */
export function formatDiopter(v) {
    const n = toNumber(v);
    if (n === null) return '—';
    return `${n > 0 ? '+' : ''}${n.toFixed(2)}`;
}

/** v1: rótulo da conversão ao vértice com a distância usada ("… (vértice 12 mm)"). */
function legacyVertexLabel(calc, t, locale) {
    return tr(t, 'contact_lens_vertex_label', 'Esférico → lente de contato (vértice :mm mm)').replace(
        ':mm',
        formatVertexMm(calc.vertex_distance_mm, locale),
    );
}

function legacySummary(calc, t, locale) {
    const eyes = (pairs) =>
        pairs
            .filter(([, , result]) => toNumber(result) !== null)
            .map(([eye, input, result]) =>
                input === undefined
                    ? `${eye}: ${formatDiopter(result)}`
                    : `${eye}: ${formatDiopter(input)} → ${formatDiopter(result)}`,
            )
            .join('  ·  ');
    const od = tr(t, 'od', 'OD');
    const oe = tr(t, 'oe', 'OE');

    return [
        {
            key: 'vertex',
            label: legacyVertexLabel(calc, t, locale),
            value: eyes([
                [od, calc.vertex_od, calc.vertex_od_result],
                [oe, calc.vertex_oe, calc.vertex_oe_result],
            ]),
        },
        {
            key: 'se',
            label: tr(t, 'contact_lens_se_title', 'Equivalente esférico'),
            value: eyes([
                [od, undefined, calc.se_od_result],
                [oe, undefined, calc.se_oe_result],
            ]),
        },
    ].filter((row) => row.value !== '');
}

function legacyCompact(calc, t, locale) {
    const results = (odResult, oeResult) =>
        [
            [tr(t, 'od', 'OD'), odResult],
            [tr(t, 'oe', 'OE'), oeResult],
        ]
            .filter(([, result]) => toNumber(result) !== null)
            .map(([eye, result]) => `${eye} ${formatDiopter(result)}`)
            .join(' | ');

    return [
        {
            key: 'vertex',
            tag: tr(t, 'contact_lens_short', 'LC'),
            label: legacyVertexLabel(calc, t, locale),
            value: results(calc.vertex_od_result, calc.vertex_oe_result),
        },
        {
            key: 'se',
            tag: tr(t, 'contact_lens_se_short', 'SE'),
            label: tr(t, 'contact_lens_se_title', 'Equivalente esférico'),
            value: results(calc.se_od_result, calc.se_oe_result),
        },
    ].filter((row) => row.value !== '');
}

// ─── Resumos ─────────────────────────────────────────────────────────────

/**
 * Resumo para o prontuário e a visualização da consulta: [{ key, label, value }].
 * v2: a potência SUGERIDA por olho com o tipo ("OD −2,50 (esférica) · OE
 * −4,75 / −1,75 × 180° (tórica)"); `detailed` (visualização) acrescenta o
 * cálculo teórico, a linha de lentes e os avisos. v1: formato antigo.
 * `t` = traduções de actions.medical_records; `locale` como 'pt-BR'.
 */
export function contactLensSummary(calc, t = {}, locale = undefined, { detailed = false } = {}) {
    if (!hasContactLensResult(calc)) return [];
    if (isLegacyContactLens(calc)) {
        const rows = legacySummary(calc, t, locale);
        // Visualização: rótulo legado, para não confundir com a lógica atual.
        return detailed ? rows.map((row) => ({ ...row, label: `${row.label} (${legacyTag(t)})` })) : rows;
    }

    const eyes = eyesOf(calc, t);
    const rows = [
        {
            key: 'suggested',
            label: detailed
                ? tr(t, 'contact_lens_suggested', 'Lente de contato sugerida')
                : tr(t, 'contact_lens_suggested_short', 'LC sugerida'),
            value: eyes.map(([eye, r]) => `${eye} ${suggestionSummary(r, locale, t)}`).join('  ·  '),
        },
    ];
    if (!detailed) return rows;

    rows.push({
        key: 'theoretical',
        label: tr(t, 'contact_lens_theoretical_label', 'Cálculo teórico (vértice :mm mm)').replace(
            ':mm',
            formatVertexMm(calc.vertex_distance_mm, locale),
        ),
        value: eyes
            .map(([eye, r]) => `${eye} ${formatTheoretical(r, { locale, t })} — ${vertexStatus(r, t)}`)
            .join('  ·  '),
    });
    rows.push({
        key: 'profile',
        label: tr(t, 'contact_lens_profile', 'Linha de lentes'),
        value: profileLabel(calc.profile, t),
    });
    const notes = eyes
        .filter(([, r]) => r.notes?.length)
        .map(([eye, r]) => `${eye}: ${r.notes.map((code) => contactLensNoteText(code, calc.profile, t)).join(' ')}`)
        .join('  ·  ');
    if (notes) rows.push({ key: 'notes', label: tr(t, 'contact_lens_notes', 'Avisos'), value: notes });

    return rows;
}

/**
 * Versão curta para o painel "Consultas anteriores" (coluna estreita), no
 * formato do painel ("OD −2,50 (esférica) | OE …"), com sigla e o rótulo
 * completo para o title. v1: formato antigo (LC / SE).
 */
export function contactLensCompact(calc, t = {}, locale = undefined) {
    if (!hasContactLensResult(calc)) return [];
    if (isLegacyContactLens(calc)) {
        return legacyCompact(calc, t, locale).map((row) => ({ ...row, label: `${row.label} (${legacyTag(t)})` }));
    }

    return [
        {
            key: 'suggested',
            tag: tr(t, 'contact_lens_short', 'LC'),
            label: `${tr(t, 'contact_lens_suggested', 'Lente de contato sugerida')} — ${profileLabel(calc.profile, t)}`,
            value: eyesOf(calc, t)
                .map(([eye, r]) => `${eye} ${suggestionSummary(r, locale, t)}`)
                .join(' | '),
        },
    ];
}

// ─── Copiar refração do prontuário ───────────────────────────────────────

const REFRACTION_FIELDS = [
    'spherical_right',
    'spherical_left',
    'cylindrical_right',
    'cylindrical_left',
    'axis_right',
    'axis_left',
];

/** Valor padrão do bloco ("", "0.00", "0,00", "0°") — mesma regra do servidor (refractionBlockOrNull). */
function isDefaultRefraction(v) {
    const s = String(v ?? '').trim();
    if (s === '') return true;
    const n = Number(s.replace(/[°º]/g, '').replace(/,/g, '.'));
    return Number.isFinite(n) && n === 0;
}

/**
 * Refração do prontuário (dinâmica/estática) no formato da calculadora —
 * esférico, cilindro e EIXO, para copiar sem redigitar. null quando o bloco
 * não foi preenchido (tudo no padrão "0.00"/"0°"): não vira "plano" por
 * engano. Num bloco preenchido, 0.00 conta como plano. `unreadable` lista os
 * campos com texto que não é número (ex.: "PL"): copiados como vazio, o
 * cálculo ignoraria o valor.
 */
export function refractionFromRecord(form, prefix) {
    if (REFRACTION_FIELDS.every((field) => isDefaultRefraction(form?.[`${prefix}_${field}`]))) {
        return null;
    }

    const unreadable = [];
    const read = (field, parse = toNumber) => {
        const raw = form?.[`${prefix}_${field}`];
        const n = parse(raw);
        if (n === null && !isBlank(raw)) unreadable.push(field);
        return n;
    };
    // Eixo sem cilindro não tem sentido (o "0°" padrão do bloco viraria ruído).
    const eye = (side) => {
        const sphere = read(`spherical_${side}`);
        const cylinder = read(`cylindrical_${side}`);
        const axis = read(`axis_${side}`, toAxis);
        return { sphere, cylinder, axis: cylinder ? axis : null };
    };
    const od = eye('right');
    const oe = eye('left');

    return { od, oe, unreadable };
}
