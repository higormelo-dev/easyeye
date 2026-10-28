import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';

/**
 * Premium Layout para o EasyEye (EasyEye)
 * Este layout utiliza as classes do Preclinic mas adiciona toques modernos com Tailwind.
 */
export default function AuthenticatedLayout({ user, header, children, breadcrumbs = [] }) {
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const [isMobile, setIsMobile] = useState(false);

    // Gerencia o estado responsivo do sidebar
    useEffect(() => {
        const handleResize = () => {
            const mobile = window.innerWidth < 1200;
            setIsMobile(mobile);
            if (mobile) setSidebarOpen(false);
            else setSidebarOpen(true);
        };
        handleResize();
        window.addEventListener('resize', handleResize);
        return () => window.removeEventListener('resize', handleResize);
    }, []);

    const toggleSidebar = () => setSidebarOpen(!sidebarOpen);

    return (
        <div className={`main-wrapper ${!sidebarOpen ? 'mini-sidebar' : ''} ${sidebarOpen && isMobile ? 'slide-nav' : ''}`}>

            {/* ═══════════════════ HEADER (Glassmorphic) ═══════════════════ */}
            <header className="navbar-header glass-effect">
                <div className="page-container topbar-menu">
                    <div className="d-flex align-items-center gap-3">
                        <Link href="/panel/dashboard" className="logo">
                            <span className="logo-light">
                                <span className="logo-lg"><img src="/system/images/logo.svg" alt="EasyEye" /></span>
                                <span className="logo-sm"><img src="/system/images/logo-small.svg" alt="EasyEye" /></span>
                            </span>
                        </Link>

                        <button
                            onClick={toggleSidebar}
                            className="sidenav-toggle-btn btn border-0 p-0 text-slate-600 hover:text-indigo-600 transition-colors"
                        >
                            <i className={`ti ${sidebarOpen ? 'ti-menu-deep' : 'ti-menu-2'} fs-24`}></i>
                        </button>
                    </div>

                    <div className="d-flex align-items-center gap-3">
                        {/* Notificações / Utils */}
                        <div className="header-item d-none d-sm-flex">
                            <button className="topbar-link btn btn-icon hover:bg-slate-100 rounded-full">
                                <i className="ti ti-moon fs-18"></i>
                            </button>
                        </div>

                        {/* Perfil do Usuário */}
                        <div className="dropdown profile-dropdown">
                            <button className="flex items-center gap-2 p-1 hover:bg-slate-50 rounded-lg transition-all border border-transparent hover:border-slate-100">
                                <div className="relative">
                                    <img
                                        src={`/system/images/users/${user.id}.jpg`}
                                        onError={(e) => e.target.src = '/system/images/team.png'}
                                        className="w-10 h-10 rounded-full border-2 border-white shadow-sm"
                                        alt={user.name}
                                    />
                                    <span className="absolute bottom-0 right-0 w-3 h-3 bg-green-500 border-2 border-white rounded-full"></span>
                                </div>
                                <div className="text-left d-none d-md-block">
                                    <p className="text-sm font-semibold text-slate-800 leading-tight mb-0">{user.name}</p>
                                    <p className="text-xs text-slate-500 leading-tight mb-0">{user.role || 'Doutor'}</p>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            {/* ═══════════════════ SIDEBAR ═══════════════════ */}
            <aside className={`sidebar ${!sidebarOpen ? 'w-20' : 'w-64'} transition-all duration-300 shadow-xl border-r border-slate-100 bg-white`} id="sidebar">
                <div className="sidebar-inner h-full p-4 overflow-y-auto">
                    <nav id="sidebar-menu" className="sidebar-menu">
                        <ul className="space-y-2">
                            <li className="menu-title text-uppercase font-bold text-xs text-slate-400 mb-4 px-2"><span>Principal</span></li>
                            <li>
                                <SidebarLink
                                    href="/panel/dashboard"
                                    active={window.location.pathname === '/panel/dashboard'}
                                    icon="ti-layout-dashboard"
                                >
                                    Dashboard
                                </SidebarLink>
                            </li>

                            <li className="menu-title text-uppercase font-bold text-xs text-slate-400 mt-6 mb-4 px-2"><span>Atendimento</span></li>
                            <li>
                                <SidebarLink href="/panel/schedules" icon="ti-calendar-event">Agenda</SidebarLink>
                            </li>
                            <li>
                                <SidebarLink href="/panel/patients" icon="ti-users-group">Pacientes</SidebarLink>
                            </li>
                            <li>
                                <SidebarLink href="/panel/medical-records" icon="ti-report-medical">Prontuário</SidebarLink>
                            </li>
                        </ul>
                    </nav>
                </div>
            </aside>

            {/* ═══════════════════ MAIN CONTENT ═══════════════════ */}
            <main className={`page-wrapper transition-all duration-300 ${!sidebarOpen ? 'ml-20' : 'ml-64'}`}>
                <div className="content p-6">
                    {/* Breadcrumbs Premium */}
                    {breadcrumbs.length > 0 && (
                        <nav className="flex mb-4 text-slate-500 text-sm" aria-label="Breadcrumb">
                            <ol className="inline-flex items-center space-x-1 md:space-x-2">
                                {breadcrumbs.map((crumb, idx) => (
                                    <li key={idx} className="inline-flex items-center">
                                        {idx !== 0 && <i className="ti ti-chevron-right mx-2 fs-12"></i>}
                                        <Link href={crumb.url} className={`hover:text-indigo-600 ${crumb.active ? 'font-semibold text-slate-900' : ''}`}>
                                            {crumb.label}
                                        </Link>
                                    </li>
                                ))}
                            </ol>
                        </nav>
                    )}

                    {/* Page Header */}
                    {header && (
                        <div className="mb-8">
                            <h1 className="text-3xl font-extrabold text-slate-900 tracking-tight">{header}</h1>
                            {/* Subtitle decorativo opcional */}
                            <div className="h-1.5 w-12 bg-indigo-600 rounded-full mt-2"></div>
                        </div>
                    )}

                    <div className="animate-in fade-in slide-in-from-bottom-4 duration-500">
                        {children}
                    </div>
                </div>
            </main>

            <style jsx>{`
                .glass-effect {
                    background: rgba(255, 255, 255, 0.8) !important;
                    backdrop-filter: blur(12px);
                    border-bottom: 1px solid rgba(226, 232, 240, 0.8);
                }
                .main-wrapper.mini-sidebar .sidebar { width: 80px; }
                .main-wrapper.mini-sidebar .page-wrapper { margin-left: 80px; }
            `}</style>
        </div>
    );
}

function SidebarLink({ href, icon, active, children }) {
    return (
        <Link
            href={href}
            className={`flex items-center gap-3 p-3 rounded-xl transition-all group ${
                active
                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-200'
                : 'text-slate-600 hover:bg-slate-50 hover:text-indigo-600'
            }`}
        >
            <i className={`ti ${icon} fs-20 ${active ? 'text-white' : 'text-slate-400 group-hover:text-indigo-600'}`}></i>
            <span className="font-medium">{children}</span>
        </Link>
    );
}
