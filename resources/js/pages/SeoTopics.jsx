import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { formatDate, timeAgo, useApi } from '../lib/hooks';
import { Alert, Badge, Button, Card, Checkbox, EmptyState, ErrorBox, Field, Input, Loading, Modal, PageHeader, Select, Stat, useToast } from '../components/ui';
import { BoardPicker, ImageModeSelect, LanguageSelect } from '../components/PinParts';
import DailyChart from '../components/DailyChart';

const ANALYTICS_SERIES = [
    { key: 'impressions', label: 'Impressions', color: '#7c8fa7' },
    { key: 'saves', label: 'Saves', color: '#dc3849' },
    { key: 'outbound_clicks', label: 'Outbound clicks', color: '#688c73' },
];

const EMPTY_TOPIC = {
    name: '', keywords: '', source_url: '', url_filters: '', pinterest_account_id: null, board_id: null, pin_template_id: null,
    language: 'en', image_mode: 'extract', owns_images: false, auto_discover: true, auto_approve: false, max_new_per_run: 10, frequency_hours: 24,
};

const list = (s) => String(s || '').split(/[,\n]/).map((x) => x.trim()).filter(Boolean);
const n = (v) => (v ?? 0).toLocaleString();

function Growth({ value }) {
    if (value === null || value === undefined) return <span className="text-gray-300">—</span>;
    return <span className={value >= 0 ? 'text-emerald-600' : 'text-red-600'}>{value > 0 ? '+' : ''}{value}%</span>;
}

function TopicForm({ open, topic, onClose, onSaved }) {
    const toast = useToast();
    const meta = useApi('/settings/meta');
    const templates = useApi('/templates');
    const [form, setForm] = useState(EMPTY_TOPIC);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setForm(topic ? { ...EMPTY_TOPIC, ...topic, keywords: (topic.keywords || []).join(', '), url_filters: (topic.url_filters || []).join(', ') } : EMPTY_TOPIC);
    }, [open, topic]);

    const set = (k) => (v) => setForm((f) => ({ ...f, [k]: v }));
    const save = async () => {
        setSaving(true);
        setErrors({});
        const payload = { ...form, keywords: list(form.keywords), url_filters: list(form.url_filters), source_url: form.source_url || null, max_new_per_run: Number(form.max_new_per_run), frequency_hours: Number(form.frequency_hours) };
        try {
            const r = topic ? await api.put(`/seo/topics/${topic.id}`, payload) : await api.post('/seo/topics', payload);
            toast(topic ? 'Topic saved.' : 'Topic created.');
            onSaved(r.data.data);
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!Object.keys(fieldErrors(err)).length) toast(errorMessage(err), 'error');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open={open} onClose={onClose} wide title={topic ? `Edit topic: ${topic.name}` : 'New SEO topic'} footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button loading={saving} onClick={save}>Save topic</Button></>}>
            <div className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Topic name" error={errors.name}><Input value={form.name} onChange={(e) => set('name')(e.target.value)} placeholder="Pharmacy POS" maxLength={100} /></Field>
                    <Field label="Target keywords" error={errors.keywords} hint="Comma-separated search phrases you want to rank for."><Input value={form.keywords} onChange={(e) => set('keywords')(e.target.value)} placeholder="pharmacy pos system, pharmacy billing software" /></Field>
                    <Field label="Website or sitemap" error={errors.source_url} hint="New pages here become Pins for this topic."><Input value={form.source_url || ''} onChange={(e) => set('source_url')(e.target.value)} placeholder="https://example.com/sitemap.xml" /></Field>
                    <Field label="Only URLs containing" error={errors.url_filters} hint="Optional, comma-separated, e.g. /pharmacy, /blog/"><Input value={form.url_filters} onChange={(e) => set('url_filters')(e.target.value)} /></Field>
                </div>
                <div>
                    <span className="ui-field-label">Publish to board</span>
                    <BoardPicker accountId={form.pinterest_account_id} boardId={form.board_id} onChange={({ pinterest_account_id: a, board_id: b }) => setForm((f) => ({ ...f, pinterest_account_id: a, board_id: b }))} />
                    {errors.board_id && <span className="mt-1 block text-xs text-red-600">{errors.board_id}</span>}
                </div>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="Image"><ImageModeSelect value={form.image_mode} onChange={set('image_mode')} /></Field>
                    <Field label="Design template">
                        <Select value={form.pin_template_id || ''} onChange={(e) => set('pin_template_id')(e.target.value ? Number(e.target.value) : null)}>
                            <option value="">Default template</option>
                            {(templates.data?.data || []).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="Language"><LanguageSelect value={form.language} onChange={set('language')} languages={meta.data?.data?.languages} /></Field>
                    <Field label="Check for new pages every" error={errors.frequency_hours}>
                        <Select value={form.frequency_hours} onChange={(e) => set('frequency_hours')(e.target.value)}>
                            {[[6, '6 hours'], [12, '12 hours'], [24, 'day'], [72, '3 days'], [168, 'week']].map(([h, l]) => <option key={h} value={h}>{l}</option>)}
                        </Select>
                    </Field>
                    <Field label="Max new Pins per run" error={errors.max_new_per_run}><Input type="number" min={1} max={100} value={form.max_new_per_run} onChange={(e) => set('max_new_per_run')(e.target.value)} /></Field>
                </div>
                <div className="space-y-2">
                    <Checkbox checked={form.auto_discover} onChange={(e) => set('auto_discover')(e.target.checked)} label="Autopilot: discover new pages and create AI Pins automatically" />
                    <Checkbox checked={form.owns_images} onChange={(e) => set('owns_images')(e.target.checked)} label="I own the images on this website (lets Pins use them without a per-Pin rights confirmation)" />
                    <Checkbox checked={form.auto_approve} onChange={(e) => set('auto_approve')(e.target.checked)} label="Auto-approve Pins that pass all publishing checks (they publish only if Automatic publishing is on)" />
                </div>
                {form.auto_approve && <Alert type="info">Approved Pins are posted by Automatic publishing (User Settings), inside your publishing window, daily limit and spacing. Without it, approved Pins wait in the Publishing Queue for you.</Alert>}
            </div>
        </Modal>
    );
}

