/**
 * Subformulário DENTRO de outro formulário (painéis de alergia/medicação no
 * cadastro do paciente e na agenda; nome do modelo pessoal): HTML não permite
 * <form> dentro de <form> e o `submit` do de dentro sobe até o de fora —
 * salvar a alergia salvava o paciente inteiro e fechava a gaveta.
 *
 * O bloco vira <div> com botões type="button"; este handler (no @keydown do
 * bloco) faz Enter num campo de texto salvar SÓ o subformulário e impede o
 * envio implícito do formulário pai. Campo que já tratou o Enter (busca com
 * lista aberta) chama preventDefault e fica de fora.
 */
const TEXT_INPUTS = new Set([
    'text',
    'search',
    'email',
    'tel',
    'url',
    'number',
    'password',
    'date',
    'time',
    'datetime-local',
    'month',
    'week',
]);

export function enterSubmits(handler) {
    return (event) => {
        if (event.key !== 'Enter' || event.isComposing || event.defaultPrevented) return;
        const el = event.target;
        if (!el || el.tagName !== 'INPUT' || !TEXT_INPUTS.has(String(el.type || 'text').toLowerCase())) return;
        event.preventDefault();
        handler();
    };
}
