/**
 * SettingsCrud — Componente genérico reutilizável para todos os CRUDs de Settings.
 *
 * Este componente substitui o fluxo completo de:
 * - BaseSettingController (backend DataTable rendering)
 * - system/settings/index.blade.php (Alpine + DataTable)
 * - system/settings/_form-modal.blade.php
 *
 * É reutilizado por todos os tipos (SkinTypes, IrisTypes, etc.)
 * através de props configuráveis.
 */
import { useState, useCallback } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function SettingsCrud({ title, records, baseUrl, columns }) {
    const { flash } = usePage().props;
    const [search, setSearch] = useState('');
    const [showModal, setShowModal] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState({ name: '', active: true });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    // Filtro local de busca
    const filtered = records.filter((r) => {
        if (!search) return true;
        const q = search.toLowerCase();
        return (r.name || '').toLowerCase().includes(q);
    });

    const openCreate = () => {
        setEditing(null);
        setForm({ name: '', active: true });
        setErrors({});
        setShowModal(true);
    };

    const openEdit = (record) => {
        setEditing(record);
        setForm({ name: record.name, active: record.active ?? true });
        setErrors({});
        setShowModal(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        const url = editing
            ? `${baseUrl}/${editing.id}`
            : baseUrl;

        const method = editing ? 'put' : 'post';

        router[method](url, form, {
            preserveScroll: true,
            onSuccess: () => {
                setShowModal(false);
                setEditing(null);
                setForm({ name: '', active: true });
            },
            onError: (errs) => setErrors(errs),
            onFinish: () => setProcessing(false),
        });
    };

    const handleDelete = useCallback((record) => {
        if (!confirm(`Deseja realmente excluir "${record.name}"?`)) return;

        router.delete(`${baseUrl}/${record.id}`, {
            preserveScroll: true,
        });
    }, [baseUrl]);

    const handleRestore = useCallback((record) => {
        if (!confirm(`Deseja restaurar "${record.name}"?`)) return;

        router.get(`${baseUrl}/${record.id}/restore`, {}, {
            preserveScroll: true,
        });
    }, [baseUrl]);

    return (
        <AuthenticatedLayout title={title}>
            {/* Flash messages */}
            {flash?.success && (
                <div className="alert alert-success alert-dismissible fade show" role="alert">
                    {flash.success}
                    <button type="button" className="btn-close" data-bs-dismiss="alert"></button>
                </div>
            )}

            {/* Page Header */}
            <div className="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-3 border-1 border-bottom">
                <div className="flex-grow-1">
                    <h4 className="fw-bold mb-0">{title}</h4>
                </div>
                <div className="d-flex align-items-center gap-2">
                    <button
                        type="button"
                        className="btn btn-primary fs-13 btn-md"
                        onClick={openCreate}
                    >
                        <i className="ti ti-plus me-1"></i> Novo
                    </button>
                </div>
            </div>

            {/* Search Bar */}
            <div className="d-flex align-items-center justify-content-between flex-wrap mb-3">
                <div className="search-set">
                    <div className="table-search d-flex align-items-center mb-0">
                        <div className="search-input">
                            <div className="input-group input-group-sm">
                                <span className="input-group-text bg-white">
                                    <i className="ti ti-search fs-12"></i>
                                </span>
                                <input
                                    type="text"
                                    className="form-control border-start-0"
                                    placeholder="Pesquisar..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                                {search && (
                                    <button
                                        className="btn btn-outline-secondary border-start-0"
                                        onClick={() => setSearch('')}
                                    >
                                        <i className="ti ti-x fs-12"></i>
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
                <span className="text-muted small">
                    {filtered.length} registro{filtered.length !== 1 ? 's' : ''}
                </span>
            </div>

            {/* Table */}
            <div className="table-responsive">
                <table className="table table-nowrap table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            {(columns || ['name']).map((col) => (
                                <th key={col} className="text-capitalize">{col === 'name' ? 'Nome' : col}</th>
                            ))}
                            <th>Status</th>
                            <th className="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filtered.length === 0 ? (
                            <tr>
                                <td colSpan={4 + (columns || ['name']).length} className="text-center text-muted py-4">
                                    Nenhum registro encontrado.
                                </td>
                            </tr>
                        ) : (
                            filtered.map((record, i) => (
                                <tr key={record.id} className={record.deleted_at ? 'table-warning opacity-50' : ''}>
                                    <td className="text-muted">{i + 1}</td>
                                    {(columns || ['name']).map((col) => (
                                        <td key={col}>{record[col] ?? '—'}</td>
                                    ))}
                                    <td>
                                        {record.deleted_at ? (
                                            <span className="badge bg-danger-light text-danger">Inativo</span>
                                        ) : record.active !== false ? (
                                            <span className="badge bg-success-light text-success">Ativo</span>
                                        ) : (
                                            <span className="badge bg-warning-light text-warning">Inativo</span>
                                        )}
                                    </td>
                                    <td className="text-end">
                                        {record.deleted_at ? (
                                            <button
                                                className="btn btn-xs btn-outline-success"
                                                onClick={() => handleRestore(record)}
                                            >
                                                <i className="ti ti-restore me-1"></i> Restaurar
                                            </button>
                                        ) : (
                                            <div className="d-flex gap-1 justify-content-end">
                                                <button
                                                    className="btn btn-xs btn-outline-primary"
                                                    onClick={() => openEdit(record)}
                                                >
                                                    <i className="ti ti-pencil"></i>
                                                </button>
                                                <button
                                                    className="btn btn-xs btn-outline-danger"
                                                    onClick={() => handleDelete(record)}
                                                >
                                                    <i className="ti ti-trash"></i>
                                                </button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {/* ═══ Modal Create/Edit ═══ */}
            {showModal && (
                <div className="modal fade show d-block" style={{ background: 'rgba(0,0,0,0.5)' }}>
                    <div className="modal-dialog modal-dialog-centered">
                        <div className="modal-content">
                            <form onSubmit={handleSubmit}>
                                <div className="modal-header">
                                    <h5 className="modal-title">
                                        {editing ? `Editar ${title}` : `Novo ${title}`}
                                    </h5>
                                    <button
                                        type="button"
                                        className="btn-close"
                                        onClick={() => setShowModal(false)}
                                    ></button>
                                </div>
                                <div className="modal-body">
                                    <div className="mb-3">
                                        <label className="form-label">Nome</label>
                                        <input
                                            type="text"
                                            className={`form-control${errors.name ? ' is-invalid' : ''}`}
                                            value={form.name}
                                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                                            autoFocus
                                            required
                                        />
                                        {errors.name && (
                                            <div className="invalid-feedback">{errors.name}</div>
                                        )}
                                    </div>
                                    <div className="form-check form-switch">
                                        <input
                                            className="form-check-input"
                                            type="checkbox"
                                            checked={form.active}
                                            onChange={(e) => setForm({ ...form, active: e.target.checked })}
                                            id="activeSwitch"
                                        />
                                        <label className="form-check-label" htmlFor="activeSwitch">
                                            Ativo
                                        </label>
                                    </div>
                                </div>
                                <div className="modal-footer">
                                    <button
                                        type="button"
                                        className="btn btn-secondary"
                                        onClick={() => setShowModal(false)}
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        type="submit"
                                        className="btn btn-primary"
                                        disabled={processing}
                                    >
                                        {processing ? (
                                            <><span className="spinner-border spinner-border-sm me-1"></span> Salvando...</>
                                        ) : (
                                            <><i className="ti ti-check me-1"></i> Salvar</>
                                        )}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
