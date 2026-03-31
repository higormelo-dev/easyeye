import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';

export default function Register({ plans, trialDays }) {
    const { errors } = usePage().props;
    const [form, setForm] = useState({
        name: '', email: '', password: '', password_confirmation: '',
        company_name: '', plan_id: '',
    });
    const [processing, setProcessing] = useState(false);
    const [showPassword, setShowPassword] = useState(false);

    const handleSubmit = (e) => {
        e.preventDefault();
        setProcessing(true);
        router.post('/register', form, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <GuestLayout title="Cadastro">
            <h2 className="fw-bold mb-1">Criar Conta</h2>
            <p className="text-muted mb-4">
                {trialDays ? `Comece seu trial gratuito de ${trialDays} dias` : 'Cadastre-se gratuitamente'}
            </p>

            <form onSubmit={handleSubmit}>
                <div className="mb-3">
                    <label className="form-label">Nome completo</label>
                    <input
                        type="text"
                        className={`form-control${errors?.name ? ' is-invalid' : ''}`}
                        value={form.name}
                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                        autoFocus
                        required
                    />
                    {errors?.name && <div className="invalid-feedback">{errors.name}</div>}
                </div>

                <div className="mb-3">
                    <label className="form-label">Nome da Clínica</label>
                    <input
                        type="text"
                        className={`form-control${errors?.company_name ? ' is-invalid' : ''}`}
                        value={form.company_name}
                        onChange={(e) => setForm({ ...form, company_name: e.target.value })}
                        required
                    />
                    {errors?.company_name && <div className="invalid-feedback">{errors.company_name}</div>}
                </div>

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
                    <label className="form-label">Senha</label>
                    <div className="input-group">
                        <input
                            type={showPassword ? 'text' : 'password'}
                            className={`form-control${errors?.password ? ' is-invalid' : ''}`}
                            value={form.password}
                            onChange={(e) => setForm({ ...form, password: e.target.value })}
                            required
                        />
                        <button type="button" className="input-group-text" onClick={() => setShowPassword(!showPassword)}>
                            <i className={`ti ti-eye${showPassword ? '-off' : ''}`}></i>
                        </button>
                    </div>
                    {errors?.password && <div className="text-danger small mt-1">{errors.password}</div>}
                </div>

                <div className="mb-3">
                    <label className="form-label">Confirmar Senha</label>
                    <input
                        type="password"
                        className="form-control"
                        value={form.password_confirmation}
                        onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
                        required
                    />
                </div>

                <button type="submit" className="btn btn-primary w-100 mb-3" disabled={processing}>
                    {processing ? <><span className="spinner-border spinner-border-sm me-1"></span> Cadastrando...</> : 'Cadastrar'}
                </button>

                <p className="text-center text-muted small mb-0">
                    Já tem uma conta?{' '}
                    <Link href="/login" className="text-primary fw-medium">Entrar</Link>
                </p>
            </form>
        </GuestLayout>
    );
}
