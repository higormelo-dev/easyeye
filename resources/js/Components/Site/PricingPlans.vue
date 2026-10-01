<template>
    <div class="pricing-plans">
        <div class="pricing-grid">
            <article v-for="plan in listings" :key="plan.id" :class="['pricing-card', { featured: plan.is_featured }]">
                <div
                    :class="[
                        'pricing-badge-slot',
                        { 'pricing-badge-slot--empty': !plan.is_featured && !hasIntegrator(plan) },
                    ]"
                >
                    <span v-if="plan.is_featured" class="pricing-badge">{{ t.featured_badge }}</span>
                    <span v-else-if="hasIntegrator(plan)" class="pricing-badge pricing-badge--integrator">{{
                        t.integrator_badge
                    }}</span>
                </div>
                <h3 class="pricing-name">{{ plan.name }}</h3>

                <div class="pricing-price">
                    <span v-if="plan.is_free" class="price-value price-value--request">{{ t.on_request }}</span>
                    <template v-else>
                        <span class="pricing-amount">
                            <span class="price-currency">R$</span>
                            <span class="price-value">{{ formatPrice(plan.price) }}</span>
                        </span>
                        <span class="price-period">{{ plan.price_period_label }}</span>
                    </template>
                </div>

                <dl v-if="comparisonKeys.length" class="pricing-comparison" :aria-label="t.summary_label">
                    <div v-for="key in comparisonKeys" :key="key" :data-feature="key" class="pricing-comparison-row">
                        <dt>
                            <a v-if="key === 'ai_monthly_credits'" href="#creditos-ia" class="pricing-credit-link">{{
                                t.comparison_labels?.[key]
                            }}</a>
                            <template v-else>{{ t.comparison_labels?.[key] }}</template>
                        </dt>
                        <dd :class="{ 'pricing-unavailable': !isIncluded(plan.featureMap.get(key)) }">
                            {{ comparisonValue(plan.featureMap.get(key)) }}
                        </dd>
                    </div>
                </dl>

                <div class="pricing-cta">
                    <a
                        v-if="plan.is_free || trialDays <= 0"
                        :href="salesHref"
                        :class="['btn', plan.is_featured ? 'btn-outline-white' : 'btn-outline']"
                    >
                        {{ t.contact_cta }}
                    </a>
                    <a
                        v-else
                        :href="plan.register_url || registerUrl"
                        :class="['btn', plan.is_featured ? 'btn-featured' : 'btn-outline']"
                    >
                        {{ t.choose_plan?.replace(':plan', plan.name) }}
                        <i v-if="plan.is_featured" class="ti ti-arrow-right" aria-hidden="true"></i>
                    </a>
                    <p v-if="!plan.is_free && trialDays > 0" class="pricing-trial">
                        {{ t.trial_text?.replace(':days', trialDays) }}
                    </p>
                </div>

                <details v-if="plan.description || plan.groups.length || hasUpcoming(plan)" class="pricing-details">
                    <summary>
                        <span>{{ t.details_label }}</span>
                        <i class="ti ti-chevron-down" aria-hidden="true"></i>
                    </summary>
                    <div class="pricing-detail-content">
                        <p v-if="plan.description" class="pricing-desc">{{ plan.description }}</p>
                        <div
                            v-for="group in plan.groups"
                            :key="group.key"
                            class="pricing-feature-group"
                            :data-group="group.key"
                        >
                            <h4>{{ t.groups?.[group.key] }}</h4>
                            <ul class="pricing-features">
                                <li
                                    v-for="feature in group.features"
                                    :key="feature.id || feature.key"
                                    :data-feature="feature.key"
                                >
                                    <i
                                        :class="['ti', isIncluded(feature) ? 'ti-circle-check' : 'ti-minus']"
                                        aria-hidden="true"
                                    ></i>
                                    <span>
                                        {{ feature.display_label }}
                                        <span
                                            v-if="!feature.enabled && !feature.is_none"
                                            class="pricing-feature-status"
                                        >
                                            — {{ t.not_included }}</span
                                        >
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div v-if="hasUpcoming(plan)" class="pricing-upcoming" data-test="pricing-upcoming">
                            <h4 class="pricing-upcoming-label">{{ t.upcoming_label }}</h4>
                            <div v-for="item in t.upcoming" :key="item.title" class="pricing-upcoming-item">
                                <i :class="'ti ' + item.icon" aria-hidden="true"></i>
                                <span>{{ item.title }}</span>
                                <span class="pricing-upcoming-badge">{{ item.badge }}</span>
                            </div>
                        </div>
                    </div>
                </details>
            </article>
        </div>
        <section
            v-if="integratorPlan"
            class="pricing-integrator"
            aria-labelledby="pricing-integrator-title"
            data-test="premium-integrator"
        >
            <div class="pricing-integrator-copy">
                <div class="pricing-integrator-heading">
                    <h3 id="pricing-integrator-title">{{ t.integrator_title }}</h3>
                    <span class="pricing-integrator-plan">{{
                        t.integrator_plan?.replace(':plan', integratorPlan.name)
                    }}</span>
                </div>
                <p>{{ t.integrator_description }}</p>
                <a
                    :href="
                        integratorPlan.is_free || trialDays <= 0
                            ? salesHref
                            : integratorPlan.register_url || registerUrl
                    "
                    class="pricing-integrator-cta"
                >
                    {{
                        integratorPlan.is_free || trialDays <= 0
                            ? t.contact_cta
                            : t.choose_plan?.replace(':plan', integratorPlan.name)
                    }}
                    <i class="ti ti-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
            <ol class="pricing-integrator-flow" :aria-label="t.integrator_title">
                <li v-for="(step, index) in t.integrator_flow" :key="step">
                    <i :class="['ti', ['ti-device-desktop', 'ti-transfer', 'ti-photo'][index]]" aria-hidden="true"></i>
                    <span>{{ step }}</span>
                    <i
                        v-if="index < t.integrator_flow.length - 1"
                        class="ti ti-chevron-right pricing-flow-arrow"
                        aria-hidden="true"
                    ></i>
                </li>
            </ol>
        </section>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    plans: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
    trialDays: { type: Number, default: 0 },
    registerUrl: { type: String, default: '' },
    salesHref: { type: String, default: '' },
    locale: { type: String, default: 'pt-BR' },
});

