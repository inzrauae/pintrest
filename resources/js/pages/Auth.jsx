import { useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { errorMessage, fieldErrors } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Alert, Button, Field, Input } from '../components/ui';

function CreativeArtwork() {
    return (
        <div className="relative mx-auto mt-9 h-[310px] w-full max-w-[380px] select-none" aria-hidden="true">
            <div className="absolute left-4 top-6 w-[180px] -rotate-[10deg] overflow-hidden rounded-2xl bg-white p-2.5 shadow-[0_15px_40px_#775c4020]">
                <div className="h-[200px] overflow-hidden rounded-xl bg-[#d7dfcf]">
                    <svg viewBox="0 0 170 210" className="h-full w-full" fill="none">
                        <circle cx="134" cy="39" r="46" fill="#e6eadf" />
                        <path d="M85 148c-2-31 7-59 12-91M86 126C68 111 63 93 61 74m26 43c18-15 25-34 28-50" stroke="#577157" strokeWidth="2" />
                        <path d="M95 77C76 58 83 39 102 29c8 17 7 34-7 48ZM104 101c2-22 15-32 32-30-2 20-13 30-32 30ZM76 103C55 104 45 90 46 72c18 1 30 11 30 31ZM88 128c-9-20-4-33 12-40 8 17 5 31-12 40Z" fill="#78916d" />
                        <ellipse cx="85" cy="191" rx="53" ry="8" fill="#acb7a2" fillOpacity=".4" />
                        <path d="M68 134h34l-1 17c18 16 27 45-16 45s-34-29-17-45l0-17Z" fill="#f6efe2" />
                        <path d="M79 137v55m8-55v57m8-57v55" stroke="#e3d7c4" strokeWidth="2" />
                    </svg>
                </div>
                <div className="px-1 pb-1 pt-3 text-[11px] font-semibold text-[#424b3e]">A little room to grow.</div>
                <div className="mt-1 px-1 text-[9px] text-gray-500">Everyday inspiration</div>
            </div>
            <div className="absolute right-2 top-1 w-[178px] rotate-[9deg] overflow-hidden rounded-2xl bg-white p-2.5 shadow-[0_15px_40px_#775c4020]">
                <div className="flex h-[200px] flex-col items-center justify-center rounded-xl bg-[#c55d47] px-4 text-center text-[#fff8eb]">
                    <svg viewBox="0 0 40 40" className="mb-4 h-9 w-9" fill="none"><path d="M20 2v36M2 20h36M7 7l26 26M7 33 33 7" stroke="currentColor" strokeWidth="2" /><circle cx="20" cy="20" r="7" fill="#c55d47" stroke="currentColor" strokeWidth="2" /></svg>
                    <span className="font-serif text-[30px] leading-[1.05] tracking-tight">Make space<br />for good<br /><i>things.</i></span>
                    <span className="mt-5 text-[8px] font-medium uppercase tracking-[.22em]">Ideas worth saving</span>
                </div>
                <div className="px-1 pb-1 pt-3 text-[11px] font-semibold text-[#594337]">Your next favorite idea.</div>
                <div className="mt-1 px-1 text-[9px] text-gray-500">Made with a little help from AI</div>
            </div>
            <div className="absolute bottom-0 left-1/2 flex -translate-x-1/2 items-center gap-2.5 whitespace-nowrap rounded-full border border-white/80 bg-white px-4 py-2.5 shadow-[0_6px_24px_#775c4015]">
                <span className="grid h-6 w-6 place-items-center rounded-full bg-[#e9f2e6] text-[#537647]">
                    <svg viewBox="0 0 20 20" fill="none" className="h-3.5 w-3.5"><path d="m5 10 3 3 7-7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg>
                </span>
                <span className="text-xs font-medium text-gray-700">From inspiration to ready-to-publish.</span>
            </div>
        </div>
    );
}

