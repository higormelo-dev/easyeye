import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans';

/**
 * Peças comuns dos relatórios financeiros (Fluxo de caixa e Convênios).
 *
 * useReportPage: período do PeriodFilter aplicado pela querystring (o servidor
 * devolve normalizado; erro de carga volta ao período exibido) e exportação
 * sempre do período APLICADO (o mesmo dos números na tela).
 */
const FORMAT_META = {
    csv:  { icon: 'ti ti-file-type-csv', labelKey: 'export_csv' },
    xlsx: { icon: 'ti ti-file-spreadsheet', labelKey: 'export_xlsx' },
    pdf:  { icon: 'ti ti-file-type-pdf', labelKey: 'export_pdf' },
};

/**
 * @param {object} props      props da página (filters, routes, export_formats, t)
 * @param {string} indexRoute nome da rota da tela (fallback se routes.index não vier)
 * @param {object} [options]
 * @param {() => object} [options.params]      parâmetros que acompanham a troca de
 *                                             período (ex.: busca/filtros da lista)
 * @param {() => void}   [options.beforeVisit] chamado antes de recarregar (ex.:
 *                                             cancelar a busca com debounce pendente)
 */
export function useReportPage(props, indexRoute, options = {}) {
    const { tx } = useTrans(() => props.t ?? {});
    const { date } = useLocaleFormat();

    const from      = ref(props.filters.from);
    const to        = ref(props.filters.to);
    const loading   = ref(false);
    const loadError = ref('');

    function restorePeriod() {
        from.value = props.filters.from;
        to.value   = props.filters.to;
    }

    watch(() => [props.filters.from, props.filters.to], restorePeriod);

    function firstError(errors) {
        const first = Object.values(errors ?? {})[0];

        return (Array.isArray(first) ? first[0] : first) || tx('load_error');
    }

    /**
     * `change` do PeriodFilter (só intervalo válido chega aqui). Os parâmetros
     * extras (options.params) seguem junto; a página da lista, não — volta à 1ª.
     */
    function applyPeriod({ from: newFrom, to: newTo }) {
        if (newFrom === props.filters.from && newTo === props.filters.to) return;

        loadError.value = '';
        options.beforeVisit?.();

        router.get(props.routes?.index ?? route(indexRoute), { ...(options.params?.() ?? {}), from: newFrom, to: newTo }, {
            preserveState:   true,
            preserveScroll:  true,
            replace:         true,
            onStart:         () => { loading.value = true; },
            onFinish:        () => { loading.value = false; },
            onError:         (errors) => { loadError.value = firstError(errors); restorePeriod(); },
            onHttpException: () => { loadError.value = tx('load_error'); restorePeriod(); return false; },
            onNetworkError:  () => { loadError.value = tx('load_error'); restorePeriod(); return false; },
        });
    }

    const exportOptions = computed(() => (props.export_formats ?? [])
        .filter((format) => FORMAT_META[format])
        .map((format) => ({
            key:   format,
            icon:  FORMAT_META[format].icon,
            label: props.t?.[FORMAT_META[format].labelKey] ?? format.toUpperCase(),
            href:  `${props.routes?.export ?? ''}?${new URLSearchParams({ from: props.filters.from, to: props.filters.to, format }).toString()}`,
        })));

    const exportTitle = computed(() => tx('export_title', { from: date(props.filters.from), to: date(props.filters.to) }));

    return { from, to, loading, loadError, applyPeriod, exportOptions, exportTitle };
}

/** Percentual no idioma do usuário (20 → "20,0%"); nulo/indefinido → '—'. */
export function usePercent() {
    const { locale } = useLocaleFormat();

    function percent(value, digits = 1) {
        if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '—';

        return new Intl.NumberFormat(locale.value, {
            style:                 'percent',
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        }).format(Number(value) / 100);
    }

    return { percent };
}

/**
 * Plural pelas regras do idioma (Intl.PluralRules): escolhe `<prefix>_one` ou
 * `<prefix>_other` em `texts` e troca :count pelo número formatado.
 */
export function usePlural() {
    const { locale, number } = useLocaleFormat();

    function plural(texts, prefix, count) {
        const form     = new Intl.PluralRules(locale.value).select(Number(count) || 0) === 'one' ? 'one' : 'other';
        const template = texts?.[`${prefix}_${form}`] ?? `${prefix}_${form}`;

        return String(template).replaceAll(':count', number(count));
    }

    return { plural };
}
