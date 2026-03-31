import { useState, useCallback } from 'react';
import { router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import PageHeader from '@/Components/UI/PageHeader';
import CardGrid from '@/Components/UI/CardGrid';
import Modal from '@/Components/UI/Modal';
import ConfirmDialog from '@/Components/UI/ConfirmDialog';
import PersonForm from '@/Components/UI/PersonForm';
import FormInput, { FormSwitch } from '@/Components/UI/FormInput';
import { StatusBadge } from '@/Components/UI/Badge';

const emptyForm = {
    name: '', nickname: '', national_registry: '', birth_date: '', gender: '',
    marital_status: '', email: '', mother_name: '', father_name: '',
    state_registry: '', state_registry_agency: '', state_registry_initial: '',
    state_registry_date: '', telephone: '', cellphone: '', whatsapp: false,
    zipcode: '', address: '', number: '', complement: '', district: '',
    city: '', state: '', record: '', record_specialty: '', color: '#3b82f6',
    observation: '', partner: false, active: true,
};

export default function DoctorsIndex({ total_doctors, genders, maritalStatuses, statesOfBrazil }) {
    const [showModal, setShowModal] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState({ ...emptyForm });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [confirmState, setConfirmState] = useState({ show: false, action: null, message: '', title: '' });

    const onChange = (name, value) => setForm((f) => ({ ...f, [name]: value }));

    const openCreate = () => {
        setEditing(null);
        setForm({ ...emptyForm });
        setErrors({});
        setShowModal(true);
    };

    const openEdit = useCallback(async (doctor) => {
        setEditing(doctor);
        setErrors({});
        try {
            const res = await fetch(`/panel/doctors/${doctor.id}/edit-data`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            setForm({ ...emptyForm, ...json.data });
            setShowModal(true);
        } catch {
            console.error('Erro ao carregar dados do médico');
        }
    }, []);

    const handleSubmit = (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        const url = editing ? `/panel/doctors/${editing.id}` : '/panel/doctors';
        const method = editing ? 'put' : 'post';

        router[method](url, form, {
            preserveScroll: true,
            onSuccess: () => {
                setShowModal(false);
                setEditing(null);
            },
            onError: (errs) => setErrors(errs),
            onFinish: () => setProcessing(false),
        });
    };

    const handleDelete = useCallback((doctor) => {
        setConfirmState({
            show: true,
            title: 'Excluir Médico',
            message: `Deseja realmente excluir "${doctor.full_name}"?`,
            action: () => router.delete(`/panel/doctors/${doctor.id}`, { preserveScroll: true })
        });
    }, []);

    const renderCard = (doctor) => (
        <div key={doctor.id} className="col-sm-6 col-md-4 col-xl-3">
            <div className="card h-100 border-0 shadow-sm">
                <div className="card-body text-center p-3">
                    <img
                        src={doctor.photo_url}
                        alt={doctor.full_name}
                        className="rounded-circle mb-2"
                        width="64"
                        height="64"
                        style={{ objectFit: 'cover' }}
                    />
                    <h6 className="mb-1 fw-semibold">{doctor.full_name}</h6>
                    <p className="text-muted small mb-1">{doctor.email}</p>
                    <p className="text-muted small mb-2">
                        <code>{doctor.code}</code>
                        {doctor.record && <span className="ms-2">CRM: {doctor.record}</span>}
                    </p>
                    <StatusBadge active={doctor.active} />
                </div>
                <div className="card-footer bg-transparent border-top-0 text-center p-2">
                    <div className="btn-group btn-group-sm">
                        <a href={`/panel/doctors/${doctor.id}`} className="btn btn-outline-primary">
                            <i className="ti ti-eye"></i>
                        </a>
                        <button className="btn btn-outline-secondary" onClick={() => openEdit(doctor)}>
                            <i className="ti ti-pencil"></i>
                        </button>
                        <button className="btn btn-outline-danger" onClick={() => handleDelete(doctor)}>
                            <i className="ti ti-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );

    return (
        <AuthenticatedLayout title="Médicos">
            <FlashMessages />

            <PageHeader title="Médicos" subtitle={`${total_doctors} médico${total_doctors !== 1 ? 's' : ''} cadastrado${total_doctors !== 1 ? 's' : ''}`}>
                <button className="btn btn-primary btn-md fs-13" onClick={openCreate}>
                    <i className="ti ti-plus me-1"></i> Novo Médico
                </button>
            </PageHeader>

            <CardGrid
                fetchUrl="/panel/doctors/cards"
                renderCard={renderCard}
                emptyMessage="Nenhum médico cadastrado."
                searchPlaceholder="Buscar por nome, código ou e-mail..."
            />

            {/* Modal Create/Edit */}
            <Modal
                show={showModal}
                onClose={() => setShowModal(false)}
                title={editing ? 'Editar Médico' : 'Novo Médico'}
                size="lg"
                footer={
                    <>
                        <button className="btn btn-secondary" onClick={() => setShowModal(false)}>Cancelar</button>
                        <button className="btn btn-primary" onClick={handleSubmit} disabled={processing}>
                            {processing ? <><span className="spinner-border spinner-border-sm me-1"></span> Salvando...</> : <><i className="ti ti-check me-1"></i> Salvar</>}
                        </button>
                    </>
                }
            >
                <form onSubmit={handleSubmit}>
                    <PersonForm
                        form={form}
                        errors={errors}
                        onChange={onChange}
                        genders={genders}
                        maritalStatuses={maritalStatuses}
                        statesOfBrazil={statesOfBrazil}
                    />

                    {/* Dados profissionais */}
                    <h6 className="fw-semibold mb-3 mt-4 text-primary">
                        <i className="ti ti-stethoscope me-1"></i> Dados Profissionais
                    </h6>
                    <div className="row">
                        <div className="col-md-4">
                            <FormInput label="CRM" name="record" value={form.record} onChange={onChange} error={errors?.record} />
                        </div>
                        <div className="col-md-4">
                            <FormInput label="Especialidade" name="record_specialty" value={form.record_specialty} onChange={onChange} error={errors?.record_specialty} />
                        </div>
                        <div className="col-md-4">
                            <FormInput label="Cor na Agenda" name="color" type="color" value={form.color} onChange={onChange} />
                        </div>
                    </div>
                    <div className="row">
                        <div className="col-md-12">
                            <FormInput label="Observações" name="observation" value={form.observation} onChange={onChange} />
                        </div>
                    </div>
                    <div className="d-flex gap-3 mt-3">
                        <FormSwitch label="Parceiro" name="partner" checked={form.partner} onChange={onChange} />
                        <FormSwitch label="Ativo" name="active" checked={form.active} onChange={onChange} />
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                show={confirmState.show}
                onClose={() => setConfirmState((s) => ({ ...s, show: false }))}
                onConfirm={confirmState.action}
                title={confirmState.title}
                message={confirmState.message}
            />
        </AuthenticatedLayout>
    );
}
