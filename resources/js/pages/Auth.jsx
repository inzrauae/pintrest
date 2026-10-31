import { useState } from 'react';
import { Link } from 'react-router-dom';
import { errorMessage, fieldErrors } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Alert, Button, Card, Field, Input } from '../components/ui';

function Shell({ title, subtitle, children }) {
    return (
        <div className="min-h-screen grid place-items-center px-4 py-10 bg-gradient-to-br from-brand-50 via-white to-gray-50">
            <div className="w-full max-w-md">
                <div className="flex items-center justify-center gap-2 mb-6 font-semibold text-xl">
                    <span className="h-9 w-9 rounded-full bg-brand-600 text-white grid place-items-center font-bold">P</span>
                    PinBot
                </div>
                <Card className="p-6 sm:p-8">
                    <h1 className="text-xl font-semibold">{title}</h1>
                    <p className="text-sm text-gray-500 mt-1 mb-6">{subtitle}</p>
                    {children}
                </Card>
            </div>
        </div>
    );
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
        <Shell title="Sign in" subtitle="Turn any web page into a Pinterest-ready Pin.">
            <form onSubmit={submit} className="space-y-4">
                {error && <Alert type="error">{error}</Alert>}
                <Field label="Email" error={errors.email}>
                    <Input type="email" autoComplete="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                </Field>
                <Field label="Password" error={errors.password}>
                    <Input type="password" autoComplete="current-password" required value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
                </Field>
                <Button type="submit" className="w-full" loading={loading}>
                    Sign in
                </Button>
                <p className="text-sm text-center text-gray-500">
                    No account? <Link to="/register" className="text-brand-600 font-medium">Create one</Link>
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
        <Shell title="Create your account" subtitle="Start generating SEO-optimized Pins in minutes.">
            <form onSubmit={submit} className="space-y-4">
                {error && <Alert type="error">{error}</Alert>}
                <Field label="Name" error={errors.name}>
                    <Input required value={form.name} onChange={set('name')} autoComplete="name" />
                </Field>
                <Field label="Email" error={errors.email}>
                    <Input type="email" required value={form.email} onChange={set('email')} autoComplete="email" />
                </Field>
                <Field label="Password" error={errors.password} hint="At least 8 characters with letters and numbers.">
                    <Input type="password" required value={form.password} onChange={set('password')} autoComplete="new-password" />
                </Field>
                <Field label="Confirm password">
                    <Input type="password" required value={form.password_confirmation} onChange={set('password_confirmation')} autoComplete="new-password" />
                </Field>
                <Button type="submit" className="w-full" loading={loading}>
                    Create account
                </Button>
                <p className="text-sm text-center text-gray-500">
                    Already registered? <Link to="/login" className="text-brand-600 font-medium">Sign in</Link>
                </p>
            </form>
        </Shell>
    );
}
