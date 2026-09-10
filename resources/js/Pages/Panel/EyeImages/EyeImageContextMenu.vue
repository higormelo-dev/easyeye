<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';

/**
 * Menu de contexto (botão direito) na miniatura do Gerenciador de Imagens —
 * benchmark contra concorrente (Ger Exames/iWayBrasil, 09/09/2026): eles
 * concentram várias ações rápidas num right-click em vez de exigir navegar
 * por botões separados. Ações realmente novas aqui: trocar lateralidade,
 * avaliar qualidade da captura, habilitar/desabilitar imagem — o resto
 * (laudo, comparar, compartilhar, baixar) já existia e só ganhou atalho.
 *
 * Fora de escopo deliberado (feature maior, não pedida com prioridade):
 * mesclar/dividir exame, adicionar imagem a exame existente, calculadora de
 * lentes — o concorrente tem, mas exigem redesenhar o agrupamento de exames.
 *
 * Auto-contido: recebe `exam` (referência viva do array de patients.value —
 * mutar campos nele aqui reflete direto nos badges do thumbnail, mesmo
 * padrão de toggleExamShare() no componente pai) + `urls` (templates com
 * __ID__, mesma convenção de exam_diagnosis_update).
 */
const props = defineProps({
    open:     { type: Boolean, required: true },
    exam:     { type: Object,  default: null },
    x:        { type: Number,  default: 0 },
    y:        { type: Number,  default: 0 },
    urls:     { type: Object,  required: true }, // { laterality, quality_rating, active }
    t:        { type: Object,  default: () => ({}) },
    // Fazer laudo / trocar lateralidade / avaliar qualidade / habilitar-
    // desabilitar são escrita clínica (Gate::IssueReport, doctor-only no
    // backend) — mesmo allowlist do botão "Novo laudo" da toolbar. Comparar/
    // compartilhar/baixar continuam abertos pra secretaria (allowlist mais
    // amplo já existente nesses fluxos).
    isDoctor: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'report', 'compare', 'share', 'download']);

function tt(key, fallback = '') {
    return props.t?.[key] ?? fallback;
}

const menuRef = ref(null);
const busy    = ref(false);

// Posição ajustada pra nunca vazar da viewport (menu some pra direita/baixo
// se aberto perto da borda).
const style = computed(() => {
    const menuW = 240;
    const menuH = 360;
    const maxX  = window.innerWidth - menuW - 8;
    const maxY  = window.innerHeight - menuH - 8;
    return {
        left: Math.max(4, Math.min(props.x, maxX)) + 'px',
        top:  Math.max(4, Math.min(props.y, maxY)) + 'px',
    };
});

function close() {
    emit('close');
}

function onOutsideClick(e) {
    if (menuRef.value && !menuRef.value.contains(e.target)) close();
}

function onKeydown(e) {
    if (e.key === 'Escape') close();
}

onMounted(() => {
    // Próximo tick: o click direito que abriu o menu não deve fechá-lo na hora.
    nextTick(() => {
        document.addEventListener('mousedown', onOutsideClick);
        document.addEventListener('keydown', onKeydown);
    });
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onOutsideClick);
    document.removeEventListener('keydown', onKeydown);
});

async function patchExam(urlTemplate, payload, applyLocal) {
    if (!props.exam || busy.value) return;
    busy.value = true;
    try {
        const url = urlTemplate.replace('__ID__', props.exam.id);
        const { data } = await window.axios.put(url, payload);
        applyLocal(data);
    } catch (e) {
        if (window.showErrorToast) window.showErrorToast(e?.response?.data?.message ?? 'Não foi possível atualizar a imagem.');
    } finally {
        busy.value = false;
    }
}

function setLaterality(value) {
    patchExam(props.urls.laterality, { laterality: value }, (data) => {
        props.exam.laterality = data.laterality;
    });
}

