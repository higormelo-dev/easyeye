import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Textos derivados de um usuário, iguais na UserTable e nos UserCards:
 * selo "+N perfis adicionais" (singular/plural) e data de cadastro no idioma
 * do usuário (o backend manda ISO 8601).
 *
 * @param {() => object} getT getter das traduções (prop `t` da página)
 */
export function useUserFormat(getT) {
    const { tx } = useTrans(getT);
    const { number, date } = useLocaleFormat();

    /** @param {{ roles_count?: number }} user '' quando não há perfis adicionais */
    function extraRolesLabel(user) {
        const count = Number(user.roles_count) || 0;
        if (count === 0) return '';

        return tx(count === 1 ? 'extra_roles_one' : 'extra_roles_other', { count: number(count) });
    }

    return { tx, date, extraRolesLabel };
}
