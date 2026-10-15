/**
 * Rótulo da sessão de uma série de agendamentos: "3/10" numa série fechada, "3" numa série ABERTA (sem total —
 * tratamento que decide o intervalo a cada visita, como a injeção intravítrea em "trate e estenda"), nada fora de série.
 */
export function sessionLabel(number, total) {
    if (!number) return null;

    return total ? `${number}/${total}` : String(number);
}
