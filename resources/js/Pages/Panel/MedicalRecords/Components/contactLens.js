/**
 * Cálculo de lentes de contato do prontuário — as MESMAS fórmulas que a
 * calculadora do Gerenciador de Imagens usava, agora vinculadas à consulta.
 * O servidor (App\Services\ContactLensCalculator) recalcula igual ao salvar;
 * o que fica gravado é o que o médico viu na tela.
 *
 *   1. Distância ao vértice (óculos → lente de contato): F / (1 − d·F),
 *      d em metros (12 mm por padrão; vazio/0 = 12 mm). Só o esférico —
 *      o cilindro não entra nesta conversão.
 *   2. Equivalente esférico: esférico + cilindro / 2 (cilindro vazio = 0;
 *      cilindro inválido = sem resultado).
 *
 * Arredondamento: Math.round(x·100)/100.
 */
export const DEFAULT_VERTEX_MM = 12;

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

const round2 = (x) => Math.round(x * 100) / 100;

export function vertexConvert(sphere, distanceMm) {
    const s = toNumber(sphere);
    if (s === null) return null;
    if (s === 0) return 0;
    const d = (toNumber(distanceMm) || DEFAULT_VERTEX_MM) / 1000;
    const denom = 1 - d * s;
    if (denom === 0) return null;
    return round2(s / denom);
}

export function sphericalEquivalent(sphere, cylinder) {
    const s = toNumber(sphere);
    if (s === null) return null;
    const cyl = isBlank(cylinder) ? 0 : toNumber(cylinder);
    if (cyl === null) return null;
    return round2(s + cyl / 2);
}

/**
 * Entradas da calculadora → objeto gravado no prontuário (entradas +
 * resultados). null quando não há nenhum resultado (nada a gravar).
 */
export function computeContactLens(inputs = {}) {
    const distance = toNumber(inputs.vertex_distance_mm) || DEFAULT_VERTEX_MM;
    const calc = {
        version: 1,
        vertex_distance_mm: distance,
        vertex_od: toNumber(inputs.vertex_od),
        vertex_oe: toNumber(inputs.vertex_oe),
        vertex_od_result: vertexConvert(inputs.vertex_od, distance),
        vertex_oe_result: vertexConvert(inputs.vertex_oe, distance),
        se_od_sphere: toNumber(inputs.se_od_sphere),
        se_od_cylinder: toNumber(inputs.se_od_cylinder),
        se_od_result: sphericalEquivalent(inputs.se_od_sphere, inputs.se_od_cylinder),
        se_oe_sphere: toNumber(inputs.se_oe_sphere),
        se_oe_cylinder: toNumber(inputs.se_oe_cylinder),
        se_oe_result: sphericalEquivalent(inputs.se_oe_sphere, inputs.se_oe_cylinder),
    };

    return hasContactLensResult(calc) ? calc : null;
}

/**
 * Payload da edição sem o cálculo quando ele não mudou desde que o prontuário
 * abriu: um valor gravado antes de uma regra nova (ex.: limite de 2 casas)
 * não pode travar com 422 o save do prontuário inteiro. Sem a chave, o
 * servidor mantém o que está gravado. `saved` = cálculo carregado (ou null).
 */
export function omitUnchangedContactLens(data, saved) {
    if (JSON.stringify(data.contact_lens_calculation ?? null) !== JSON.stringify(saved ?? null)) return data;
    const payload = { ...data };
    delete payload.contact_lens_calculation;
    return payload;
}

export function hasContactLensResult(calc) {
    return (
        !!calc &&
        ['vertex_od_result', 'vertex_oe_result', 'se_od_result', 'se_oe_result'].some(
            (key) => calc[key] !== null && calc[key] !== undefined,
        )
    );
}

/**
 * Faixas aceitas pelo servidor (Store/UpdateMedicalRecordRequest): o modal
 * avisa na hora, em vez de um 422 só ao salvar a consulta.
 */
export const CONTACT_LENS_LIMITS = Object.freeze({
    vertex_distance_mm: [5, 25],
    vertex_od: [-40, 40],
    vertex_oe: [-40, 40],
    se_od_sphere: [-40, 40],
    se_od_cylinder: [-15, 15],
    se_oe_sphere: [-40, 40],
    se_oe_cylinder: [-15, 15],
});

/**
 * Campos fora da faixa ou com mais de 2 casas decimais (o servidor exige o
 * mesmo — decimal:0,2 —, para o valor exibido ser o usado no cálculo).
 * Distância vazia/0 vira 12 mm — não conta.
 */
export function contactLensInvalid(inputs = {}) {
    return Object.entries(CONTACT_LENS_LIMITS)
        .filter(([key, [min, max]]) => {
            const n = key === 'vertex_distance_mm' ? toNumber(inputs[key]) || DEFAULT_VERTEX_MM : toNumber(inputs[key]);
            return n !== null && (n < min || n > max || round2(n) !== n);
        })
        .map(([key]) => key);
}