function TopicCard({ topic, onEdit, onChanged }) {
    const toast = useToast();
    const [busy, setBusy] = useState('');
    const [open, setOpen] = useState(false);
    const detail = useApi(`/seo/topics/${topic.id}`, null, { enabled: open });
    const a = topic.analytics.totals;
    const s = topic.pins.by_status || {};

    const run = async () => {
        setBusy('run');
        try {
            const r = await api.post(`/seo/topics/${topic.id}/run`);
            toast(`${r.data.run.new} new page(s) found, ${r.data.run.queued} queued for Pins.`);
            onChanged();
        } catch (err) {
            toast(errorMessage(err), 'error');
            onChanged();
        } finally {
            setBusy('');
        }
    };
    const remove = async () => {
        if (!window.confirm(`Delete topic “${topic.name}”? Its Pins are kept.`)) return;
        setBusy('delete');
        try {
            await api.delete(`/seo/topics/${topic.id}`);
            onChanged();
        } finally {
            setBusy('');
        }
    };

    return (
        <Card className="p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="font-semibold">{topic.name} {topic.auto_discover && topic.source_url ? <Badge status="processing">autopilot</Badge> : <Badge status="paused">manual</Badge>}</h3>
                    <div className="mt-1 flex flex-wrap gap-1">{topic.keywords.map((k) => <span key={k} className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{k}</span>)}</div>
                    <p className="mt-2 text-xs text-gray-500">
                        {topic.source_url ? <>Source: <span className="text-gray-700">{topic.source_url}</span></> : 'No source website'} · Board: <span className="text-gray-700">{topic.board_name || 'default'}</span>
                        {topic.auto_approve && ' · auto-approve'}
                    </p>
                    <p className="mt-0.5 text-xs text-gray-500">
                        Last run: {topic.last_run_at ? `${timeAgo(topic.last_run_at)} — ${topic.last_run?.error ? topic.last_run.error : `${topic.last_run?.new ?? 0} new, ${topic.last_run?.queued ?? 0} queued`}` : 'never'}
                        {topic.next_run_at && ` · next ${formatDate(topic.next_run_at)}`}
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button size="sm" loading={busy === 'run'} disabled={!topic.source_url || !!busy} onClick={run}>Run now</Button>
                    <Button size="sm" variant="secondary" onClick={() => onEdit(topic)}>Edit</Button>
                    <Button size="sm" variant="ghost" loading={busy === 'delete'} onClick={remove}>Delete</Button>
                </div>
            </div>

            <div className="mt-4 grid grid-cols-3 gap-3 text-center sm:grid-cols-7">
                {[['Pins', topic.pins.total], ['Drafts', s.draft], ['Approved', (s.approved || 0) + (s.scheduled || 0)], ['Published', s.published], ['Avg SEO', topic.pins.average_seo ?? '—'], ['Impressions', n(a.impressions)], ['Outbound clicks', n(a.outbound_clicks)]].map(([label, value]) => (
                    <div key={label} className="rounded-lg bg-gray-50 px-2 py-2"><div className="text-lg font-semibold tabular-nums">{value ?? 0}</div><div className="text-[11px] text-gray-500">{label}</div></div>
                ))}
            </div>

            <button type="button" className="mt-3 text-xs font-medium text-brand-600 hover:underline" onClick={() => setOpen(!open)}>{open ? 'Hide details ▲' : 'Keywords, trends & top Pins ▼'}</button>
            {open && (detail.loading ? <Loading /> : detail.error ? <ErrorBox message={detail.error} onRetry={detail.reload} /> : (
                <div className="mt-3 grid gap-5 lg:grid-cols-3">
                    <div>
                        <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Pinterest searches for this topic</h4>
                        {detail.data.keywords.length ? <ul className="space-y-1 text-sm">{detail.data.keywords.slice(0, 12).map((k) => <li key={k.keyword} className="flex justify-between gap-2"><span className="truncate">{k.keyword}</span><Growth value={k.growth} /></li>)}</ul> : <p className="text-sm text-gray-500">No research data (see SEO Dashboard notice).</p>}
                    </div>
                    <div>
                        <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Trending now</h4>
                        {detail.data.trends.length ? <ul className="space-y-1 text-sm">{detail.data.trends.slice(0, 12).map((t) => <li key={t.keyword} className="flex justify-between gap-2"><span className="truncate">{t.keyword}</span><Growth value={t.pct_growth_mom} /></li>)}</ul> : <p className="text-sm text-gray-500">None of this topic's keywords are trending in your region right now.</p>}
                    </div>
                    <div>
                        <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Top Pins (30 days)</h4>
                        {detail.data.pins.length ? <ul className="space-y-1.5 text-sm">{detail.data.pins.slice(0, 8).map((p) => (
                            <li key={p.id} className="flex items-center justify-between gap-2">
                                <Link to={`/pins/${p.id}`} className="truncate hover:underline">{p.title || 'Untitled'}</Link>
                                <span className="shrink-0 text-xs text-gray-500 tabular-nums">{p.status === 'published' ? `${n(p.impressions)} imp · ${n(p.outbound_clicks)} clicks` : p.status}</span>
                            </li>
                        ))}</ul> : <p className="text-sm text-gray-500">No Pins yet — click Run now.</p>}
                    </div>
                </div>
            ))}
        </Card>
    );
}

export default function SeoTopics() {
    const toast = useToast();
    const topics = useApi('/seo/topics');
    const [editing, setEditing] = useState(null);
    const [formOpen, setFormOpen] = useState(false);
    const [syncing, setSyncing] = useState(false);

    if (topics.loading) return <Loading />;
    if (topics.error) return <ErrorBox message={topics.error} onRetry={topics.reload} />;
    const { data: list, analytics, automation } = topics.data;
    const t = analytics.totals;

    const sync = async () => {
        setSyncing(true);
        try {
            const r = await api.post('/seo/analytics/sync');
            toast(r.data.message, 'info');
            topics.reload(true);
        } catch (err) {
            toast(errorMessage(err), 'error');
        } finally {
            setSyncing(false);
        }
    };

    return (
        <>
            <PageHeader
                title="SEO Autopilot"
                description="Per topic: discover new pages → AI Pins built on real Pinterest searches → publish to the topic's board → track results."
                actions={<><Link to="/seo"><Button variant="secondary">SEO Dashboard</Button></Link><Button onClick={() => { setEditing(null); setFormOpen(true); }}>New topic</Button></>}
            />

            <div className="mb-5 space-y-2">
                {!automation.production_connected && <Alert type="info">Analytics come from Pinterest for Pins published with a <b>production</b> account (the sandbox has none). <Link to="/accounts" className="font-semibold underline">Connect production</Link>.</Alert>}
                {!automation.auto_publish && <Alert type="info">Automatic publishing is off, so auto-approved Pins wait in the Publishing Queue. Turn it on in <Link to="/settings" className="font-semibold underline">User Settings</Link> to post them automatically (up to {automation.daily_publish_limit}/day, {automation.spacing_minutes} min apart).</Alert>}
                {automation.paused && <Alert>Automation is paused. <Link to="/queue" className="font-semibold underline">Resume in Publishing Queue</Link>.</Alert>}
            </div>

            <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                <Stat label="Impressions (30d)" value={n(t.impressions)} tone="brand" />
                <Stat label="Saves" value={n(t.saves)} tone="violet" />
                <Stat label="Pin clicks" value={n(t.pin_clicks)} tone="amber" />
                <Stat label="Outbound clicks" value={n(t.outbound_clicks)} tone="green" />
                <Stat label="Click-through rate" value={analytics.ctr === null ? '—' : `${analytics.ctr}%`} hint="Outbound clicks ÷ impressions" />
            </div>

            <Card className="mt-6 p-5 sm:p-6">
                <div className="mb-3 flex items-center justify-between gap-3"><h2 className="font-semibold">Pinterest performance, last 30 days</h2><Button size="sm" variant="secondary" loading={syncing} onClick={sync}>Update analytics</Button></div>
                {analytics.daily.length ? <DailyChart daily={analytics.daily} series={ANALYTICS_SERIES} /> : <p className="text-sm text-gray-500">No analytics yet. They appear a day or two after Pins are published with a production account, and update every 6 hours.</p>}
            </Card>

            <h2 className="mb-3 mt-8 font-semibold">Topics</h2>
            {list.length === 0 ? (
                <Card><EmptyState title="No topics yet" description="Create a topic with the keywords you want to rank for and your website. The autopilot finds new pages and turns them into SEO Pins." icon="🧭" action={<Button onClick={() => setFormOpen(true)}>New topic</Button>} /></Card>
            ) : (
                <div className="space-y-4">{list.map((topic) => <TopicCard key={topic.id} topic={topic} onEdit={(tp) => { setEditing(tp); setFormOpen(true); }} onChanged={() => topics.reload(true)} />)}</div>
            )}

            <TopicForm open={formOpen} topic={editing} onClose={() => setFormOpen(false)} onSaved={() => { setFormOpen(false); topics.reload(true); }} />
        </>
    );
}
