<script setup>
import { computed, reactive, watch } from 'vue';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
    saving: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['save']);

function initial(value) {
    return {
        prestador: {
            cnpj: value?.prestador?.cnpj ?? '',
            inscricao_municipal: value?.prestador?.inscricao_municipal ?? '',
            codigo_municipio: value?.prestador?.codigo_municipio ?? '',
            cnae: value?.prestador?.cnae ?? '',
            item_lista_servico: value?.prestador?.item_lista_servico ?? '01.07',
            codigo_tributario_municipio: value?.prestador?.codigo_tributario_municipio ?? '',
            codigo_tributacao_nacional_iss: value?.prestador?.codigo_tributacao_nacional_iss ?? '010701',
            tributacao_iss: value?.prestador?.tributacao_iss ?? 1,
            aliquota_iss: value?.prestador?.aliquota_iss == null ? '' : Number(value.prestador.aliquota_iss) * 100,
            iss_retido: Boolean(value?.prestador?.iss_retido),
            natureza_operacao: value?.prestador?.natureza_operacao ?? '1',
            optante_simples_nacional: Boolean(value?.prestador?.optante_simples_nacional),
            regime_especial_tributacao: value?.prestador?.regime_especial_tributacao ?? '',
            serie_dps: value?.prestador?.serie_dps ?? 1,
            proximo_numero_dps: value?.prestador?.proximo_numero_dps ?? 1,
        },
        standard: value?.standard ?? 'municipal',
        environment: value?.environment ?? 'homologacao',
        auto_issue: Boolean(value?.auto_issue),
        copy_email: value?.copy_email ?? '',
        discriminacao: { plano: value?.discriminacao?.plano ?? '', ia: value?.discriminacao?.ia ?? '' },
    };
}

const form = reactive(initial(props.settings));
watch(
    () => props.settings,
    (value) => Object.assign(form, initial(value)),
    { deep: true },
);
const national = computed(() => form.standard === 'nacional');

function submit() {
    emit('save', {
        ...form,
        copy_email: form.copy_email.trim() || null,
        prestador: {
            ...form.prestador,
            aliquota_iss: form.prestador.aliquota_iss === '' ? null : Number(form.prestador.aliquota_iss) / 100,
            regime_especial_tributacao: form.prestador.regime_especial_tributacao || null,
        },
    });
}

function error(key) {
    return props.errors[key] ?? '';
}
</script>

