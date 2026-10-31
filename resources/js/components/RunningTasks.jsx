import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { formatDate, timeAgo, useApi, useInterval } from '../lib/hooks';
import { Alert, Badge, Button, Card, useToast } from './ui';

function Dot({ on }) {
    return (
        <span className="relative inline-flex h-2.5 w-2.5" aria-hidden="true">
            {on && <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60" />}
            <span className={`relative inline-flex h-2.5 w-2.5 rounded-full ${on ? 'bg-emerald-500' : 'bg-red-500'}`} />
        </span>
    );
}

function ServicePill({ label, service }) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs" title={service.last_seen_at ? `Last seen ${timeAgo(service.last_seen_at)}` : 'Never seen'}>
            <Dot on={service.running} /> {label}: <span className={service.running ? 'text-emerald-700' : 'text-red-600'}>{service.running ? 'running' : 'stopped'}</span>
        </span>
    );
}

function Progress({ batch }) {
    const c = batch.counts || {};
    const w = (n) => `${((n || 0) / Math.max(1, batch.total)) * 100}%`;
    return (
        <div className="h-2 overflow-hidden rounded-full bg-gray-100 flex" aria-label={`${batch.percent}% done`}>
            <div className="bg-emerald-500" style={{ width: w(c.generated) }} />
            <div className="bg-red-400" style={{ width: w(c.failed) }} />
            <div className="bg-gray-300" style={{ width: w((c.duplicate || 0) + (c.cancelled || 0)) }} />
            <div className="bg-blue-400 animate-pulse" style={{ width: w(c.processing) }} />
        </div>
    );
}

