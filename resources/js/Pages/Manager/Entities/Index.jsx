import { useState, useEffect, useCallback } from 'react';
import { router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import PageHeader from '@/Components/UI/PageHeader';
import Modal from '@/Components/UI/Modal';
import ConfirmDialog from '@/Components/UI/ConfirmDialog';
import FormInput, { FormSwitch } from '@/Components/UI/FormInput';
import Badge, { StatusBadge } from '@/Components/UI/Badge';
import Pagination from '@/Components/UI/Pagination';

const emptyForm = {
    name: '', subdomain: '', email: '', telephone: '', cellphone: '',
    national_registration: '', state_registration: '', municipal_registration: '',
    website: '', schedule_interval: 15, active: true,
    zipcode: '', address: '', number: '', complement: '',
    district: '', city: '', state: '', country: 'Brasil',
};

function useEntityList() {
    const [data, setData] = useState([]);
    const [meta, setMeta] = useState(null);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [refreshKey, setRefreshKey] = useState(0);

    const fetchData = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ page, search });
            const res = await fetch(`/panel/manager/entities/list?${params}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            setData(json.data || []);
            setMeta(json.meta || null);
        } catch (err) {
            console.error('Error fetching entities:', err);
        } finally {
            setLoading(false);
        }
    }, [page, search, refreshKey]);

    useEffect(() => {
        const timer = setTimeout(fetchData, search ? 300 : 0);
        return () => clearTimeout(timer);
    }, [fetchData]);

    useEffect(() => { setPage(1); }, [search]);

    return { data, meta, search, setSearch, page, setPage, loading, refresh: () => setRefreshKey((k) => k + 1) };
}

export default function EntitiesIndex() {
    const { data, meta, search, setSearch, page, setPage, loading, refresh } = useEntityList();

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

    const openEdit = useCallback(async (entity) => {
        setEditing(entity);
        setErrors({});
        try {
            const res = await fetch(`/panel/manager/entities/${entity.id}/edit-data`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            setForm({ ...emptyForm, ...json.data });
            setShowModal(true);
        } catch {
            console.error('Erro ao carregar dados da entidade');
        }
    }, []);

    const handleSubmit = async (e) => {
        e?.preventDefault();
        setProcessing(true);
        setErrors({});

        const url     = editing ? `/panel/manager/entities/${editing.id}` : '/panel/manager/entities';
        const method  = editing ? 'put' : 'post';
        const csrf    = document.querySelector('meta[name="csrf-token"]')?.content;

        try {
            const res = await fetch(url, {
                method: method.toUpperCase(),
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(form),
            });

            const json = await res.json();

            if (!res.ok) {
                setErrors(json.errors || {});
                return;
            }

            setShowModal(false);
            setEditing(null);
            refresh();
        } finally {
            setProcessing(false);
        }
    };

    const handleDelete = useCallback((entity) => {
        setConfirmState({
            show: true,
            title: 'Excluir Empresa',
            message: `Deseja realmente excluir "${entity.name}"?`,
            variant: 'danger',
            action: async () => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                await fetch(`/panel/manager/entities/${entity.id}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                refresh();
            },
        });
    }, [refresh]);

    const handleRestore = useCallback((entity) => {
        setConfirmState({
            show: true,
            title: 'Restaurar Empresa',
            message: `Deseja restaurar "${entity.name}"?`,
            variant: 'success',
            action: async () => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                await fetch(`/panel/manager/entities/${entity.id}/restore`, {
                    method: 'PUT',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                refresh();
            },
        });
    }, [refresh]);

    return (
        <AuthenticatedLayout title="Empresas">
            <FlashMessages />

            <PageHeader title="Empresas" subtitle="Gestão de clínicas e entidades">
                <button className="btn btn-primary btn-md fs-13" onClick={openCreate}>
                    <i className="ti ti-plus me-1"></i> Nova Empresa
                </button>
            </PageHeader>

            <div className="card border-0 shadow-sm">
                <div className="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <div className="input-group input-group-sm" style={{ maxWidth: 300 }}>
                        <span className="input-group-text bg-white">
                            <i className="ti ti-search fs-12"></i>
                        </span>
                        <input
                            type="text"
                            className="form-control border-start-0"
                            placeholder="Buscar por nome, código ou cidade..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        {search && (
                            <button className="btn btn-outline-secondary" onClick={() => setSearch('')}>
                                <i className="ti ti-x fs-12"></i>
                            </button>
                        )}
                    </div>
                    {meta && <span className="text-muted small">{meta.total} empresa{meta.total !== 1 ? 's' : ''}</span>}
                </div>
                <div className="card-body p-0">
                    {loading ? (
                        <div className="text-center py-5">
                            <div className="spinner-border text-primary" role="status">
                                <span className="visually-hidden">Carregando...</span>
                            </div>
                        </div>
                    ) : data.length === 0 ? (
                        <div className="text-center text-muted py-5">
                            <i className="ti ti-building-off fs-1 d-block mb-2"></i>
                            Nenhuma empresa encontrada.
                        </div>
                    ) : (
                        <div className="table-responsive">
                            <table className="table table-hover table-nowrap mb-0">
                                <thead className="table-light">
                                    <tr>
                                        <th>Código</th>
                                        <th>Nome</th>
                                        <th>E-mail</th>
                                        <th>Localidade</th>
                                        <th>Tipo</th>
                                        <th>Status</th>
                                        <th className="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.map((entity) => (
                                        <tr key={entity.id} className={entity.deleted ? 'opacity-50' : ''}>
                                            <td><code className="small">{entity.code}</code></td>
                                            <td className="fw-semibold">{entity.name}</td>
                                            <td className="text-muted small">{entity.email || '—'}</td>
                                            <td className="text-muted small">
                                                {[entity.city, entity.state].filter(Boolean).join(' / ') || '—'}
                                            </td>
                                            <td>
                                                <Badge variant={entity.is_client ? 'primary' : 'secondary'}>
                                                    {entity.is_client ? 'Cliente' : 'Sistema'}
                                                </Badge>
                                            </td>
                                            <td>
                                                <StatusBadge active={entity.active} deletedAt={entity.deleted ? 'deleted' : null} />
                                            </td>
                                            <td className="text-end">
                                                {entity.deleted ? (
                                                    <button
                                                        className="btn btn-sm btn-outline-success"
                                                        onClick={() => handleRestore(entity)}
                                                        title="Restaurar"
                                                    >
                                                        <i className="ti ti-restore"></i>
                                                    </button>
                                                ) : (
                                                    <div className="btn-group btn-group-sm">
                                                        <a
                                                            href={`/panel/manager/entities/${entity.id}`}
                                                            className="btn btn-outline-primary"
                                                            title="Ver detalhes"
                                                        >
                                                            <i className="ti ti-eye"></i>
                                                        </a>
                                                        <button
                                                            className="btn btn-outline-secondary"
                                                            onClick={() => openEdit(entity)}
                                                            title="Editar"
                                                        >
                                                            <i className="ti ti-pencil"></i>
                                                        </button>
                                                        <a
                                                            href={`/panel/manager/subscriptions?entity=${entity.id}`}
                                                            className="btn btn-outline-info"
                                                            title="Assinatura"
                                                        >
                                                            <i className="ti ti-credit-card"></i>
                                                        </a>
                                                        <button
                                                            className="btn btn-outline-danger"
                                                            onClick={() => handleDelete(entity)}
                                                            title="Excluir"
                                                        >
                                                            <i className="ti ti-trash"></i>
                                                        </button>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
                {meta && (
                    <div className="card-footer">
                        <Pagination meta={meta} onPageChange={setPage} />
                    </div>
                )}
            </div>

            {/* Modal Create/Edit */}
            <Modal
                show={showModal}
                onClose={() => setShowModal(false)}
                title={editing ? `Editar: ${editing.name}` : 'Nova Empresa'}
                size="lg"
                footer={
                    <>
                        <button className="btn btn-secondary" onClick={() => setShowModal(false)}>Cancelar</button>
                        <button className="btn btn-primary" onClick={handleSubmit} disabled={processing}>
                            {processing
                                ? <><span className="spinner-border spinner-border-sm me-1"></span> Salvando...</>
                                : <><i className="ti ti-check me-1"></i> Salvar</>
                            }
                        </button>
                    </>
                }
            >
                <form onSubmit={handleSubmit}>
                    <h6 className="fw-semibold mb-3 text-primary">
                        <i className="ti ti-building me-1"></i> Dados da Empresa
                    </h6>
                    <div className="row">
                        <div className="col-md-8">
                            <FormInput label="Nome *" name="name" value={form.name} onChange={onChange} error={errors?.name} required autoFocus />
                        </div>
                        <div className="col-md-4">
                            <FormInput label="Subdomínio" name="subdomain" value={form.subdomain} onChange={onChange} error={errors?.subdomain} />
                        </div>
                    </div>
                    <div className="row">
                        <div className="col-md-6">
                            <FormInput label="E-mail" name="email" type="email" value={form.email} onChange={onChange} error={errors?.email} />
                        </div>
                        <div className="col-md-3">
                            <FormInput label="Telefone" name="telephone" value={form.telephone} onChange={onChange} error={errors?.telephone} />
                        </div>
                        <div className="col-md-3">
                            <FormInput label="Celular" name="cellphone" value={form.cellphone} onChange={onChange} error={errors?.cellphone} />
                        </div>
                    </div>
                    <div className="row">
                        <div className="col-md-4">
                            <FormInput label="CNPJ" name="national_registration" value={form.national_registration} onChange={onChange} error={errors?.national_registration} />
                        </div>
                        <div className="col-md-4">
                            <FormInput label="Insc. Estadual" name="state_registration" value={form.state_registration} onChange={onChange} />
                        </div>
                        <div className="col-md-4">
                            <FormInput label="Insc. Municipal" name="municipal_registration" value={form.municipal_registration} onChange={onChange} />
                        </div>
                    </div>
                    <div className="row">
                        <div className="col-md-6">
                            <FormInput label="Website" name="website" value={form.website} onChange={onChange} />
                        </div>
                        <div className="col-md-3">
                            <FormInput label="Intervalo de Agenda (min)" name="schedule_interval" type="number" value={form.schedule_interval} onChange={onChange} />
                        </div>
                        <div className="col-md-3 d-flex align-items-end pb-2">
                            <FormSwitch label="Ativo" name="active" checked={form.active} onChange={onChange} />
                        </div>
                    </div>

                    <h6 className="fw-semibold mb-3 mt-4 text-primary">
                        <i className="ti ti-map-pin me-1"></i> Endereço
                    </h6>
                    <div className="row">
                        <div className="col-md-3">
                            <FormInput label="CEP" name="zipcode" value={form.zipcode} onChange={onChange} />
                        </div>
                        <div className="col-md-7">
                            <FormInput label="Endereço" name="address" value={form.address} onChange={onChange} />
                        </div>
                        <div className="col-md-2">
                            <FormInput label="Número" name="number" value={form.number} onChange={onChange} />
                        </div>
                    </div>
                    <div className="row">
                        <div className="col-md-4">
                            <FormInput label="Complemento" name="complement" value={form.complement} onChange={onChange} />
                        </div>
                        <div className="col-md-4">
                            <FormInput label="Bairro" name="district" value={form.district} onChange={onChange} />
                        </div>
                        <div className="col-md-4">
                            <FormInput label="Cidade" name="city" value={form.city} onChange={onChange} />
                        </div>
                    </div>
                    <div className="row">
                        <div className="col-md-3">
                            <FormInput label="UF" name="state" value={form.state} onChange={onChange} maxLength={2} />
                        </div>
                        <div className="col-md-4">
                            <FormInput label="País" name="country" value={form.country} onChange={onChange} />
                        </div>
                    </div>
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
