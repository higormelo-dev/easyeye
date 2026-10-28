import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Textos derivados de um perfil customizado, iguais na RoleTable e nos
 * RoleCards: contagens com singular/plural ("1 permissão", "3 usuários") e
 * os grupos das permissões ("Financeiro, Usuários"), no idioma de `t`.
 *
 * @param {() => object} getT getter das traduções (prop `t` da página)
 */
export function useRoleFormat(getT) {
    const { tx } = useTrans(getT);
    const { number, date } = useLocaleFormat();

    function counted(prefix, value) {
        const count = Number(value) || 0;

        return tx(count === 1 ? `${prefix}_one` : `${prefix}_other`, { count: number(count) });
    }

    /** @param {{ permissions?: Array }} role */
    function permissionsLabel(role) {
        return counted('permissions', role.permissions?.length ?? 0);
    }

    /** @param {{ users_count?: number }} role */
    function usersLabel(role) {
        return counted('users', role.users_count ?? 0);
    }

    /** Grupos distintos das permissões, na ordem em que aparecem. */
    function permissionGroups(role) {
        const groups = (role.permissions ?? []).map((permission) => permission.group).filter(Boolean);

        return [...new Set(groups)].join(', ');
    }

    return { tx, date, permissionsLabel, usersLabel, permissionGroups };
}
