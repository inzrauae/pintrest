import { createContext, useCallback, useContext, useEffect, useState } from 'react';

const cx = (...c) => c.filter(Boolean).join(' ');

/* ---------- Toasts ---------- */
const ToastContext = createContext(() => {});

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const push = useCallback((message, type = 'success') => {
        const id = Math.random().toString(36).slice(2);
        setToasts((t) => [...t, { id, message, type }]);
        setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), type === 'error' ? 7000 : 4000);
    }, []);

    return (
        <ToastContext.Provider value={push}>
            {children}
            <div className="fixed bottom-4 right-4 left-4 sm:left-auto z-50 flex flex-col gap-2 sm:w-96" role="status" aria-live="polite">
                {toasts.map((t) => (
                    <div
                        key={t.id}
                        className={cx(
                            'animate-toast-in rounded-lg px-4 py-3 text-sm shadow-lg border',
                            t.type === 'error' && 'bg-red-50 border-red-200 text-red-800',
                            t.type === 'success' && 'bg-gray-900 border-gray-900 text-white',
                            t.type === 'info' && 'bg-white border-gray-200 text-gray-800',
                        )}
                    >
                        {t.message}
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export const useToast = () => useContext(ToastContext);

/* ---------- Primitives ---------- */
export function Button({ variant = 'primary', size = 'md', loading, className, children, disabled, ...props }) {
    return (
        <button
            className={cx(
                'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1',
                size === 'sm' ? 'px-2.5 py-1.5 text-xs' : 'px-4 py-2 text-sm',
                variant === 'primary' && 'bg-brand-600 text-white hover:bg-brand-700',
                variant === 'secondary' && 'bg-white text-gray-800 border border-gray-300 hover:bg-gray-50',
                variant === 'ghost' && 'text-gray-600 hover:bg-gray-100',
                variant === 'danger' && 'bg-white text-red-600 border border-red-200 hover:bg-red-50',
                variant === 'dark' && 'bg-gray-900 text-white hover:bg-gray-800',
                className,
            )}
            disabled={disabled || loading}
            {...props}
        >
            {loading && <Spinner className="h-4 w-4" />}
            {children}
        </button>
    );
}

export function Spinner({ className = 'h-5 w-5' }) {
    return (
        <svg className={cx('animate-spin', className)} viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" strokeOpacity="0.25" strokeWidth="4" />
            <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
        </svg>
    );
}

export function Card({ className, children, ...props }) {
    return (
        <div className={cx('bg-white rounded-xl border border-gray-200 shadow-sm', className)} {...props}>
            {children}
        </div>
    );
}

export function Field({ label, error, hint, children, className }) {
    return (
        <label className={cx('block', className)}>
            {label && <span className="block text-sm font-medium text-gray-700 mb-1">{label}</span>}
            {children}
            {hint && !error && <span className="block text-xs text-gray-500 mt-1">{hint}</span>}
            {error && <span className="block text-xs text-red-600 mt-1">{error}</span>}
        </label>
    );
}

const inputBase =
    'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none disabled:bg-gray-100';

// Full width unless the caller sets its own width (e.g. "w-36"), which would otherwise lose to w-full.
const inputCls = (className) => cx(inputBase, /(^|\s|:)w-/.test(className || '') ? '' : 'w-full', className);

export const Input = (props) => <input {...props} className={inputCls(props.className)} />;
export const Textarea = (props) => <textarea {...props} className={inputCls(props.className)} />;
export const Select = ({ children, ...props }) => (
    <select {...props} className={inputCls(props.className)}>
        {children}
    </select>
);

export function Checkbox({ label, ...props }) {
    return (
        <label className="inline-flex items-start gap-2 text-sm text-gray-700 cursor-pointer">
            <input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-gray-300 accent-brand-600" {...props} />
            <span>{label}</span>
        </label>
    );
}

export function CharCount({ value, max }) {
    const n = (value || '').length;
    return <span className={cx('text-xs', n > max ? 'text-red-600 font-medium' : 'text-gray-400')}>{n}/{max}</span>;
}

const STATUS_STYLES = {
    draft: 'bg-amber-50 text-amber-700 ring-amber-200',
    approved: 'bg-sky-50 text-sky-700 ring-sky-200',
    scheduled: 'bg-violet-50 text-violet-700 ring-violet-200',
    publishing: 'bg-blue-50 text-blue-700 ring-blue-200',
    published: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    generated: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    succeeded: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    failed: 'bg-red-50 text-red-700 ring-red-200',
    error: 'bg-red-50 text-red-700 ring-red-200',
    expired: 'bg-red-50 text-red-700 ring-red-200',
    pending: 'bg-gray-50 text-gray-600 ring-gray-200',
    queued: 'bg-gray-50 text-gray-600 ring-gray-200',
    processing: 'bg-blue-50 text-blue-700 ring-blue-200',
    running: 'bg-blue-50 text-blue-700 ring-blue-200',
    paused: 'bg-orange-50 text-orange-700 ring-orange-200',
    cancelled: 'bg-gray-100 text-gray-500 ring-gray-200',
    duplicate: 'bg-gray-100 text-gray-500 ring-gray-200',
    warning: 'bg-amber-50 text-amber-700 ring-amber-200',
    info: 'bg-gray-50 text-gray-600 ring-gray-200',
    sandbox: 'bg-yellow-50 text-yellow-800 ring-yellow-200',
};

export function Badge({ status, children, className }) {
    return (
        <span
            className={cx(
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset capitalize whitespace-nowrap',
                STATUS_STYLES[status] || STATUS_STYLES.info,
                className,
            )}
        >
            {children || status}
        </span>
    );
}

export function PageHeader({ title, description, actions }) {
    return (
        <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                {description && <p className="text-sm text-gray-500 mt-1">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

export function EmptyState({ title, description, action, icon = '📌' }) {
    return (
        <div className="text-center py-14 px-6">
            <div className="text-4xl mb-3" aria-hidden="true">{icon}</div>
            <h3 className="font-semibold text-gray-900">{title}</h3>
            {description && <p className="text-sm text-gray-500 mt-1 max-w-md mx-auto">{description}</p>}
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}

export function Loading({ label = 'Loading…' }) {
    return (
        <div className="flex items-center justify-center gap-3 py-16 text-gray-500 text-sm">
            <Spinner /> {label}
        </div>
    );
}

export function ErrorBox({ message, onRetry }) {
    if (!message) return null;
    return (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 flex items-center justify-between gap-3">
            <span>{message}</span>
            {onRetry && (
                <Button size="sm" variant="secondary" onClick={onRetry}>
                    Retry
                </Button>
            )}
        </div>
    );
}

export function Alert({ type = 'warning', children }) {
    const styles = {
        warning: 'border-amber-200 bg-amber-50 text-amber-900',
        info: 'border-sky-200 bg-sky-50 text-sky-900',
        error: 'border-red-200 bg-red-50 text-red-800',
        success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    };
    return <div className={cx('rounded-lg border px-4 py-3 text-sm', styles[type])}>{children}</div>;
}

export function Modal({ open, onClose, title, children, footer, wide }) {
    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) return null;
    return (
        <div className="fixed inset-0 z-40 flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true">
            <div className="absolute inset-0 bg-black/40" onClick={onClose} />
            <div className={cx('relative bg-white w-full rounded-t-2xl sm:rounded-2xl shadow-xl max-h-[92vh] flex flex-col', wide ? 'sm:max-w-3xl' : 'sm:max-w-lg')}>
                <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <h2 className="font-semibold">{title}</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-700 text-xl leading-none" aria-label="Close">
                        ×
                    </button>
                </div>
                <div className="p-5 overflow-y-auto">{children}</div>
                {footer && <div className="px-5 py-4 border-t border-gray-100 flex justify-end gap-2">{footer}</div>}
            </div>
        </div>
    );
}

export function Pagination({ meta, onPage }) {
    if (!meta || meta.last_page <= 1) return null;
    return (
        <div className="flex items-center justify-between px-1 py-3 text-sm text-gray-600">
            <span>
                Page {meta.current_page} of {meta.last_page} · {meta.total} total
            </span>
            <div className="flex gap-2">
                <Button size="sm" variant="secondary" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
                    Previous
                </Button>
                <Button size="sm" variant="secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
                    Next
                </Button>
            </div>
        </div>
    );
}

export function Stat({ label, value, tone = 'gray', hint }) {
    const tones = {
        gray: 'text-gray-900',
        green: 'text-emerald-600',
        red: 'text-red-600',
        amber: 'text-amber-600',
        violet: 'text-violet-600',
        brand: 'text-brand-600',
    };
    return (
        <Card className="p-4">
            <div className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</div>
            <div className={cx('text-2xl font-semibold mt-1 tabular-nums', tones[tone])}>{value ?? '—'}</div>
            {hint && <div className="text-xs text-gray-400 mt-1">{hint}</div>}
        </Card>
    );
}

export { cx };
