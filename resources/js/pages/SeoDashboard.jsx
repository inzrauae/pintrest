import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { useApi } from '../lib/hooks';
import { Alert, Button, Card, EmptyState, ErrorBox, Field, Input, Loading, PageHeader, Pagination, Select, Stat, useToast } from '../components/ui';

const ISSUE_TIPS = {
    focus_in_title: 'Optimize with AI rewrites titles around a real Pinterest search.',
    description_keywords: 'Descriptions need 3+ keywords used naturally.',
    board: 'Use boards named after your main keywords — see suggestions below.',
    pinterest_terms: 'Optimize with AI to work researched searches into the copy.',
};

function scoreTone(score) {
    if (score === null || score === undefined) return 'text-gray-400 bg-gray-50 ring-gray-200';
    if (score >= 80) return 'text-emerald-700 bg-emerald-50 ring-emerald-200';
    if (score >= 50) return 'text-amber-700 bg-amber-50 ring-amber-200';
    return 'text-red-700 bg-red-50 ring-red-200';
}

function ScorePill({ score }) {
    return <span className={`inline-flex min-w-[2.75rem] justify-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset tabular-nums ${scoreTone(score)}`}>{score ?? '—'}</span>;
}

function Growth({ value }) {
    if (value === null || value === undefined) return <span className="text-gray-300">—</span>;
    return <span className={`tabular-nums ${value >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{value > 0 ? '+' : ''}{value}%</span>;
}

function Sparkline({ series }) {
    const values = Object.keys(series || {}).sort().map((k) => Number(series[k]) || 0);
    if (values.length < 2) return null;
    const max = Math.max(...values, 1);
    const points = values.map((v, i) => `${(i / (values.length - 1)) * 80},${20 - (v / max) * 18}`).join(' ');
    return <svg viewBox="0 0 80 22" className="h-5 w-20 text-brand-500" aria-hidden="true"><polyline points={points} fill="none" stroke="currentColor" strokeWidth="1.5" /></svg>;
}

function SourceTags({ sources }) {
    const styles = { suggested: 'bg-blue-50 text-blue-700', related: 'bg-violet-50 text-violet-700', trending: 'bg-emerald-50 text-emerald-700' };
    return (
        <span className="flex flex-wrap gap-1">
            {(sources || []).map((s) => <span key={s} className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${styles[s] || 'bg-gray-100 text-gray-600'}`}>{s}</span>)}
        </span>
    );
}

function CopyButton({ text }) {
    const toast = useToast();
    return (
        <button type="button" className="text-xs text-gray-400 hover:text-brand-600" onClick={() => navigator.clipboard?.writeText(text).then(() => toast('Copied.'))} title="Copy">
            Copy
        </button>
    );
}

function ResearchStatus({ research }) {
    if (!research) return null;
    if (!research.connected) {
        return <Alert type="info">Connect a Pinterest account to research real Pinterest searches. <Link to="/accounts" className="font-semibold underline">Connect account →</Link></Alert>;
    }
    if (!research.enabled) {
        return <Alert type="info">Pinterest keyword research is turned off in <Link to="/settings" className="font-semibold underline">User Settings</Link>.</Alert>;
    }
    const notes = [];
    if (research.terms === 'missing_scope') notes.push(<>Suggested and related searches need the <code>ads:read</code> permission. <Link to="/accounts" className="font-semibold underline">Reconnect Pinterest</Link> to grant it.</>);
    if (research.terms === 'unavailable') notes.push(<>Pinterest refused suggested/related searches for this app (they can require Standard access or an ads account). Trends still work if allowed. See Reports → logs for the exact API error.</>);
    if (research.terms === 'ok' && research.terms_environment === 'sandbox') notes.push(<>Suggested/related searches are coming from your <b>sandbox</b> account, which only returns test data (it repeats your term back). <Link to="/accounts" className="font-semibold underline">Reconnect your production Pinterest account</Link> so it grants <code>ads:read</code> — research then uses real Pinterest searches automatically.</>);
    if (research.trends === 'unavailable') notes.push(<>Pinterest refused trending keywords for this app. See Reports → logs for the exact API error.</>);
    return (
        <div className="space-y-2">
            {notes.map((n, i) => <Alert key={i}>{n}</Alert>)}
            <p className="text-xs text-gray-500">
                Runs automatically before each Pin is written · searches via <span className="font-medium text-gray-700">{research.terms_environment || '—'}</span> account · trends via <span className="font-medium text-gray-700">{research.trends_environment || '—'}</span> account · trends region <span className="font-medium text-gray-700">{research.region}</span>
            </p>
        </div>
    );
}

