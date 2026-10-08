import { describe, it, expect } from 'vitest';
import {
    plain,
    core,
    containsPhrase,
    termsOf,
    itemConflicts,
    textConflicts,
    reconcileItems,
    recordSafetyRows,
} from '@/Support/clinicalSafety.js';
import { CHECK, STOPWORDS } from './fixtures.js';

/**
 * Checagem de alergia NA TELA do receituário (Fase 2 / A4) — espelha a regra
 * do servidor (AllergyConflictChecker): sem acento, sem sais, palavra inteira.
 */
describe('normalização', () => {
    it('minúsculo, sem acento, tags viram espaço (não colam palavras)', () => {
        expect(plain('<p>Data: 07/10/2026</p><p>Dipírona SÓDICA</p>')).toBe('data 07 10 2026 dipirona sodica');
        expect(core('Cloridrato de Tansulosina', STOPWORDS)).toBe('tansulosina');
    });

    it('frase por palavra inteira: "iodo" não casa com "iodopovidona"', () => {
        expect(containsPhrase('iodopovidona topica', 'iodo')).toBe(false);
        expect(containsPhrase('povidona iodo 10', 'iodo')).toBe(true);
    });

    it('termos de uma substância composta', () => {
        expect(termsOf('Amoxicilina + clavulanato; Dipirona', STOPWORDS)).toEqual([
            'amoxicilina',
            'clavulanato',
            'dipirona',
        ]);
    });
});

describe('conflitos do item', () => {
    it('mesma classe (penicilina → amoxicilina do catálogo)', () => {
        const found = itemConflicts({ name: 'AMOXIL', active_ingredient: 'AMOXICILINA TRI-HIDRATADA' }, CHECK);

        expect(found).toHaveLength(1);
        expect(found[0]).toMatchObject({ allergy_id: 'al-pen', match: 'class', reaction: 'Urticária' });
    });

    it('mesmo princípio ativo, com sal e acento diferentes', () => {
        const found = itemConflicts({ name: 'Novalgina', active_ingredient: 'DIPIRONA MONOIDRATADA' }, CHECK);

        expect(found.map((c) => [c.allergy_id, c.match])).toEqual([['al-dip', 'ingredient']]);
    });

    it('colírio com alergia ao conservante (cloreto de benzalcônio)', () => {
        const found = itemConflicts(
            { name: 'LACRIMA PLUS', active_ingredient: 'DEXTRANA + HIPROMELOSE', is_eye_drop: true },
            CHECK,
        );

        expect(found.map((c) => c.match)).toEqual(['eye_drops']);
    });

    it('sem conflito: nada; sem dados de checagem (perfil sem acesso): nada', () => {
        expect(itemConflicts({ name: 'TIMOPTOL', active_ingredient: 'MALEATO DE TIMOLOL' }, CHECK)).toEqual([]);
        expect(itemConflicts({ name: 'AMOXIL', active_ingredient: 'AMOXICILINA' }, null)).toEqual([]);
    });
});

describe('conflitos no texto livre', () => {
    it('acha substância e membro da classe no texto (sem acento/maiúsculas)', () => {
        const found = textConflicts('1) DIPIRONA 500mg\n2) Cefalexina 500 mg 6/6h', CHECK);

        expect(found.map((c) => [c.allergy_id, c.match])).toEqual([
            ['al-pen', 'class'],
            ['al-dip', 'text'],
        ]);
    });
});

describe('reconciliação de itens com o texto final (14ª revisão Codex, P2)', () => {
    it('mantém item cujo NOME ainda aparece no texto, mesmo com princípio ativo preenchido', () => {
        const items = [{ name: 'LACRIMA PLUS', active_ingredient: 'DEXTRANA + HIPROMELOSE' }];

        // Texto só cita a marca — não tem "dextrana"/"hipromelose" escrito.
        expect(reconcileItems(items, 'Lacrima Plus — 1 gota 4x/dia', STOPWORDS)).toEqual(items);
    });

    it('descarta item cujo nome E princípio ativo saíram do texto', () => {
        const items = [{ name: 'AMOXIL', active_ingredient: 'AMOXICILINA TRI-HIDRATADA' }];

        expect(reconcileItems(items, 'Dipirona 500mg se dor', STOPWORDS)).toEqual([]);
    });

    it('item sem nome nem princípio ativo pra comparar fica (não dá pra provar que saiu)', () => {
        const items = [{ name: '', active_ingredient: '' }];

        expect(reconcileItems(items, 'Dipirona 500mg se dor', STOPWORDS)).toEqual(items);
    });

    it('sem itens ou sem texto: lista vazia', () => {
        expect(reconcileItems([], 'Dipirona', STOPWORDS)).toEqual([]);
        expect(reconcileItems(null, 'Dipirona', STOPWORDS)).toEqual([]);
    });
});

describe('histórico da consulta', () => {
    it('retrato de alergias/medicações e uso de LC viram linhas', () => {
        const rows = recordSafetyRows(
            {
                allergies_snapshot: {
                    state: 'has',
                    items: [{ substance: 'Dipirona', reaction: 'Edema', severity_label: 'Grave' }],
                },
                medications_snapshot: [{ name: 'Secotex', posology: '1 cp' }],
                contact_lens_use_rows: [{ label: 'Uso de lente de contato', value: 'Sim' }],
            },
            {},
        );

        expect(rows).toEqual([
            ['Alergias (no momento da consulta)', 'Dipirona (Edema; Grave)'],
            ['Medicações em uso (no momento da consulta)', 'Secotex — 1 cp'],
            ['Uso de lente de contato', 'Sim'],
        ]);
        expect(
            recordSafetyRows({ allergies_snapshot: { state: 'unknown', items: [] }, medications_snapshot: [] }, {}),
        ).toEqual([
            ['Alergias (no momento da consulta)', 'Sem registro de alergias'],
            ['Medicações em uso (no momento da consulta)', 'Nenhuma na lista estruturada.'],
        ]);
        expect(recordSafetyRows({}, {})).toEqual([]);
    });
});
