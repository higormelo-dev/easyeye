import { useState, useEffect, useCallback } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import PageHeader from '@/Components/UI/PageHeader';
import Modal from '@/Components/UI/Modal';
import ConfirmDialog from '@/Components/UI/ConfirmDialog';
import FormInput, { FormSelect, FormSwitch } from '@/Components/UI/FormInput';
import Badge, { StatusBadge } from '@/Components/UI/Badge';
import Pagination from '@/Components/UI/Pagination';

const emptyForm = {
    name: '', description: '', price: 0, billing_cycle: 'monthly',
    sort_order: 0, active: true, features: {},
};

function usePlanList(refreshKey) {
    const [data, setData] = useState([]);
    const [meta, setMeta] = useState(null);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);

    const fetchData = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ page, search });
            const res = await fetch(`/panel/manager/plans/list?${params}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            setData(json.data || []);
            setMeta(json.meta || null);
        } catch (err) {
            console.error('Error fetching plans:', err);
        } finally {
            setLoading(false);
        }
    }, [page, search, refreshKey]);

    useEffect(() => {
        const timer = setTimeout(fetchData, search ? 300 : 0);
        return () => clearTimeout(timer);
    }, [fetchData]);

    useEffect(() => { setPage(1); }, [search]);

    return { data, meta, search, setSearch, page, setPage, loading };
}

export default function PlansIndex({ featureKeys, billingCycles }) {
    const [refreshKey, setRefreshKey] = useState(0);
    const refresh = () => setRefreshKey((k) => k + 1);

    const { data, meta, search, setSearch, page, setPage, loading } = usePlanList(refreshKey);

    const [showModal, setShowModal] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState({ ...emptyForm });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [confirmState, setConfirmState] = useState({ show: false, action: null, message: '', title: '' });

    const billingOptions = Object.fromEntries(billingCycles.map((c) => [c.value, c.label]));

    const onChange = (name, value) => setForm((f) => ({ ...f, [name]: value }));
    const onFeatureChange = (key, value) => setForm((f) => ({
        ...f,
        features: { ...f.features, [key]: value },
    }));

    const openCreate = () => {
        const defaultFeatures = Object.fromEntries(featureKeys.map((f) => [f.key, f.is_boolean ? '0' : '0']));
        setEditing(null);
        setForm({ ...emptyForm, features: defaultFeatures });
        setErrors({});
        setShowModal(true);
    };

    const openEdit = useCallback(async (plan) => {
        setEditing(plan);
        setErrors({});
        const defaultFeatures = Object.fromEntries(featureKeys.map((f) => [f.key, '0']));
        setForm({
            name: plan.name,
            description: plan.description || '',
            price: plan.price,
            billing_cycle: plan.billing_cycle,
            sort_order: plan.sort_order,
            active: plan.active,
            features: { ...defaultFeatures, ...plan.features },
        });
        setShowModal(true);
    }, [featureKeys]);

    const handleSubmit = async (e) => {
        e?.preventDefault();
        setProcessing(true);
        setErrors({});

        const url    = editing ? `/panel/manager/plans/${editing.id}` : '/panel/manager/plans';
        const method = editing ? 'PUT' : 'POST';
        const csrf   = document.querySelector('meta[name="csrf-token"]')?.content;

        try {
            const res = await fetch(url, {
                method,
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

    const handleToggleActive = useCallback(async (plan) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        await fetch(`/panel/manager/plans/${plan.id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ active: !plan.active }),
        });
        refresh();
    }, [refresh]);

    const handleDelete = useCallback((plan) => {
        setConfirmState({
            show: true,
            title: 'Excluir Plano',
            message: `Deseja realmente excluir o plano "${plan.name}"?`,
            action: async () => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                await fetch(`/panel/manager/plans/${plan.id}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                refresh();
            },
        });
    }, [refresh]);

    const formatPrice = (price) =>
        Number(price).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

    return (
        <AuthenticatedLayout title="Planos">
            <FlashMessages />

            <PageHeader title="Planos" subtitle="Gestão de planos de assinatura">
                <button className="btn btn-primary btn-md fs-13" onClick={openCreate}>
                    <i className="ti ti-plus me-1"></i> Novo Plano
                </button>
            </PageHeader>

            <div className="card border-0 shadow-sm">
                <div className="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <div className="input-group input-group-sm" style={{ maxWidth: 280 }}>
                        <span className="input-group-text bg-white">
                            <i className="ti ti-search fs-12"></i>
                        </span>
                        <input
                            type="text"
                            className="form-control border-start-0"
                            placeholder="Buscar planos..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        {search && (
                            <button className="btn btn-outline-secondary" onClick={() => setSearch('')}>
                                <i className="ti ti-x fs-12"></i>
                            </button>
                        )}
                    </div>
                    {meta && <span className="text-muted small">{meta.total} plano{meta.total !== 1 ? 's' : ''}</span>}
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
                            <i className="ti ti-packages-off fs-1 d-block mb-2"></i>
                            Nenhum plano encontrado.
                        </div>
                    ) : (
                        <div className="table-responsive">
                            <table className="table table-hover table-nowrap mb-0">
                                <thead className="table-light">
                                    <tr>
                                        <th>Nome</th>
                                        <th>Ciclo</th>
                                        <th>Preço</th>
                                        <th>Ordem</th>
                                        <th>Status</th>
                                        <th className="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.map((plan) => (
                                        <tr key={plan.id}>
                                            <td>
                                                <div className="fw-semibold">{plan.name}</div>
                                                {plan.description && (
                                                    <div className="text-muted small">{plan.description}</div>
                                                )}
                                            </td>
                                            <td>
                                                <Badge variant="secondary">{plan.billing_label}</Badge>
                                            </td>
                                            <td className="fw-semibold text-success">{formatPrice(plan.price)}</td>
                                            <td className="text-muted">{plan.sort_order}</td>
                                            <td>
                                                <button
                                                    className={`btn btn-sm ${plan.active ? 'btn-success' : 'btn-outline-secondary'}`}
                                                    onClick={() => handleToggleActive(plan)}
                                                    title={plan.active ? 'Clique para desativar' : 'Clique para ativar'}
                                                >
                                                    {plan.active ? 'Ativo' : 'Inativo'}
                                                </button>
                                            </td>
                                            <td className="text-end">
                                                <div className="btn-group btn-group-sm">
                                                    <button
                                                        className="btn btn-outline-secondary"
                                                        onClick={() => openEdit(plan)}
                                                        title="Editar"
                                                    >
                                                        <i className="ti ti-pencil"></i>
                                                    </button>
                                                    <button
                                                        className="btn btn-outline-danger"
                                                        onClick={() => handleDelete(plan)}
                                                        title="Excluir"
                                                    >
                                                        <i className="ti ti-trash"></i>
                                                    </button>
                                                </div>
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
                title={editing ? `Editar Plano: ${editing.name}` : 'Novo Plano'}
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
                        <i className="ti ti-package me-1"></i> Dados do Plano
                    </h6>
                    <div className="row">
                        <div className="col-md-6">
                            <FormInput label="Nome *" name="name" value={form.name} onChange={onChange} error={errors?.name} required autoFocus />
                        </div>
                        <div className="col-md-3">
                            <FormSelect
                                label="Ciclo de Cobrança *"
                                name="billing_cycle"
                                value={form.billing_cycle}
                                onChange={onChange}
                                options={billingOptions}
                                error={errors?.billing_cycle}
                            />
                        </div>
                        <div className="col-md-3">
                            <FormInput label="Preço (R$)" name="price" type="number" step="0.01" min="0" value={form.price} onChange={onChange} error={errors?.price} />
                        </div>
                    </div>
                    <div className="row">
                        <div className="col-md-10">
                            <FormInput label="Descrição" name="description" value={form.description} onChange={onChange} />
                        </div>
                        <div className="col-md-2">
                            <FormInput label="Ordem" name="sort_order" type="number" min="0" value={form.sort_order} onChange={onChange} />
                        </div>
                    </div>
                    <div className="mb-3">
                        <FormSwitch label="Plano ativo" name="active" checked={form.active} onChange={onChange} />
                    </div>

                    <h6 className="fw-semibold mb-3 mt-4 text-primary">
                        <i className="ti ti-settings me-1"></i> Features / Limites
                    </h6>
                    <div className="row g-2">
                        {featureKeys.map((feature) => (
                            <div key={feature.key} className="col-md-6">
                                {feature.is_boolean ? (
                                    <div className="form-check form-switch py-2">
                                        <input
                                            className="form-check-input"
                                            type="checkbox"
                                            id={`feature_${feature.key}`}
                                            checked={form.features[feature.key] === '1'}
                                            onChange={(e) => onFeatureChange(feature.key, e.target.checked ? '1' : '0')}
                                        />
                                        <label className="form-check-label small" htmlFor={`feature_${feature.key}`}>
                                            {feature.label}
                                        </label>
                                    </div>
                                ) : (
                                    <div>
                                        <label className="form-label small mb-1">
                                            {feature.label}
                                            <span className="text-muted ms-1">(0 = ilimitado)</span>
                                        </label>
                                        <input
                                            type="number"
                                            min="0"
                                            className="form-control form-control-sm"
                                            value={form.features[feature.key] ?? '0'}
                                            onChange={(e) => onFeatureChange(feature.key, e.target.value)}
                                        />
                                    </div>
                                )}
                            </div>
                        ))}
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