function KeywordResearch() {
    const [term, setTerm] = useState('');
    const [result, setResult] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const search = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            const r = await api.get('/seo/keywords', { params: { term } });
            setResult(r.data.data);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const empty = result && !result.keywords.length && !result.suggested.length && !result.related.length;

    return (
        <Card className="p-5 sm:p-6">
            <h2 className="font-semibold">Keyword research</h2>
            <p className="mb-4 mt-0.5 text-xs text-gray-500">Real Pinterest searches for a topic: suggested (autocomplete), related, and trending growth.</p>
            <form onSubmit={search} className="flex gap-2">
                <Input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="e.g. pos system" minLength={2} maxLength={80} required />
                <Button type="submit" loading={busy}>Research</Button>
            </form>
            {error && <div className="mt-3"><Alert type="error">{error}</Alert></div>}
            {empty && <p className="mt-4 text-sm text-gray-500">No Pinterest data for “{result.term}”. Try a broader or shorter phrase, or check the notice above.</p>}
            {result && result.keywords.length > 0 && (
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="text-left text-xs text-gray-500"><th className="py-2 pr-3 font-medium">Keyword</th><th className="py-2 pr-3 font-medium">Source</th><th className="py-2 pr-3 font-medium">Growth</th><th /></tr></thead>
                        <tbody className="divide-y divide-gray-100">
                            {result.keywords.map((k) => (
                                <tr key={k.keyword}>
                                    <td className="py-2 pr-3 font-medium text-gray-800">{k.keyword}</td>
                                    <td className="py-2 pr-3"><SourceTags sources={k.sources} /></td>
                                    <td className="py-2 pr-3"><Growth value={k.growth} /></td>
                                    <td className="py-2 text-right whitespace-nowrap"><CopyButton text={k.keyword} /> <ProfilePinButton keyword={k.keyword} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

function Trends({ defaultRegion }) {
    const [filters, setFilters] = useState({ region: defaultRegion || 'US', type: 'growing', interest: '', keywords: '' });
    const [applied, setApplied] = useState(filters);
    const trends = useApi('/seo/trends', applied);
    const options = trends.data?.data?.options;
    const set = (k) => (e) => setFilters((f) => ({ ...f, [k]: e.target.value }));
    const list = trends.data?.data?.trends || [];

    return (
        <Card className="p-5 sm:p-6">
            <h2 className="font-semibold">Trending on Pinterest</h2>
            <p className="mb-4 mt-0.5 text-xs text-gray-500">Top searches by region. “Growing” = rising fast; “seasonal” = recurring peaks. Pinterest Trends covers the listed regions only.</p>
            <form onSubmit={(e) => { e.preventDefault(); setApplied({ ...filters }); }} className="grid gap-3 sm:grid-cols-4">
                <Field label="Region">
                    <Select value={filters.region} onChange={set('region')}>{(options?.regions || [filters.region]).map((r) => <option key={r} value={r}>{r}</option>)}</Select>
                </Field>
                <Field label="Trend type">
                    <Select value={filters.type} onChange={set('type')}>{(options?.types || ['growing', 'monthly', 'yearly', 'seasonal']).map((t) => <option key={t} value={t}>{t}</option>)}</Select>
                </Field>
                <Field label="Interest">
                    <Select value={filters.interest} onChange={set('interest')}>
                        <option value="">All interests</option>
                        {(options?.interests || []).map((i) => <option key={i} value={i}>{i.replaceAll('_', ' ')}</option>)}
                    </Select>
                </Field>
                <Field label="Only these keywords">
                    <Input value={filters.keywords} onChange={set('keywords')} placeholder="comma-separated" />
                </Field>
                <div className="sm:col-span-4"><Button type="submit" size="sm" variant="secondary" loading={trends.loading}>Show trends</Button></div>
            </form>
            {trends.error && <div className="mt-3"><Alert type="error">{trends.error}</Alert></div>}
            {!trends.loading && !list.length && <p className="mt-4 text-sm text-gray-500">No trending keywords returned for these filters.</p>}
            {list.length > 0 && (
                <div className="mt-4 max-h-96 overflow-auto">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-white"><tr className="text-left text-xs text-gray-500"><th className="py-2 pr-3 font-medium">#</th><th className="py-2 pr-3 font-medium">Keyword</th><th className="py-2 pr-3 font-medium">Week</th><th className="py-2 pr-3 font-medium">Month</th><th className="py-2 pr-3 font-medium">Year</th><th className="py-2 pr-3 font-medium">Past year</th><th /></tr></thead>
                        <tbody className="divide-y divide-gray-100">
                            {list.map((t, i) => (
                                <tr key={t.keyword}>
                                    <td className="py-2 pr-3 text-gray-400 tabular-nums">{i + 1}</td>
                                    <td className="py-2 pr-3 font-medium text-gray-800">{t.keyword}</td>
                                    <td className="py-2 pr-3"><Growth value={t.pct_growth_wow} /></td>
                                    <td className="py-2 pr-3"><Growth value={t.pct_growth_mom} /></td>
                                    <td className="py-2 pr-3"><Growth value={t.pct_growth_yoy} /></td>
                                    <td className="py-2 pr-3"><Sparkline series={t.time_series} /></td>
                                    <td className="py-2 text-right whitespace-nowrap"><CopyButton text={t.keyword} /> <ProfilePinButton keyword={t.keyword} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

function Boards({ boards, accountId, onCreated }) {
    const toast = useToast();
    const [creating, setCreating] = useState('');
    const create = async (name) => {
        setCreating(name);
        try {
            await api.post('/pinterest/boards', { account_id: accountId, name, privacy: 'PUBLIC' });
            toast(`Board “${name}” created.`);
            onCreated();
        } catch (err) {
            toast(errorMessage(err), 'error');
        } finally {
            setCreating('');
        }
    };

    return (
        <Card className="p-5 sm:p-6">
            <h2 className="font-semibold">Board names</h2>
            <p className="mb-4 mt-0.5 text-xs text-gray-500">Boards named after what people search help Pinterest understand every Pin saved to them.</p>
            {boards.list.length === 0 ? (
                <p className="text-sm text-gray-500">No boards synced yet. <Link to="/boards" className="underline">Sync boards</Link>.</p>
            ) : (
                <ul className="divide-y divide-gray-100 text-sm">
                    {boards.list.map((b) => (
                        <li key={b.id} className="flex items-center justify-between gap-3 py-2">
                            <span className="min-w-0 truncate">{b.name}</span>
                            <span className="flex shrink-0 items-center gap-3 text-xs">
                                <span className="text-gray-400">{b.pins} Pin(s)</span>
                                {b.keyword_rich ? <span className="text-emerald-600">✓ keyword</span> : <span className="text-amber-600">no keyword</span>}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {boards.suggestions.length > 0 && (
                <>
                    <h3 className="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-gray-500">Suggested keyword boards</h3>
                    <ul className="space-y-1.5">
                        {boards.suggestions.map((name) => (
                            <li key={name} className="flex items-center justify-between gap-3 text-sm">
                                <span className="truncate">{name}</span>
                                {accountId && <Button size="sm" variant="ghost" loading={creating === name} disabled={!!creating} onClick={() => create(name)}>Create</Button>}
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </Card>
    );
}

function PinRow({ pin, onOptimized }) {
    const toast = useToast();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const failed = pin.seo.checks.filter((c) => !c.passed);
    const editable = ['draft', 'approved', 'failed', 'scheduled'].includes(pin.status);

    const optimize = async () => {
        setBusy(true);
        try {
            const r = await api.post(`/seo/pins/${pin.id}/optimize`);
            toast(`SEO score ${pin.seo.score} → ${r.data.data.seo.score}`);
            onOptimized();
        } catch (err) {
            toast(errorMessage(err), 'error');
        } finally {
            setBusy(false);
        }
    };

    return (
        <li className="px-5 py-3 sm:px-6">
            <div className="flex items-center gap-3">
                <div className="h-14 w-10 shrink-0 overflow-hidden rounded bg-gray-100">{pin.image?.url && <img src={pin.image.url} alt="" className="h-full w-full object-cover" loading="lazy" />}</div>
                <div className="min-w-0 flex-1">
                    <Link to={`/pins/${pin.id}`} className="block truncate text-sm font-medium text-gray-800 hover:underline">{pin.title || 'Untitled Pin'}</Link>
                    <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                        <span>Focus: <span className="text-gray-700">{pin.seo.focus_keyword || '—'}</span></span>
                        {failed.length > 0 && <button type="button" onClick={() => setOpen(!open)} className="text-amber-600 hover:underline">{failed.length} issue(s) {open ? '▲' : '▼'}</button>}
                        <span className="capitalize">{pin.status}</span>
                    </div>
                </div>
                <ScorePill score={pin.seo.score} />
                {editable && pin.seo.score < 100 && <Button size="sm" variant="secondary" loading={busy} onClick={optimize}>Optimize with AI</Button>}
            </div>
            {open && (
                <ul className="ml-[3.25rem] mt-2 space-y-1 text-xs">
                    {failed.map((c) => <li key={c.key}><span className="font-medium text-gray-700">{c.label}:</span> <span className="text-gray-500">{c.tip}</span></li>)}
                </ul>
            )}
        </li>
    );
}

function PinsTable() {
    const [params, setParams] = useState({ band: '', sort: 'score_asc', page: 1 });
    const pins = useApi('/seo/pins', params);
    const set = (k) => (e) => setParams((p) => ({ ...p, [k]: e.target.value, page: 1 }));

    return (
        <Card>
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 sm:px-6">
                <div><h2 className="font-semibold">Pin SEO scores</h2><p className="text-xs text-gray-500">Fix the weakest first. “Optimize with AI” rewrites copy around real Pinterest searches; review before publishing.</p></div>
                <div className="flex gap-2">
                    <Select value={params.band} onChange={set('band')}><option value="">All scores</option><option value="poor">Poor (&lt;50)</option><option value="fair">Fair (50–79)</option><option value="good">Good (80+)</option></Select>
                    <Select value={params.sort} onChange={set('sort')}><option value="score_asc">Lowest first</option><option value="score_desc">Highest first</option><option value="newest">Newest</option></Select>
                </div>
            </div>
            {pins.loading ? <Loading /> : pins.error ? <ErrorBox message={pins.error} onRetry={pins.reload} /> : !pins.data?.data?.length ? (
                <EmptyState title="No Pins here" description="Generate Pins from a URL or a whole website to see their SEO." icon="🔎" />
            ) : (
                <>
                    <ul className="divide-y divide-gray-100">{pins.data.data.map((p) => <PinRow key={p.id} pin={p} onOptimized={() => pins.reload(true)} />)}</ul>
                    <Pagination meta={pins.data} onPage={(page) => setParams((p) => ({ ...p, page }))} />
                </>
            )}
        </Card>
    );
}

const PROFILE_PIN_EVENT = 'pinbot:profile-pin-created';

/** Creates a Pin about this keyword that links to the user's Pinterest profile. */
function ProfilePinButton({ keyword }) {
    const toast = useToast();
    const [state, setState] = useState({ busy: false, queued: false });
    // The Pin is written in the background; follow it in the Pin Library.
    if (state.queued) return <Link to="/pins" className="text-xs font-medium text-emerald-700 hover:underline">Creating… view in Pin Library →</Link>;
    const create = async () => {
        setState({ busy: true, queued: false });
        try {
            const r = await api.post('/seo/profile/pins', { keyword });
            toast(r.data.message);
            setState({ busy: false, queued: true });
            window.dispatchEvent(new Event(PROFILE_PIN_EVENT));
        } catch (err) {
            toast(errorMessage(err), 'error');
            setState({ busy: false, queued: false });
        }
    };
    return (
        <button type="button" disabled={state.busy} onClick={create} className="text-xs font-medium text-brand-600 hover:underline disabled:opacity-60" title="Create a Pin for this keyword that links to your Pinterest profile">
            {state.busy ? 'Creating Pin…' : '+ Profile Pin'}
        </button>
    );
}

function ProfileCard() {
    const toast = useToast();
    const profile = useApi('/seo/profile');
    const [suggestion, setSuggestion] = useState(null);
    const [busy, setBusy] = useState('');

    useEffect(() => {
        const refresh = () => profile.reload(true);
        window.addEventListener(PROFILE_PIN_EVENT, refresh);
        return () => window.removeEventListener(PROFILE_PIN_EVENT, refresh);
    }, [profile.reload]); // eslint-disable-line react-hooks/exhaustive-deps

    const d = profile.data?.data;
    if (profile.loading || !d) return null;
    const p = d.profile;
    const n = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString());

    const suggest = async () => {
        setBusy('suggest');
        try {
            setSuggestion((await api.post('/seo/profile/suggest')).data.data);
        } catch (err) {
            toast(errorMessage(err), 'error');
        } finally {
            setBusy('');
        }
    };

    return (
        <Card className="mb-6 p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex min-w-0 items-center gap-3">
                    {p.profile_image ? <img src={p.profile_image} alt="" className="h-14 w-14 rounded-full object-cover" /> : <div className="grid h-14 w-14 place-items-center rounded-full bg-gray-100 text-lg font-semibold text-gray-500">{(p.username || '?')[0].toUpperCase()}</div>}
                    <div className="min-w-0">
                        <h2 className="truncate font-semibold">{p.business_name || p.username}</h2>
                        <a href={p.profile_url} target="_blank" rel="noopener noreferrer" className="text-xs text-brand-600 hover:underline">{p.profile_url} ↗</a>
                        <p className="mt-0.5 text-xs text-gray-500">{p.environment} account{!p.live && ' · Pinterest did not return profile details (sandbox or API limit)'}</p>
                    </div>
                </div>
                <div className="grid grid-cols-4 gap-3 text-center">
                    {[['Followers', p.follower_count], ['Monthly views', p.monthly_views], ['Pins', p.pin_count], ['Boards', p.board_count]].map(([l, v]) => (
                        <div key={l}><div className="text-lg font-semibold tabular-nums">{n(v)}</div><div className="text-[11px] text-gray-500">{l}</div></div>
                    ))}
                </div>
            </div>

            <div className="mt-5 grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 className="mb-2 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-gray-500">Profile SEO <span className="normal-case tracking-normal text-gray-700">{d.audit.score}/100</span></h3>
                    <ul className="space-y-1.5 text-sm">
                        {d.audit.checks.map((c) => (
                            <li key={c.key} className="flex gap-2">
                                <span className={c.passed ? 'text-emerald-600' : 'text-amber-600'}>{c.passed ? '✓' : '!'}</span>
                                <span><span className="text-gray-800">{c.label}</span>{!c.passed && <span className="block text-xs text-gray-500">{c.tip}</span>}</span>
                            </li>
                        ))}
                    </ul>
                    {d.keywords.length > 0 && <p className="mt-3 text-xs text-gray-500">Checked against your main keywords: {d.keywords.slice(0, 8).join(', ')}</p>}
                </div>
                <div>
                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Keyword-rich profile text</h3>
                    {suggestion ? (
                        <div className="space-y-3 text-sm">
                            <div><div className="flex justify-between text-xs text-gray-500"><span>Display name</span><CopyButton text={suggestion.display_name} /></div><div className="rounded-lg bg-gray-50 px-3 py-2">{suggestion.display_name}</div></div>
                            <div><div className="flex justify-between text-xs text-gray-500"><span>About</span><CopyButton text={suggestion.about} /></div><div className="whitespace-pre-line rounded-lg bg-gray-50 px-3 py-2">{suggestion.about}</div></div>
                        </div>
                    ) : <p className="text-sm text-gray-500">Get a display name and About text built around your keywords.</p>}
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <Button size="sm" variant="secondary" loading={busy === 'suggest'} onClick={suggest}>{suggestion ? 'Write again' : 'Write with AI'}</Button>
                        <a href="https://www.pinterest.com/settings/edit-profile" target="_blank" rel="noopener noreferrer" className="text-xs text-brand-600 hover:underline">Paste into Pinterest → Edit profile ↗</a>
                    </div>
                    <p className="mt-2 text-[11px] text-gray-400">Pinterest's API cannot change profiles, so copy these into your profile settings.</p>
                </div>
            </div>

            <div className="mt-5 border-t border-gray-100 pt-4">
                <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Profile Pins</h3>
                <p className="mb-2 text-xs text-gray-500">Pins for search keywords that link to your profile, so searchers find and follow you. Use <b>+ Profile Pin</b> next to any trending or researched keyword below.</p>
                {d.pins.length === 0 ? <p className="text-sm text-gray-500">None yet.</p> : (
                    <ul className="grid gap-1.5 text-sm sm:grid-cols-2">
                        {d.pins.map((pin) => (
                            <li key={pin.id} className="flex items-center justify-between gap-2">
                                <Link to={`/pins/${pin.id}`} className="truncate hover:underline">{pin.title || pin.keyword}</Link>
                                <span className="shrink-0 text-xs capitalize text-gray-500">{pin.status}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </Card>
    );
}

const BUCKET_LABELS = {
    all: 'All requests',
    org_write: 'Publishing & board changes',
    org_read: 'Reading boards & Pins',
    trends_read: 'Trending keywords',
    ads_read: 'Keyword suggestions',
    org_analytics: 'Pin analytics',
};

function LimitBar({ label, used, limit, note }) {
    const pct = Math.min(100, (used / Math.max(1, limit)) * 100);
    return (
        <li>
            <div className="flex justify-between gap-3 text-xs"><span className="text-gray-700">{label}</span><span className={`tabular-nums ${used >= limit ? 'font-semibold text-red-600' : 'text-gray-500'}`}>{used} / {limit}{note ? ` ${note}` : ''}</span></div>
            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100"><div className={`h-full ${pct >= 100 ? 'bg-red-500' : pct >= 70 ? 'bg-amber-400' : 'bg-emerald-500'}`} style={{ width: `${pct}%` }} /></div>
        </li>
    );
}

function ApiUsage({ usage, own }) {
    if (!usage?.length && !own) return null;
    const publishFull = own && own.published_today >= own.daily_publish_limit;
    return (
        <Card className="mt-6 p-5 sm:p-6">
            {own && (
                <div className="mb-6">
                    <h2 className="font-semibold">Your PinBot limits</h2>
                    <p className="mt-0.5 text-xs text-gray-500">Your own safety limits from <Link to="/settings" className="text-brand-600 hover:underline">User Settings → Limits</Link>. They reset at midnight (publishing, URLs per day) or on a rolling hour.</p>
                    {publishFull && <div className="mt-3"><Alert>You reached your daily publishing limit of {own.daily_publish_limit}. Pinterest still has room — {own.daily_publish_limit < own.max_daily_publish_limit ? <>raise it (up to {own.max_daily_publish_limit}) in <Link to="/settings" className="font-semibold underline">User Settings</Link>, or </> : null}wait until midnight.</Alert></div>}
                    <ul className="mt-3 grid gap-3 md:grid-cols-3">
                        <LimitBar label="Pins published today" used={own.published_today} limit={own.daily_publish_limit} />
                        <LimitBar label="URLs processed today" used={own.processed_today} limit={own.daily_processing_limit} />
                        <LimitBar label="URLs processed this hour" used={own.processed_last_hour} limit={own.hourly_processing_limit} />
                    </ul>
                </div>
            )}
            {usage?.length > 0 && <>
            <h2 className="font-semibold">Pinterest API usage</h2>
            <p className="mt-0.5 text-xs text-gray-500">
                {usage[0].level === 'trial' ? 'Trial access: limits per day for the whole app.' : 'Standard access: limits per minute per account.'} Keyword research and analytics stop at 70% of a limit so publishing always has room.
            </p>
            <div className="mt-4 grid gap-6 md:grid-cols-2">
                {usage.map((u) => (
                    <div key={u.environment}>
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{u.environment} · resets {new Date(u.resets_at).toLocaleString()}</h3>
                        <ul className="space-y-2">
                            {u.buckets.map((b) => {
                                const pct = Math.min(100, (b.used / Math.max(1, b.limit)) * 100);
                                return (
                                    <li key={b.bucket}>
                                        <div className="flex justify-between gap-3 text-xs"><span className="text-gray-700">{BUCKET_LABELS[b.bucket] || b.bucket}</span><span className="tabular-nums text-gray-500">{b.used} / {b.limit} per {u.window}</span></div>
                                        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100"><div className={`h-full ${pct >= 90 ? 'bg-red-500' : pct >= 70 ? 'bg-amber-400' : 'bg-emerald-500'}`} style={{ width: `${pct}%` }} /></div>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </div>
            </>}
        </Card>
    );
}

export default function SeoDashboard() {
    const toast = useToast();
    const overview = useApi('/seo/overview');
    const [rescoring, setRescoring] = useState(false);

    if (overview.loading) return <Loading />;
    if (overview.error) return <ErrorBox message={overview.error} onRetry={overview.reload} />;
    const { research, stats, issues, top_keywords: topKeywords, researched_keywords: researched, boards, api_usage: apiUsage, own_limits: ownLimits } = overview.data.data;

    const rescore = async () => {
        setRescoring(true);
        try {
            const r = await api.post('/seo/rescore');
            toast(r.data.message);
            overview.reload(true);
        } finally {
            setRescoring(false);
        }
    };

    return (
        <>
            <PageHeader title="SEO Dashboard" description="How well your Pins target what people search on Pinterest, and what to fix." actions={<><Link to="/seo/topics"><Button>SEO Autopilot &amp; analytics</Button></Link><Button variant="secondary" loading={rescoring} onClick={rescore}>Recalculate scores</Button></>} />

            <div className="mb-5"><ResearchStatus research={research} /></div>

            <ProfileCard />

            <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                <Stat label="Average SEO score" value={stats.average ?? '—'} tone="brand" hint={`${stats.pins} Pin(s) scored`} />
                <Stat label="Good (80+)" value={stats.good} tone="green" />
                <Stat label="Fair (50–79)" value={stats.fair} tone="amber" />
                <Stat label="Poor (<50)" value={stats.poor} tone="red" />
                <Stat label="Keyword-researched" value={stats.with_research} tone="violet" hint="Pins written with Pinterest search data" />
            </div>

            <div className="mt-6 grid gap-5 lg:grid-cols-3">
                <Card className="p-5 sm:p-6">
                    <h2 className="font-semibold">Most common issues</h2>
                    {issues.length === 0 ? <p className="mt-3 text-sm text-gray-500">No issues found. 🎉</p> : (
                        <ul className="mt-3 space-y-3">
                            {issues.map((i) => (
                                <li key={i.key}>
                                    <div className="flex justify-between gap-3 text-sm"><span>{i.label}</span><span className="text-gray-500 tabular-nums">{i.count}</span></div>
                                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100"><div className="h-full bg-amber-400" style={{ width: `${(i.count / Math.max(1, stats.pins)) * 100}%` }} /></div>
                                    {ISSUE_TIPS[i.key] && <p className="mt-1 text-xs text-gray-400">{ISSUE_TIPS[i.key]}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
                <Card className="p-5 sm:p-6">
                    <h2 className="font-semibold">Researched Pinterest searches</h2>
                    <p className="mt-0.5 text-xs text-gray-500">Search terms found for your pages, by number of Pins.</p>
                    {researched.length === 0 ? <p className="mt-3 text-sm text-gray-500">None yet — new Pins are researched automatically when Pinterest allows it.</p> : (
                        <ul className="mt-3 space-y-1.5 text-sm">
                            {researched.map((k) => <li key={k.keyword} className="flex justify-between gap-3"><span className="truncate">{k.keyword}</span><span className="flex shrink-0 gap-3 text-xs"><Growth value={k.growth} /><span className="text-gray-400">{k.pins}</span><ProfilePinButton keyword={k.keyword} /></span></li>)}
                        </ul>
                    )}
                </Card>
                <Card className="p-5 sm:p-6">
                    <h2 className="font-semibold">Keywords used in your Pins</h2>
                    {topKeywords.length === 0 ? <p className="mt-3 text-sm text-gray-500">No keywords yet.</p> : (
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {topKeywords.map((k) => <span key={k.keyword} className="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700">{k.keyword} <span className="text-gray-400">{k.pins}</span></span>)}
                        </div>
                    )}
                </Card>
            </div>

            <div className="mt-6 grid gap-5 lg:grid-cols-2">
                <KeywordResearch />
                <Boards boards={boards} accountId={research.account_id} onCreated={() => overview.reload(true)} />
            </div>

            <ApiUsage usage={apiUsage} own={ownLimits} />

            <div className="mt-6"><Trends defaultRegion={research.region} /></div>

            <div className="mt-6"><PinsTable /></div>
        </>
    );
}
