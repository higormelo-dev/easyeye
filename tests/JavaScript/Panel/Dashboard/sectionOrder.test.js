import { describe, it, expect } from 'vitest';
import {
    groupSections,
    moveVisibleSection,
    normalizeHiddenSections,
    normalizeSectionOrder,
} from '@/Pages/Panel/Dashboard/sectionOrder.js';

/**
 * Ordem das seções do Dashboard: a escolha do usuário sobrevive quando uma
 * seção aparece ou some (estoque, troca de clínica), seção NOVA entra logo
 * depois da que a precede na ordem padrão do perfil (não no fim da página) e
 * mover no menu (só as visíveis) não bagunça a posição das ocultas.
 */

const KEYS = ['kpis', 'shortcuts', 'agenda', 'patients', 'stock'];

describe('normalizeSectionOrder', () => {
    it('mantém a ordem salva; seção nova entra logo depois da que a precede na ordem padrão', () => {
        // Ordem salva antes de "stock" existir (preferência antiga, só 4).
        expect(normalizeSectionOrder(['patients', 'agenda', 'kpis', 'shortcuts'], KEYS)).toEqual([
            'patients',
            'stock',
            'agenda',
            'kpis',
            'shortcuts',
        ]);
    });

    it('seção nova que é a primeira da ordem padrão entra no começo (ex.: "Próximo paciente" do médico)', () => {
        const doctor = ['next', 'kpis', 'agenda', 'pending', 'patients', 'shortcuts', 'stock'];

        expect(normalizeSectionOrder(['kpis', 'shortcuts', 'agenda', 'pending', 'patients', 'stock'], doctor)).toEqual([
            'next',
            'kpis',
            'shortcuts',
            'agenda',
            'pending',
            'patients',
            'stock',
        ]);
    });

    it('recepção: "Confirmações", "Lista de espera" e "Aniversariantes" entram depois da agenda salva', () => {
        const secretary = [
            'kpis',
            'agenda',
            'confirmations',
            'waitlist',
            'birthdays',
            'patients',
            'shortcuts',
            'stock',
        ];

        expect(normalizeSectionOrder(['agenda', 'kpis', 'shortcuts', 'patients', 'stock'], secretary)).toEqual([
            'agenda',
            'confirmations',
            'waitlist',
            'birthdays',
            'kpis',
            'shortcuts',
            'patients',
            'stock',
        ]);
    });

    it('descarta chaves desconhecidas (de outro perfil) e repetidas; valor inválido volta ao padrão', () => {
        expect(normalizeSectionOrder(['agenda', 'x', 'agenda', 'kpis'], KEYS)).toEqual([
            'agenda',
            'patients',
            'stock',
            'kpis',
            'shortcuts',
        ]);
        expect(normalizeSectionOrder(null, KEYS)).toEqual(KEYS);
        expect(normalizeSectionOrder('kpis', KEYS)).toEqual(KEYS);
        expect(normalizeSectionOrder(['finance', 'trends'], KEYS)).toEqual(KEYS);
    });
});

describe('normalizeHiddenSections', () => {
    it('só chaves do perfil, sem repetição; valor inválido = nada oculto', () => {
        expect(normalizeHiddenSections(['patients', 'x', 'patients', 'stock'], KEYS)).toEqual(['patients', 'stock']);
        expect(normalizeHiddenSections({ patients: true }, KEYS)).toEqual([]);
        expect(normalizeHiddenSections(undefined, KEYS)).toEqual([]);
    });
});

describe('moveVisibleSection', () => {
    it('move entre as visíveis e devolve a oculta à posição que ocupava', () => {
        const order = ['kpis', 'stock', 'shortcuts', 'agenda', 'patients'];
        const visible = ['kpis', 'shortcuts', 'agenda', 'patients']; // sem alerta de estoque agora

        // "Pacientes" (índice 3 no menu) para o topo.
        expect(moveVisibleSection(order, visible, 3, 0)).toEqual(['patients', 'stock', 'kpis', 'shortcuts', 'agenda']);
    });

    it('com todas visíveis é um mover simples', () => {
        expect(moveVisibleSection(KEYS, KEYS, 0, 4)).toEqual(['shortcuts', 'agenda', 'patients', 'stock', 'kpis']);
    });

    it('movimento inválido não muda nada (null)', () => {
        expect(moveVisibleSection(KEYS, KEYS, 1, 1)).toBeNull();
        expect(moveVisibleSection(KEYS, KEYS, -1, 0)).toBeNull();
        expect(moveVisibleSection(KEYS, ['kpis', 'agenda'], 0, 2)).toBeNull();
    });

    it('perfil sem agenda/pacientes (financeiro): mover no menu não perde a posição das seções ocultas', () => {
        const order = ['agenda', 'kpis', 'pending', 'shortcuts', 'patients', 'stock'];
        const visible = ['kpis', 'shortcuts'];

        expect(moveVisibleSection(order, visible, 1, 0)).toEqual([
            'agenda',
            'shortcuts',
            'pending',
            'kpis',
            'patients',
            'stock',
        ]);
    });
});

describe('groupSections', () => {
    it('seções compactas vizinhas dividem a mesma linha; as demais ocupam a linha toda', () => {
        const blocks = groupSections([
            { key: 'kpis', size: 'full' },
            { key: 'waitlist', size: 'compact' },
            { key: 'birthdays', size: 'compact' },
            { key: 'patients', size: 'compact' },
            { key: 'shortcuts', size: 'full' },
            { key: 'stock', size: 'compact' },
        ]);

        expect(blocks.map((b) => [b.type, b.sections.map((s) => s.key)])).toEqual([
            ['full', ['kpis']],
            ['compact', ['waitlist', 'birthdays', 'patients']],
            ['full', ['shortcuts']],
            ['compact', ['stock']],
        ]);
        expect(new Set(blocks.map((b) => b.key)).size).toBe(blocks.length);
    });
});
