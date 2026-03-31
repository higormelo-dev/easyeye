import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';

export default function ResetPassword({ token, email }) {
    const { errors } = usePage().props;
    const [form, setForm] = useState({
        token, email: email || '', password: '', password_confirmation: '',
    });
    const [processing, setProcessing] = useState(false);

    const handleSubmit = (e) => {
        e.preventDefault();
        setProcessing(true);
        router.post('/reset-password', form, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <GuestLayout title="Redefinir Senha">
            <h2 className="fw-bold mb-1">Redefinir Senha</h2>
            <p className="text-muted mb-4">Digite sua nova senha.</p>

            <form onSubmit={handleSubmit}>
                <div className="mb-3">
                    <label className="form-label">E-mail</label>
                    <input
                        type="email"
                        className={`form-control${errors?.email ? ' is-invalid' : ''}`}
                        value={form.email}
                        onChange={(e) => setForm({ ...form, email: e.target.value })}
                        required
                    />
                    {errors?.email && <div className="invalid-feedback">{errors.email}</div>}
                </div>

                <div className="mb-3">
                    <label className="form-label">Nova Senha</label>
                    <input
                        type="password"
                        className={`form-control${errors?.password ? ' is-invalid' : ''}`}
                        value={form.password}
                        onChange={(e) => setForm({ ...form, password: e.target.value })}
                        autoFocus
                        required
                    />
                    {errors?.password && <div className="invalid-feedback">{errors.password}</div>}
                </div>

                <div className="mb-3">
                    <label className="form-label">Confirmar Nova Senha</label>
                    <input
                        type="password"
                        className="form-control"
                        value={form.password_confirmation}
                        onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
                        required
                    />
                </div>

                <button type="submit" className="btn btn-primary w-100" disabled={processing}>
                    {processing ? <><span className="spinner-border spinner-border-sm me-1"></span> Salvando...</> : 'Redefinir Senha'}
                </button>
            </form>
        </GuestLayout>
    );
}
