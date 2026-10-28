/**
 * Cores do selo de situação do agendamento (ScheduleSituation::badgeClass()
 * do servidor → fundo/texto suaves), usadas na agenda de hoje e no próximo
 * paciente do Dashboard.
 */
const BADGE_COLORS = {
    'bg-secondary': { bg: '#e2e8f0', text: '#475569' },
    'bg-info text-dark': { bg: '#e0f2fe', text: '#0369a1' },
    'bg-warning text-dark': { bg: '#fef3c7', text: '#92400e' },
    'bg-purple text-white': { bg: '#ede9fe', text: '#7c3aed' },
    'bg-orange text-white': { bg: '#ffedd5', text: '#c2410c' },
    'bg-teal text-white': { bg: '#ccfbf1', text: '#0f766e' },
    'bg-primary': { bg: '#dbeafe', text: '#1d4ed8' },
    'bg-success': { bg: '#dcfce7', text: '#166534' },
    'bg-danger': { bg: '#fee2e2', text: '#991b1b' },
    'bg-dark': { bg: '#f1f5f9', text: '#334155' },
};

export function situationBadgeStyle(badge) {
    const c = BADGE_COLORS[badge] ?? BADGE_COLORS['bg-secondary'];
    // --sb-text: o tema escuro (dashboard.css) usa a cor do texto para um
    // selo translúcido, em vez do fundo claro que "acende" na tela escura.
    return { backgroundColor: c.bg, color: c.text, fontWeight: 600, '--sb-text': c.text };
}
