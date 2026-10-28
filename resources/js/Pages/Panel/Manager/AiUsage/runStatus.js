/** Cor do badge de situação de uma execução de IA (ai_runs.status). */
export function runStatusClass(status) {
    switch (status) {
        case 'approved':
            return 'badge-soft-success';
        case 'waiting_approval':
            return 'badge-soft-warning';
        case 'failed':
            return 'badge-soft-danger';
        case 'rejected':
        case 'cancelled':
            return 'badge-soft-secondary';
        default:
            return 'badge-soft-info'; // pending, reserved, running
    }
}
