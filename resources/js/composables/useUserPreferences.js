import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Preferências pessoais do usuário (item MELHORIA "mais humano" — ordem do
 * Dashboard, atalhos favoritos, e futuramente toggle de notícias/playlist).
 *
 * Backend-persisted, NÃO localStorage: ao contrário de `useColumnOrder`
 * (preferência de tabela, device-bound é aceitável), isto precisa
 * acompanhar o usuário entre dispositivos — é literalmente "o ambiente
 * pessoal dele" clínica/computador afora. Ver UserPreference.
 *
 * Lê de `page.props.auth.user.preferences` (compartilhado globalmente por
 * HandleInertiaRequests em toda navegação do painel) e grava via PATCH
 * simples (fetch, não router do Inertia — o endpoint é uma API JSON pura,
 * não uma página Inertia) debounced por chave.
 */
const debounceTimers = {};

export function useUserPreferences() {
    const page = usePage();

    const preferences = computed(() => page.props.auth?.user?.preferences ?? {});

    function getPreference(key, fallback = null) {
        return preferences.value[key] ?? fallback;
    }

    /**
     * Atualiza otimisticamente (reflete na UI na hora) e persiste em
     * background, debounced por chave — evita martelar o endpoint durante
     * um drag-and-drop de várias etapas seguidas.
     */
    function savePreference(key, value, { debounceMs = 500 } = {}) {
        if (page.props.auth?.user) {
            page.props.auth.user.preferences = { ...preferences.value, [key]: value };
        }

        clearTimeout(debounceTimers[key]);
        debounceTimers[key] = setTimeout(async () => {
            try {
                await fetch(route('panel.preferences.update'), {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ [key]: value }),
                });
            } catch {
                // Falha silenciosa — preferência de UI/conforto, não crítica.
                // O valor otimista fica válido na sessão atual; próxima
                // gravação bem-sucedida (ou próximo load) resolve o estado.
            }
        }, debounceMs);
    }

    return { preferences, getPreference, savePreference };
}
