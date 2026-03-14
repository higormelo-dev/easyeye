import Inputmask from 'inputmask';

/**
 * Alpine.js directive: x-mask-br="'cpf'|'cnpj'|'cep'|'tel'|'cel'"
 *
 * Aplica máscara de entrada brasileira ao campo usando Inputmask.
 * O valor exibido contém a máscara; o servidor deve remover os caracteres
 * não numéricos antes de salvar (feito em prepareForValidation).
 *
 * Exemplos:
 *   <input x-mask-br="'cpf'" x-model="form.national_registry">
 *   <input x-mask-br="'cel'" x-model="form.cellphone">
 */
export default function maskBrDirective(Alpine) {
    Alpine.directive('mask-br', (el, { expression }) => {
        const type = expression?.replace(/['"]/g, '');

        const options = {
            clearMaskOnLostFocus: false,
            showMaskOnHover:      false,
            showMaskOnFocus:      false,
        };

        switch (type) {
            case 'cpf':
                Inputmask('999.999.999-99', options).mask(el);
                break;

            case 'cnpj':
                Inputmask('99.999.999/9999-99', options).mask(el);
                break;

            case 'cep':
                Inputmask('99999-999', options).mask(el);
                break;

            case 'tel':
                // Fixo: (99) 9999-9999
                Inputmask('(99) 9999-9999', options).mask(el);
                break;

            case 'cel':
                // Celular: dígito opcional permite 8 ou 9 dígitos após DDD
                Inputmask('(99) 9999[9]-9999', options).mask(el);
                break;

            default:
                console.warn(`[x-mask-br] tipo desconhecido: "${type}"`);
        }
    });
}