<template>
    <form class="card h-100" novalidate @submit.prevent="submit">
        <div class="card-header bg-transparent d-flex align-items-center gap-2">
            <i class="ti ti-settings-dollar" aria-hidden="true"></i>
            <h2 class="h6 mb-0">{{ t.settings?.title }}</h2>
        </div>
        <div class="card-body">
            <fieldset class="mb-4">
                <legend class="fs-6 fw-semibold">{{ t.settings?.provider }}</legend>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="nfse-cnpj" class="form-label">{{ t.settings?.cnpj }}</label
                        ><input
                            id="nfse-cnpj"
                            v-model="form.prestador.cnpj"
                            class="form-control"
                            inputmode="text"
                            autocomplete="off"
                            :aria-invalid="!!error('prestador.cnpj')"
                        />
                        <div v-if="error('prestador.cnpj')" class="invalid-feedback d-block">
                            {{ error('prestador.cnpj') }}
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="nfse-im" class="form-label">{{ t.settings?.inscricao_municipal }}</label
                        ><input id="nfse-im" v-model="form.prestador.inscricao_municipal" class="form-control" />
                        <div v-if="error('prestador.inscricao_municipal')" class="invalid-feedback d-block">
                            {{ error('prestador.inscricao_municipal') }}
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label for="nfse-ibge" class="form-label">{{ t.settings?.codigo_municipio }}</label
                        ><input
                            id="nfse-ibge"
                            v-model="form.prestador.codigo_municipio"
                            class="form-control"
                            inputmode="numeric"
                            maxlength="7"
                        />
                    </div>
                    <div class="col-md-4">
                        <label for="nfse-cnae" class="form-label">{{ t.settings?.cnae }}</label
                        ><input id="nfse-cnae" v-model="form.prestador.cnae" class="form-control" inputmode="numeric" />
                    </div>
                    <div class="col-md-4">
                        <label for="nfse-item" class="form-label">{{ t.settings?.item_lista_servico }}</label
                        ><input id="nfse-item" v-model="form.prestador.item_lista_servico" class="form-control" />
                    </div>
                    <div class="col-md-6">
                        <label for="nfse-tributario-municipal" class="form-label">{{
                            t.settings?.codigo_tributario_municipio
                        }}</label
                        ><input
                            id="nfse-tributario-municipal"
                            v-model="form.prestador.codigo_tributario_municipio"
                            class="form-control"
                        />
                    </div>
                    <div class="col-md-6">
                        <label for="nfse-tributario-nacional" class="form-label">{{
                            t.settings?.codigo_tributacao_nacional_iss
                        }}</label
                        ><input
                            id="nfse-tributario-nacional"
                            v-model="form.prestador.codigo_tributacao_nacional_iss"
                            class="form-control"
                        />
                    </div>
                    <div class="col-md-4">
                        <label for="nfse-tributacao" class="form-label">{{ t.settings?.tributacao_iss }}</label
                        ><select
                            id="nfse-tributacao"
                            v-model.number="form.prestador.tributacao_iss"
                            class="form-select"
                        >
                            <option v-for="option in [1, 2, 3, 4]" :key="option" :value="option">
                                {{ t.settings?.[`tributacao_iss_option_${option}`] }}
                            </option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="nfse-aliquota" class="form-label">{{ t.settings?.aliquota_iss }}</label
                        ><input
                            id="nfse-aliquota"
                            v-model="form.prestador.aliquota_iss"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            class="form-control"
                            inputmode="decimal"
                        />
                    </div>
                    <div class="col-md-4 d-flex flex-column justify-content-end gap-2">
                        <div class="form-check">
                            <input
                                id="nfse-iss-retido"
                                v-model="form.prestador.iss_retido"
                                class="form-check-input"
                                type="checkbox"
                            /><label for="nfse-iss-retido" class="form-check-label">{{ t.settings?.iss_retido }}</label>
                        </div>
                        <div class="form-check">
                            <input
                                id="nfse-simples"
                                v-model="form.prestador.optante_simples_nacional"
                                class="form-check-input"
                                type="checkbox"
                            /><label for="nfse-simples" class="form-check-label">{{
                                t.settings?.optante_simples_nacional
                            }}</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="nfse-natureza" class="form-label">{{ t.settings?.natureza_operacao }}</label
                        ><select id="nfse-natureza" v-model="form.prestador.natureza_operacao" class="form-select">
                            <option v-for="option in ['1', '2', '3', '4', '5', '6']" :key="option" :value="option">
                                {{ option }}
                            </option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="nfse-regime" class="form-label">{{ t.settings?.regime_especial_tributacao }}</label
                        ><input
                            id="nfse-regime"
                            v-model="form.prestador.regime_especial_tributacao"
                            class="form-control"
                            inputmode="numeric"
                        />
                    </div>
                </div>
            </fieldset>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label for="nfse-standard" class="form-label">{{ t.settings?.standard }}</label
                    ><select id="nfse-standard" v-model="form.standard" class="form-select">
                        <option value="municipal">{{ t.standard?.municipal }}</option>
                        <option value="nacional">{{ t.standard?.nacional }}</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="nfse-environment" class="form-label">{{ t.settings?.environment }}</label
                    ><select id="nfse-environment" v-model="form.environment" class="form-select">
                        <option value="homologacao">{{ t.environment?.homologacao }}</option>
                        <option value="producao">{{ t.environment?.producao }}</option>
                    </select>
                </div>
            </div>
            <div v-if="national" class="row g-3 mb-4">
                <p class="form-text mt-0">{{ t.settings?.national_only_hint }}</p>
                <div class="col-md-6">
                    <label for="nfse-dps-series" class="form-label">{{ t.settings?.serie_dps }}</label
                    ><input
                        id="nfse-dps-series"
                        v-model.number="form.prestador.serie_dps"
                        type="number"
                        min="1"
                        max="49999"
                        class="form-control"
                        inputmode="numeric"
                    />
                </div>
                <div class="col-md-6">
                    <label for="nfse-dps-number" class="form-label">{{ t.settings?.proximo_numero_dps }}</label
                    ><input
                        id="nfse-dps-number"
                        v-model.number="form.prestador.proximo_numero_dps"
                        type="number"
                        min="1"
                        class="form-control"
                        inputmode="numeric"
                    />
                </div>
            </div>
            <div class="form-check mb-3">
                <input id="nfse-auto-issue" v-model="form.auto_issue" class="form-check-input" type="checkbox" /><label
                    for="nfse-auto-issue"
                    class="form-check-label"
                    >{{ t.settings?.auto_issue }}</label
                >
            </div>
            <div class="mb-3">
                <label for="nfse-copy-email" class="form-label">{{ t.settings?.copy_email }}</label
                ><input
                    id="nfse-copy-email"
                    v-model="form.copy_email"
                    type="email"
                    class="form-control"
                    autocomplete="email"
                />
                <div class="form-text">{{ t.settings?.copy_email_hint }}</div>
            </div>
            <div class="mb-3">
                <label for="nfse-description-plan" class="form-label">{{ t.settings?.discriminacao_plano }}</label
                ><textarea
                    id="nfse-description-plan"
                    v-model="form.discriminacao.plano"
                    class="form-control"
                    rows="3"
                ></textarea>
            </div>
            <div>
                <label for="nfse-description-ia" class="form-label">{{ t.settings?.discriminacao_ia }}</label
                ><textarea
                    id="nfse-description-ia"
                    v-model="form.discriminacao.ia"
                    class="form-control"
                    rows="3"
                ></textarea>
                <div class="form-text">{{ t.settings?.discriminacao_hint }}</div>
            </div>
        </div>
        <div class="card-footer bg-transparent text-end">
            <button type="submit" class="btn btn-primary" :disabled="saving">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                >{{ t.settings?.save }}
            </button>
        </div>
    </form>
</template>
