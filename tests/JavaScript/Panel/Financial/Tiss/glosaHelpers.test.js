import { describe, it, expect } from 'vitest';
import { activeAppeal, daysUntil, deadlineBadge, glosaRef, nextAction, softBadge } from '@/Pages/Panel/Financial/Tiss/glosaHelpers.js';

const tx = (key, params = {}) => Object.entries(params).reduce((text, [name, value]) => text.replaceAll(`:${name}`, value), key);

describe('glosaHelpers', () => {
    it('daysUntil conta dias de calendário sem fuso (positivo = futuro)', () => {
        expect(daysUntil('2026-09-29', '2026-09-26')).toBe(3);
        expect(daysUntil('2026-09-20', '2026-09-26')).toBe(-6);
        expect(daysUntil(null, '2026-09-26')).toBeNull();
    });

    it('deadlineBadge só para glosa em aberto, com texto além da cor', () => {
        const open = { is_actionable: true };

        expect(deadlineBadge({ ...open, deadline: '2026-09-20' }, '2026-09-26', 5, tx)).toMatchObject({ cls: 'badge-soft-danger', text: 'deadline_overdue_days' });
        expect(deadlineBadge({ ...open, deadline: '2026-09-26' }, '2026-09-26', 5, tx)).toMatchObject({ text: 'deadline_today' });
        expect(deadlineBadge({ ...open, deadline: '2026-09-30' }, '2026-09-26', 5, tx)).toMatchObject({ cls: 'badge-soft-warning' });
        expect(deadlineBadge({ ...open, deadline: '2026-10-30' }, '2026-09-26', 5, tx)).toMatchObject({ cls: 'badge-soft-info' });
        expect(deadlineBadge({ ...open, deadline: null }, '2026-09-26', 5, tx)).toMatchObject({ text: 'deadline_none' });
        expect(deadlineBadge({ is_actionable: false, deadline: '2026-09-20' }, '2026-09-26', 5, tx)).toBeNull();
    });

    it('nextAction segue as flags do servidor: recorrer → marcar enviado → decisão → detalhes', () => {
        const opened = { id: 'a', can_be_submitted: true, can_be_resolved: false };
        const sent   = { id: 'b', can_be_submitted: false, can_be_resolved: true };

        expect(nextAction({ is_actionable: true, appeals: [] })).toEqual({ kind: 'appeal', appeal: null });
        expect(nextAction({ is_actionable: false, appeals: [opened] })).toEqual({ kind: 'submit', appeal: opened });
        expect(nextAction({ is_actionable: false, appeals: [sent] })).toEqual({ kind: 'resolve', appeal: sent });
        expect(nextAction({ is_actionable: false, appeals: [{ can_be_submitted: false, can_be_resolved: false }] })).toEqual({ kind: 'details', appeal: null });
        expect(activeAppeal({ appeals: [] })).toBeNull();
    });

    it('softBadge mapeia "light" para secondary e glosaRef usa guia, código GUI ou motivo', () => {
        expect(softBadge('light')).toBe('badge-soft-secondary');
        expect(softBadge('danger')).toBe('badge-soft-danger');
        expect(glosaRef({ guide_number: 'G-1', claim_code: 'GUI-1', reason_code: '3099' })).toBe('G-1');
        expect(glosaRef({ guide_number: null, claim_code: 'GUI-1', reason_code: '3099' })).toBe('GUI-1');
        expect(glosaRef({ reason_code: '3099' })).toBe('3099');
    });
});
