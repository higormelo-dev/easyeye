import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/UI/PageHeader';

export default function ManagerDashboard() {
    return (
        <AuthenticatedLayout title="Manager">
            <PageHeader title="Administração" subtitle="Painel SaaS global" />
            
            <div className="row mt-4">
                <div className="col-12">
                    <div className="card shadow-sm border-0">
                        <div className="card-body py-5 text-center">
                            <i className="ti ti-server-cog text-primary mb-3" style={{ fontSize: '3rem' }}></i>
                            <h2 className="fw-bold fs-4">Painel Gerenciador</h2>
                            <p className="text-muted">Gestão central de Inquilinos, Planos e Assinaturas.</p>
                            <div className="mt-4">
                                <a href="/panel/manager/entities" className="btn btn-outline-primary me-2">
                                    <i className="ti ti-building me-1"></i> Entidades
                                </a>
                                <a href="/panel/manager/plans" className="btn btn-outline-secondary">
                                    <i className="ti ti-currency-dollar me-1"></i> Planos
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
