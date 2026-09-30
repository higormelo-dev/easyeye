import { describe, it, expect } from 'vitest';
import { normalizeSectionOrder, moveVisibleSection } from '@/Pages/Panel/Dashboard/sectionOrder.js';

/**
 * Ordem das seções do Dashboard: a escolha do usuário sobrevive quando a
 * seção de alertas de estoque aparece ou some (polling, troca de clínica) e
 * mover no menu (só as visíveis) não bagunça a posição das ocultas.
 */

const KEYS = ['kpis', 'shortcuts', 'agenda', 'patients', 'stock'];

describe('normalizeSectionOrder', () => {
    it('mantém a ordem salva e acrescenta no fim as seções novas', () => {
        // Ordem salva antes de "stock" existir na lista (preferência antiga, só 4).
        expect(normalizeSectionOrder(['patients', 'agenda', 'kpis', 'shortcuts'], KEYS))
            .toEqual(['patients', 'agenda', 'kpis', 'shortcuts', 'stock']);
    });

    it('descarta chaves desconhecidas e repetidas; valor inválido volta ao padrão', () => {
        expect(normalizeSectionOrder(['agenda', 'x', 'agenda', 'kpis'], KEYS))
            .toEqual(['agenda', 'kpis', 'shortcuts', 'patients', 'stock']);
        expect(normalizeSectionOrder(null, KEYS)).toEqual(KEYS);
        expect(normalizeSectionOrder('kpis', KEYS)).toEqual(KEYS);
    });
});

describe('moveVisibleSection', () => {
    it('move entre as visíveis e devolve a oculta à posição que ocupava', () => {
        const order   = ['kpis', 'stock', 'shortcuts', 'agenda', 'patients'];
        const visible = ['kpis', 'shortcuts', 'agenda', 'patients']; // sem alerta de estoque agora

        // "Pacientes" (índice 3 no menu) para o topo.
        expect(moveVisibleSection(order, visible, 3, 0))
            .toEqual(['patients', 'stock', 'kpis', 'shortcuts', 'agenda']);
    });

    it('com todas visíveis é um mover simples', () => {
        expect(moveVisibleSection(KEYS, KEYS, 0, 4)).toEqual(['shortcuts', 'agenda', 'patients', 'stock', 'kpis']);
    });

    it('movimento inválido não muda nada (null)', () => {
        expect(moveVisibleSection(KEYS, KEYS, 1, 1)).toBeNull();
        expect(moveVisibleSection(KEYS, KEYS, -1, 0)).toBeNull();
        expect(moveVisibleSection(KEYS, ['kpis', 'agenda'], 0, 2)).toBeNull();
    });
});
