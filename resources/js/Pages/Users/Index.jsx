import { useState, useCallback } from 'react';
import { router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import PageHeader from '@/Components/UI/PageHeader';
import CardGrid from '@/Components/UI/CardGrid';
import Modal from '@/Components/UI/Modal';
import FormInput, { FormSelect } from '@/Components/UI/FormInput';
import Badge, { StatusBadge } from '@/Components/UI/Badge';
import ConfirmDialog from '@/Components/UI/ConfirmDialog';

const emptyForm = {
    name: '', email: '', password: '', password_confirmation: '', rule: '', active: true,
};

export default function UsersIndex({ roles }) {
    const [showModal, setShowModal] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState({ ...emptyForm });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [confirmState, setConfirmState] = useState({ show: false, action: null, message: '', title: '', variant: 'danger' });

    const onChange = (name, value) => setForm((f) => ({ ...f, [name]: value }));

    const openCreate = () => {
        setEditing(null);
        setForm({ ...emptyForm });
        setErrors({});
        setShowModal(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        const url = editing ? `/panel/accesscontrol/users/${editing.id}` : '/panel/accesscontrol/users';
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

    const handleDelete = useCallback((user) => {
        setConfirmState({
            show: true,
            title: 'Excluir Usuário',
            message: `Deseja realmente excluir "${user.full_name}"?`,
            variant: 'danger',
            action: () => router.delete(`/panel/accesscontrol/users/${user.id}`, { preserveScroll: true })
        });
    }, []);

    const handleRestore = useCallback((user) => {
        setConfirmState({
            show: true,
            title: 'Restaurar Usuário',
            message: `Deseja restaurar "${user.full_name}"?`,
            variant: 'success',
            action: () => router.get(`/panel/accesscontrol/users/${user.id}/restore`, {}, { preserveScroll: true })
        });
    }, []);

    const renderCard = (user) => (
        <div key={user.id} className="col-sm-6 col-md-4 col-xl-3">
            <div className={`card h-100 border-0 shadow-sm${user.deleted ? ' opacity-50' : ''}`}>
                <div className="card-body text-center p-3">
                    <img
                        src={user.photo_url}
                        alt={user.full_name}
                        className="rounded-circle mb-2"
                        width="64"
                        height="64"
                        style={{ objectFit: 'cover' }}
                    />
                    <h6 className="mb-1 fw-semibold">{user.full_name}</h6>
                    <p className="text-muted small mb-1">{user.email}</p>
                    <div className="mb-2">
                        <Badge variant="info">{user.rule_label}</Badge>
                    </div>
                    <StatusBadge active={user.active} deletedAt={user.deleted ? 'deleted' : null} />
                </div>
                <div className="card-footer bg-transparent border-top-0 text-center p-2">
                    {user.deleted ? (
                        <button className="btn btn-sm btn-outline-success" onClick={() => handleRestore(user)}>
                            <i className="ti ti-restore me-1"></i> Restaurar
                        </button>
                    ) : (
                        <div className="btn-group btn-group-sm">
                            <a href={`/panel/accesscontrol/users/${user.id}`} className="btn btn-outline-primary">
                                <i className="ti ti-eye"></i>
                            </a>
                            <button className="btn btn-outline-danger" onClick={() => handleDelete(user)} disabled={!user.own_entity}>
                                <i className="ti ti-trash"></i>
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );

    return (
        <AuthenticatedLayout title="Usuários">
            <FlashMessages />

            <PageHeader title="Usuários" subtitle="Controle de acesso">
                <button className="btn btn-primary btn-md fs-13" onClick={openCreate}>
                    <i className="ti ti-plus me-1"></i> Novo Usuário
                </button>
            </PageHeader>

            <CardGrid
                fetchUrl="/panel/accesscontrol/users/cards"
                renderCard={renderCard}
                emptyMessage="Nenhum usuário cadastrado."
                searchPlaceholder="Buscar por nome ou e-mail..."
            />

            {/* Modal Create */}
            <Modal
                show={showModal}
                onClose={() => setShowModal(false)}
                title="Novo Usuário"
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
                    <FormInput label="Nome" name="name" value={form.name} onChange={onChange} error={errors?.name} required autoFocus />
                    <FormInput label="E-mail" name="email" type="email" value={form.email} onChange={onChange} error={errors?.email} required />
                    <FormSelect label="Papel" name="rule" value={form.rule} onChange={onChange} options={roles} error={errors?.rule} required />
                    <FormInput label="Senha" name="password" type="password" value={form.password} onChange={onChange} error={errors?.password} required />
                    <FormInput label="Confirmar Senha" name="password_confirmation" type="password" value={form.password_confirmation} onChange={onChange} error={errors?.password_confirmation} required />
                </form>
            </Modal>

            <ConfirmDialog
                show={confirmState.show}
                onClose={() => setConfirmState((s) => ({ ...s, show: false }))}
                onConfirm={confirmState.action}
                title={confirmState.title}
                message={confirmState.message}
                variant={confirmState.variant}
            />
        </AuthenticatedLayout>
    );
}
