import { useEffect, useState } from 'react';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useApi } from '../lib/hooks';
import { Alert, Button, Card, Checkbox, Field, Input, Loading, PageHeader, Select, useToast } from '../components/ui';
import { ImageModeSelect, LanguageSelect } from '../components/PinParts';

function Section({ title, description, children }) {
    return (
        <Card className="p-5 sm:p-6 grid md:grid-cols-3 gap-5">
            <div>
                <h2 className="font-semibold">{title}</h2>
                {description && <p className="text-sm text-gray-500 mt-1">{description}</p>}
            </div>
            <div className="md:col-span-2 space-y-4">{children}</div>
        </Card>
    );
}

function Projects() {
    const toast = useToast();
    const { data, reload } = useApi('/projects');
    const [name, setName] = useState('');
    const add = async (e) => {
        e.preventDefault();
        try {
            await api.post('/projects', { name });
            setName('');
            reload(true);
        } catch (err) {
            toast(errorMessage(err), 'error');
        }
    };
    const remove = async (p) => {
        if (!window.confirm(`Delete project “${p.name}”? Pins are kept.`)) return;
        await api.delete(`/projects/${p.id}`).catch((e) => toast(errorMessage(e), 'error'));
        reload(true);
    };
    return (
        <Section title="Projects" description="Group Pins by site or campaign.">
            <form onSubmit={add} className="flex gap-2">
                <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="New project name" maxLength={100} />
                <Button type="submit" disabled={!name.trim()}>Add</Button>
            </form>
            <ul className="divide-y divide-gray-100 border border-gray-100 rounded-lg">
                {(data?.data || []).map((p) => (
                    <li key={p.id} className="px-3 py-2 flex items-center justify-between text-sm">
                        <span>{p.name} <span className="text-gray-400">· {p.pins_count} pins</span></span>
                        <button className="text-xs text-red-600" onClick={() => remove(p)}>Delete</button>
                    </li>
                ))}
                {data?.data?.length === 0 && <li className="px-3 py-2 text-sm text-gray-500">No projects yet.</li>}
            </ul>
        </Section>
    );
}