function Shell({ title, subtitle, children }) {
    return (
        <div className="flex min-h-screen flex-col bg-[#faf9f6] px-4 py-6 sm:px-8 sm:py-8">
            <header className="mx-auto flex w-full max-w-[1160px] items-center justify-between">
                <Link to="/login" className="inline-flex items-center gap-2.5 text-xl font-bold tracking-tight" aria-label="PinBot home">
                    <span className="grid h-9 w-9 place-items-center rounded-xl bg-brand-600 text-white shadow-sm">
                        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" aria-hidden="true"><path d="m9 20 2-9m-1 5c-2.4-.7-4-2.7-4-5.3A6.3 6.3 0 0 1 12.5 4C16.2 4 19 6.5 19 10c0 4-2.1 7-5 7-1.8 0-2.8-1-2.4-2.7l.8-3.3" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" /></svg>
                    </span>
                    PinBot<span className="mb-2 -ml-2 text-brand-600">.</span>
                </Link>
                <span className="hidden text-xs font-medium text-gray-500 sm:block">A little inspiration. A lot of possibility.</span>
            </header>
            <main className="mx-auto my-auto w-full max-w-[1160px] py-8 sm:py-12">
                <div className="grid overflow-hidden rounded-[28px] border border-[#e9e6e0] bg-white shadow-[0_12px_60px_#2c241108] lg:grid-cols-[1.05fr_1fr]">
                    <section className="relative hidden overflow-hidden bg-[#f1e9dc] p-10 lg:flex lg:flex-col xl:p-12">
                        <div className="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[.2em] text-[#8b6b50]"><span className="h-1.5 w-1.5 rounded-full bg-[#c55d47]" />Your Pinterest creative space</div>
                        <h2 className="relative mt-8 text-[42px] font-semibold leading-[1.12] tracking-[-.045em] text-[#392f28] xl:text-[48px]">Big ideas.<br />Beautiful Pins.<br /><span className="font-serif font-normal italic text-[#b85543]">Less busywork.</span></h2>
                        <p className="mt-5 max-w-[310px] text-sm leading-6 text-[#7a6c5c]">Turn the content you love into Pins worth saving. Create, plan, and publish in one happy place.</p>
                        <CreativeArtwork />
                        <div className="mt-auto flex items-center justify-center gap-6 pt-8 text-[10px] font-medium text-[#7c705f]">
                            {['AI-assisted creation', 'Thoughtful scheduling'].map((text) => <span key={text} className="flex items-center gap-1.5"><svg viewBox="0 0 16 16" fill="none" className="h-3 w-3" aria-hidden="true"><path d="m3 8 3 3 7-7" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" /></svg>{text}</span>)}
                        </div>
                    </section>
                    <section className="flex items-center justify-center px-6 py-10 sm:px-12 sm:py-14 lg:px-10 xl:px-16">
                        <div className="w-full max-w-[360px]">
                            <span className="mb-6 inline-flex items-center gap-1.5 rounded-full border border-brand-100 bg-brand-50 px-3 py-1.5 text-[10px] font-semibold text-brand-700">
                                <svg viewBox="0 0 16 16" fill="none" className="h-3 w-3" aria-hidden="true"><path d="m8 1 1.8 4.2L14 7l-4.2 1.8L8 13 6.2 8.8 2 7l4.2-1.8L8 1Z" stroke="currentColor" strokeWidth="1.2" strokeLinejoin="round" /></svg>A brighter way to Pinterest
                            </span>
                            <h1 className="text-[30px] font-semibold leading-tight tracking-[-.035em] text-gray-900">{title}</h1>
                            <p className="mb-8 mt-3 text-sm leading-6 text-gray-500">{subtitle}</p>
                            {children}
                        </div>
                    </section>
                </div>
            </main>
            <footer className="mx-auto flex w-full max-w-[1160px] flex-col items-center justify-between gap-2 text-[11px] text-gray-500 sm:flex-row">
                <span>PinBot. Make something worth saving.</span>
                <span className="flex items-center gap-1.5"><svg viewBox="0 0 16 16" fill="none" className="h-3.5 w-3.5" aria-hidden="true"><path d="M8 1.5 13 4v4c0 3-5 6.5-5 6.5S3 11 3 8V4l5-2.5Z" stroke="currentColor" strokeWidth="1.2" strokeLinejoin="round" /><path d="m5.5 7.5 1.7 1.7 3.3-3.4" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round" /></svg>Connect through official Pinterest OAuth</span>
            </footer>
        </div>
    );
}

function PasswordField({ label, error, hint, ...props }) {
    const id = useId();
    const [visible, setVisible] = useState(false);
    return (
        <div>
            <label htmlFor={id} className="mb-1 block text-sm font-medium text-gray-700">{label}</label>
            <div className="relative">
                <Input {...props} id={id} type={visible ? 'text' : 'password'} className="pr-12" aria-invalid={Boolean(error)} aria-describedby={error || hint ? `${id}-message` : undefined} />
                <button type="button" onClick={() => setVisible(!visible)} aria-label={visible ? `Hide ${label.toLowerCase()}` : `Show ${label.toLowerCase()}`} aria-pressed={visible} className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-xl text-gray-400 transition hover:text-gray-700 focus-visible:outline-2 focus-visible:outline-brand-600">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" className="h-4 w-4" aria-hidden="true">
                        {visible ? <path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.8 5.2a11 11 0 0 1 2.2-.2c6 0 10 7 10 7a18 18 0 0 1-3.1 3.8M6.5 6.5C3.7 8.5 2 12 2 12s4 7 10 7a10.7 10.7 0 0 0 5.5-1.6" /> : <><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></>}
                    </svg>
                </button>
            </div>
            {(error || hint) && <span id={`${id}-message`} className={`mt-1 block text-xs ${error ? 'text-red-600' : 'text-gray-500'}`}>{error || hint}</span>}
        </div>
    );
}

function SubmitArrow() {
    return <svg viewBox="0 0 20 20" fill="none" className="h-4 w-4" aria-hidden="true"><path d="M4 10h12m-5-5 5 5-5 5" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" /></svg>;
}

export function Login() {
    const { login } = useAuth();
    const [form, setForm] = useState({ email: '', password: '', remember: true });
    const [errors, setErrors] = useState({});
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setErrors({});
        setError('');
        try {
            await login(form);
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!err.response?.data?.errors) setError(errorMessage(err));
        } finally {
            setLoading(false);
        }
    };

    return (
        <Shell title="Welcome back." subtitle="Your ideas have a place here. Sign in and pick up where you left off.">
            <form onSubmit={submit} className="space-y-5">
                {error && <Alert type="error">{error}</Alert>}
                <Field label="Email address" error={errors.email}>
                    <Input type="email" autoComplete="email" placeholder="you@example.com" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} aria-invalid={Boolean(errors.email)} />
                </Field>
                <PasswordField label="Password" error={errors.password} autoComplete="current-password" placeholder="Enter your password" required value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
                <label className="inline-flex cursor-pointer items-center gap-2.5 text-xs text-gray-600">
                    <input type="checkbox" checked={form.remember} onChange={(e) => setForm({ ...form, remember: e.target.checked })} className="h-4 w-4 rounded border-gray-300 accent-brand-600" />Keep me signed in
                </label>
                <Button type="submit" className="w-full !py-3" loading={loading}>
                    Sign in to your workspace <SubmitArrow />
                </Button>
                <p className="border-t border-gray-100 pt-6 text-center text-xs text-gray-500">
                    New around here? <Link to="/register" className="font-semibold text-brand-600 hover:underline">Create an account</Link>
                </p>
            </form>
        </Shell>
    );
}