/** Dioptria com sinal e 2 casas (ex.: "-5.60", "+6.47", "0.00"); vazio = "—". */
export function formatDiopter(v) {
    const n = toNumber(v);
    if (n === null) return '—';
    return `${n > 0 ? '+' : ''}${n.toFixed(2)}`;
}

/** Distância ao vértice no idioma da tela, sem zeros à toa ("12", "12,5"). `locale` como 'pt-BR'. */
export function formatVertexMm(v, locale = 'pt-BR') {
    const n = toNumber(v) ?? DEFAULT_VERTEX_MM;
    return new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(n);
}

/** Rótulo da conversão ao vértice com a distância usada ("… (vértice 12 mm)"). */
function vertexLabel(calc, t, locale) {
    return (t.contact_lens_vertex_label ?? 'Esférico → lente de contato (vértice :mm mm)').replace(
        ':mm',
        formatVertexMm(calc.vertex_distance_mm, locale),
    );
}

/**
 * Resumo para o prontuário e a visualização da consulta: [{ key, label,
 * value }]. Vértice mostra entrada → resultado ("OD: -6.00 → -5.60"), para
 * ficar claro que só o esférico foi convertido; SE mostra o resultado. Olho
 * sem resultado fica de fora; linha sem resultado some. `t` = traduções de
 * actions.medical_records; `locale` formata a distância em mm.
 */
export function contactLensSummary(calc, t = {}, locale = undefined) {
    if (!hasContactLensResult(calc)) return [];
    const eyes = (pairs) =>
        pairs
            .filter(([, , result]) => toNumber(result) !== null)
            .map(([eye, input, result]) =>
                input === undefined
                    ? `${eye}: ${formatDiopter(result)}`
                    : `${eye}: ${formatDiopter(input)} → ${formatDiopter(result)}`,
            )
            .join('  ·  ');
    const od = t.od ?? 'OD';
    const oe = t.oe ?? 'OE';

    return [
        {
            key: 'vertex',
            label: vertexLabel(calc, t, locale),
            value: eyes([
                [od, calc.vertex_od, calc.vertex_od_result],
                [oe, calc.vertex_oe, calc.vertex_oe_result],
            ]),
        },
        {
            key: 'se',
            label: t.contact_lens_se_title ?? 'Equivalente esférico',
            value: eyes([
                [od, undefined, calc.se_od_result],
                [oe, undefined, calc.se_oe_result],
            ]),
        },
    ].filter((row) => row.value !== '');
}

/**
 * Versão curta para o painel "Consultas anteriores" (coluna estreita): só o
 * resultado por olho, no formato do painel ("OD -5.60 | OE +6.47"), com sigla
 * (LC / SE) e o rótulo completo para o title. Linha sem resultado some.
 */
export function contactLensCompact(calc, t = {}, locale = undefined) {
    if (!hasContactLensResult(calc)) return [];
    const results = (odResult, oeResult) =>
        [
            [t.od ?? 'OD', odResult],
            [t.oe ?? 'OE', oeResult],
        ]
            .filter(([, result]) => toNumber(result) !== null)
            .map(([eye, result]) => `${eye} ${formatDiopter(result)}`)
            .join(' | ');

    return [
        {
            key: 'vertex',
            tag: t.contact_lens_short ?? 'LC',
            label: vertexLabel(calc, t, locale),
            value: results(calc.vertex_od_result, calc.vertex_oe_result),
        },
        {
            key: 'se',
            tag: t.contact_lens_se_short ?? 'SE',
            label: t.contact_lens_se_title ?? 'Equivalente esférico',
            value: results(calc.se_od_result, calc.se_oe_result),
        },
    ].filter((row) => row.value !== '');
}

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
 * para copiar sem redigitar. null quando o bloco não foi preenchido (tudo no
 * padrão "0.00"/"0°"): não vira "plano" por engano. Num bloco preenchido,
 * 0.00 conta como plano. `unreadable` lista os campos com texto que não é
 * número (ex.: "PL"): copiados como vazio, o SE ignoraria o cilindro.
 */
export function refractionFromRecord(form, prefix) {
    if (REFRACTION_FIELDS.every((field) => isDefaultRefraction(form?.[`${prefix}_${field}`]))) {
        return null;
    }

    const unreadable = [];
    const read = (field) => {
        const raw = form?.[`${prefix}_${field}`];
        const n = toNumber(raw);
        if (n === null && !isBlank(raw)) unreadable.push(field);
        return n;
    };

    return {
        od: { sphere: read('spherical_right'), cylinder: read('cylindrical_right') },
        oe: { sphere: read('spherical_left'), cylinder: read('cylindrical_left') },
        unreadable,
    };
}
