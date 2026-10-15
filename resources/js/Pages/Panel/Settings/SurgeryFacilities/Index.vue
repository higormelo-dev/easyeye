<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';

/**
 * Configurações > Cirurgia: locais (clínica própria, hospital, centro
 * cirúrgico) e as salas de cada um. Sala da própria clínica pode ser ligada a
 * uma sala da Agenda (escala semanal e bloqueios valem para a cirurgia).
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    facilities: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    resources: { type: Array, default: () => [] },
    urls: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const ui = computed(() => props.t.ui?.facilities ?? {});
const withId = (url, id) => url.replace('__ID__', id);

// ── Local ─────────────────────────────────────────────────────────────────
const editingFacility = ref(null); // null | 'new' | facility
const facilityForm = useForm({
    name: '',
    type: 'own_clinic',
    cnes: '',
    operator_code: '',
    phone: '',
    zip_code: '',
    address: '',
    address_number: '',
    district: '',
    city: '',
    state: '',
    notes: '',
    active: true,
});

function openFacility(facility = null) {
    facilityForm.clearErrors();
    const f = facility ?? {};
    facilityForm.defaults({
        name: f.name ?? '',
        type: f.type ?? 'own_clinic',
        cnes: f.cnes ?? '',
        operator_code: f.operator_code ?? '',
        phone: f.phone ?? '',
        zip_code: f.zip_code ?? '',
        address: f.address ?? '',
        address_number: f.address_number ?? '',
        district: f.district ?? '',
        city: f.city ?? '',
        state: f.state ?? '',
        notes: f.notes ?? '',
        active: f.active ?? true,
    });
    facilityForm.reset();
    editingFacility.value = facility ?? 'new';
}

function saveFacility() {
    const blank = (v) => (v === '' ? null : v);
    facilityForm.transform((d) => ({
        ...d,
        cnes: blank(d.cnes),
        operator_code: blank(d.operator_code),
        phone: blank(d.phone),
        zip_code: blank(d.zip_code),
        address: blank(d.address),
        address_number: blank(d.address_number),
        district: blank(d.district),
        city: blank(d.city),
        state: blank(d.state),
        notes: blank(d.notes),
    }));
    const options = { preserveScroll: true, onSuccess: () => (editingFacility.value = null) };

    if (editingFacility.value === 'new') facilityForm.post(props.urls.store, options);
    else facilityForm.put(withId(props.urls.update, editingFacility.value.id), options);
}

async function removeFacility(facility) {
    const result = await window.Swal?.fire({
        icon: 'warning',
        title: ui.value.delete_facility,
        text: ui.value.delete_facility_confirm,
        showCancelButton: true,
        confirmButtonText: ui.value.delete,
        cancelButtonText: ui.value.cancel,
    });
    if (result?.isConfirmed) router.delete(withId(props.urls.destroy, facility.id), { preserveScroll: true });
}

function toggleFacility(facility) {
    router.put(withId(props.urls.update, facility.id), { active: !facility.active }, { preserveScroll: true });
}

// ── Sala ──────────────────────────────────────────────────────────────────
const editingRoom = ref(null); // { facility, room|null }
const roomForm = useForm({ name: '', clinic_resource_id: '', active: true });

function openRoom(facility, room = null) {
    roomForm.clearErrors();
    roomForm.defaults({
        name: room?.name ?? '',
        clinic_resource_id: room?.clinic_resource_id ?? '',
        active: room?.active ?? true,
    });
    roomForm.reset();
    editingRoom.value = { facility, room };
}

function saveRoom() {
    roomForm.transform((d) => ({ ...d, clinic_resource_id: d.clinic_resource_id || null }));
    const options = { preserveScroll: true, onSuccess: () => (editingRoom.value = null) };
    const { facility, room } = editingRoom.value;

    if (room) roomForm.put(withId(props.urls.room_update, room.id), options);
    else roomForm.post(withId(props.urls.room_store, facility.id), options);
}

async function removeRoom(room) {
    const result = await window.Swal?.fire({
        icon: 'warning',
        title: ui.value.delete_room,
        text: ui.value.delete_room_confirm,
        showCancelButton: true,
        confirmButtonText: ui.value.delete,
        cancelButtonText: ui.value.cancel,
    });
    if (result?.isConfirmed) router.delete(withId(props.urls.room_destroy, room.id), { preserveScroll: true });
}

const resourceName = (id) => props.resources.find((r) => r.id === id)?.name;
const fe = (path) => facilityForm.errors[path] ?? '';
</script>

<template>
    <Head :title="t.facilities.title" />

    <AppLayout :title="t.facilities.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.facilities.title" :subtitle="t.facilities.subtitle">
                <template #actions>
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        data-testid="facility-new"
                        @click="openFacility()"
                    >
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ ui.new_facility }}
                    </button>
                </template>
            </PageHeader>

            <div v-if="!facilities.length" class="card" data-testid="facility-empty">
                <div class="card-body text-center py-5">
                    <i class="ti ti-building-hospital fs-1 text-muted d-block mb-2" aria-hidden="true"></i>
                    <p class="fw-semibold mb-1">{{ ui.empty }}</p>
                    <p class="text-muted small mb-0">{{ ui.empty_hint }}</p>
                </div>
            </div>

            <div v-for="facility in facilities" :key="facility.id" class="card mb-3" data-testid="facility-card">
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <h2 class="h6 mb-0 me-2">{{ facility.name }}</h2>
                    <span class="badge text-bg-light border">{{ facility.type_label }}</span>
                    <span v-if="!facility.active" class="badge text-bg-secondary">{{ ui.inactive }}</span>
                    <span v-if="facility.cnes" class="text-muted small">CNES {{ facility.cnes }}</span>
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="openFacility(facility)">
                            {{ ui.edit }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            @click="toggleFacility(facility)"
                        >
                            {{ facility.active ? ui.deactivate : ui.activate }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-danger"
                            :aria-label="ui.delete_facility"
                            @click="removeFacility(facility)"
                        >
                            <i class="ti ti-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <h3 class="h6 mb-0">{{ ui.rooms }}</h3>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary ms-auto"
                            data-testid="room-new"
                            @click="openRoom(facility)"
                        >
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ ui.new_room }}
                        </button>
                    </div>
                    <p v-if="!facility.rooms.length" class="text-muted small mb-0">{{ ui.rooms_empty }}</p>
                    <ul v-else class="list-group list-group-flush">
                        <li
                            v-for="room in facility.rooms"
                            :key="room.id"
                            class="list-group-item px-0 d-flex align-items-center gap-2"
                            data-testid="room-row"
                        >
                            <span class="me-auto">
                                {{ room.name }}
                                <span v-if="room.clinic_resource_id" class="text-muted small ms-2"
                                    ><i class="ti ti-link" aria-hidden="true"></i>
                                    {{ resourceName(room.clinic_resource_id) ?? ui.linked_resource }}</span
                                >
                                <span v-if="!room.active" class="badge text-bg-secondary ms-2">{{ ui.inactive }}</span>
                            </span>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                @click="openRoom(facility, room)"
                            >
                                {{ ui.edit }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                :aria-label="ui.delete_room"
                                @click="removeRoom(room)"
                            >
                                <i class="ti ti-trash" aria-hidden="true"></i>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <CenteredModal :open="editingFacility !== null" size="lg" @close="editingFacility = null">
            <template #header>{{ editingFacility === 'new' ? ui.new_facility : ui.edit_facility }}</template>
            <form class="p-2 row g-3" novalidate @submit.prevent="saveFacility">
                <div class="col-md-8">
                    <label class="form-label fw-medium" for="fac-name">{{ ui.name }}</label>
                    <input
                        id="fac-name"
                        v-model="facilityForm.name"
                        type="text"
                        maxlength="150"
                        class="form-control"
                        :class="{ 'is-invalid': fe('name') }"
                        data-testid="facility-name"
                    />
                    <div class="invalid-feedback">{{ fe('name') }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium" for="fac-type">{{ ui.type }}</label>
                    <select id="fac-type" v-model="facilityForm.type" class="form-select">
                        <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium" for="fac-cnes">CNES</label>
                    <input
                        id="fac-cnes"
                        v-model="facilityForm.cnes"
                        type="text"
                        inputmode="numeric"
                        maxlength="7"
                        class="form-control"
                        :class="{ 'is-invalid': fe('cnes') }"
                    />
                    <div class="invalid-feedback">{{ fe('cnes') }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium" for="fac-op">{{ ui.operator_code }}</label>
                    <input
                        id="fac-op"
                        v-model="facilityForm.operator_code"
                        type="text"
                        maxlength="20"
                        class="form-control"
                    />
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium" for="fac-phone">{{ ui.phone }}</label>
                    <input
                        id="fac-phone"
                        v-model="facilityForm.phone"
                        type="text"
                        maxlength="30"
                        class="form-control"
                    />
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium" for="fac-zip">{{ ui.zip_code }}</label>
                    <input
                        id="fac-zip"
                        v-model="facilityForm.zip_code"
                        type="text"
                        maxlength="9"
                        class="form-control"
                    />
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium" for="fac-address">{{ ui.address }}</label>
                    <input
                        id="fac-address"
                        v-model="facilityForm.address"
                        type="text"
                        maxlength="150"
                        class="form-control"
                    />
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium" for="fac-number">{{ ui.number }}</label>
                    <input
                        id="fac-number"
                        v-model="facilityForm.address_number"
                        type="text"
                        maxlength="20"
                        class="form-control"
                    />
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium" for="fac-district">{{ ui.district }}</label>
                    <input
                        id="fac-district"
                        v-model="facilityForm.district"
                        type="text"
                        maxlength="80"
                        class="form-control"
                    />
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-medium" for="fac-city">{{ ui.city }}</label>
                    <input id="fac-city" v-model="facilityForm.city" type="text" maxlength="80" class="form-control" />
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium" for="fac-state">{{ ui.state }}</label>
                    <input
                        id="fac-state"
                        v-model="facilityForm.state"
                        type="text"
                        maxlength="2"
                        class="form-control text-uppercase"
                        :class="{ 'is-invalid': fe('state') }"
                    />
                    <div class="invalid-feedback">{{ fe('state') }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium" for="fac-notes">{{ ui.notes }}</label>
                    <textarea
                        id="fac-notes"
                        v-model="facilityForm.notes"
                        rows="2"
                        maxlength="2000"
                        class="form-control"
                    ></textarea>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="editingFacility = null">
                    {{ ui.cancel }}
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="facilityForm.processing"
                    data-testid="facility-save"
                    @click="saveFacility"
                >
                    {{ ui.save }}
                </button>
            </template>
        </CenteredModal>

        <CenteredModal :open="editingRoom !== null" size="md" @close="editingRoom = null">
            <template #header>{{ editingRoom?.room ? ui.edit_room : ui.new_room }}</template>
            <form class="p-2 row g-3" novalidate @submit.prevent="saveRoom">
                <div class="col-12">
                    <label class="form-label fw-medium" for="room-name">{{ ui.room_name }}</label>
                    <input
                        id="room-name"
                        v-model="roomForm.name"
                        type="text"
                        maxlength="100"
                        class="form-control"
                        :class="{ 'is-invalid': roomForm.errors.name }"
                        data-testid="room-name"
                    />
                    <div class="invalid-feedback">{{ roomForm.errors.name }}</div>
                </div>
                <div v-if="editingRoom?.facility.type === 'own_clinic'" class="col-12">
                    <label class="form-label fw-medium" for="room-resource">{{ ui.agenda_room }}</label>
                    <select
                        id="room-resource"
                        v-model="roomForm.clinic_resource_id"
                        class="form-select"
                        :class="{ 'is-invalid': roomForm.errors.clinic_resource_id }"
                    >
                        <option value="">{{ ui.agenda_room_none }}</option>
                        <option v-for="r in resources" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                    <div class="invalid-feedback">{{ roomForm.errors.clinic_resource_id }}</div>
                    <div class="form-text">{{ ui.agenda_room_hint }}</div>
                </div>
                <div v-if="editingRoom?.room" class="col-12">
                    <div class="form-check form-switch">
                        <input
                            id="room-active"
                            v-model="roomForm.active"
                            type="checkbox"
                            class="form-check-input"
                            role="switch"
                        />
                        <label class="form-check-label" for="room-active">{{ ui.active }}</label>
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="editingRoom = null">
                    {{ ui.cancel }}
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="roomForm.processing"
                    data-testid="room-save"
                    @click="saveRoom"
                >
                    {{ ui.save }}
                </button>
            </template>
        </CenteredModal>
    </AppLayout>
</template>
