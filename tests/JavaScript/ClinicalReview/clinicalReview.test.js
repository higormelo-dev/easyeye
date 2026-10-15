import { describe, it, expect } from 'vitest';
import {
    blankAttestation,
    draftPayload,
    fieldOrder,
    groupedHash,
    initialState,
    needsComment,
    normalizeNumber,
    orderedErrorPaths,
    submitPayload,
    validateReview,
} from '@/Support/clinicalReview.js';

const items = [
    { id: 'i-1', key: 'glaucoma', title: 'Glaucoma', version: 1 },
    { id: 'i-2', key: 'dmri', title: 'DMRI', version: 1 },
];
const ufs = ['SP', 'RJ', 'MG'];

/** Formulário completo e válido (os dois itens aprovados). */
function filled() {
    const state = initialState(items, null);
    state.decisions['i-1'].decision = 'approved';
    state.decisions['i-2'].decision = 'approved';
    Object.assign(state.attestation, {
        name: 'Dra. Helena Prado',
        email: 'helena@exemplo.test',
        crm_number: '12.345',
        crm_uf: 'SP',
        rqe: '6789',
        has_conflict: false,
        allow_identity_display: true,
        agreed: true,
    });

    return state;
}

describe('initialState', () => {
    it('sem rascunho: tudo vazio, especialidade padrão e nenhuma escolha feita', () => {
        const state = initialState(items, null);

        expect(Object.keys(state.decisions)).toEqual(['i-1', 'i-2']);
        expect(state.decisions['i-1']).toEqual({ decision: null, comment: '', proposed_changes: '' });
        expect(state.attestation).toEqual(blankAttestation());
        expect(state.attestation.specialty).toBe('Oftalmologia');
        expect(state.attestation.has_conflict).toBeNull();
        expect(state.attestation.allow_identity_display).toBeNull();
        expect(state.attestation.agreed).toBe(false);
    });

    it('com rascunho: devolve o que foi salvo, ignora item de outro convite e valor fora do formato', () => {
        const state = initialState(items, {
            decisions: {
                'i-1': {
                    decision: 'changes_requested',
                    comment: 'Reduzir a campimetria.',
                    proposed_changes: '9 meses',
                },
                'i-2': { decision: 'qualquer-coisa', comment: 42 },
                'item-de-outro-convite': { decision: 'approved', comment: 'não pode entrar' },
            },
            attestation: {
                name: 'Dra. Helena',
                crm_uf: 'RJ',
                has_conflict: true,
                allow_identity_display: 'sim',
                agreed: true,
            },
        });

        expect(state.decisions['i-1']).toEqual({
            decision: 'changes_requested',
            comment: 'Reduzir a campimetria.',
            proposed_changes: '9 meses',
        });
        expect(state.decisions['i-2']).toEqual({ decision: null, comment: '', proposed_changes: '' });
        expect(state.decisions['item-de-outro-convite']).toBeUndefined();
        expect(state.attestation.name).toBe('Dra. Helena');
        expect(state.attestation.crm_uf).toBe('RJ');
        expect(state.attestation.has_conflict).toBe(true);
        // "sim" não é booleano: volta a "não respondeu".
        expect(state.attestation.allow_identity_display).toBeNull();
    });

    it('a concordância NUNCA volta do rascunho (a declaração pode ter mudado): o Revisor concorda de novo', () => {
        expect(initialState(items, { attestation: { agreed: true } }).attestation.agreed).toBe(false);
    });
});

describe('payloads', () => {
    it('o rascunho é uma cópia: mexer nele não mexe no estado', () => {
        const state = filled();
        const payload = draftPayload(state);

        payload.attestation.name = 'Outra pessoa';

        expect(state.attestation.name).toBe('Dra. Helena Prado');
        expect(Object.keys(payload)).toEqual(['decisions', 'attestation']);
    });

    it('o envio leva a declaração (id e hash) que o Revisor tinha na tela', () => {
        const payload = submitPayload(filled(), { term_version_id: 'tv-1', sha256: 'a'.repeat(64) });

        expect(payload.statement_term_version_id).toBe('tv-1');
        expect(payload.statement_sha256).toBe('a'.repeat(64));
        expect(Object.keys(payload.decisions)).toEqual(['i-1', 'i-2']);
    });
});

describe('normalizeNumber', () => {
    it.each([
        ['12.345', '12345'],
        ['012345', '12345'],
        [' 12-345 ', '12345'],
        ['000', ''],
        ['abc', ''],
        [null, ''],
    ])('%s → %s', (input, expected) => {
        expect(normalizeNumber(input)).toBe(expected);
    });
});

