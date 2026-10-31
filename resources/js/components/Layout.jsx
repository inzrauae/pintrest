import { useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import { cx } from './ui';

const NAV = [
    { to: '/', label: 'Dashboard', icon: 'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10' },
    { to: '/create', label: 'Create Pin', icon: 'M12 5v14M5 12h14' },
    { to: '/bulk', label: 'Bulk URL Import', icon: 'M4 6h16M4 12h16M4 18h10' },
    { to: '/pins', label: 'Pin Library', icon: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z' },
    { to: '/queue', label: 'Publishing Queue', icon: 'M12 8v4l3 2M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z' },
    { to: '/accounts', label: 'Pinterest Accounts', icon: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM4 21a8 8 0 0 1 16 0' },
    { to: '/boards', label: 'Boards', icon: 'M3 5h18v14H3zM3 10h18M9 10v9' },
    { to: '/ai-settings', label: 'AI Settings', icon: 'M12 3l2.5 5.5L20 11l-5.5 2.5L12 19l-2.5-5.5L4 11l5.5-2.5z' },
    { to: '/templates', label: 'Templates', icon: 'M4 4h16v16H4zM4 15h16' },
    { to: '/reports', label: 'Reports', icon: 'M4 20V10M10 20V4M16 20v-7M22 20H2' },
    { to: '/settings', label: 'User Settings', icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z' },
];

export function Icon({ d, className = 'h-5 w-5' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d={d} />
        </svg>
    );
}

function Logo() {
    return (
        <div className="flex items-center gap-2 font-semibold text-lg">
            <span className="h-8 w-8 rounded-full bg-brand-600 text-white grid place-items-center text-sm font-bold">P</span>
            PinBot
        </div>
    );
}

export default function Layout() {
    const { user, logout } = useAuth();
    const [open, setOpen] = useState(false);
    const location = useLocation();

    const nav = (
        <nav className="flex-1 overflow-y-auto px-3 py-4 space-y-0.5">
            {NAV.map((item) => (
                <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.to === '/'}
                    onClick={() => setOpen(false)}
                    className={({ isActive }) =>
                        cx(
                            'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                            isActive ? 'bg-brand-50 text-brand-700' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
                        )
                    }
                >
                    <Icon d={item.icon} />
                    {item.label}
                </NavLink>
            ))}
        </nav>
    );

    const footer = (
        <div className="border-t border-gray-200 p-4">
            <div className="text-sm font-medium truncate">{user?.name}</div>
            <div className="text-xs text-gray-500 truncate">{user?.email}</div>
            <button onClick={logout} className="mt-2 text-xs text-gray-500 hover:text-brand-600">
                Sign out
            </button>
        </div>
    );

    return (
        <div className="min-h-screen lg:flex">
            {/* Desktop sidebar */}
            <aside className="hidden lg:flex lg:w-64 lg:flex-col lg:fixed lg:inset-y-0 bg-white border-r border-gray-200">
                <div className="h-16 flex items-center px-6 border-b border-gray-200">
                    <Logo />
                </div>
                {nav}
                {footer}
            </aside>

            {/* Mobile top bar + drawer */}
            <header className="lg:hidden sticky top-0 z-30 bg-white border-b border-gray-200 h-14 flex items-center justify-between px-4">
                <Logo />
                <button onClick={() => setOpen(true)} className="p-2 -mr-2 text-gray-600" aria-label="Open menu">
                    <Icon d="M4 6h16M4 12h16M4 18h16" className="h-6 w-6" />
                </button>
            </header>
            {open && (
                <div className="lg:hidden fixed inset-0 z-40">
                    <div className="absolute inset-0 bg-black/40" onClick={() => setOpen(false)} />
                    <aside className="absolute inset-y-0 left-0 w-72 bg-white flex flex-col shadow-xl">
                        <div className="h-14 flex items-center px-5 border-b border-gray-200">
                            <Logo />
                        </div>
                        {nav}
                        {footer}
                    </aside>
                </div>
            )}

            <main className="flex-1 lg:pl-64 min-w-0">
                <div key={location.pathname} className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
                    <Outlet />
                </div>
            </main>
        </div>
    );
}