export function Register() {
    const { register } = useAuth();
    const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
    const [errors, setErrors] = useState({});
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setErrors({});
        setError('');
        try {
            await register(form);
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!err.response?.data?.errors) setError(errorMessage(err));
        } finally {
            setLoading(false);
        }
    };

    return (
        <Shell title="Good things start here." subtitle="Create your account and turn your next idea into something worth saving.">
            <form onSubmit={submit} className="space-y-4">
                {error && <Alert type="error">{error}</Alert>}
                <Field label="Your name" error={errors.name}>
                    <Input required value={form.name} onChange={set('name')} autoComplete="name" placeholder="Alex Morgan" aria-invalid={Boolean(errors.name)} />
                </Field>
                <Field label="Email address" error={errors.email}>
                    <Input type="email" required value={form.email} onChange={set('email')} autoComplete="email" placeholder="you@example.com" aria-invalid={Boolean(errors.email)} />
                </Field>
                <PasswordField label="Password" error={errors.password} hint="At least 8 characters with letters and numbers." required value={form.password} onChange={set('password')} autoComplete="new-password" placeholder="Create a strong password" />
                <PasswordField label="Confirm password" error={errors.password_confirmation} required value={form.password_confirmation} onChange={set('password_confirmation')} autoComplete="new-password" placeholder="Enter your password again" />
                <Button type="submit" className="w-full !py-3" loading={loading}>
                    Create your workspace <SubmitArrow />
                </Button>
                <p className="border-t border-gray-100 pt-5 text-center text-xs text-gray-500">
                    Already have an account? <Link to="/login" className="font-semibold text-brand-600 hover:underline">Sign in</Link>
                </p>
            </form>
        </Shell>
    );
}
