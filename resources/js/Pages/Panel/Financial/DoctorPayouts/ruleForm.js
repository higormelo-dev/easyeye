/**
 * Regra de repasse: formulário ↔ payload do DoctorPayoutRuleRequest.
 *
 * "Aplicar a" é UM select (todos os itens do tipo + grupos por tipo de item),
 * com valor 'visit_type:<id>' | 'procedure:<id>' | 'exam_type:<id>' | ''. O
 * payload leva no máximo UM dos três ids (os outros null), o valor só do
 * cálculo escolhido e o convênio só com pagador "convênio" — a mesma
 * coerência que o backend confere (DoctorPayoutRuleRequest::after).
 *
 * Divisão (E4, só percentual): `participants` = [{ role: 'executor'|'doctor',
 * doctor_id, percentage }] somando 100%; sem participantes o executor fica
 * com o grupo inteiro. Regra de valor fixo não leva participantes.
 */
export const SERVICE_TYPES = ['consultation', 'exam', 'procedure'];
export const ALL_TYPES     = 'all';
export const PAYER_SCOPES  = ['any', 'particular', 'covenant'];
export const CALCULATIONS  = ['percentage', 'fixed'];

/** Tipos de item que cada tipo de serviço aceita (ordem de exibição). */
const ITEM_KINDS = {
    consultation: ['visit_type'],
    exam:         ['exam_type', 'visit_type'],
    procedure:    ['procedure', 'visit_type'],
};

export function itemKindsFor(serviceType) {
    return ITEM_KINDS[serviceType] ?? [];
}

/**
 * Opções de um tipo de item para o tipo de serviço. Tipos de atendimento só
 * os que geram aquele serviço (visit_types[].service_type).
 *
 * @returns {Array<{ id: string, name: string }>}
 */
export function itemOptionsFor(kind, serviceType, options = {}) {
    switch (kind) {
        case 'visit_type':
            return (options.visit_types ?? [])
                .filter((visitType) => visitType.service_type === serviceType)
                .map((visitType) => ({ id: visitType.id, name: visitType.name }));
        case 'procedure':
            return (options.procedures ?? [])
                .map((procedure) => ({ id: procedure.id, name: procedure.code ? `${procedure.code} — ${procedure.name}` : procedure.name }));
        case 'exam_type':
            return (options.exam_types ?? []).map((examType) => ({ id: examType.id, name: examType.name }));
        default:
            return [];
    }
}

export function encodeItem(kind, id) {
    return kind && id ? `${kind}:${id}` : '';
}

export function decodeItem(value) {
    const text = String(value ?? '');
    const at   = text.indexOf(':');

    return at > 0 ? { kind: text.slice(0, at), id: text.slice(at + 1) } : { kind: '', id: '' };
}

export function emptyRuleForm() {
    return {
        doctor_id:    '',
        service_type: 'consultation',
        item:         '',
        payer_scope:  'any',
        covenant_id:  '',
        calculation:  'percentage',
        percentage:   '',
        fixed_amount: null,
        valid_from:   '',
        valid_until:  '',
        active:       true,
        notes:        '',
        participants: [],
        // Edição: 'fix' corrige a regra (vale para o que ainda não foi
        // fechado); 'new' cria nova vigência a partir de effective_from.
        change_mode:    'fix',
        effective_from: '',
    };
}

/** Linha da listagem (DoctorPayoutRulesController::row) → campos do formulário. */
export function ruleToForm(rule) {
    return {
        doctor_id:    rule.doctor_id ?? '',
        service_type: rule.service_type,
        item:         encodeItem(rule.item_kind, rule.item_id),
        payer_scope:  rule.payer_scope ?? 'any',
        covenant_id:  rule.covenant_id ?? '',
        calculation:  rule.calculation ?? 'percentage',
        percentage:   rule.percentage ?? '',
        fixed_amount: rule.fixed_amount ?? null,
        valid_from:   rule.valid_from ?? '',
        valid_until:  rule.valid_until ?? '',
        active:       rule.active !== false,
        notes:        rule.notes ?? '',
        participants: (rule.participants ?? []).map((participant) => ({
            role:       participant.role,
            doctor_id:  participant.doctor_id ?? '',
            percentage: participant.percentage,
        })),
        change_mode:    'fix',
        effective_from: '',
    };
}

/** Regra existente como modelo de uma NOVA (duplicar): mesmos campos, sem vigência. */
export function ruleAsTemplate(rule) {
    return { ...ruleToForm(rule), valid_from: '', valid_until: '', active: true };
}

const blankToNull = (value) => (value === '' || value === undefined ? null : value);

/** Campos do formulário → payload enviado (form.transform). */
export function rulePayload(data) {
    const { item, change_mode: changeMode, effective_from: effectiveFrom, ...fields } = data;
    const { kind, id } = data.service_type === ALL_TYPES ? { kind: '', id: '' } : decodeItem(item);

    return {
        ...fields,
        doctor_id:     blankToNull(data.doctor_id),
        visit_type_id: kind === 'visit_type' ? id : null,
        procedure_id:  kind === 'procedure' ? id : null,
        exam_type_id:  kind === 'exam_type' ? id : null,
        covenant_id:   data.payer_scope === 'covenant' ? blankToNull(data.covenant_id) : null,
        percentage:    data.calculation === 'percentage' ? blankToNull(data.percentage) : null,
        fixed_amount:  data.calculation === 'fixed' ? blankToNull(data.fixed_amount) : null,
        valid_from:    blankToNull(data.valid_from),
        valid_until:   blankToNull(data.valid_until),
        notes:         blankToNull(data.notes),
        active:        Boolean(data.active),
        participants:  data.calculation === 'percentage'
            ? (data.participants ?? []).map((participant) => ({
                role:       participant.role,
                doctor_id:  participant.role === 'doctor' ? blankToNull(participant.doctor_id) : null,
                percentage: blankToNull(participant.percentage),
            }))
            : [],
        // Nova vigência só vai quando escolhida (e só existe ao editar); o
        // servidor exige a data nesse modo — em branco não vira correção.
        ...(changeMode === 'new' ? { change_mode: 'new', effective_from: blankToNull(effectiveFrom) } : {}),
    };
}
