import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import { cx } from './ui';

const NAV = [
    { to: '/', label: 'Dashboard', group: 'Workspace', icon: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z' },
    { to: '/create', label: 'Create Pin', group: 'Workspace', icon: 'M12 5v14M5 12h14' },
    { to: '/pins', label: 'Pin Library', group: 'Workspace', icon: 'M4 4h6v16H4zM14 4h6v10h-6zM14 18h6v2h-6z' },
    { to: '/bulk', label: 'Bulk URL Import', group: 'Workspace', icon: 'M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3M12 3h9v9M21 3 10 14' },
    { to: '/site', label: 'Website to Pins', group: 'Workspace', icon: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18' },
    { to: '/seo/topics', label: 'SEO Autopilot', group: 'Workspace', icon: 'M12 2v4M12 18v4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M2 12h4M18 12h4M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8z' },
    { to: '/seo', label: 'SEO Dashboard', group: 'Workspace', icon: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3M8 13l2-2 2 2 3-3' },
    { to: '/queue', label: 'Publishing Queue', group: 'Workspace', icon: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2zM8 14h2M14 14h2M8 17h2' },
    { to: '/accounts', label: 'Pinterest Accounts', group: 'Manage', icon: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM4 21a8 8 0 0 1 16 0' },
    { to: '/boards', label: 'Boards', group: 'Manage', icon: 'M3 5h18v14H3zM3 10h18M9 10v9' },
    { to: '/templates', label: 'Templates', group: 'Manage', icon: 'M4 4h16v16H4zM4 15h16M14 4v11' },
    { to: '/reports', label: 'Reports', group: 'Manage', icon: 'M4 20V10M10 20V4M16 20v-7M22 20H2' },
    { to: '/ai-settings', label: 'AI Settings', group: 'Preferences', icon: 'm12 3 2.5 5.5L20 11l-5.5 2.5L12 19l-2.5-5.5L4 11l5.5-2.5zM20 3v4M18 5h4' },
    { to: '/settings', label: 'User Settings', group: 'Preferences', icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z' },
];

export function Icon({ d, className = 'h-5 w-5' }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={d} /></svg>;
}

function Logo() {
    return (
        <Link to="/" className="brand-logo" aria-label="PinBot home">
            <span className="brand-mark"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 20V6h5a4.5 4.5 0 0 1 0 9H8" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" /><path d="m16 3 1-2m3 6 2-1" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" /></svg></span>
            <span>PinBot<span className="brand-dot">.</span></span>
        </Link>
    );
}

export default function Layout() {
    const { user, logout } = useAuth();
    const [open, setOpen] = useState(false);
    const location = useLocation();
    const menuButton = useRef(null);
    const drawer = useRef(null);
    const current = NAV.find((item) => item.to === '/' ? location.pathname === '/' : location.pathname.startsWith(item.to));
    const initials = (user?.name || 'You').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

    useEffect(() => { setOpen(false); }, [location.pathname]);

    useEffect(() => {
        if (!open) return undefined;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const focusable = () => [...(drawer.current?.querySelectorAll('a[href], button:not([disabled])') || [])];
        focusable()[0]?.focus();
        const onKey = (event) => {
            if (event.key === 'Escape') setOpen(false);
            if (event.key !== 'Tab') return;
            const elements = focusable();
            const first = elements[0];
            const last = elements[elements.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        };
        const onResize = () => { if (window.innerWidth >= 1024) setOpen(false); };
        window.addEventListener('keydown', onKey);
        window.addEventListener('resize', onResize);
        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('resize', onResize);
            menuButton.current?.focus();
        };
    }, [open]);

    const nav = (
        <nav className="sidebar-nav" aria-label="Main navigation">
            {['Workspace', 'Manage', 'Preferences'].map((group) => (
                <div className="nav-group" key={group}>
                    <p className="nav-group-label">{group}</p>
                    {NAV.filter((item) => item.group === group).map((item) => (
                        <NavLink key={item.to} to={item.to} end={item.to === '/'} onClick={() => setOpen(false)} className={({ isActive }) => cx('sidebar-link', isActive && 'is-active')}>
                            <Icon d={item.icon} className="h-[18px] w-[18px] shrink-0" /><span>{item.label}</span>
                            {item.to === '/ai-settings' && <span className="nav-ai-label">AI</span>}
                        </NavLink>
                    ))}
                </div>
            ))}
        </nav>
    );

    const footer = (
        <div className="sidebar-footer">
            <div className="sidebar-note">
                <Icon d="m12 3 2.5 5.5L20 11l-5.5 2.5L12 19l-2.5-5.5L4 11l5.5-2.5z" className="h-5 w-5 text-brand-600" />
                <p>A little inspiration.<br /><strong>A lot of possibility.</strong></p>
                <Link to="/create">Create something great <span aria-hidden="true">↗</span></Link>
            </div>
            <div className="sidebar-profile">
                <Link to="/settings" className="profile-link" onClick={() => setOpen(false)}><span className="profile-avatar">{initials}</span><span className="min-w-0"><strong className="block truncate">{user?.name}</strong><span className="block truncate text-xs text-gray-500">{user?.email}</span></span></Link>
                <button onClick={logout} className="icon-button shrink-0" aria-label="Sign out" title="Sign out"><Icon d="M9 4H4v16h5M14 8l4 4-4 4M8 12h13" className="h-[18px] w-[18px]" /></button>
            </div>
        </div>
    );

    return (
        <div className="app-shell">
            <a href="#main-content" className="skip-link">Skip to content</a>
            <aside className="desktop-sidebar"><div className="sidebar-brand"><Logo /><span className="studio-label">CREATOR STUDIO</span></div>{nav}{footer}</aside>
            <div className="app-main">
                <header className="workspace-header">
                    <div className="flex min-w-0 items-center gap-3">
                        <button ref={menuButton} onClick={() => setOpen(true)} className="icon-button lg:hidden" aria-label="Open navigation" aria-expanded={open} aria-controls="mobile-navigation"><Icon d="M4 6h16M4 12h16M4 18h16" /></button>
                        <div className="lg:hidden"><Logo /></div>
                        <div className="hidden items-center gap-3 text-[13px] sm:flex"><span className="text-gray-400">Workspace</span><span className="text-gray-300" aria-hidden="true">/</span><span className="font-medium text-gray-700">{current?.label || 'Pin editor'}</span></div>
                    </div>
                    <div className="flex shrink-0 items-center gap-3 sm:gap-5">
                        <Link to="/pins" className="header-library-link" aria-label="Pin library"><Icon d="M10 17a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM15 15l6 6" className="h-[17px] w-[17px]" /><span className="hidden md:inline">Pin library</span></Link>
                        <div className="header-divider" /><Link to="/settings" className="profile-avatar header-avatar" aria-label="Open your settings">{initials}</Link>
                    </div>
                </header>
                <main id="main-content" className="workspace-content" tabIndex={-1}>
                    <div key={location.pathname} className="page-content"><Outlet /></div>
                    <footer className="workspace-footer"><span>Made for your next big idea.</span><span>PinBot creator workspace <span className="text-brand-500" aria-hidden="true">✦</span></span></footer>
                </main>
            </div>
            {open && (
                <div className="mobile-menu lg:hidden">
                    <div className="mobile-menu-backdrop" onClick={() => setOpen(false)} aria-hidden="true" />
                    <aside ref={drawer} id="mobile-navigation" className="mobile-sidebar" role="dialog" aria-modal="true" aria-label="Navigation">
                        <div className="sidebar-brand flex-row items-center justify-between"><Logo /><button className="icon-button" onClick={() => setOpen(false)} aria-label="Close navigation"><Icon d="m6 6 12 12M18 6 6 18" /></button></div>
                        {nav}{footer}
                    </aside>
                </div>
            )}
        </div>
    );
}
