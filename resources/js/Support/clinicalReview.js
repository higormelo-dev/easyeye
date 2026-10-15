/**
 * Regras e estado do formulário do Revisor Clínico (Fase 6, ADR 0002) — funções puras, sem
 * Vue, para a página pública e os testes. O servidor continua sendo quem decide
 * (SubmitClinicalReviewRequest + ReviewSubmissionService); aqui só se repete o que dá para
 * dizer ao Revisor antes de ele enviar: o que falta e onde.
 */

export const COMMENT_MIN = 10;
export const COMMENT_MAX = 3000;
export const PROPOSED_MAX = 6000;
export const NAME_MIN = 3;
export const SPECIALTY_DEFAULT = 'Oftalmologia';

/** Decisões que exigem justificativa (tudo menos "aprovo"). */
export const needsComment = (decision) => decision === 'changes_requested' || decision === 'rejected';

export function blankAttestation() {
    return {
        name: '',
        email: '',
        crm_number: '',
        crm_uf: '',
        rqe: '',
        specialty: SPECIALTY_DEFAULT,
        has_conflict: null,
        conflict_description: '',
        allow_identity_display: null,
        agreed: false,
    };
}

const text = (value) => (typeof value === 'string' ? value : '');
const tristate = (value) => (typeof value === 'boolean' ? value : null);

/**
 * Estado do formulário a partir dos itens do convite e do rascunho salvo no servidor.
 * Só entra o que é conhecido (o rascunho vem de fora do componente).
 */
export function initialState(items, draft) {
    const decisions = {};

    for (const item of items ?? []) {
        const saved = draft?.decisions?.[item.id] ?? {};
        decisions[item.id] = {
            decision: ['approved', 'changes_requested', 'rejected'].includes(saved.decision) ? saved.decision : null,
            comment: text(saved.comment),
            proposed_changes: text(saved.proposed_changes),
        };
    }

    const saved = draft?.attestation ?? {};
    const blank = blankAttestation();

    return {
        decisions,
        attestation: {
            name: text(saved.name),
            email: text(saved.email),
            crm_number: text(saved.crm_number),
            crm_uf: text(saved.crm_uf),
            rqe: text(saved.rqe),
            specialty: text(saved.specialty) || blank.specialty,
            has_conflict: tristate(saved.has_conflict),
            conflict_description: text(saved.conflict_description),
            allow_identity_display: tristate(saved.allow_identity_display),
            // A concordância vale para o texto que o Revisor tinha na tela ao marcar; o rascunho não guarda qual era
            // (a declaração pode ter sido atualizada depois), então ele concorda de novo, explicitamente.
            agreed: false,
        },
    };
}

/** O que vai para o rascunho no servidor (cópia: o estado reativo nunca sai do componente). */
export function draftPayload(state) {
    return JSON.parse(JSON.stringify({ decisions: state.decisions, attestation: state.attestation }));
}

/** O que vai no POST do parecer. */
export function submitPayload(state, statement) {
    return {
        ...draftPayload(state),
        statement_term_version_id: statement.term_version_id,
        statement_sha256: statement.sha256,
    };
}

/** "12.345" e "012345" são o mesmo número: só dígitos, sem zeros à esquerda. */
export function normalizeNumber(value) {
    return String(value ?? '')
        .replace(/\D+/g, '')
        .replace(/^0+/, '');
}

const NUMBER_FORMAT = /^[\d.\-\s]{1,15}$/;
const EMAIL_FORMAT = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function numberProblem(value) {
    const raw = String(value ?? '').trim();

    if (raw === '') return 'number_required';
    if (!NUMBER_FORMAT.test(raw) || normalizeNumber(raw) === '') return 'number_invalid';

    return null;
}

/**
 * O que falta no formulário. Devolve `{ caminho: código }` com os MESMOS caminhos do
 * servidor (`decisions.<id>.comment`, `attestation.crm_uf`) — o código é uma chave de
 * `errors.*` nos textos. Vazio = pronto para revisar.
 */
export function validateReview(state, items, ufs = []) {
    const errors = {};

    for (const item of items ?? []) {
        const row = state.decisions[item.id] ?? {};

        if (!row.decision) {
            errors[`decisions.${item.id}.decision`] = 'decision_required';
            continue;
        }

        const comment = text(row.comment).trim();

        if (needsComment(row.decision) && comment.length < COMMENT_MIN) {
            errors[`decisions.${item.id}.comment`] = 'comment_required';
        } else if (comment.length > COMMENT_MAX) {
            errors[`decisions.${item.id}.comment`] = 'comment_too_long';
        }

        if (text(row.proposed_changes).length > PROPOSED_MAX) {
            errors[`decisions.${item.id}.proposed_changes`] = 'proposed_too_long';
        }
    }

    const a = state.attestation;

    if (text(a.name).trim().length < NAME_MIN) errors['attestation.name'] = 'name_required';
    if (!EMAIL_FORMAT.test(text(a.email).trim())) errors['attestation.email'] = 'email_invalid';

    const crm = numberProblem(a.crm_number);
    if (crm) errors['attestation.crm_number'] = crm;

    if (!text(a.crm_uf) || (ufs.length > 0 && !ufs.includes(text(a.crm_uf))))
        errors['attestation.crm_uf'] = 'uf_required';

    const rqe = numberProblem(a.rqe);
    if (rqe) errors['attestation.rqe'] = rqe;

    if (text(a.specialty).trim() === '') errors['attestation.specialty'] = 'specialty_required';

    if (a.has_conflict === null) {
        errors['attestation.has_conflict'] = 'choice_required';
    } else if (a.has_conflict === true && text(a.conflict_description).trim() === '') {
        errors['attestation.conflict_description'] = 'conflict_description_required';
    }

    if (a.allow_identity_display === null) errors['attestation.allow_identity_display'] = 'choice_required';
    if (a.agreed !== true) errors['attestation.agreed'] = 'agreed_required';

    return errors;
}

/**
 * Ordem de leitura dos campos (para a lista de erros e para o foco no primeiro): os itens
 * na ordem do convite, depois a identificação na ordem do formulário.
 */
export function fieldOrder(items) {
    const order = [];

    for (const item of items ?? []) {
        order.push(
            `decisions.${item.id}.decision`,
            `decisions.${item.id}.comment`,
            `decisions.${item.id}.proposed_changes`,
        );
    }

    order.push(
        'attestation.name',
        'attestation.email',
        'attestation.crm_number',
        'attestation.crm_uf',
        'attestation.rqe',
        'attestation.specialty',
        'attestation.has_conflict',
        'attestation.conflict_description',
        'attestation.allow_identity_display',
        'attestation.agreed',
    );

    return order;
}

/** Os caminhos com erro, na ordem de leitura (os que não estão na ordem vão ao final). */
export function orderedErrorPaths(errors, items) {
    const order = fieldOrder(items);
    const paths = Object.keys(errors ?? {});

    return paths.sort((a, b) => {
        const ia = order.indexOf(a);
        const ib = order.indexOf(b);

        return (ia === -1 ? order.length : ia) - (ib === -1 ? order.length : ib);
    });
}

/**
 * Código de verificação do envio em grupos de 4 ("1:a1b2 c3d4 …") — lê-se por telefone. O prefixo
 * de versão do algoritmo ("1:") fica colado ao primeiro grupo e não entra na contagem.
 */
export function groupedHash(hash) {
    const clean = String(hash ?? '').replace(/\s+/g, '');
    const [, prefix = '', rest = ''] = /^(\d+:)?(.*)$/.exec(clean) ?? [];

    return prefix + rest.replace(/(.{4})/g, '$1 ').trim();
}
