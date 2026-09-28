import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Textos derivados de um modelo de documento, iguais na tabela e nos cards:
 * blocos incluídos (cabeçalho/assinatura/rodapé — com rótulo acessível, não
 * só ícone de check) e data de atualização no idioma do usuário.
 *
 * @param {() => object} getT getter das traduções (prop `t` da página)
 */
export function useReportSettingFormat(getT) {
    const { tx } = useTrans(getT);
    const { date } = useLocaleFormat();

    const BLOCKS = [
        { key: 'show_header',    labelKey: 'block_header',    icon: 'ti ti-layout-navbar' },
        { key: 'show_signature', labelKey: 'block_signature', icon: 'ti ti-signature' },
        { key: 'show_footer',    labelKey: 'block_footer',    icon: 'ti ti-layout-bottombar' },
    ];

    /** @returns {Array<{ key: string, icon: string, on: boolean, label: string, text: string }>} */
    function blocks(item) {
        return BLOCKS.map(({ key, labelKey, icon }) => {
            const label = tx(labelKey);
            const on    = !!item[key];

            return { key, icon, on, label, text: tx(on ? 'block_on' : 'block_off', { block: label }) };
        });
    }

    return { tx, date, blocks };
}