const comparisonOrder = ['max_doctors', 'max_storage_gb', 'ai_monthly_credits', 'has_inventory_module'];
const capacityKeys = new Set(['max_doctors', 'max_users', 'max_patients', 'max_storage_gb']);
const booleanComparisonKeys = new Set(['has_inventory_module']);

// A mesma ordem em todos os planos permite comparar sem memorizar o cartão
// anterior. Uma chave ausente não prova que o recurso está ou não incluído.
const comparisonKeys = computed(() =>
    comparisonOrder.filter((key) => props.plans.some((plan) => plan.features?.some((feature) => feature.key === key))),
);
const listings = computed(() =>
    props.plans.map((plan) => {
        // A API é privada e exclusiva do integrador local: seus controles internos
        // não são uma oferta de API pública nem uma ausência comercial a anunciar.
        const features = (plan.features ?? [])
            .filter((feature) => {
                if (feature.key === 'api_monthly_exam_sends') return false;
                return feature.key !== 'has_api_integrator' || isIncluded(feature);
            })
            .map((feature) => {
                if (feature.key === 'has_api_integrator') {
                    return { ...feature, display_label: props.t.integrator_label };
                }
                if (feature.key === 'has_ai_chat_assistant' && props.t.ai_chat_label) {
                    return { ...feature, display_label: props.t.ai_chat_label };
                }
                return feature;
            });
        const groups = { capacity: [], ai: [], resources: [] };

        for (const feature of features) {
            const group = capacityKeys.has(feature.key)
                ? 'capacity'
                : feature.key?.startsWith('has_ai_') || feature.key === 'ai_monthly_credits'
                  ? 'ai'
                  : 'resources';
            groups[group].push(feature);
        }

        return {
            ...plan,
            featureMap: new Map(features.map((feature) => [feature.key, feature])),
            groups: Object.entries(groups)
                .filter(([, rows]) => rows.length)
                .map(([key, rows]) => ({ key, features: rows })),
        };
    }),
);

function hasIntegrator(plan) {
    return plan.slug === 'premium' && isIncluded(plan.featureMap.get('has_api_integrator'));
}
const integratorPlan = computed(() => listings.value.find(hasIntegrator));

function isIncluded(feature) {
    return Boolean(feature?.enabled && !feature.is_none);
}

