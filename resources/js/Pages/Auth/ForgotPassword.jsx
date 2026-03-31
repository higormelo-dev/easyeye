import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';

export default function ForgotPassword() {
    const { errors, flash } = usePage().props;
    const [email, setEmail] = useState('');
    const [processing, setProcessing] = useState(false);

    const handleSubmit = (e) => {
        e.preventDefault();
        setProcessing(true);
        router.post('/forgot-password', { email }, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <GuestLayout title="Recuperar Senha">
            <h2 className="fw-bold mb-1">Esqueceu a senha?</h2>
            <p className="text-muted mb-4">
                Digite seu e-mail e enviaremos um link para redefinir sua senha.
            </p>

            {flash?.status && (
                <div className="alert alert-success py-2 small">{flash.status}</div>
            )}

            <form onSubmit={handleSubmit}>
                <div className="mb-3">
                    <label className="form-label">E-mail</label>
                    <input
                        type="email"
                        className={`form-control${errors?.email ? ' is-invalid' : ''}`}
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        autoFocus
                        required
                    />
                    {errors?.email && <div className="invalid-feedback">{errors.email}</div>}
                </div>

                <button type="submit" className="btn btn-primary w-100 mb-3" disabled={processing}>
                    {processing ? <><span className="spinner-border spinner-border-sm me-1"></span> Enviando...</> : 'Enviar Link de Redefinição'}
                </button>

                <p className="text-center">
                    <Link href="/login" className="text-muted small">
                        <i className="ti ti-arrow-left me-1"></i> Voltar ao login
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
