<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

const props = defineProps({
    open:   { type: Boolean, required: true },
    item:   { type: Object,  default: null }, // null = criar; objeto = editar
    routes: { type: Object,  required: true }, // { store, update (__ID__) }
});

const emit = defineEmits(['close', 'saved']);

const isEdit = computed(() => !!props.item);
const title  = computed(() => isEdit.value ? 'Editar fornecedor' : 'Novo fornecedor');

const form = useForm({
    name:          '',
    document:      '',
    email:         '',
    phone:         '',
    contact_name:  '',
    notes:         '',
    active:        true,
});

function reset() {
    form.reset();
    form.clearErrors();
}

watch(() => props.open, (val) => {
    if (!val) return;
    reset();

    if (props.item) {
        form.name         = props.item.name ?? '';
        form.document     = props.item.document ?? '';
        form.email        = props.item.email ?? '';
        form.phone        = props.item.phone ?? '';
        form.contact_name = props.item.contact_name ?? '';
        form.notes        = props.item.notes ?? '';
        form.active       = props.item.active ?? true;
    }
});

function submit() {
    if (isEdit.value) {
        form.put(props.routes.update.replace('__ID__', props.item.id), {
            preserveScroll: true,
            onSuccess: () => emit('saved'),
        });
    } else {
        form.post(props.routes.store, {
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
    <OffcanvasPanel :open="open" :width="520" @close="close">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-truck-delivery me-2 text-primary"></i>{{ title }}
            </h5>
        </template>

        <form @submit.prevent="submit">
            <div class="mb-3">
                <label class="form-label">Nome <span class="text-danger">*</span></label>
                <input v-model="form.name" type="text" class="form-control" :class="{ 'is-invalid': form.errors.name }" maxlength="255">
                <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">CNPJ/CPF</label>
                    <input v-model="form.document" type="text" class="form-control" :class="{ 'is-invalid': form.errors.document }" maxlength="20">
                    <div v-if="form.errors.document" class="invalid-feedback">{{ form.errors.document }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Telefone</label>
                    <input v-model="form.phone" type="text" class="form-control" :class="{ 'is-invalid': form.errors.phone }" maxlength="20">
                    <div v-if="form.errors.phone" class="invalid-feedback">{{ form.errors.phone }}</div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">E-mail</label>
                    <input v-model="form.email" type="email" class="form-control" :class="{ 'is-invalid': form.errors.email }" maxlength="255">
                    <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contato</label>
                    <input v-model="form.contact_name" type="text" class="form-control" :class="{ 'is-invalid': form.errors.contact_name }" maxlength="255">
                    <div v-if="form.errors.contact_name" class="invalid-feedback">{{ form.errors.contact_name }}</div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Observações</label>
                <textarea v-model="form.notes" class="form-control" :class="{ 'is-invalid': form.errors.notes }" rows="2" maxlength="2000"></textarea>
                <div v-if="form.errors.notes" class="invalid-feedback">{{ form.errors.notes }}</div>
            </div>

            <div class="mb-2">
                <div class="form-check form-switch">
                    <input id="supplier_active" v-model="form.active" type="checkbox" class="form-check-input" role="switch">
                    <label class="form-check-label" for="supplier_active">{{ form.active ? 'Ativo' : 'Inativo' }}</label>
                </div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="form.processing" @click="close">Cancelar</button>
            <button type="button" class="btn btn-primary px-4" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ isEdit ? 'Salvar alterações' : 'Cadastrar fornecedor' }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