function comparisonValue(feature) {
    if (!feature) return props.t.not_specified;
    if (booleanComparisonKeys.has(feature.key)) return isIncluded(feature) ? props.t.included : props.t.not_included;
    if (typeof feature.value === 'number' && Number.isFinite(feature.value)) {
        const formats = props.t.comparison_values ?? {};
        if (feature.value === 0)
            return (feature.key === 'ai_monthly_credits' ? formats.none : formats.unlimited) ?? feature.display_label;
        const key = { max_doctors: 'up_to', max_storage_gb: 'storage', ai_monthly_credits: 'credits' }[feature.key];
        const count = feature.value.toLocaleString(props.locale.replace('_', '-'));
        return formats[key]?.replace(':count', count) ?? feature.display_label;
    }
    return feature.display_label;
}

function hasUpcoming(plan) {
    return plan.slug === 'premium' && props.t.upcoming?.length;
}

function formatPrice(price) {
    return Number(price).toLocaleString(props.locale.replace('_', '-'), {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}
</script>

<style scoped lang="scss">
.pricing-plans {
    min-width: 0;

    .pricing-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
        align-items: start;
        gap: 24px;
        max-width: none;
        margin: 0;
    }

    .pricing-card {
        --plan-text: var(--navy, #0f2551);
        --plan-muted: var(--text-muted, #5a6a80);
        --plan-line: var(--border, #e2e8f0);
        --plan-accent: var(--teal-ink, #00708a);
        display: flex;
        flex-direction: column;
        min-width: 0;
        container: pricing-card / inline-size;
        padding: 28px;
        border: 1px solid var(--plan-line);
        border-radius: 16px;
        color: var(--plan-text);
        background: #fff;
        transform: none;
        transition: border-color 160ms cubic-bezier(0.16, 1, 0.3, 1);

        &:hover {
            transform: none;
            box-shadow: none;
        }

        &.featured {
            --plan-text: #fff;
            --plan-muted: #c7d8ee;
            --plan-line: #425b80;
            --plan-accent: var(--teal-lt, #90e0ef);
            border-color: var(--navy, #0f2551);
            background: var(--navy, #0f2551);
            transform: none;
        }

        &:focus-within {
            border-color: var(--plan-accent);
        }
    }

    .pricing-badge-slot {
        display: flex;
        align-items: flex-start;
        min-height: 28px;
        margin-bottom: 12px;
    }
    .pricing-badge {
        display: inline-flex;
        align-items: center;
        min-height: 28px;
        margin: 0;
        padding: 4px 10px;
        border-radius: 999px;
        background: var(--teal, #00b4d8);
        color: var(--navy, #0f2551);
        font-size: 0.8125rem;
        font-weight: 700;
        line-height: 1.2;
        border: 1px solid transparent;
    }
    .pricing-badge--integrator {
        color: var(--plan-accent);
        background: #effafd;
        border-color: #c3e7ef;
    }
    .pricing-name {
        min-height: 1.25em;
        margin: 0 0 18px;
        color: var(--plan-text);
        font-size: 1.25rem;
        font-weight: 800;
        line-height: 1.25;
        letter-spacing: -0.015em;
        overflow-wrap: anywhere;
    }

    .pricing-price {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        justify-content: flex-start;
        min-height: 84px;
        gap: 8px;
        margin: 0 0 20px;
    }
    .pricing-amount {
        display: inline-flex;
        align-items: baseline;
        gap: 6px;
        max-width: 100%;
        white-space: nowrap;
    }
    .price-currency {
        color: var(--plan-text);
        font-size: 1rem;
        font-weight: 700;
    }
    .price-value {
        color: var(--plan-text);
        font-size: clamp(2.25rem, calc(1.75rem + 1vw), 2.75rem);
        font-weight: 900;
        font-variant-numeric: tabular-nums;
        line-height: 1.1;
        letter-spacing: -0.01em;
    }
    .price-value--request {
        font-size: clamp(1.25rem, calc(1rem + 1vw), 1.5rem);
        font-weight: 800;
        line-height: 1.25;
        white-space: normal;
    }
    .price-period {
        color: var(--plan-muted);
        font-size: 0.9375rem;
        line-height: 1.5;
    }

    // A medida em rem acompanha a ampliação de texto do visitante. Em um
    // cartão estreito, o preço ganha linhas em vez de diminuir a fonte.
    @container pricing-card (max-width: 14rem) {
        .pricing-amount {
            flex-direction: column;
            align-items: flex-start;
            gap: 2px;
            white-space: normal;
        }
        .price-value {
            max-width: 100%;
            overflow-wrap: anywhere;
        }
    }

    .pricing-comparison {
        margin: 0 0 24px;
    }
    .pricing-comparison-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: baseline;
        gap: 12px;
        padding: 12px 0;
        border-top: 1px solid var(--plan-line);
        min-height: 48px;
        font-size: 0.9375rem;
        line-height: 1.5;
    }
    .pricing-comparison dt {
        color: var(--plan-muted);
        font-weight: 400;
        overflow-wrap: anywhere;
    }
    .pricing-comparison dd {
        max-width: 14ch;
        margin: 0;
        color: var(--plan-text);
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        text-align: right;
        overflow-wrap: anywhere;
    }
    .pricing-comparison .pricing-unavailable {
        color: var(--plan-muted);
        font-weight: 400;
    }
    .pricing-credit-link {
        display: inline-flex;
        align-items: center;
        min-height: 44px;
        margin-block: -11px;
        color: var(--plan-accent);
        text-decoration: underline;
        text-underline-offset: 4px;

        &:hover {
            color: var(--plan-text);
        }
        &:focus-visible {
            outline: 2px solid var(--plan-accent);
            outline-offset: 3px;
            border-radius: 2px;
        }
    }

    .pricing-cta {
        margin: 0;
    }
    .pricing-cta .btn {
        width: 100%;
        min-height: 52px;
        padding-inline: 12px;
        white-space: normal;
    }
    .pricing-trial {
        margin: 12px 0 0;
        color: var(--plan-muted);
        font-size: 0.9375rem;
        line-height: 1.5;
        text-align: center;
    }

    .pricing-integrator {
        display: grid;
        container: pricing-integrator / inline-size;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        align-items: center;
        gap: 32px;
        margin-top: 32px;
        padding: 32px;
        border: 1px solid #c3e7ef;
        border-radius: 16px;
        background: #f0fafc;
        color: var(--navy, #0f2551);

        p {
            max-width: 48ch;
            margin: 12px 0 8px;
            color: #3b5e70;
            font-size: 1rem;
            line-height: 1.6;
        }
    }
    .pricing-integrator-heading {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px 12px;
    }
    .pricing-integrator-heading h3 {
        margin: 0;
        font-size: clamp(1.25rem, calc(1rem + 1vw), 1.5rem);
        font-weight: 800;
        line-height: 1.25;
        letter-spacing: -0.015em;
    }
    .pricing-integrator-plan {
        font-size: 0.875rem;
        font-weight: 600;
        color: #00708a;
    }
    .pricing-integrator-cta {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 44px;
        color: #00708a;
        font-weight: 700;
        text-decoration: underline;
        text-underline-offset: 4px;
        transition: color 160ms cubic-bezier(0.16, 1, 0.3, 1);

        &:hover,
        &:focus-visible,
        &:active {
            color: var(--navy, #0f2551);
        }
        > i {
            transition: transform 160ms cubic-bezier(0.16, 1, 0.3, 1);
        }
    }
    .pricing-integrator-flow {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 20px;
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .pricing-integrator-flow li {
        min-width: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
        position: relative;
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.4;
        text-align: center;
    }
    .pricing-integrator-flow li > span {
        min-width: 0;
        max-width: 100%;
        overflow-wrap: anywhere;
    }
    .pricing-integrator-flow li > i:first-child {
        display: grid;
        place-items: center;
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: #d9f2f7;
        color: #00708a;
        font-size: 24px;
    }
    .pricing-integrator-flow .pricing-flow-arrow {
        position: absolute;
        top: 16px;
        right: -18px;
        color: #467283;
        font-size: 16px;
    }

    // No celular e com texto ampliado, a sequência vertical mantém os nomes
    // das etapas legíveis, sem fragmentá-los em três colunas estreitas.
    @container pricing-integrator (max-width: 24rem) {
        .pricing-integrator-flow {
            grid-template-columns: minmax(0, 1fr);
            gap: 28px;
        }
        .pricing-integrator-flow li {
            flex-direction: row;
            text-align: left;
        }
        .pricing-integrator-flow li > i:first-child {
            flex-shrink: 0;
        }
        .pricing-integrator-flow .pricing-flow-arrow {
            top: auto;
            right: auto;
            left: 16px;
            bottom: -22px;
            transform: rotate(90deg);
        }
    }

    .pricing-details {
        margin-top: 24px;
        border-top: 1px solid var(--plan-line);
    }
    .pricing-details summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        min-height: 48px;
        padding: 16px 0 0;
        color: var(--plan-text);
        font-size: 0.9375rem;
        font-weight: 600;
        line-height: 1.5;
        list-style: none;
        cursor: pointer;
        border-radius: 4px;
        transition: color 160ms cubic-bezier(0.16, 1, 0.3, 1);

        &::-webkit-details-marker {
            display: none;
        }
        &:hover {
            color: var(--plan-accent);
        }
        &:focus-visible {
            outline: 2px solid var(--plan-accent);
            outline-offset: 6px;
        }
        i {
            flex-shrink: 0;
            transition: transform 160ms cubic-bezier(0.16, 1, 0.3, 1);
        }
    }
    .pricing-details[open] summary i {
        transform: rotate(180deg);
    }
    .pricing-details[open] .pricing-detail-content {
        animation: easyeye-pricing-details-open 160ms cubic-bezier(0.16, 1, 0.3, 1);
    }
    .pricing-detail-content {
        padding-top: 20px;
    }
    .pricing-desc {
        color: var(--plan-muted);
        font-size: 1rem;
        line-height: 1.6;
        margin: 0 0 24px;
    }
    .pricing-feature-group + .pricing-feature-group {
        margin-top: 24px;
    }
    .pricing-feature-group h4,
    .pricing-upcoming-label {
        margin: 0 0 12px;
        color: var(--plan-text);
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.5;
        letter-spacing: normal;
        text-transform: none;
    }
    .pricing-features {
        display: grid;
        gap: 12px;
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .pricing-features li {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        color: var(--plan-muted);
        font-size: 1rem;
        line-height: 1.6;
        overflow-wrap: anywhere;
    }
    .pricing-features li i {
        flex-shrink: 0;
        margin-top: 3px;
        color: var(--plan-accent);
    }
    .pricing-features li .ti-minus {
        color: var(--plan-muted);
    }
    .pricing-feature-status {
        color: var(--plan-muted);
    }
    .pricing-upcoming {
        border-top: 1px dashed var(--plan-line);
        margin: 24px 0 0;
        padding-top: 20px;
    }
    .pricing-upcoming-item {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        color: var(--plan-muted);
        font-size: 0.9375rem;
        line-height: 1.5;
    }
    .pricing-upcoming-item i {
        flex-shrink: 0;
        color: var(--plan-muted);
        font-size: 1rem;
    }
    .pricing-upcoming-item > span:not(.pricing-upcoming-badge) {
        flex: 1;
        min-width: 0;
    }
    .pricing-upcoming-badge {
        flex-shrink: 0;
        margin-left: auto;
        padding: 2px 8px;
        border: 1px solid var(--plan-line);
        border-radius: 999px;
        color: var(--plan-muted);
        font-size: 0.8125rem;
        font-weight: 600;
        white-space: nowrap;
    }
}

@keyframes easyeye-pricing-details-open {
    from {
        opacity: 0.85;
    }
    to {
        opacity: 1;
    }
}

@media (hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference) {
    .pricing-plans .pricing-integrator-cta:is(:hover, :focus-visible) > i {
        transform: translateX(2px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .pricing-plans .pricing-details summary i,
    .pricing-plans .pricing-integrator-cta > i {
        transition: none;
    }
    .pricing-plans .pricing-details[open] .pricing-detail-content {
        animation: none;
    }
}

@media (max-width: 959px) {
    .pricing-plans {
        max-width: 520px;
        margin-inline: auto;
    }
    .pricing-plans .pricing-grid {
        grid-template-columns: 1fr;
    }
    .pricing-plans .pricing-card {
        padding: 28px;
    }
    .pricing-plans .pricing-badge-slot--empty {
        display: none;
    }
    .pricing-plans .pricing-price {
        min-height: 0;
    }
    .pricing-plans .pricing-comparison-row {
        min-height: 0;
    }
    .pricing-plans .pricing-integrator {
        grid-template-columns: minmax(0, 1fr);
        gap: 24px;
        margin-top: 24px;
        padding: 24px;
    }
}

@media (max-width: 359px) {
    .pricing-plans .pricing-card {
        padding: 24px 20px;
    }
    .pricing-plans .pricing-integrator {
        padding: 20px;
    }
}
</style>