describe('validateReview', () => {
    it('formulário completo não tem erro', () => {
        expect(validateReview(filled(), items, ufs)).toEqual({});
    });

    it('formulário vazio: aponta cada campo pelo caminho do servidor', () => {
        const errors = validateReview(initialState(items, null), items, ufs);

        expect(errors).toMatchObject({
            'decisions.i-1.decision': 'decision_required',
            'decisions.i-2.decision': 'decision_required',
            'attestation.name': 'name_required',
            'attestation.email': 'email_invalid',
            'attestation.crm_number': 'number_required',
            'attestation.crm_uf': 'uf_required',
            'attestation.rqe': 'number_required',
            'attestation.has_conflict': 'choice_required',
            'attestation.allow_identity_display': 'choice_required',
            'attestation.agreed': 'agreed_required',
        });
        // Especialidade já vem preenchida.
        expect(errors['attestation.specialty']).toBeUndefined();
    });

    it('só "aprovo" dispensa justificativa; ajuste e rejeição exigem 10+ caracteres de verdade', () => {
        const state = filled();

        state.decisions['i-1'].decision = 'changes_requested';
        state.decisions['i-2'].decision = 'rejected';
        expect(validateReview(state, items, ufs)).toMatchObject({
            'decisions.i-1.comment': 'comment_required',
            'decisions.i-2.comment': 'comment_required',
        });

        // Espaços não contam.
        state.decisions['i-1'].comment = '   curto    ';
        state.decisions['i-2'].comment = '          ';
        expect(Object.keys(validateReview(state, items, ufs))).toEqual([
            'decisions.i-1.comment',
            'decisions.i-2.comment',
        ]);

        state.decisions['i-1'].comment = '0123456789';
        state.decisions['i-2'].comment = 'Justificativa suficiente.';
        expect(validateReview(state, items, ufs)).toEqual({});

        state.decisions['i-1'].decision = 'approved';
        state.decisions['i-1'].comment = '';
        expect(validateReview(state, items, ufs)).toEqual({});
    });

    it('limites de tamanho do comentário e da alteração sugerida', () => {
        const state = filled();

        state.decisions['i-1'].comment = 'x'.repeat(3001);
        state.decisions['i-2'].proposed_changes = 'x'.repeat(6001);

        expect(validateReview(state, items, ufs)).toEqual({
            'decisions.i-1.comment': 'comment_too_long',
            'decisions.i-2.proposed_changes': 'proposed_too_long',
        });
    });

    it('CRM e RQE: só dígitos, ponto, hífen e espaço; zero sozinho não é número', () => {
        const state = filled();

        for (const [value, code] of [
            ['', 'number_required'],
            ['   ', 'number_required'],
            ['12a45', 'number_invalid'],
            ['0000', 'number_invalid'],
            ['1234567890123456', 'number_invalid'],
        ]) {
            state.attestation.crm_number = value;
            expect(validateReview(state, items, ufs)['attestation.crm_number']).toBe(code);
        }

        state.attestation.crm_number = '012.345-6';
        expect(validateReview(state, items, ufs)['attestation.crm_number']).toBeUndefined();
    });

    it('UF fora da lista, e-mail sem arroba e nome curto são recusados', () => {
        const state = filled();

        state.attestation.crm_uf = 'XX';
        state.attestation.email = 'helena.exemplo.test';
        state.attestation.name = 'Al';

        expect(validateReview(state, items, ufs)).toEqual({
            'attestation.name': 'name_required',
            'attestation.email': 'email_invalid',
            'attestation.crm_uf': 'uf_required',
        });
    });

    it('conflito de interesses declarado exige a descrição', () => {
        const state = filled();

        state.attestation.has_conflict = true;
        expect(validateReview(state, items, ufs)).toEqual({
            'attestation.conflict_description': 'conflict_description_required',
        });

        state.attestation.conflict_description = '   ';
        expect(validateReview(state, items, ufs)['attestation.conflict_description']).toBe(
            'conflict_description_required',
        );

        state.attestation.conflict_description = 'Palestrante de um fabricante.';
        expect(validateReview(state, items, ufs)).toEqual({});
    });

    it('sem concordar com a declaração não envia', () => {
        const state = filled();

        state.attestation.agreed = false;

        expect(validateReview(state, items, ufs)).toEqual({ 'attestation.agreed': 'agreed_required' });
    });
});

describe('ordem dos erros', () => {
    it('lê os itens na ordem do convite e depois a identificação na ordem do formulário', () => {
        const order = fieldOrder(items);

        expect(order.slice(0, 3)).toEqual([
            'decisions.i-1.decision',
            'decisions.i-1.comment',
            'decisions.i-1.proposed_changes',
        ]);
        expect(order.indexOf('decisions.i-2.decision')).toBeLessThan(order.indexOf('attestation.name'));
        expect(order.indexOf('attestation.name')).toBeLessThan(order.indexOf('attestation.agreed'));
    });

    it('ordena os erros; caminho desconhecido vai para o fim', () => {
        const paths = orderedErrorPaths(
            { 'attestation.agreed': 'x', desconhecido: 'x', 'attestation.name': 'x', 'decisions.i-2.decision': 'x' },
            items,
        );

        expect(paths).toEqual(['decisions.i-2.decision', 'attestation.name', 'attestation.agreed', 'desconhecido']);
    });
});

describe('utilidades', () => {
    it('needsComment', () => {
        expect(needsComment('approved')).toBe(false);
        expect(needsComment('changes_requested')).toBe(true);
        expect(needsComment('rejected')).toBe(true);
        expect(needsComment(null)).toBe(false);
    });

    it('código de verificação em grupos de 4', () => {
        expect(groupedHash('a1b2c3d4e5f6')).toBe('a1b2 c3d4 e5f6');
        expect(groupedHash('ABCD2345')).toBe('ABCD 2345');
        expect(groupedHash('abc')).toBe('abc');
        // O prefixo de versão do algoritmo fica colado ao primeiro grupo.
        expect(groupedHash('1:a1b2c3d4e5f6')).toBe('1:a1b2 c3d4 e5f6');
        expect(groupedHash(null)).toBe('');
    });
});
