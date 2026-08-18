<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

/**
 * Form de criar/editar item de inventário de lente IOL (catarata) DA CLÍNICA.
 *
 * DECISÃO — dados de edição: `item` chega PRONTO do card clicado em Index.vue
 * (o objeto já é o `EntityIolLensResource::toArray()` retornado na listagem
 * paginada, que já traz TODOS os campos que este form edita — diopter_min/
 * diopter_max/price CRUS, não formatados, conforme o comentário do próprio
 * Resource). Não chamamos `routes.show` aqui: seria um roundtrip HTTP extra
 * pra buscar dado que a listagem já trouxe. Se no futuro a listagem parar de
 * trazer algum campo necessário, reintroduzir um fetch em `loadEditData()`.
 *
 * DECISÃO — autocomplete fabricante/modelo: reaproveita <SearchSelect> em
 * modo remoto (mesmo padrão do filtro de CID-10 em EyeImages/Index.vue), mas
 * o valor selecionado (`option-selected`, ver SearchSelect.vue) só HIDRATA
 * manufacturer/model_name/category/iol_lens_model_id — os três primeiros
 * continuam inputs de texto livres e sempre editáveis por baixo. Isso cobre
 * o pedido do ticket ("digita e sugere, mas pode cadastrar novo") sem forçar
 * o usuário a escolher um item da lista: se nada bater, ele só ignora o
 * picker e digita direto nos campos abaixo (iol_lens_model_id fica null e o
 * backend cria um modelo novo no catálogo global — ver
 * IolLensesController::store()).
 *
 * DECISÃO — picker em modo EDIÇÃO: quando o item já tem `iol_lens_model_id`
 * vinculado, o picker abre VAZIO mesmo assim (não há endpoint pra buscar 1
 * IolLensModel por id e re-popular o label do Multiselect remoto). Isso é
 * inofensivo: manufacturer/model_name já vêm preenchidos nos campos de texto
 * abaixo a partir do snapshot local, e o vínculo (`iol_lens_model_id`)
 * permanece intacto no submit enquanto o usuário não tocar no picker.
 *
 * DECISÃO — upload + update: Laravel não aceita PUT multipart direto. Em vez
 * de fixar `_method` no objeto inicial do useForm (como Profile/Edit.vue faz
 * — mas aquele form É SEMPRE edição), usamos `form.transform()` pra injetar
 * `_method: 'PUT'` só no submit de edição — no create, nenhum `_method`
 * vazio é enviado (evita SuspiciousOperationException do Symfony ao tentar
 * `strtoupper('')` como verbo HTTP). `forceFormData: true` sempre, pra
 * upload de imagem funcionar em ambos os fluxos.
 */
const props = defineProps({
    open:   { type: Boolean, required: true },
    item:   { type: Object,  default: null }, // null = criar; objeto = editar
    routes: { type: Object,  required: true }, // { store, update (__ID__), search }
});

const emit = defineEmits(['close', 'saved']);

const isEdit = computed(() => !!props.item);
const title  = computed(() => isEdit.value ? 'Editar lente' : 'Nova lente');

const CATEGORY_SUGGESTIONS = ['Monofocal', 'Multifocal', 'Tórica', 'EDF', 'Outro'];

const form = useForm({
    manufacturer:       '',
    model_name:         '',
    category:           '',
    iol_lens_model_id:  null,
    diopter_min:        null,
    diopter_max:        null,
    price:              null,
    image:              null,
    active:             true,
});

// ── Preview de imagem ────────────────────────────────────────────────────
// Prioridade: arquivo local recém-selecionado > imagem do catálogo global
// (autocomplete) > imagem atual do item (edição) > nenhuma (placeholder).
const existingImageUrl = ref(null);
const catalogImageUrl  = ref(null);
const localImagePreview = ref(null);
const fileInput = ref(null);

const imagePreview = computed(() =>
    localImagePreview.value ?? catalogImageUrl.value ?? existingImageUrl.value ?? null,
);

function onImageChange(e) {
    const file = e.target.files[0] ?? null;
    form.image = file;
    localImagePreview.value = file ? URL.createObjectURL(file) : null;
}

function clearImageSelection() {
    form.image = null;
    localImagePreview.value = null;
    if (fileInput.value) fileInput.value.value = '';
}

// ── Autocomplete catálogo global (fabricante/modelo) ─────────────────────
const searchUrl = computed(() => `${props.routes.search}?q=__Q__`);
const catalogPickerValue = ref(null); // id do IolLensModel selecionado no picker (não é o form final)

function onCatalogOptionSelected(option) {
    if (!option) {
        form.iol_lens_model_id = null;
        catalogImageUrl.value = null;
        return;
    }

    form.iol_lens_model_id = option.id ?? null;
    form.manufacturer      = option.manufacturer ?? form.manufacturer;
    form.model_name        = option.model_name ?? form.model_name;
    form.category          = option.category ?? form.category;
    catalogImageUrl.value  = option.image_url ?? null;
}

// ── Reset / hidratação ao abrir ───────────────────────────────────────────
function reset() {
    form.reset();
    form.clearErrors();
    catalogPickerValue.value = null;
    catalogImageUrl.value    = null;
    localImagePreview.value  = null;
    clearImageSelection();
}

