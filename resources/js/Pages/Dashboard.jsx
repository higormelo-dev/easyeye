import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const statsData = [
    { label: 'Pacientes Hoje', value: '24', icon: 'ti-users', color: 'bg-indigo-600', trend: '+12%', trendColor: 'text-green-500' },
    { label: 'Agendamentos', value: '56', icon: 'ti-calendar-check', color: 'bg-emerald-500', trend: '+8%', trendColor: 'text-green-500' },
    { label: 'Exames Pendentes', value: '18', icon: 'ti-microscope', color: 'bg-amber-500', trend: '-2%', trendColor: 'text-red-500' },
    { label: 'Faturamento', value: 'R$ 14.2k', icon: 'ti-trending-up', color: 'bg-rose-500', trend: '+15%', trendColor: 'text-green-500' }
];

export default function Dashboard({ auth }) {
    return (
        <AuthenticatedLayout 
            user={auth.user} 
            header="Bem-vindo de volta, Dr."
            breadcrumbs={[
                { label: 'Home', url: '/panel/dashboard' },
                { label: 'Dashboard', url: '/panel/dashboard', active: true }
            ]}
        >
            <Head title="Dashboard" />

            {/* ═══════════════════ GRID DE STATS ═══════════════════ */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                {statsData.map((stat, idx) => (
                    <div 
                        key={idx} 
                        className="bg-white p-6 rounded-3xl shadow-sm border border-slate-100/60 hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group"
                    >
                        <div className="flex items-center justify-between mb-4">
                            <div className={`w-12 h-12 ${stat.color} rounded-2xl flex items-center justify-center text-white shadow-lg group-hover:scale-110 transition-transform`}>
                                <i className={`ti ${stat.icon} fs-24`}></i>
                            </div>
                            <span className={`text-xs font-bold px-2 py-1 rounded-full bg-slate-50 ${stat.trendColor}`}>
                                {stat.trend}
                            </span>
                        </div>
                        <h3 className="text-4xl font-extrabold text-slate-900 tracking-tight mb-1">{stat.value}</h3>
                        <p className="text-sm font-medium text-slate-400 uppercase tracking-widest">{stat.label}</p>
                    </div>
                ))}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                {/* ═══════════════════ LISTA DE PACIENTES DO DIA ═══════════════════ */}
                <div className="lg:col-span-2">
                    <div className="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden h-full">
                        <div className="p-6 border-bottom border-slate-50 flex items-center justify-between bg-slate-50/30">
                            <div>
                                <h4 className="text-xl font-bold text-slate-800">Próximos Atendimentos</h4>
                                <p className="text-sm text-slate-500 mb-0">Hoje, {new Date().toLocaleDateString('pt-BR')}</p>
                            </div>
                            <Link href="/panel/schedules" className="text-indigo-600 text-sm font-semibold hover:underline">Ver Agenda Completa</Link>
                        </div>
                        
                        <div className="p-0 overflow-x-auto">
                            <table className="w-full text-left">
                                <thead className="bg-slate-50 text-slate-400 text-xs font-bold uppercase tracking-wider">
                                    <tr>
                                        <th className="px-6 py-4">Paciente</th>
                                        <th className="px-6 py-4">Horário</th>
                                        <th className="px-6 py-4">Tipo</th>
                                        <th className="px-6 py-4">Status</th>
                                        <th className="px-6 py-4"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    <PatientRow name="Maria das Dores" time="09:30" type="Consulta" status="Confirmado" img="/system/images/team.png" />
                                    <PatientRow name="João Silva" time="10:15" type="Retorno" status="Em Andamento" img="/system/images/team.png" statusColor="text-indigo-600 bg-indigo-50" />
                                    <PatientRow name="Ana Paula Santos" time="11:00" type="Exame OCT" status="Aguardando" img="/system/images/team.png" statusColor="text-amber-600 bg-amber-50" />
                                    <PatientRow name="Ricardo Oliveira" time="11:45" type="Primeira Vez" status="Cancelado" img="/system/images/team.png" statusColor="text-red-600 bg-red-50" />
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {/* ═══════════════════ CARDS LATERAIS / GROWTH ═══════════════════ */}
                <div className="space-y-8">
                    {/* Card de Ativação do Trial (Growth) */}
                    <div className="bg-gradient-to-br from-indigo-600 to-violet-700 p-8 rounded-3xl text-white shadow-xl shadow-indigo-200 relative overflow-hidden group">
                        <div className="relative z-10">
                            <h4 className="text-xl font-bold mb-2">Meta de Ativação</h4>
                            <p className="text-indigo-100 text-sm mb-6">Complete os marcos para ganhar 15 dias extras de trial.</p>
                            
                            <div className="space-y-4">
                                <ActivationStep label="Adicionar primeiro paciente" completed />
                                <ActivationStep label="Realizar primeiro upload de exame" completed />
                                <ActivationStep label="Emitir primeira receita digital" />
                                <ActivationStep label="Personalizar o portal do paciente" />
                            </div>
                            
                            <div className="mt-8 bg-white/20 h-2 w-full rounded-full">
                                <div className="bg-white h-2 w-1/2 rounded-full shadow-[0_0_15px_rgba(255,255,255,0.5)]"></div>
                            </div>
                            <p className="text-xs mt-3 text-indigo-50">Progresso: 50% concluído</p>
                        </div>
                        
                        {/* Decoração de Fundo */}
                        <div className="absolute -bottom-10 -right-10 w-40 h-40 bg-white/10 rounded-full blur-3xl group-hover:scale-150 transition-transform duration-700"></div>
                    </div>

                    {/* Quick Access / Atalhos */}
                    <div className="bg-white p-8 rounded-3xl shadow-sm border border-slate-100">
                        <h4 className="text-lg font-bold text-slate-800 mb-6">Ações Rápidas</h4>
                        <div className="grid grid-cols-2 gap-4">
                            <QuickAction icon="ti-user-plus" label="Novo Paciente" color="text-indigo-600 bg-indigo-50" />
                            <QuickAction icon="ti-calendar-plus" label="Novo Agendamento" color="text-emerald-600 bg-emerald-50" />
                            <QuickAction icon="ti-prescription" label="Emitir Receita" color="text-amber-600 bg-amber-50" />
                            <QuickAction icon="ti-file-invoice" label="Financeiro" color="text-rose-600 bg-rose-50" />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function PatientRow({ name, time, type, status, img, statusColor = 'text-green-600 bg-green-50' }) {
    return (
        <tr className="hover:bg-slate-50/50 transition-colors cursor-pointer group">
            <td className="px-6 py-4">
                <div className="flex items-center gap-3">
                    <img src={img} className="w-10 h-10 rounded-xl" alt={name} />
                    <span className="font-bold text-slate-800 group-hover:text-indigo-600 transition-colors">{name}</span>
                </div>
            </td>
            <td className="px-6 py-4 font-medium text-slate-700">{time}</td>
            <td className="px-6 py-4 text-slate-500">{type}</td>
            <td className="px-6 py-4">
                <span className={`px-3 py-1 rounded-full text-xs font-bold ${statusColor}`}>
                    {status}
                </span>
            </td>
            <td className="px-6 py-4 text-right">
                <button className="text-slate-300 hover:text-slate-600">
                    <i className="ti ti-dots-vertical fs-18"></i>
                </button>
            </td>
        </tr>
    );
}

function ActivationStep({ label, completed = false }) {
    return (
        <div className="flex items-center gap-3">
            <div className={`w-5 h-5 rounded-full flex items-center justify-center ${completed ? 'bg-white text-indigo-600' : 'border-2 border-white/30'}`}>
                {completed && <i className="ti ti-check fs-12"></i>}
            </div>
            <span className={`text-sm ${completed ? 'text-white' : 'text-indigo-200'}`}>{label}</span>
        </div>
    );
}

function QuickAction({ icon, label, color }) {
    return (
        <button className="flex flex-col items-center justify-center p-4 rounded-2xl hover:bg-white hover:shadow-lg transition-all border border-transparent hover:border-slate-100 group">
            <div className={`w-12 h-12 rounded-2xl flex items-center justify-center mb-3 transition-transform group-hover:scale-110 ${color}`}>
                <i className={`ti ${icon} fs-22`}></i>
            </div>
            <span className="text-xs font-bold text-slate-600 text-center">{label}</span>
        </button>
    );
}
