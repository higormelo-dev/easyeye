import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';

export default function Login() {
    const { errors } = usePage().props;
    const [form, setForm] = useState({ email: '', password: '', remember: false });
    const [processing, setProcessing] = useState(false);
    const [showPassword, setShowPassword] = useState(false);

    const handleSubmit = (e) => {
        e.preventDefault();
        setProcessing(true);
        router.post('/login', form, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <GuestLayout title="Login">
            <h2 className="fw-bold mb-1">Entrar</h2>
            <p className="text-muted mb-4">Acesse sua conta</p>

            {errors?.email && (
                <div className="alert alert-danger py-2 small">{errors.email}</div>
            )}

            <form onSubmit={handleSubmit}>
                <div className="mb-3">
                    <label className="form-label">E-mail</label>
                    <div className="input-group">
                        <span className="input-group-text"><i className="ti ti-mail"></i></span>
                        <input
                            type="email"
                            className={`form-control${errors?.email ? ' is-invalid' : ''}`}
                            placeholder="seu@email.com"
                            value={form.email}
                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                            autoFocus
                            required
                        />
                    </div>
                </div>

                <div className="mb-3">
                    <label className="form-label">Senha</label>
                    <div className="input-group">
                        <span className="input-group-text"><i className="ti ti-lock"></i></span>
                        <input
                            type={showPassword ? 'text' : 'password'}
                            className={`form-control${errors?.password ? ' is-invalid' : ''}`}
                            placeholder="••••••••"
                            value={form.password}
                            onChange={(e) => setForm({ ...form, password: e.target.value })}
                            required
                        />
                        <button
                            type="button"
                            className="input-group-text"
                            onClick={() => setShowPassword(!showPassword)}
                        >
                            <i className={`ti ti-eye${showPassword ? '-off' : ''}`}></i>
                        </button>
                    </div>
                    {errors?.password && <div className="text-danger small mt-1">{errors.password}</div>}
                </div>

                <div className="d-flex align-items-center justify-content-between mb-4">
                    <div className="form-check">
                        <input
                            className="form-check-input"
                            type="checkbox"
                            id="remember"
                            checked={form.remember}
                            onChange={(e) => setForm({ ...form, remember: e.target.checked })}
                        />
                        <label className="form-check-label" htmlFor="remember">Lembrar-me</label>
                    </div>
                    <Link href="/forgot-password" className="text-primary small">
                        Esqueceu a senha?
                    </Link>
                </div>

                <button type="submit" className="btn btn-primary w-100 mb-3" disabled={processing}>
                    {processing ? <><span className="spinner-border spinner-border-sm me-1"></span> Entrando...</> : 'Entrar'}
                </button>

                <p className="text-center text-muted small mb-0">
                    Não tem uma conta?{' '}
                    <Link href="/register" className="text-primary fw-medium">Cadastre-se</Link>
                </p>
            </form>
        </GuestLayout>
    );
}