watch(() => props.open, (val) => {
    if (!val) return;
    reset();

    if (props.item) {
        form.manufacturer      = props.item.manufacturer ?? '';
        form.model_name        = props.item.model_name ?? '';
        form.category          = props.item.category ?? '';
        form.iol_lens_model_id = props.item.iol_lens_model_id ?? null;
        form.diopter_min       = props.item.diopter_min ?? null;
        form.diopter_max       = props.item.diopter_max ?? null;
        form.price             = props.item.price ?? null;
        form.active            = props.item.active ?? true;
        existingImageUrl.value = props.item.image_url ?? null;
    } else {
        existingImageUrl.value = null;
    }
});

function submit() {
    if (isEdit.value) {
        form.transform((data) => ({ ...data, _method: 'PUT' }));
        form.post(props.routes.update.replace('__ID__', props.item.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => emit('saved'),
        });
    } else {
        form.transform((data) => data);
        form.post(props.routes.store, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => emit('saved'),
        });
    }
}

function close() {
    if (form.processing) return;
    emit('close');
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="560" @close="close">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-eye me-2 text-primary"></i>{{ title }}
            </h5>
        </template>

        <form @submit.prevent="submit">
            <!-- Autocomplete catálogo global -->
            <div class="mb-3">
                <label class="form-label">Buscar no catálogo global</label>
                <SearchSelect
                    v-model="catalogPickerValue"
                    :remote-search-url="searchUrl"
                    :remote-min-chars="2"
                    placeholder="Digite fabricante ou modelo..."
                    @option-selected="onCatalogOptionSelected"
                />
                <small class="text-muted d-block mt-1">
                    Selecione um resultado pra preencher fabricante/modelo/categoria automaticamente,
                    ou ignore e cadastre um modelo novo digitando os campos abaixo.
                </small>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Fabricante <span class="text-danger">*</span></label>
                    <input
                        v-model="form.manufacturer"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.manufacturer }"
                        maxlength="255"
                    >
                    <div v-if="form.errors.manufacturer" class="invalid-feedback">{{ form.errors.manufacturer }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Modelo <span class="text-danger">*</span></label>
                    <input
                        v-model="form.model_name"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.model_name }"
                        maxlength="255"
                    >
                    <div v-if="form.errors.model_name" class="invalid-feedback">{{ form.errors.model_name }}</div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Tipo / categoria</label>
                <input
                    v-model="form.category"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.category }"
                    list="iol-category-suggestions"
                    maxlength="100"
                    placeholder="Ex.: Monofocal, Multifocal, Tórica..."
                >
                <datalist id="iol-category-suggestions">
                    <option v-for="c in CATEGORY_SUGGESTIONS" :key="c" :value="c" />
                </datalist>
                <div v-if="form.errors.category" class="invalid-feedback d-block">{{ form.errors.category }}</div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Dioptria mínima</label>
                    <input
                        v-model="form.diopter_min"
                        type="number"
                        step="0.01"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.diopter_min }"
                    >
                    <div v-if="form.errors.diopter_min" class="invalid-feedback">{{ form.errors.diopter_min }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Dioptria máxima</label>
                    <input
                        v-model="form.diopter_max"
                        type="number"
                        step="0.01"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.diopter_max }"
                    >
                    <div v-if="form.errors.diopter_max" class="invalid-feedback">{{ form.errors.diopter_max }}</div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Valor (R$)</label>
                <input
                    v-model="form.price"
                    type="number"
                    step="0.01"
                    min="0"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.price }"
                    placeholder="0,00"
                >
                <div v-if="form.errors.price" class="invalid-feedback">{{ form.errors.price }}</div>
            </div>

            <!-- Imagem -->
            <div class="mb-3">
                <label class="form-label">Imagem</label>
                <div class="d-flex align-items-center gap-3">
                    <div class="iol-lens-form__preview">
                        <img v-if="imagePreview" :src="imagePreview" alt="Preview da lente">
                        <i v-else class="ti ti-eye text-muted"></i>
                    </div>
                    <div class="flex-grow-1">
                        <input
                            ref="fileInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': form.errors.image }"
                            @change="onImageChange"
                        >
                        <small class="text-muted d-block mt-1">JPG, PNG ou WebP, máx 4MB.</small>
                        <div v-if="form.errors.image" class="invalid-feedback d-block">{{ form.errors.image }}</div>
                        <button
                            v-if="localImagePreview"
                            type="button"
                            class="btn btn-link btn-sm px-0 mt-1"
                            @click="clearImageSelection"
                        >
                            Remover seleção
                        </button>
                    </div>
                </div>
            </div>

            <!-- Status -->
            <div class="mb-2">
                <div class="form-check form-switch">
                    <input
                        id="iol_lens_active"
                        v-model="form.active"
                        type="checkbox"
                        class="form-check-input"
                        role="switch"
                    >
                    <label class="form-check-label" for="iol_lens_active">
                        {{ form.active ? 'Ativa' : 'Inativa' }}
                    </label>
                </div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="form.processing" @click="close">
                Cancelar
            </button>
            <button type="button" class="btn btn-primary px-4" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ isEdit ? 'Salvar alterações' : 'Cadastrar lente' }}
            </button>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.iol-lens-form__preview {
    width: 72px;
    height: 72px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: .5rem;
    overflow: hidden;
    background: var(--bs-tertiary-bg, #f8f9fa);
}

.iol-lens-form__preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.iol-lens-form__preview i {
    font-size: 1.75rem;
}
</style>