function setQualityRating(value) {
    // Clicar na mesma estrela já marcada cancela a avaliação (toggle).
    const next = props.exam.quality_rating === value ? 0 : value;
    patchExam(props.urls.quality_rating, { quality_rating: next }, (data) => {
        props.exam.quality_rating = data.quality_rating;
    });
}

async function confirmDisable() {
    const message = tt('context_menu_disable_confirm',
        'Desabilitar esta imagem bloqueia laudo, PDF e análise de IA para ela, e revoga o compartilhamento com o paciente no Portal, se houver. Deseja continuar?');
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon: 'warning',
            title: tt('context_menu_disable', 'Desabilitar imagem'),
            text: message,
            showCancelButton: true,
            confirmButtonText: tt('context_menu_disable_confirm_btn', 'Desabilitar'),
            cancelButtonText: tt('cancel', 'Cancelar'),
            confirmButtonColor: '#dc3545',
        });
        return result.isConfirmed;
    }
    return window.confirm(message);
}

// Desabilitar tem efeito real (bloqueia laudo/PDF/IA + revoga share) —
// confirmação só nesse sentido; reabilitar é seguro/reversível, sem prévia.
async function setActive(value) {
    if (!value && !(await confirmDisable())) return;
    patchExam(props.urls.active, { active: value }, (data) => {
        props.exam.active = data.active;
    });
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open && exam" ref="menuRef" class="eic-menu" role="menu"
             :aria-label="tt('context_menu_title', 'Ações rápidas')" :style="style" @contextmenu.prevent>
            <div class="eic-menu__header">
                {{ tt('context_menu_title', 'Ações rápidas') }}
                <span v-if="busy" class="spinner-border spinner-border-sm ms-1" style="width:.7rem;height:.7rem;"></span>
            </div>

            <button v-if="isDoctor" type="button" role="menuitem" class="eic-menu__item" @click="emit('report'); close();">
                <i class="ti ti-file-text"></i>{{ tt('context_menu_report', 'Fazer laudo manual') }}
            </button>
            <button type="button" role="menuitem" class="eic-menu__item" @click="emit('compare'); close();">
                <i class="ti ti-arrows-diff"></i>{{ tt('context_menu_compare', 'Comparar / Alinhar') }}
            </button>
            <button type="button" role="menuitem" class="eic-menu__item" @click="emit('share'); close();">
                <i :class="exam.shared_with_patient ? 'ti ti-share-off' : 'ti ti-share'"></i>
                {{ exam.shared_with_patient
                    ? tt('context_menu_unshare', 'Revogar do Portal do Paciente')
                    : tt('context_menu_share', 'Compartilhar com o paciente') }}
            </button>
            <button type="button" role="menuitem" class="eic-menu__item" @click="emit('download'); close();">
                <i class="ti ti-download"></i>{{ tt('context_menu_download', 'Baixar imagem') }}
            </button>

            <template v-if="isDoctor">
                <div class="eic-menu__sep"></div>

                <div class="eic-menu__label">{{ tt('context_menu_eye', 'Lateralidade') }}</div>
                <div class="eic-menu__row px-2 pb-2">
                    <div class="btn-group btn-group-sm w-100" role="group">
                        <button type="button" class="btn btn-sm" :class="exam.laterality === 1 ? 'btn-primary' : 'btn-outline-primary'"
                                :disabled="busy" @click="setLaterality(1)">OD</button>
                        <button type="button" class="btn btn-sm" :class="exam.laterality === 2 ? 'btn-danger' : 'btn-outline-danger'"
                                :disabled="busy" @click="setLaterality(2)">OE</button>
                        <button type="button" class="btn btn-sm" :class="(exam.laterality !== 1 && exam.laterality !== 2) ? 'btn-secondary' : 'btn-outline-secondary'"
                                :disabled="busy" @click="setLaterality(null)">AO</button>
                    </div>
                </div>

                <div class="eic-menu__label">{{ tt('context_menu_quality', 'Qualidade da captura') }}</div>
                <div class="eic-menu__row px-2 pb-2 d-flex align-items-center gap-1">
                    <button v-for="n in 5" :key="n" type="button" class="eic-star-btn"
                            :class="{ 'is-active': (exam.quality_rating ?? 0) >= n }"
                            :disabled="busy"
                            :aria-label="`${n}/5`"
                            :title="tt('context_menu_quality_hint', 'Clique pra avaliar — clique de novo na mesma estrela pra cancelar')"
                            @click="setQualityRating(n)">
                        <i :class="(exam.quality_rating ?? 0) >= n ? 'fa fa-star' : 'fa fa-star-o'"></i>
                    </button>
                    <span v-if="exam.quality_rating" class="text-muted small ms-1">({{ exam.quality_rating }}/5)</span>
                </div>

                <div class="eic-menu__sep"></div>

                <button type="button" role="menuitem" class="eic-menu__item"
                        :class="{ 'text-warning': exam.active }"
                        :disabled="busy" @click="setActive(!exam.active)">
                    <i :class="exam.active ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                    {{ exam.active
                        ? tt('context_menu_disable', 'Desabilitar imagem')
                        : tt('context_menu_enable', 'Habilitar imagem') }}
                </button>
            </template>

            <div class="eic-menu__sep"></div>

            <div class="eic-menu__info">
                <div><strong>{{ tt('context_menu_info_type', 'Tipo') }}:</strong> {{ exam.exam_type_name ?? exam.exam_type?.name ?? '—' }}</div>
                <div><strong>{{ tt('context_menu_info_created', 'Capturado em') }}:</strong> {{ exam.created_at_fmt ?? '—' }}</div>
                <div v-if="exam.equipment_name"><strong>{{ tt('context_menu_info_equipment', 'Equipamento') }}:</strong> {{ exam.equipment_name }}</div>
                <div v-if="exam.doctor_name"><strong>{{ tt('context_menu_info_doctor', 'Médico') }}:</strong> {{ exam.doctor_name }}</div>
                <div v-if="exam.is_external"><strong>{{ tt('context_menu_info_origin', 'Origem') }}:</strong> {{ exam.external_origin || 'Importado' }}</div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.eic-menu {
    position: fixed;
    z-index: 1090;
    width: 240px;
    background: var(--bs-body-bg, #fff);
    color: var(--bs-body-color, #212529);
    border: 1px solid var(--bs-border-color, rgba(0,0,0,.15));
    border-radius: .5rem;
    box-shadow: 0 6px 24px rgba(0,0,0,.25);
    padding: .35rem 0;
    font-size: .8rem;
    user-select: none;
}

.eic-menu__header {
    padding: .3rem .75rem .4rem;
    font-weight: 600;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .03em;
    opacity: .6;
}

.eic-menu__item {
    display: flex;
    align-items: center;
    gap: .55rem;
    width: 100%;
    background: none;
    border: none;
    text-align: start;
    padding: .4rem .75rem;
    color: inherit;
    font-size: .8rem;
}

.eic-menu__item:hover:not(:disabled) {
    background: rgba(13, 110, 253, .08);
}

.eic-menu__item i {
    width: 1rem;
    text-align: center;
    opacity: .8;
}

.eic-menu__sep {
    height: 1px;
    background: var(--bs-border-color, rgba(0,0,0,.1));
    margin: .3rem 0;
}

.eic-menu__label {
    padding: 0 .75rem;
    font-size: .68rem;
    text-transform: uppercase;
    letter-spacing: .03em;
    opacity: .55;
    margin-bottom: .15rem;
}

.eic-star-btn {
    background: none;
    border: none;
    padding: .2rem;
    line-height: 1;
    font-size: .95rem;
    color: var(--bs-secondary-color, #6c757d);
}

.eic-star-btn.is-active { color: #ffc107; }

.eic-star-btn:disabled {
    cursor: default;
    opacity: .5;
}

.eic-menu__info {
    padding: .2rem .75rem .3rem;
    font-size: .7rem;
    opacity: .75;
    line-height: 1.5;
}
</style>