export default function UserSettings() {
    const toast = useToast();
    const { user, setUser } = useAuth();
    const meta = useApi('/settings/meta');
    const { data, loading } = useApi('/settings/preferences');
    const [prefs, setPrefs] = useState(null);
    const [profile, setProfile] = useState({ name: user.name, email: user.email });
    const [password, setPassword] = useState({ current_password: '', password: '', password_confirmation: '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState('');
    const [ack, setAck] = useState(false);

    useEffect(() => {
        if (data) setPrefs({ ...data.data, publishing_window_start: data.data.publishing_window_start || '', publishing_window_end: data.data.publishing_window_end || '' });
    }, [data]);

    const submit = async (key, fn, msg) => {
        setBusy(key);
        setErrors({});
        try {
            const r = await fn();
            toast(msg);
            return r;
        } catch (e) {
            setErrors(fieldErrors(e));
            toast(errorMessage(e), 'error');
        } finally {
            setBusy('');
        }
    };

    if (loading || !prefs) return <Loading />;
    const set = (k, cast = (v) => v) => (e) => setPrefs({ ...prefs, [k]: cast(e.target.type === 'checkbox' ? e.target.checked : e.target.value) });

    const savePrefs = async (e) => {
        e.preventDefault();
        const payload = {
            language: prefs.language,
            timezone: prefs.timezone,
            image_mode: prefs.image_mode,
            hourly_processing_limit: Number(prefs.hourly_processing_limit),
            daily_processing_limit: Number(prefs.daily_processing_limit),
            daily_publish_limit: Number(prefs.daily_publish_limit),
            publishing_window_start: prefs.publishing_window_start || null,
            publishing_window_end: prefs.publishing_window_end || null,
            auto_publish: prefs.auto_publish,
            auto_publish_acknowledged: ack,
            auto_publish_spacing_minutes: Number(prefs.auto_publish_spacing_minutes),
            automation_paused: prefs.automation_paused,
        };
        const r = await submit('prefs', () => api.put('/settings/preferences', payload), 'Preferences saved.');
        if (r) setPrefs({ ...r.data.data, publishing_window_start: r.data.data.publishing_window_start || '', publishing_window_end: r.data.data.publishing_window_end || '' });
    };

    const timezones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [prefs.timezone];
    const enablingAuto = prefs.auto_publish && !data.data.auto_publish;

    return (
        <>
            <PageHeader title="User Settings" description="Profile, defaults, limits and automation." />
            <div className="space-y-6">
                <form onSubmit={savePrefs} className="space-y-6">
                    <Section title="Defaults" description="Used for new Pins and batches.">
                        <div className="grid sm:grid-cols-3 gap-4">
                            <Field label="Language">
                                <LanguageSelect value={prefs.language} onChange={(v) => setPrefs({ ...prefs, language: v })} languages={meta.data?.data?.languages} />
                            </Field>
                            <Field label="Image mode">
                                <ImageModeSelect value={prefs.image_mode} onChange={(v) => setPrefs({ ...prefs, image_mode: v })} />
                            </Field>
                            <Field label="Timezone" error={errors.timezone}>
                                <Select value={prefs.timezone} onChange={set('timezone')}>
                                    {!timezones.includes(prefs.timezone) && <option value={prefs.timezone}>{prefs.timezone}</option>}
                                    {timezones.map((tz) => <option key={tz} value={tz}>{tz}</option>)}
                                </Select>
                            </Field>
                        </div>
                    </Section>

                    <Section title="Limits" description="Your own quotas, on top of Pinterest's API rate limits. PinBot never bypasses platform limits.">
                        <div className="grid sm:grid-cols-3 gap-4">
                            <Field label="URLs per hour" error={errors.hourly_processing_limit}>
                                <Input type="number" min="1" max="1000" value={prefs.hourly_processing_limit} onChange={set('hourly_processing_limit')} />
                            </Field>
                            <Field label="URLs per day" error={errors.daily_processing_limit}>
                                <Input type="number" min="1" max="5000" value={prefs.daily_processing_limit} onChange={set('daily_processing_limit')} />
                            </Field>
                            <Field label="Pins published per day" error={errors.daily_publish_limit}>
                                <Input type="number" min="1" max="500" value={prefs.daily_publish_limit} onChange={set('daily_publish_limit')} />
                            </Field>
                        </div>
                    </Section>

                    <Section title="Publishing window & automation" description="Scheduled and automatic publishing only happen inside this window (your timezone).">
                        <div className="grid sm:grid-cols-2 gap-4">
                            <Field label="Window start" error={errors.publishing_window_start} hint="Leave both empty for any time.">
                                <Input type="time" value={prefs.publishing_window_start} onChange={set('publishing_window_start')} />
                            </Field>
                            <Field label="Window end" error={errors.publishing_window_end}>
                                <Input type="time" value={prefs.publishing_window_end} onChange={set('publishing_window_end')} />
                            </Field>
                        </div>
                        <Checkbox checked={prefs.automation_paused} onChange={set('automation_paused')} label="Pause all automation (scheduled + auto-publish)" />
                        <div className="rounded-lg border border-gray-200 p-4 space-y-3">
                            <Checkbox checked={prefs.auto_publish} onChange={set('auto_publish')} label={<><strong>Auto-publish approved Pins</strong> — publish Pins you have approved, one at a time, without scheduling each.</>} />
                            {prefs.auto_publish && (
                                <Field label="Minimum minutes between automatic Pins" error={errors.auto_publish_spacing_minutes}>
                                    <Input type="number" min="5" max="1440" value={prefs.auto_publish_spacing_minutes} onChange={set('auto_publish_spacing_minutes')} className="sm:w-40" />
                                </Field>
                            )}
                            {enablingAuto && (
                                <Alert>
                                    <Checkbox checked={ack} onChange={(e) => setAck(e.target.checked)} label="I understand approved Pins will be published to Pinterest automatically, within my window and daily limit, without another confirmation. Unreviewed drafts are never auto-published." />
                                    {errors.auto_publish_acknowledged && <p className="text-xs text-red-600 mt-1">{errors.auto_publish_acknowledged}</p>}
                                </Alert>
                            )}
                        </div>
                        <Button type="submit" loading={busy === 'prefs'}>Save preferences</Button>
                    </Section>
                </form>

                <Projects />

                <Section title="Profile">
                    <form
                        className="space-y-4"
                        onSubmit={async (e) => {
                            e.preventDefault();
                            const r = await submit('profile', () => api.put('/settings/profile', profile), 'Profile updated.');
                            if (r) setUser(r.data.data);
                        }}
                    >
                        <div className="grid sm:grid-cols-2 gap-4">
                            <Field label="Name" error={errors.name}><Input value={profile.name} onChange={(e) => setProfile({ ...profile, name: e.target.value })} /></Field>
                            <Field label="Email" error={errors.email}><Input type="email" value={profile.email} onChange={(e) => setProfile({ ...profile, email: e.target.value })} /></Field>
                        </div>
                        <Button type="submit" variant="secondary" loading={busy === 'profile'}>Update profile</Button>
                    </form>
                </Section>

                <Section title="Password">
                    <form
                        className="space-y-4"
                        onSubmit={async (e) => {
                            e.preventDefault();
                            const r = await submit('password', () => api.put('/settings/password', password), 'Password changed.');
                            if (r) setPassword({ current_password: '', password: '', password_confirmation: '' });
                        }}
                    >
                        <Field label="Current password" error={errors.current_password}><Input type="password" autoComplete="current-password" value={password.current_password} onChange={(e) => setPassword({ ...password, current_password: e.target.value })} /></Field>
                        <div className="grid sm:grid-cols-2 gap-4">
                            <Field label="New password" error={errors.password}><Input type="password" autoComplete="new-password" value={password.password} onChange={(e) => setPassword({ ...password, password: e.target.value })} /></Field>
                            <Field label="Confirm new password"><Input type="password" autoComplete="new-password" value={password.password_confirmation} onChange={(e) => setPassword({ ...password, password_confirmation: e.target.value })} /></Field>
                        </div>
                        <Button type="submit" variant="secondary" loading={busy === 'password'}>Change password</Button>
                    </form>
                </Section>
            </div>
        </>
    );
}
