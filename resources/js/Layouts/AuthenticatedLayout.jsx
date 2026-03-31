/**
 * AuthenticatedLayout — Layout principal para páginas autenticadas.
 *
 * Adapta a estrutura HTML/CSS vinda do template Preclinic para trabalhar
 * com Inertia.js ao invés de React Router + Redux. O CSS do Preclinic
 * (classes como main-wrapper, sidebar, page-wrapper, etc.) permanece
 * inalterado — apenas trocamos o mecanismo de navegação e estado.
 */
import { useState, useEffect } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import Toast from '@/Components/UI/Toast';

export default function AuthenticatedLayout({ children, title }) {
    const { auth, entity, flash, locale, appName } = usePage().props;
    const [miniSidebar, setMiniSidebar] = useState(false);
    const [mobileSidebar, setMobileSidebar] = useState(false);

    // Flash messages como toast
    // The Toast component will handle the flash rendering automatically based on usePage().props.flash

    const toggleSidebar = () => setMiniSidebar(!miniSidebar);
    const toggleMobileSidebar = () => setMobileSidebar(!mobileSidebar);

    // Pegar a URL atual para marcar o menu ativo
    const currentUrl = usePage().url;

    return (
        <>
            <Head title={title} />
            <div className={`
                ${miniSidebar ? 'mini-sidebar' : ''}
                ${mobileSidebar ? 'menu-opened slide-nav' : ''}
            `}>
                <div className="main-wrapper">
                    {/* ═══════════════════ HEADER ═══════════════════ */}
                    <header className="navbar-header">
                        <div className="page-container topbar-menu">
                            <div className="d-flex align-items-center gap-2">
                                <Link href="/panel/dashboard" className="logo">
                                    <span className="logo-light">
                                        <span className="logo-lg">
                                            <img src="/system/images/preclinic/logo.svg" alt={appName} />
                                        </span>
                                        <span className="logo-sm">
                                            <img src="/system/images/preclinic/logo-small.svg" alt={appName} />
                                        </span>
                                    </span>
                                    <span className="logo-dark">
                                        <span className="logo-lg">
                                            <img src="/system/images/preclinic/logo-white.svg" alt={appName} />
                                        </span>
                                    </span>
                                </Link>
                                <a className="mobile-btn" href="#sidebar" onClick={(e) => {
                                    e.preventDefault();
                                    toggleMobileSidebar();
                                }}>
                                    <i className="ti ti-menu-deep fs-24"></i>
                                </a>
                                <button
                                    className="sidenav-toggle-btn btn border-0 p-0 active"
                                    onClick={toggleSidebar}
                                >
                                    <i className={`ti ti-arrow-${miniSidebar ? 'right' : 'left'}`}></i>
                                </button>
                            </div>

                            <div className="d-flex align-items-center">
                                {/* Light/Dark Mode */}
                                <div className="header-item d-none d-sm-flex me-2">
                                    <button
                                        className="topbar-link btn btn-icon"
                                        onClick={() => {
                                            const html = document.documentElement;
                                            const current = html.getAttribute('data-color') || 'light';
                                            html.setAttribute('data-color', current === 'light' ? 'dark' : 'light');
                                        }}
                                    >
                                        <i className="ti ti-moon fs-16"></i>
                                    </button>
                                </div>

                                {/* User Dropdown */}
                                <div className="dropdown profile-dropdown d-flex align-items-center justify-content-center">
                                    <a
                                        href="#"
                                        className="topbar-link dropdown-toggle drop-arrow-none position-relative"
                                        data-bs-toggle="dropdown"
                                        data-bs-offset="0,22"
                                    >
                                        <img
                                            src="/system/images/team.png"
                                            width="32"
                                            className="rounded-circle d-flex"
                                            alt={auth?.user?.name || ''}
                                        />
                                        <span className="online text-success">
                                            <i className="ti ti-circle-filled d-flex bg-white rounded-circle border border-1 border-white"></i>
                                        </span>
                                    </a>
                                    <div className="dropdown-menu dropdown-menu-end dropdown-menu-md p-2">
                                        <div className="d-flex align-items-center bg-light rounded-3 p-2 mb-2">
                                            <img src="/system/images/team.png" className="rounded-circle" width="42" height="42" alt="" />
                                            <div className="ms-2">
                                                <p className="fw-medium text-dark mb-0">{auth?.user?.name}</p>
                                                <span className="d-block fs-13">{auth?.user?.email}</span>
                                            </div>
                                        </div>
                                        <Link href="/panel/profile" className="dropdown-item">
                                            <i className="ti ti-user-circle me-1 align-middle"></i>
                                            <span className="align-middle">Editar Perfil</span>
                                        </Link>
                                        <div className="pt-2 mt-2 border-top">
                                            <Link
                                                href="/logout"
                                                method="post"
                                                as="button"
                                                className="dropdown-item text-danger w-100 text-start"
                                            >
                                                <i className="ti ti-logout me-1 fs-17 align-middle"></i>
                                                <span className="align-middle">Sair</span>
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </header>

                    {/* ═══════════════════ SIDEBAR ═══════════════════ */}
                    <div className="sidebar" id="sidebar">
                        <div className="sidebar-logo">
                            <div>
                                <Link href="/panel/dashboard" className="logo logo-normal">
                                    <img src="/system/images/preclinic/logo.svg" alt={appName} />
                                </Link>
                                <Link href="/panel/dashboard" className="logo-small">
                                    <img src="/system/images/preclinic/logo-small.svg" alt={appName} />
                                </Link>
                                <Link href="/panel/dashboard" className="dark-logo">
                                    <img src="/system/images/preclinic/logo-white.svg" alt={appName} />
                                </Link>
                            </div>
                            <button className="sidenav-toggle-btn btn border-0 p-0 active" onClick={toggleSidebar}>
                                <i className={`ti ti-arrow-${miniSidebar ? 'right' : 'left'}`}></i>
                            </button>
                            <button className="sidebar-close" onClick={toggleMobileSidebar}>
                                <i className="ti ti-x align-middle"></i>
                            </button>
                        </div>
                        <div className="sidebar-inner" data-simplebar="">
                            <div id="sidebar-menu" className="sidebar-menu">
                                <ul>
                                    <li className="menu-title"><span>Menu</span></li>
                                    <li>
                                        <ul>
                                            <SidebarItem
                                                href="/panel/dashboard"
                                                icon="ti ti-layout-dashboard"
                                                label="Dashboard"
                                                currentUrl={currentUrl}
                                            />
                                            <SidebarItem
                                                href="/panel/schedules"
                                                icon="ti ti-calendar"
                                                label="Agendamentos"
                                                currentUrl={currentUrl}
                                            />
                                            <SidebarItem
                                                href="/panel/patients"
                                                icon="ti ti-users"
                                                label="Pacientes"
                                                currentUrl={currentUrl}
                                            />
                                            <SidebarItem
                                                href="/panel/doctors"
                                                icon="ti ti-stethoscope"
                                                label="Médicos"
                                                currentUrl={currentUrl}
                                            />
                                            <SidebarItem
                                                href="/panel/reports"
                                                icon="ti ti-chart-bar"
                                                label="Relatórios"
                                                currentUrl={currentUrl}
                                            />

                                            <li className="menu-title"><span>Configurações</span></li>
                                            <SidebarItem
                                                href="/panel/users"
                                                icon="ti ti-user-cog"
                                                label="Usuários"
                                                currentUrl={currentUrl}
                                            />
                                            <SidebarItem
                                                href="/panel/setting"
                                                icon="ti ti-settings"
                                                label="Configurações"
                                                currentUrl={currentUrl}
                                                matchPrefix
                                            />
                                        </ul>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    {/* ═══════════════════ PAGE WRAPPER ═══════════════════ */}
                    <div className="page-wrapper">
                        <div className="content">
                            {children}
                        </div>
                    </div>
                </div>

                {/* Overlay para mobile sidebar */}
                <div
                    className={`sidebar-overlay${mobileSidebar ? ' opened' : ''}`}
                    onClick={toggleMobileSidebar}
                ></div>
            </div>
            
            <Toast flash={flash} />
        </>
    );
}

/**
 * Componente helper para itens do menu lateral.
 */
function SidebarItem({ href, icon, label, currentUrl, matchPrefix = false }) {
    const isActive = matchPrefix
        ? currentUrl.startsWith(href)
        : currentUrl === href;

    return (
        <li>
            <Link href={href} className={isActive ? 'active' : ''}>
                <i className={icon}></i>
                <span>{label}</span>
            </Link>
        </li>
    );
}
