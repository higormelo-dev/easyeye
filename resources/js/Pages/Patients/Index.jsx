import { useState, useCallback } from 'react';
import { router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import PageHeader from '@/Components/UI/PageHeader';
import CardGrid from '@/Components/UI/CardGrid';
import Modal from '@/Components/UI/Modal';
import ConfirmDialog from '@/Components/UI/ConfirmDialog';
import PersonForm from '@/Components/UI/PersonForm';
import FormInput, { FormSelect, FormSwitch } from '@/Components/UI/FormInput';
import { StatusBadge } from '@/Components/UI/Badge';

const emptyForm = {
    name: '', nickname: '', national_registry: '', birth_date: '', gender: '',
    marital_status: '', email: '', mother_name: '', father_name: '',
    state_registry: '', state_registry_agency: '', state_registry_initial: '',
    state_registry_date: '', telephone: '', cellphone: '', whatsapp: false,
    zipcode: '', address: '', number: '', complement: '', district: '',
    city: '', state: '', covenant_id: '', card_number: '', skin_id: '',
    iris_id: '', active: true,
};

export default function PatientsIndex({ total_patients, genders, maritalStatuses, statesOfBrazil, covenants, skinTypes, irisTypes }) {
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

    const openEdit = useCallback(async (patient) => {
        setEditing(patient);
        setErrors({});
        try {
            const res = await fetch(`/panel/patients/${patient.id}/edit-data`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            setForm({ ...emptyForm, ...json.data });
            setShowModal(true);
        } catch {
            console.error('Erro ao carregar dados do paciente');
        }
    }, []);

    const handleSubmit = (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        const url = editing ? `/panel/patients/${editing.id}` : '/panel/patients';
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

    const handleDelete = useCallback((patient) => {
        setConfirmState({
            show: true,
            title: 'Excluir Paciente',
            message: `Deseja realmente excluir "${patient.full_name}"?`,
            action: () => router.delete(`/panel/patients/${patient.id}`, { preserveScroll: true })
        });
    }, []);

    const renderCard = (patient) => (
        <div key={patient.id} className="col-sm-6 col-md-4 col-xl-3">
            <div className="card h-100 border-0 shadow-sm">
                <div className="card-body text-center p-3">
                    <img
                        src={patient.photo_url}
                        alt={patient.full_name}
                        className="rounded-circle mb-2"
                        width="64"
                        height="64"
                        style={{ objectFit: 'cover' }}
                    />
                    <h6 className="mb-1 fw-semibold">{patient.full_name}</h6>
                    <p className="text-muted small mb-1">
                        <code>{patient.code}</code>
                        {patient.age && <span className="ms-2">{patient.age}</span>}
                    </p>
                    {(patient.covenant || patient.skin || patient.iris) && (
                        <p className="text-muted small mb-2">
                            {[patient.covenant, patient.skin, patient.iris].filter(Boolean).join(' · ')}
                        </p>
                    )}
                    <StatusBadge active={patient.active} />
                </div>
                <div className="card-footer bg-transparent border-top-0 text-center p-2">
                    <div className="btn-group btn-group-sm">
                        <a href={`/panel/patients/${patient.id}`} className="btn btn-outline-primary">
                            <i className="ti ti-eye"></i>
                        </a>
                        <button className="btn btn-outline-secondary" onClick={() => openEdit(patient)}>
                            <i className="ti ti-pencil"></i>
                        </button>
                        <a href={`/panel/patients/${patient.id}/medicalrecords`} className="btn btn-outline-info" title="Prontuários">
                            <i className="ti ti-file-medical"></i>
                        </a>
                        <button className="btn btn-outline-danger" onClick={() => handleDelete(patient)}>
                            <i className="ti ti-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );

    return (
        <AuthenticatedLayout title="Pacientes">
            <FlashMessages />

            <PageHeader title="Pacientes" subtitle={`${total_patients} paciente${total_patients !== 1 ? 's' : ''}`}>
                <button className="btn btn-primary btn-md fs-13" onClick={openCreate}>
                    <i className="ti ti-plus me-1"></i> Novo Paciente
                </button>
            </PageHeader>

            <CardGrid
                fetchUrl="/panel/patients/cards"
                renderCard={renderCard}
                emptyMessage="Nenhum paciente cadastrado."
                searchPlaceholder="Buscar por nome ou código..."
            />

            {/* Modal Create/Edit */}
            <Modal
                show={showModal}
                onClose={() => setShowModal(false)}
                title={editing ? 'Editar Paciente' : 'Novo Paciente'}
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
                        showEmail={false}
                    />

                    {/* Dados Clínicos */}
                    <h6 className="fw-semibold mb-3 mt-4 text-primary">
                        <i className="ti ti-heart-rate-monitor me-1"></i> Dados Clínicos
                    </h6>
                    <div className="row">
                        <div className="col-md-4">
                            <FormSelect label="Convênio" name="covenant_id" value={form.covenant_id} onChange={onChange} options={covenants} />
                        </div>
                        <div className="col-md-4">
                            <FormInput label="Nº Carteirinha" name="card_number" value={form.card_number} onChange={onChange} />
                        </div>
                        <div className="col-md-4">
                            <FormSelect label="Tipo de Pele" name="skin_id" value={form.skin_id} onChange={onChange} options={skinTypes} />
                        </div>
                    </div>
                    <div className="row">
                        <div className="col-md-4">
                            <FormSelect label="Tipo de Íris" name="iris_id" value={form.iris_id} onChange={onChange} options={irisTypes} />
                        </div>
                    </div>
                    <div className="mt-3">
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
