import { Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Index() {
    return (
        <AuthenticatedLayout title="Relatórios">
            <nav aria-label="breadcrumb" className="mb-3">
                <ol className="breadcrumb mb-0">
                    <li className="breadcrumb-item">
                        <Link href="/panel/dashboard">Dashboard</Link>
                    </li>
                    <li className="breadcrumb-item active">Relatórios</li>
                </ol>
            </nav>

            <div className="row g-4">
                <div className="col-md-6">
                    <div className="card h-100 border-0 shadow-sm">
                        <div className="card-body d-flex flex-column align-items-start gap-3 p-4">
                            <div className="rounded-circle bg-info bg-opacity-10 p-3">
                                <i className="fas fa-chart-bar fa-2x text-info"></i>
                            </div>
                            <div>
                                <h5 className="card-title mb-1">Relatório de Produção</h5>
                                <p className="card-text text-muted small">
                                    Visualize o total de agendamentos por período, médico, convênio e situação.
                                    Inclui taxa de comparecimento e breakdown por médico.
                                </p>
                            </div>
                            <Link
                                href="/panel/reports/schedules"
                                className="btn btn-info btn-sm mt-auto"
                            >
                                <i className="fas fa-arrow-right me-1"></i> Acessar relatório
                            </Link>
                        </div>
                    </div>
                </div>

                <div className="col-md-6">
                    <div className="card h-100 border-0 shadow-sm">
                        <div className="card-body d-flex flex-column align-items-start gap-3 p-4">
                            <div className="rounded-circle bg-warning bg-opacity-10 p-3">
                                <i className="fas fa-user-times fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h5 className="card-title mb-1">Relatório de Absenteísmo</h5>
                                <p className="card-text text-muted small">
                                    Analise faltas e cancelamentos por período e médico.
                                    Identifique padrões e calcule a taxa de absenteísmo.
                                </p>
                            </div>
                            <Link
                                href="/panel/reports/absenteeism"
                                className="btn btn-warning btn-sm mt-auto"
                            >
                                <i className="fas fa-arrow-right me-1"></i> Acessar relatório
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
