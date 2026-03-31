import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Expired({ lastSubscription, plans, supportEmail }) {
    return (
        <AuthenticatedLayout title="Assinatura Expirada">
            <div className="container py-4">

                {/* ── Banner de aviso ── */}
                <div className="alert alert-warning d-flex align-items-start gap-3 mb-4" role="alert">
                    <i className="fas fa-exclamation-triangle fa-2x mt-1 text-warning flex-shrink-0"></i>
                    <div>
                        <h5 className="alert-heading mb-1">Acesso ao sistema bloqueado</h5>
                        <p className="mb-0">
                            {lastSubscription?.in_grace_period
                                ? `Sua assinatura está em período de carência até ${lastSubscription.grace_period_ends_at}. Renove agora para não perder o acesso.`
                                : 'Sua assinatura expirou. Para continuar usando o sistema, escolha um plano abaixo.'}
                        </p>
                        {lastSubscription && (
                            <small className="text-muted">
                                Último plano:{' '}
                                <strong>{lastSubscription.plan_name}</strong>
                                {' '}— {lastSubscription.status_label}
                            </small>
                        )}
                    </div>
                </div>

                {/* ── Título ── */}
                <h4 className="mb-4 text-center">Escolha um plano para continuar</h4>

                {/* ── Cards de planos ── */}
                <div className="row row-cols-1 row-cols-md-3 g-4 mb-5">
                    {plans.map(plan => (
                        <div className="col" key={plan.id}>
                            <div className={`card h-100${plan.is_popular ? ' border-primary shadow' : ''}`}>
                                {plan.is_popular && (
                                    <div className="card-header bg-primary text-white text-center fw-bold py-1">
                                        <i className="fas fa-star me-1"></i> Mais popular
                                    </div>
                                )}
                                <div className="card-body d-flex flex-column">
                                    <h5 className="card-title">{plan.name}</h5>
                                    <p className="text-muted small mb-3">{plan.description}</p>

                                    <div className="mb-3">
                                        <span className="fs-3 fw-bold">R$ {plan.price}</span>
                                        <span className="text-muted">/ {plan.billing_cycle}</span>
                                    </div>

                                    <ul className="list-unstyled mb-4 flex-grow-1">
                                        {plan.features.map(f => (
                                            <li key={f.key} className="mb-1">
                                                {f.is_boolean ? (
                                                    <>
                                                        <i className={`fas fa-${f.value === '1' ? 'check-circle text-success' : 'times-circle text-danger'} me-1`}></i>
                                                        {f.label}
                                                    </>
                                                ) : (
                                                    <>
                                                        <i className="fas fa-check-circle text-success me-1"></i>
                                                        {f.label}:{' '}
                                                        <strong>
                                                            {f.value === '0' ? 'Ilimitado' : f.value}
                                                        </strong>
                                                    </>
                                                )}
                                            </li>
                                        ))}
                                    </ul>

                                    <a
                                        href={`mailto:${supportEmail}?subject=Assinatura%20${encodeURIComponent(plan.name)}`}
                                        className={`btn ${plan.is_popular ? 'btn-primary' : 'btn-outline-primary'} w-100`}
                                    >
                                        Contratar plano
                                    </a>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>

                {/* ── Footer ── */}
                <p className="text-center text-muted small">
                    Dúvidas? Entre em contato:{' '}
                    <a href={`mailto:${supportEmail}`}>{supportEmail}</a>
                </p>
            </div>
        </AuthenticatedLayout>
    );
}
