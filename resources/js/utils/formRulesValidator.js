/**
 * F9 — validator client-side espelhado do FormRequest Laravel.
 *
 * Consome o JSON exposto por MedicalRecordValidationRulesController:
 *   {
 *     "field_name": {
 *       "rules":  ["required", "string", "max"],
 *       "params": { "max": "5000" }
 *     }
 *   }
 *
 * Retorna { valid: bool, errors: { field: [msg, ...] } }.
 *
 * Não substitui o validator do servidor — é UX. Servidor continua sendo a
 * fonte de verdade (mesmo FormRequest).
 */

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const messages = {
    required:    (field)        => `O campo ${field} é obrigatório.`,
    string:      (field)        => `O campo ${field} deve ser um texto.`,
    numeric:     (field)        => `O campo ${field} deve ser numérico.`,
    integer:     (field)        => `O campo ${field} deve ser um inteiro.`,
    boolean:     (field)        => `O campo ${field} deve ser verdadeiro ou falso.`,
    uuid:        (field)        => `O campo ${field} deve ser um identificador válido.`,
    array:       (field)        => `O campo ${field} deve ser uma lista.`,
    min:         (field, value) => `O campo ${field} deve ser maior ou igual a ${value}.`,
    minLen:      (field, value) => `O campo ${field} deve ter pelo menos ${value} caracteres.`,
    minArr:      (field, value) => `O campo ${field} deve conter pelo menos ${value} itens.`,
    max:         (field, value) => `O campo ${field} deve ser menor ou igual a ${value}.`,
    maxLen:      (field, value) => `O campo ${field} não pode passar de ${value} caracteres.`,
    maxArr:      (field, value) => `O campo ${field} não pode conter mais de ${value} itens.`,
    date_format: (field, value) => `O campo ${field} deve seguir o formato ${value}.`,
    in:          (field)        => `O valor selecionado para ${field} é inválido.`,
};

function isEmpty(value) {
    if (value === null || value === undefined) return true;
    if (typeof value === 'string') return value.trim() === '';
    if (Array.isArray(value))     return value.length === 0;
    return false;
}

function isNumericLike(value) {
    if (typeof value === 'number') return Number.isFinite(value);
    if (typeof value !== 'string') return false;
    const trimmed = value.trim();
    if (trimmed === '') return false;
    return !Number.isNaN(Number(trimmed));
}

function isIntegerLike(value) {
    if (!isNumericLike(value)) return false;
    return Number.isInteger(Number(value));
}

function isBooleanLike(value) {
    if (typeof value === 'boolean') return true;
    if (value === 0 || value === 1) return true;
    if (typeof value === 'string') {
        return ['0', '1', 'true', 'false', 'on', 'off'].includes(value.toLowerCase());
    }
    return false;
}

function matchDateFormat(value, format) {
    if (typeof value !== 'string') return false;
    if (format === 'd/m/Y') return /^\d{2}\/\d{2}\/\d{4}$/.test(value);
    if (format === 'H:i')   return /^\d{2}:\d{2}$/.test(value);
    if (format === 'Y-m-d') return /^\d{4}-\d{2}-\d{2}$/.test(value);
    return true; // formato desconhecido — confia no servidor
}

/**
 * Valida um único campo contra a definição da rule.
 *
 * @returns {string[]} mensagens de erro acumuladas.
 */
function validateField(label, value, definition) {
    const errors  = [];
    const rules   = definition?.rules  || [];
    const params  = definition?.params || {};
    const empty   = isEmpty(value);

    // Skip nullable/sometimes para valores vazios — rules abaixo só rodam se há valor.
    const isOptional = rules.includes('nullable') || rules.includes('sometimes');
    if (empty && !rules.includes('required') && isOptional) {
        return errors;
    }

    if (rules.includes('required') && empty) {
        errors.push(messages.required(label));
        return errors; // sem valor, demais rules são irrelevantes.
    }

    if (empty) return errors; // não-required, sem valor → nada a checar.

    if (rules.includes('numeric') && !isNumericLike(value))   errors.push(messages.numeric(label));
    if (rules.includes('integer') && !isIntegerLike(value))   errors.push(messages.integer(label));
    if (rules.includes('boolean') && !isBooleanLike(value))   errors.push(messages.boolean(label));
    if (rules.includes('uuid')    && (typeof value !== 'string' || !UUID_RE.test(value))) {
        errors.push(messages.uuid(label));
    }
    if (rules.includes('array') && !Array.isArray(value))     errors.push(messages.array(label));
    if (rules.includes('string') && typeof value !== 'string' && typeof value !== 'number') {
        errors.push(messages.string(label));
    }

    if (params.min !== undefined) {
        const limit = Number(params.min);
        if (rules.includes('numeric') || rules.includes('integer')) {
            if (Number(value) < limit) errors.push(messages.min(label, limit));
        } else if (Array.isArray(value)) {
            if (value.length < limit) errors.push(messages.minArr(label, limit));
        } else if (typeof value === 'string') {
            if (value.length < limit) errors.push(messages.minLen(label, limit));
        }
    }

    if (params.max !== undefined) {
        const limit = Number(params.max);
        if (rules.includes('numeric') || rules.includes('integer')) {
            if (Number(value) > limit) errors.push(messages.max(label, limit));
        } else if (Array.isArray(value)) {
            if (value.length > limit) errors.push(messages.maxArr(label, limit));
        } else if (typeof value === 'string') {
            if (value.length > limit) errors.push(messages.maxLen(label, limit));
        }
    }

    if (params.date_format && !matchDateFormat(value, params.date_format)) {
        errors.push(messages.date_format(label, params.date_format));
    }

    if (params.in && Array.isArray(params.in) && !params.in.includes(String(value))) {
        errors.push(messages.in(label));
    }

    return errors;
}

/**
 * Valida um payload contra um conjunto de rules exportadas.
 *
 * Suporta apenas atributos top-level (nesting `diagnosis_cids.*.code` é
 * coberto pelo servidor — Alpine não introspecta).
 */
export function validatePayload(payload, rulesByField, labels = {}) {
    const errors = {};

    for (const [field, definition] of Object.entries(rulesByField || {})) {
        if (field.includes('*') || field.includes('.')) continue; // nested → server only

        // Campos `array` são serializados como JSON string no hidden input
        // (ex.: diagnosis_cids). Cliente não introspecta — server valida.
        if ((definition?.rules || []).includes('array')) continue;

        const label  = labels[field] || field;
        const value  = payload[field];
        const fieldErrors = validateField(label, value, definition);

        if (fieldErrors.length > 0) {
            errors[field] = fieldErrors;
        }
    }

    return { valid: Object.keys(errors).length === 0, errors };
}