function Row({ title, children, actions }) {
    return (
        <div className="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:px-6">
            <div className="min-w-0 flex-1">
                <div className="text-sm font-medium text-gray-800">{title}</div>
                <div className="mt-1 text-xs text-gray-500">{children}</div>
            </div>
            {actions && <div className="flex shrink-0 flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

/**
 * One-line "what is happening now" bar (Pin Library). Hidden when idle and healthy.
 * onTick(active) is called after each refresh so the page can reload its own data while work is running.
 */
export function NowBar({ onTick }) {
    const toast = useToast();
    const tasks = useApi('/tasks');
    const [busy, setBusy] = useState(false);
    const t = tasks.data?.data;
    useInterval(() => tasks.reload(true), t?.active ? 4000 : 15000, !!t);
    // Each refresh produces a new object; tell the page whether work is running so it can refresh too.
    useEffect(() => {
        if (t) onTick?.(t.active, t);
    }, [t]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!t) return null;
    const { services, batches, generation: g, publishing: p } = t;
    const running = batches.filter((b) => ['processing', 'waiting'].includes(b.status));
    const problems = !services.worker.running || !services.scheduler.running;
    if (!t.active && !t.paused && !problems && !g.waiting_for_quota) return null;

    const toggle = async () => {
        setBusy(true);
        try {
            const r = await api.post('/tasks/pause-all', { paused: !t.paused });
            toast(r.data.message);
            await tasks.reload(true);
        } catch (err) {
            toast(errorMessage(err), 'error');
        } finally {
            setBusy(false);
        }
    };

    const parts = [
        g.processing && `generating ${g.processing} Pin${g.processing > 1 ? 's' : ''}`,
        p.publishing && `publishing ${p.publishing}`,
        g.pending && `${g.pending} queued`,
        g.waiting_for_quota && `${g.waiting_for_quota} waiting for your limit`,
    ].filter(Boolean);

    return (
        <Card className="mb-5 px-4 py-3 sm:px-5">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span className="flex items-center gap-2 text-sm font-semibold"><Dot on={t.active && !t.paused} /> Now</span>
                <span className="min-w-0 flex-1 text-sm text-gray-700">
                    {t.paused ? <span className="text-amber-700">Paused — nothing new starts until you resume.</span> : parts.length ? parts.join(' · ') : 'Idle'}
                    {g.now.length > 0 && <span className="block truncate text-xs text-gray-500">Working on: {g.now.join(', ')}</span>}
                    {p.now.length > 0 && <span className="block truncate text-xs text-gray-500">Publishing: {p.now.map((pin) => pin.title || `Pin #${pin.id}`).join(', ')}</span>}
                </span>
                <span className="flex items-center gap-2">
                    {!services.worker.running && <span className="text-xs text-red-600">Worker stopped</span>}
                    {!services.scheduler.running && <span className="text-xs text-amber-700">Scheduler stopped</span>}
                    <Link to="/" className="text-xs text-brand-600 hover:underline">Details</Link>
                    <Button size="sm" variant={t.paused ? 'primary' : 'secondary'} loading={busy} onClick={toggle}>{t.paused ? '▶ Resume all' : '⏸ Pause all'}</Button>
                </span>
            </div>
            {running.length > 0 && (
                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                    {running.slice(0, 4).map((b) => (
                        <Link key={b.id} to={`/bulk/${b.id}`} className="block rounded-lg border border-gray-100 px-3 py-2 hover:border-gray-200">
                            <div className="mb-1 flex justify-between gap-2 text-xs"><span className="truncate font-medium text-gray-700">{b.name}</span><span className="shrink-0 tabular-nums text-gray-500">{b.done}/{b.total} · {b.percent}%</span></div>
                            <Progress batch={b} />
                        </Link>
                    ))}
                </div>
            )}
        </Card>
    );
}

export default function RunningTasks() {
    const toast = useToast();
    const tasks = useApi('/tasks');
    const [busy, setBusy] = useState('');
    const t = tasks.data?.data;
    // Poll fast while something is moving, slowly otherwise.
    useInterval(() => tasks.reload(true), t?.active ? 4000 : 15000, !!t);

    const act = async (key, fn, success) => {
        setBusy(key);
        try {
            const r = await fn();
            if (success || r?.data?.message) toast(success || r.data.message);
            await tasks.reload(true);
        } catch (err) {
            toast(errorMessage(err), 'error');
        } finally {
            setBusy('');
        }
    };

    if (!t) return null;
    const { services, batches, generation: g, publishing: p, topics } = t;
    const backlog = Object.values(services.queue || {}).reduce((a, b) => a + b, 0);

    return (
        <Card className="mb-6 overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 sm:px-6">
                <div>
                    <h2 className="flex items-center gap-2 text-sm font-semibold">Running tasks {t.active && <Dot on />}</h2>
                    <p className="mt-0.5 text-xs text-gray-400">Live status of batches, Pin generation, publishing and the SEO autopilot. Updates automatically.</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <ServicePill label="Worker" service={services.worker} />
                    <ServicePill label="Scheduler" service={services.scheduler} />
                    {t.paused ? (
                        <Button size="sm" loading={busy === 'all'} onClick={() => act('all', () => api.post('/tasks/pause-all', { paused: false }))}>▶ Resume all</Button>
                    ) : (
                        <Button size="sm" variant="secondary" loading={busy === 'all'} onClick={() => act('all', () => api.post('/tasks/pause-all', { paused: true }))}>⏸ Pause all</Button>
                    )}
                </div>
            </div>

            {(!services.worker.running || !services.scheduler.running || t.paused) && (
                <div className="space-y-2 border-b border-gray-100 px-5 py-3 sm:px-6">
                    {t.paused && <Alert>Everything is paused: no new Pins are generated, published or discovered until you resume.</Alert>}
                    {!services.worker.running && <Alert type="error">The background worker is not running{backlog ? `, so ${backlog} job(s) are waiting` : ''}. Pin generation and retries need it. Start it with <code className="text-xs">php artisan queue:work --queue=publishing,processing,default</code>.</Alert>}
                    {!services.scheduler.running && <Alert>The scheduler is not running, so scheduled Pins, auto-publishing, quota resumes and the SEO autopilot will not run. Start it with <code className="text-xs">php artisan schedule:work</code>.</Alert>}
                </div>
            )}

            <div className="divide-y divide-gray-100">
                <Row title="Pin generation">
                    {g.processing ? <span className="text-blue-700">{g.processing} generating now</span> : 'Idle'}
                    {` · ${g.pending} queued · ${g.waiting_for_quota} waiting for your limit · ${g.remaining_quota} more allowed right now (${g.limits.hourly}/hour, ${g.limits.daily}/day)`}
                    {g.now.length > 0 && <div className="mt-1 truncate">Working on: {g.now.join(', ')}</div>}
                    {g.waiting_for_quota > 0 && <div className="mt-1">Waiting URLs continue automatically every 5 minutes as your limit allows. <Link to="/settings" className="text-brand-600 hover:underline">Change limits</Link></div>}
                </Row>

                <Row
                    title="Publishing"
                    actions={p.paused
                        ? <Button size="sm" loading={busy === 'pub'} onClick={() => act('pub', () => api.post('/publishing-queue/automation', { paused: false }), 'Publishing resumed.')}>▶ Resume publishing</Button>
                        : <Button size="sm" variant="secondary" loading={busy === 'pub'} onClick={() => act('pub', () => api.post('/publishing-queue/automation', { paused: true }), 'Publishing paused.')}>⏸ Pause publishing</Button>}
                >
                    {p.paused ? <span className="text-amber-700">Paused</span> : p.publishing ? <span className="text-blue-700">{p.publishing} publishing now</span> : 'Idle'}
                    {` · ${p.approved} approved waiting · ${p.scheduled} scheduled${p.next_scheduled_at ? ` (next ${formatDate(p.next_scheduled_at)})` : ''} · ${p.published_today}/${p.daily_limit} published today · auto-publish ${p.auto_publish ? 'on' : 'off'}`}
                    {p.now.map((pin) => <div key={pin.id} className="mt-1 truncate"><Link to={`/pins/${pin.id}`} className="text-brand-600 hover:underline">{pin.title || `Pin #${pin.id}`}</Link>{pin.error_message && <span className="text-amber-700"> — {pin.error_message}</span>}</div>)}
                    <div className="mt-1"><Link to="/queue" className="text-brand-600 hover:underline">Open Publishing Queue →</Link></div>
                </Row>

                {batches.length === 0 ? (
                    <Row title="Batches">No batches running. <Link to="/site" className="text-brand-600 hover:underline">Create Pins from a website →</Link></Row>
                ) : batches.map((b) => (
                    <Row
                        key={b.id}
                        title={<span className="flex items-center gap-2"><Link to={`/bulk/${b.id}`} className="truncate hover:underline">{b.name}</Link><Badge status={b.status === 'waiting' ? 'paused' : b.status}>{b.status === 'waiting' ? 'waiting for limit' : b.status}</Badge></span>}
                        actions={<>
                            {b.status === 'processing' && <Button size="sm" variant="secondary" loading={busy === `p${b.id}`} onClick={() => act(`p${b.id}`, () => api.post(`/batches/${b.id}/pause`), 'Batch paused.')}>⏸ Pause</Button>}
                            {['paused', 'waiting'].includes(b.status) && <Button size="sm" loading={busy === `r${b.id}`} onClick={() => act(`r${b.id}`, () => api.post(`/batches/${b.id}/resume`), 'Batch resumed.')}>▶ Resume</Button>}
                            <Button size="sm" variant="ghost" loading={busy === `c${b.id}`} onClick={() => window.confirm(`Cancel “${b.name}”? URLs not yet processed are skipped; Pins already made are kept.`) && act(`c${b.id}`, () => api.post(`/batches/${b.id}/cancel`), 'Batch cancelled.')}>Cancel</Button>
                        </>}
                    >
                        <Progress batch={b} />
                        <div className="mt-1.5">
                            {b.done}/{b.total} done ({b.percent}%) · {b.counts.generated || 0} Pins · {b.counts.processing || 0} generating · {b.counts.pending || 0} queued
                            {b.waiting_for_quota ? ` · ${b.waiting_for_quota} waiting for limit` : ''}{b.counts.failed ? ` · ${b.counts.failed} failed` : ''} · started {timeAgo(b.created_at)}
                        </div>
                    </Row>
                ))}

                {topics.length === 0 && (
                    <Row title="SEO autopilot">No topics yet, so nothing runs automatically. <Link to="/seo/topics" className="text-brand-600 hover:underline">Create a topic →</Link> to discover new pages and make Pins on a schedule.</Row>
                )}
                {topics.length > 0 && topics.map((topic) => (
                    <Row
                        key={`t${topic.id}`}
                        title={<span className="flex items-center gap-2">SEO autopilot: <Link to="/seo/topics" className="hover:underline">{topic.name}</Link><Badge status={topic.paused ? 'paused' : topic.active ? 'processing' : 'draft'}>{topic.paused ? 'paused' : topic.active ? 'on' : 'no website'}</Badge></span>}
                        actions={topic.paused
                            ? <Button size="sm" loading={busy === `t${topic.id}`} onClick={() => act(`t${topic.id}`, () => api.put(`/seo/topics/${topic.id}`, { auto_discover: true }), 'Autopilot resumed.')}>▶ Resume</Button>
                            : <Button size="sm" variant="secondary" loading={busy === `t${topic.id}`} onClick={() => act(`t${topic.id}`, () => api.put(`/seo/topics/${topic.id}`, { auto_discover: false }), 'Autopilot paused.')}>⏸ Pause</Button>}
                    >
                        Last run {topic.last_run_at ? `${timeAgo(topic.last_run_at)} — ${topic.last_run?.error || `${topic.last_run?.new ?? 0} new, ${topic.last_run?.queued ?? 0} queued`}` : 'never'}
                        {topic.next_run_at && !topic.paused && ` · next ${formatDate(topic.next_run_at)}`}
                    </Row>
                ))}

                <Row title={<>Recently finished <span className="font-normal text-gray-500">· today {t.today.generated} Pins made, {t.today.published} published{t.today.failed ? `, ${t.today.failed} errors` : ''}</span></>}>
                    {!t.active && <div className="mb-1 text-gray-600">Nothing is running right now — all work is done.</div>}
                    {t.recent.length === 0 ? 'No activity yet.' : (
                        <ul className="space-y-1">
                            {t.recent.map((e) => (
                                <li key={e.id} className="flex gap-2">
                                    <span className={`shrink-0 ${e.level === 'error' ? 'text-red-600' : e.level === 'warning' ? 'text-amber-600' : 'text-emerald-600'}`}>{e.level === 'error' ? '✕' : e.level === 'warning' ? '!' : '✓'}</span>
                                    <span className="min-w-0 truncate">{e.generated_pin_id ? <Link to={`/pins/${e.generated_pin_id}`} className="hover:underline">{e.message}</Link> : e.message}</span>
                                    <span className="ml-auto shrink-0 text-gray-400">{timeAgo(e.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="mt-1"><Link to="/reports" className="text-brand-600 hover:underline">Full activity log →</Link></div>
                </Row>
            </div>
        </Card>
    );
}
