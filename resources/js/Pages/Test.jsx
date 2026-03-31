/**
 * Página de teste — Renderiza dentro do AuthenticatedLayout (Preclinic)
 * para validar que sidebar, header, e CSS estão funcionando corretamente.
 *
 * Rota: /react-test
 * Remover após validação completa da Fase 2.
 */
import { usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Test() {
    const { auth, entity, appName, locale, flash } = usePage().props;

    return (
        <AuthenticatedLayout title="React Test">
            {/* Breadcrumb */}
            <div className="page-header">
                <div className="row">
                    <div className="col-sm-12">
                        <ul className="breadcrumb">
                            <li className="breadcrumb-item">
                                <a href="/panel/dashboard">Dashboard</a>
                            </li>
                            <li className="breadcrumb-item active">React Test</li>
                        </ul>
                    </div>
                </div>
            </div>

            {/* Card com dados */}
            <div className="row">
                <div className="col-xl-6">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title d-flex align-items-center gap-2">
                                <span style={{ fontSize: 28 }}>🎉</span>
                                React + Inertia.js — Funcionando!
                            </h4>
                        </div>
                        <div className="card-body">
                            <p className="text-muted mb-4">
                                Esta página React está renderizando dentro do layout Preclinic,
                                com sidebar e header, navegação via Inertia.js (SPA-like) e dados
                                compartilhados pelo Laravel.
                            </p>
                            <table className="table table-bordered">
                                <thead className="table-light">
                                    <tr>
                                        <th>Prop (Shared)</th>
                                        <th>Valor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>App Name</strong></td>
                                        <td><span className="badge bg-primary">{appName}</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Locale</strong></td>
                                        <td><span className="badge bg-info">{locale}</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Usuário</strong></td>
                                        <td>{auth?.user?.name ?? <span className="text-muted">Não autenticado</span>}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>E-mail</strong></td>
                                        <td>{auth?.user?.email ?? '—'}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Entidade</strong></td>
                                        <td>{entity?.name ?? <span className="text-muted">Nenhuma selecionada</span>}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Flash Success</strong></td>
                                        <td>{flash?.success ?? '—'}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div className="col-xl-6">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">✅ Checklist da Migração</h4>
                        </div>
                        <div className="card-body">
                            <ul className="list-group">
                                <li className="list-group-item d-flex justify-content-between align-items-center">
                                    Inertia.js (Backend)
                                    <span className="badge bg-success">OK</span>
                                </li>
                                <li className="list-group-item d-flex justify-content-between align-items-center">
                                    React + Vite (Frontend)
                                    <span className="badge bg-success">OK</span>
                                </li>
                                <li className="list-group-item d-flex justify-content-between align-items-center">
                                    Shared Props (auth, entity, flash)
                                    <span className="badge bg-success">OK</span>
                                </li>
                                <li className="list-group-item d-flex justify-content-between align-items-center">
                                    Layout Preclinic (Sidebar + Header)
                                    <span className="badge bg-success">OK</span>
                                </li>
                                <li className="list-group-item d-flex justify-content-between align-items-center">
                                    CSS Preclinic (Bootstrap + Custom)
                                    <span className="badge bg-success">OK</span>
                                </li>
                                <li className="list-group-item d-flex justify-content-between align-items-center">
                                    Navegação SPA (Inertia Link)
                                    <span className="badge bg-success">OK</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
