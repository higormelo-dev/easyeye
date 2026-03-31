import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';

export default function SelectEntity({ entities }) {
    const { errors } = usePage().props;
    const [selected, setSelected] = useState('');
    const [processing, setProcessing] = useState(false);

    const handleSubmit = (e) => {
        e.preventDefault();
        setProcessing(true);
        router.post('/select-entity', { entity_user_id: selected }, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <GuestLayout title="Selecionar Clínica">
            <h2 className="fw-bold mb-1">Selecione a Clínica</h2>
            <p className="text-muted mb-4">Você tem acesso a mais de uma clínica. Escolha em qual deseja operar.</p>

            {errors?.entity_user_id && (
                <div className="alert alert-danger py-2 small">{errors.entity_user_id}</div>
            )}

            <form onSubmit={handleSubmit}>
                <div className="list-group mb-4">
                    {entities.map((entity) => (
                        <label
                            key={entity.id}
                            className={`list-group-item list-group-item-action d-flex align-items-center gap-3 ${selected === entity.id ? 'active' : ''}`}
                            style={{ cursor: 'pointer' }}
                        >
                            <input
                                type="radio"
                                name="entity_user_id"
                                value={entity.id}
                                checked={selected === entity.id}
                                onChange={() => setSelected(entity.id)}
                                className="form-check-input mt-0"
                            />
                            <div>
                                <strong>{entity.entity_name || entity.name}</strong>
                                {entity.code && <span className="d-block text-muted small">{entity.code}</span>}
                                {entity.rule_label && <span className="badge bg-info-light text-info ms-2">{entity.rule_label}</span>}
                            </div>
                        </label>
                    ))}
                </div>

                <button type="submit" className="btn btn-primary w-100" disabled={processing || !selected}>
                    {processing ? <><span className="spinner-border spinner-border-sm me-1"></span> Entrando...</> : 'Continuar'}
                </button>
            </form>
        </GuestLayout>
    );
}
