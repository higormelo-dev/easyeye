import { useState, useEffect, useCallback } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import PageHeader from '@/Components/UI/PageHeader';
import Modal from '@/Components/UI/Modal';
import ConfirmDialog from '@/Components/UI/ConfirmDialog';
import FormInput, { FormSelect } from '@/Components/UI/FormInput';
import Badge from '@/Components/UI/Badge';
import Pagination from '@/Components/UI/Pagination';

function useSubscriptionList(refreshKey, statusFilter) {
    const [data, setData] = useState([]);
    const [meta, setMeta] = useState(null);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);

    const fetchData = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ page, search });
            if (statusFilter) params.set('status', statusFilter);
            const res = await fetch(`/panel/manager/subscriptions/list?${params}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            setData(json.data || []);
            setMeta(json.meta || null);
        } catch (err) {
            console.error('Error fetching subscriptions:', err);
        } finally {
            setLoading(false);
        }
    }, [page, search, refreshKey, statusFilter]);

    useEffect(() => {
        const timer = setTimeout(fetchData, search ? 300 : 0);
        return () => clearTimeout(timer);
    }, [fetchData]);

    useEffect(() => { setPage(1); }, [search, statusFilter]);

    return { data, meta, search, setSearch, page, setPage, loading };
}

export default function SubscriptionsIndex({ plans, billingCycles, statuses, trialDays, graceDays }) {
    const [refreshKey, setRefreshKey] = useState(0);
    const refresh = () => setRefreshKey((k) => k + 1);

    const [statusFilter, setStatusFilter] = useState('');
    const { data, meta, search, setSearch, page, setPage, loading } = useSubscriptionList(refreshKey, statusFilter);

    // Edit subscription modal
    const [showEditModal, setShowEditModal] = useState(false);
    const [editingSub, setEditingSub] = useState(null);
    const [editForm, setEditForm] = useState({ plan_id: '', status: '', starts_at: '', ends_at: '', trial_ends_at: '' });
    const [editErrors, setEditErrors] = useState({});
    const [editProcessing, setEditProcessing] = useState(false);

    // Activate modal
    const [showActivateModal, setShowActivateModal] = useState(false);
    const [activateSub, setActivateSub] = useState(null);
    const [activateForm, setActivateForm] = useState({ plan_id: '', billing_cycle: 'monthly' });

    // Trial modal
    const [showTrialModal, setShowTrialModal] = useState(false);
    const [trialSub, setTrialSub] = useState(null);
    const [trialForm, setTrialForm] = useState({ plan_id: '', days: trialDays });

    // Confirm dialog
    const [confirmState, setConfirmState] = useState({ show: false, action: null, message: '', title: '', variant: 'danger' });

    const planOptions = Object.fromEntries([['', 'Selecione...'], ...plans.map((p) => [p.id, p.name])]);
    const billingOptions = Object.fromEntries(billingCycles.map((c) => [c.value, c.label]));
    const statusOptions = Object.fromEntries([['', 'Todos os status'], ...statuses.map((s) => [s.value, s.label])]);
    const statusEditOptions = Object.fromEntries(statuses.map((s) => [s.value, s.label]));

    const openEdit = useCallback(async (sub) => {
        setEditingSub(sub);
        setEditErrors({});
        try {
            const res = await fetch(`/panel/manager/subscriptions/${sub.id}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            const d = json.data;
            setEditForm({
                plan_id: d.plan_id || '',
                status: d.status || '',
                starts_at: d.starts_at || '',
                ends_at: d.ends_at || '',
                trial_ends_at: d.trial_ends_at || '',
            });
            setShowEditModal(true);
        } catch {
            console.error('Erro ao carregar assinatura');
        }
    }, []);

    const handleEditSubmit = async (e) => {
        e?.preventDefault();
        setEditProcessing(true);
        setEditErrors({});
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

        try {
            const res = await fetch(`/panel/manager/subscriptions/${editingSub.id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(editForm),
            });

            const json = await res.json();
            if (!res.ok) { setEditErrors(json.errors || {}); return; }

            setShowEditModal(false);
            refresh();
        } finally {
            setEditProcessing(false);
        }
    };

    const openActivate = useCallback((sub) => {
        setActivateSub(sub);
        setActivateForm({ plan_id: sub.plan_id || '', billing_cycle: 'monthly' });
        setShowActivateModal(true);
    }, []);

    const handleActivate = async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch('/panel/manager/subscriptions/activate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ entity_id: activateSub.entity_id, ...activateForm }),
        });
        if (res.ok) { setShowActivateModal(false); refresh(); }
    };

    const openTrial = useCallback((sub) => {
        setTrialSub(sub);
        setTrialForm({ plan_id: sub.plan_id || '', days: trialDays });
        setShowTrialModal(true);
    }, [trialDays]);

    const handleStartTrial = async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch('/panel/manager/subscriptions/trial', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ entity_id: trialSub.entity_id, ...trialForm }),
        });
        if (res.ok) { setShowTrialModal(false); refresh(); }
    };

    const handleCancel = useCallback((sub) => {
        setConfirmState({
            show: true,
            title: 'Cancelar Assinatura',
            message: `Deseja cancelar a assinatura de "${sub.entity_name}"?`,
            variant: 'danger',
            action: async () => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                await fetch('/panel/manager/subscriptions/cancel', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ entity_id: sub.entity_id }),
                });
                refresh();
            },
        });
    }, [refresh]);

    const handleBlockAccess = useCallback((sub) => {
        const blocking = sub.entity_active;
        setConfirmState({
            show: true,
            title: blocking ? 'Bloquear Acesso' : 'Liberar Acesso',
            message: blocking
                ? `Deseja bloquear o acesso de "${sub.entity_name}"?`
                : `Deseja liberar o acesso de "${sub.entity_name}"?`,
            variant: blocking ? 'danger' : 'success',
            action: async () => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                await fetch('/panel/manager/subscriptions/block-access', {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ entity_id: sub.entity_id, active: !blocking }),
                });
                refresh();
            },
        });
    }, [refresh]);

    return (
        <AuthenticatedLayout title="Assinaturas">
            <FlashMessages />

            <PageHeader title="Assinaturas" subtitle="Gestão de assinaturas e planos das clínicas" />

            <div className="card border-0 shadow-sm">
                <div className="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <div className="d-flex gap-2 flex-wrap">
                        <div className="input-group input-group-sm" style={{ maxWidth: 280 }}>
                            <span className="input-group-text bg-white">
                                <i className="ti ti-search fs-12"></i>
                            </span>
                            <input
                                type="text"
                                className="form-control border-start-0"
                                placeholder="Buscar por clínica..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            {search && (
                                <button className="btn btn-outline-secondary" onClick={() => setSearch('')}>
                                    <i className="ti ti-x fs-12"></i>
                                </button>
                            )}
                        </div>
                        <select
                            className="form-select form-select-sm"
                            style={{ maxWidth: 200 }}
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                        >
                            {Object.entries(statusOptions).map(([v, l]) => (
                                <option key={v} value={v}>{l}</option>
                            ))}
                        </select>
                    </div>
                    {meta && <span className="text-muted small">{meta.total} assinatura{meta.total !== 1 ? 's' : ''}</span>}
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
                            <i className="ti ti-credit-card-off fs-1 d-block mb-2"></i>
                            Nenhuma assinatura encontrada.
                        </div>
                    ) : (
                        <div className="table-responsive">
                            <table className="table table-hover table-nowrap mb-0">
                                <thead className="table-light">
                                    <tr>
                                        <th>Empresa</th>
                                        <th>Plano</th>
                                        <th>Status</th>
                                        <th>Início</th>
                                        <th>Fim / Trial</th>
                                        <th className="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.map((sub) => (
                                        <tr key={sub.id}>
                                            <td>
                                                <div className="fw-semibold">{sub.entity_name}</div>
                                                <div className="text-muted small">
                                                    <code>{sub.entity_code}</code>
                                                    {!sub.entity_active && (
                                                        <Badge variant="danger" className="ms-2">Bloqueado</Badge>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="text-muted">{sub.plan_name}</td>
                                            <td>
                                                <span className={`badge ${sub.status_badge}`}>
                                                    {sub.status_label}
                                                </span>
                                            </td>
                                            <td className="text-muted small">{sub.starts_at || '—'}</td>
                                            <td className="text-muted small">
                                                {sub.ends_at && <div>Fim: {sub.ends_at}</div>}
                                                {sub.trial_ends_at && <div className="text-info">Trial: {sub.trial_ends_at}</div>}
                                                {!sub.ends_at && !sub.trial_ends_at && '—'}
                                            </td>
                                            <td className="text-end">
                                                <div className="btn-group btn-group-sm">
                                                    <button
                                                        className="btn btn-outline-secondary"
                                                        onClick={() => openEdit(sub)}
                                                        title="Editar assinatura"
                                                    >
                                                        <i className="ti ti-pencil"></i>
                                                    </button>
                                                    <button
                                                        className="btn btn-outline-success"
                                                        onClick={() => openActivate(sub)}
                                                        title="Ativar plano"
                                                    >
                                                        <i className="ti ti-player-play"></i>
                                                    </button>
                                                    <button
                                                        className="btn btn-outline-info"
                                                        onClick={() => openTrial(sub)}
                                                        title="Iniciar trial"
                                                    >
                                                        <i className="ti ti-hourglass"></i>
                                                    </button>
                                                    <button
                                                        className={`btn ${sub.entity_active ? 'btn-outline-warning' : 'btn-outline-success'}`}
                                                        onClick={() => handleBlockAccess(sub)}
                                                        title={sub.entity_active ? 'Bloquear acesso' : 'Liberar acesso'}
                                                    >
                                                        <i className={`ti ${sub.entity_active ? 'ti-lock' : 'ti-lock-open'}`}></i>
                                                    </button>
                                                    <button
                                                        className="btn btn-outline-danger"
                                                        onClick={() => handleCancel(sub)}
                                                        title="Cancelar assinatura"
                                                    >
                                                        <i className="ti ti-ban"></i>
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

            {/* Modal Editar Assinatura */}
            <Modal
                show={showEditModal}
                onClose={() => setShowEditModal(false)}
                title={`Editar Assinatura: ${editingSub?.entity_name ?? ''}`}
                footer={
                    <>
                        <button className="btn btn-secondary" onClick={() => setShowEditModal(false)}>Cancelar</button>
                        <button className="btn btn-primary" onClick={handleEditSubmit} disabled={editProcessing}>
                            {editProcessing
                                ? <><span className="spinner-border spinner-border-sm me-1"></span> Salvando...</>
                                : <><i className="ti ti-check me-1"></i> Salvar</>
                            }
                        </button>
                    </>
                }
            >
                <form onSubmit={handleEditSubmit}>
                    <FormSelect
                        label="Plano"
                        name="plan_id"
                        value={editForm.plan_id}
                        onChange={(n, v) => setEditForm((f) => ({ ...f, [n]: v }))}
                        options={planOptions}
                        error={editErrors?.plan_id}
                    />
                    <FormSelect
                        label="Status"
                        name="status"
                        value={editForm.status}
                        onChange={(n, v) => setEditForm((f) => ({ ...f, [n]: v }))}
                        options={statusEditOptions}
                        error={editErrors?.status}
                    />
                    <div className="row">
                        <div className="col-md-4">
                            <FormInput label="Início" name="starts_at" type="date" value={editForm.starts_at} onChange={(n, v) => setEditForm((f) => ({ ...f, [n]: v }))} />
                        </div>
                        <div className="col-md-4">
                            <FormInput label="Fim" name="ends_at" type="date" value={editForm.ends_at} onChange={(n, v) => setEditForm((f) => ({ ...f, [n]: v }))} />
                        </div>
                        <div className="col-md-4">
                            <FormInput label="Fim do Trial" name="trial_ends_at" type="date" value={editForm.trial_ends_at} onChange={(n, v) => setEditForm((f) => ({ ...f, [n]: v }))} />
                        </div>
                    </div>
                </form>
            </Modal>

            {/* Modal Ativar Plano */}
            <Modal
                show={showActivateModal}
                onClose={() => setShowActivateModal(false)}
                title={`Ativar Plano: ${activateSub?.entity_name ?? ''}`}
                footer={
                    <>
                        <button className="btn btn-secondary" onClick={() => setShowActivateModal(false)}>Cancelar</button>
                        <button className="btn btn-success" onClick={handleActivate}>
                            <i className="ti ti-player-play me-1"></i> Ativar
                        </button>
                    </>
                }
            >
                <FormSelect
                    label="Plano *"
                    name="plan_id"
                    value={activateForm.plan_id}
                    onChange={(n, v) => setActivateForm((f) => ({ ...f, [n]: v }))}
                    options={planOptions}
                />
                <FormSelect
                    label="Ciclo de Cobrança *"
                    name="billing_cycle"
                    value={activateForm.billing_cycle}
                    onChange={(n, v) => setActivateForm((f) => ({ ...f, [n]: v }))}
                    options={billingOptions}
                />
            </Modal>

            {/* Modal Iniciar Trial */}
            <Modal
                show={showTrialModal}
                onClose={() => setShowTrialModal(false)}
                title={`Iniciar Trial: ${trialSub?.entity_name ?? ''}`}
                footer={
                    <>
                        <button className="btn btn-secondary" onClick={() => setShowTrialModal(false)}>Cancelar</button>
                        <button className="btn btn-info text-white" onClick={handleStartTrial}>
                            <i className="ti ti-hourglass me-1"></i> Iniciar Trial
                        </button>
                    </>
                }
            >
                <FormSelect
                    label="Plano (opcional)"
                    name="plan_id"
                    value={trialForm.plan_id}
                    onChange={(n, v) => setTrialForm((f) => ({ ...f, [n]: v }))}
                    options={planOptions}
                />
                <FormInput
                    label="Duração (dias)"
                    name="days"
                    type="number"
                    min="1"
                    max="365"
                    value={trialForm.days}
                    onChange={(n, v) => setTrialForm((f) => ({ ...f, [n]: v }))}
                />
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
