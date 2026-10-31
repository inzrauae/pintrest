import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { timeAgo } from '../lib/hooks';
import { Badge, Button, Checkbox, Field, Input, Select, useToast } from './ui';

/** Pinterest-style card preview. */
export function PinPreview({ pin, compact = false }) {
    const image = pin.image?.url;
    let host = '';
    try {
        host = new URL(pin.destination_url).hostname.replace(/^www\./, '');
    } catch {
        /* ignore */
    }
    return (
        <div className={compact ? '' : 'max-w-xs mx-auto'}>
            <div className="rounded-2xl overflow-hidden bg-gray-100 aspect-[2/3] relative">
                {image ? (
                    <img src={image} alt={pin.alt_text || pin.title || ''} className="w-full h-full object-cover" loading="lazy" />
                ) : (
                    <div className="absolute inset-0 grid place-items-center text-gray-400 text-sm px-6 text-center">No image selected</div>
                )}
                {host && !compact && (
                    <span className="absolute bottom-3 left-3 bg-white/90 rounded-full px-3 py-1 text-xs font-medium text-gray-800">{host}</span>
                )}
            </div>
            <div className="px-1 pt-2">
                <div className={compact ? 'text-sm font-medium line-clamp-2' : 'font-semibold leading-snug'}>{pin.title || 'Untitled Pin'}</div>
                {!compact && pin.description && <p className="text-sm text-gray-600 mt-1 line-clamp-4">{pin.description}</p>}
            </div>
        </div>
    );
}

const DUPLICATE_ERROR = 'already published to the same board';
export const TRIAL_ERROR = 'Trial access, which can only publish to the sandbox';
export const TRIAL_CONFIRM = 'Your Pinterest app has Trial access, so it can only publish to your sandbox account (Pins visible only to you).\n\nMove this Pin to your sandbox account and publish it now?';

/** Placeholder card for a page that is being turned into a Pin (or is next in line). */
export function PendingPinCard({ item }) {
    let host = item.url;
    try {
        const u = new URL(item.url);
        host = u.hostname.replace(/^www\./, '') + (u.pathname !== '/' ? u.pathname : '');
    } catch {
        /* keep raw */
    }
    const generating = item.status === 'processing';
    return (
        <div className="pin-library-card">
            <div className={`relative grid aspect-[2/3] place-items-center overflow-hidden rounded-2xl ${generating ? 'bg-blue-50' : 'bg-gray-50'}`}>
                {generating && <div className="absolute inset-0 animate-pulse bg-gradient-to-b from-blue-50 via-blue-100/60 to-blue-50" />}
                <div className="relative px-4 text-center">
                    {generating
                        ? <svg className="mx-auto h-7 w-7 animate-spin text-blue-500" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity=".25" strokeWidth="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" /></svg>
                        : <span className="text-2xl" aria-hidden="true">⏳</span>}
                    <div className={`mt-2 text-xs font-semibold ${generating ? 'text-blue-700' : 'text-gray-500'}`}>{generating ? 'Generating Pin…' : 'Queued'}</div>
                    <div className="mt-1 text-[11px] text-gray-500">{generating ? 'Writing copy, researching keywords, preparing image' : 'Starts when the worker is free'}</div>
                </div>
            </div>
            <div className="px-1 pt-2 text-sm font-medium line-clamp-2 break-all text-gray-600">{host}</div>
            <div className="flex items-center justify-between gap-2 px-1 mt-1">
                <Badge status={generating ? 'processing' : 'pending'}>{generating ? 'generating' : 'queued'}</Badge>
                {item.url_batch_id && <Link to={`/bulk/${item.url_batch_id}`} className="text-xs text-brand-600 hover:underline">Batch</Link>}
            </div>
            <div className="px-1 mt-1 text-[11px] text-gray-400">{generating ? `Started ${timeAgo(item.updated_at)}` : `Added ${timeAgo(item.created_at)}`}</div>
        </div>
    );
}

/** One-click publish for a Pin card. Calls onChange(updatedPin). */
export function PublishButton({ pin, onChange }) {
    const toast = useToast();
    const [busy, setBusy] = useState(false);

    if (pin.status === 'published') {
        return pin.pinterest_url
            ? <a href={pin.pinterest_url} target="_blank" rel="noopener noreferrer" className="ui-button ui-button-secondary ui-button-sm w-full justify-center">View on Pinterest ↗</a>
            : null;
    }
    if (pin.status === 'publishing') {
        return <Button size="sm" variant="secondary" className="w-full" disabled>Publishing…</Button>;
    }

    const publish = async (allowDuplicate = false, useSandbox = false) => {
        setBusy(true);
        try {
            const r = await api.post(`/pins/${pin.id}/publish`, { allow_duplicate: allowDuplicate, use_sandbox: useSandbox });
            toast(r.data.message, r.status === 200 ? 'success' : 'info');
            onChange?.(r.data.data);
        } catch (err) {
            const message = errorMessage(err);
            if (!allowDuplicate && message.includes(DUPLICATE_ERROR)) {
                setBusy(false);
                if (window.confirm('A Pin for this page is already on this board. Publish another one anyway?')) publish(true, useSandbox);
                return;
            }
            if (!useSandbox && message.includes(TRIAL_ERROR)) {
                setBusy(false);
                if (window.confirm(TRIAL_CONFIRM)) publish(allowDuplicate, true);
                return;
            }
            toast(`${message} Open the Pin to fix it.`, 'error');
            if (err.response?.data?.data) onChange?.(err.response.data.data);
        } finally {
            setBusy(false);
        }
    };

    return <Button size="sm" className="w-full" loading={busy} onClick={() => publish()}>Publish</Button>;
}

const NEW_FOR_MS = 30 * 60 * 1000;

export function PinCard({ pin, onChange }) {
    const created = pin.created_at ? new Date(pin.created_at) : null;
    const isNew = created && Date.now() - created.getTime() < NEW_FOR_MS;
    return (
        <div className="pin-library-card">
            <Link to={`/pins/${pin.id}`} className="group block">
                <div className="relative transition group-hover:opacity-90">
                    <PinPreview pin={pin} compact />
                    {isNew && <span className="absolute left-2 top-2 rounded-full bg-brand-600 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white shadow">New</span>}
                </div>
                <div className="flex items-center justify-between gap-2 px-1 mt-1">
                    <Badge status={pin.status} />
                    {pin.board_name && <span className="text-xs text-gray-500 truncate">{pin.board_name}</span>}
                </div>
                {created && (
                    <div className="px-1 mt-1 text-[11px] text-gray-400" title={created.toLocaleString()}>
                        Created {timeAgo(pin.created_at)} · {created.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}
                    </div>
                )}
            </Link>
            <div className="mt-2 px-1">
                <PublishButton pin={pin} onChange={onChange} />
            </div>
        </div>
    );
}

/** Account + board selector with refresh. Calls onChange({pinterest_account_id, board_id}). */
export function BoardPicker({ accountId, boardId, onChange, disabled, suggested }) {
    const toast = useToast();
    const [accounts, setAccounts] = useState([]);
    const [accessLevel, setAccessLevel] = useState(null);
    const [boards, setBoards] = useState([]);
    const [refreshing, setRefreshing] = useState(false);

    useEffect(() => {
        api.get('/pinterest/accounts').then((r) => setAccounts(r.data.data));
        api.get('/settings/meta').then((r) => setAccessLevel(r.data.data.limits?.pinterest_access_level));
        api.get('/pinterest/boards').then((r) => setBoards(r.data.data));
    }, []);

    const accountBoards = useMemo(() => boards.filter((b) => b.pinterest_account_id === Number(accountId)), [boards, accountId]);
    const [creating, setCreating] = useState(null); // { name, secret, busy } while the "New board" form is open

    const createBoard = async () => {
        const name = creating?.name.trim();
        if (!name) return;
        setCreating({ ...creating, busy: true });
        try {
            const r = await api.post('/pinterest/boards', { account_id: accountId, name, privacy: creating.secret ? 'SECRET' : 'PUBLIC' });
            const board = r.data.data;
            setBoards((prev) => [...prev, board]);
            onChange({ pinterest_account_id: accountId, board_id: board.board_id });
            setCreating(null);
            toast(`Board “${board.name}” created.`);
        } catch (e) {
            toast(errorMessage(e), 'error');
            setCreating((c) => (c ? { ...c, busy: false } : c));
        }
    };

    const refresh = async () => {
        setRefreshing(true);
        try {
            const r = await api.get('/pinterest/boards', { params: { refresh: 1, account_id: accountId || undefined } });
            setBoards((prev) => [...prev.filter((b) => accountId && b.pinterest_account_id !== Number(accountId)), ...r.data.data]);
            r.data.errors?.length ? toast(r.data.errors.join(' '), 'error') : toast('Boards refreshed.');
        } catch (e) {
            toast(errorMessage(e), 'error');
        } finally {
            setRefreshing(false);
        }
    };

    if (accounts.length === 0) {
        return (
            <div className="text-sm text-gray-600 rounded-lg border border-dashed border-gray-300 p-3">
                No Pinterest account connected. <Link to="/accounts" className="text-brand-600 font-medium">Connect one</Link> to choose a board.
            </div>
        );
    }

    return (
        <div className="grid sm:grid-cols-2 gap-3">
            <Field label="Pinterest account">
                <Select
                    value={accountId || ''}
                    disabled={disabled}
                    onChange={(e) => onChange({ pinterest_account_id: e.target.value ? Number(e.target.value) : null, board_id: null })}
                >
                    <option value="">Select account…</option>
                    {accounts.map((a) => (
                        <option key={a.id} value={a.id} disabled={a.status !== 'active'}>
                            @{a.username}
                            {a.environment === 'sandbox' ? ' (sandbox)' : accessLevel === 'trial' ? ' — needs Standard access to publish' : ''}
                            {a.status !== 'active' ? ` — ${a.status}` : ''}
                        </option>
                    ))}
                </Select>
            </Field>
            <Field
                label={
                    <span className="flex items-center justify-between">
                        Board
                        {accountId && (
                            <button type="button" onClick={refresh} disabled={refreshing} className="text-xs text-brand-600 font-normal hover:underline">
                                {refreshing ? 'Refreshing…' : 'Refresh'}
                            </button>
                        )}
                    </span>
                }
                hint={suggested ? `AI suggestion: ${suggested}` : undefined}
            >
                <Select
                    value={boardId || ''}
                    disabled={disabled || !accountId}
                    onChange={(e) => (e.target.value === NEW_BOARD ? setCreating({ name: suggested || '' }) : onChange({ pinterest_account_id: accountId, board_id: e.target.value || null }))}
                >
                    <option value="">{accountId ? (accountBoards.length ? 'Select board…' : 'No boards yet — create one') : 'Select an account first'}</option>
                    {accountBoards.map((b) => (
                        <option key={b.id} value={b.board_id}>
                            {b.name}
                            {b.privacy === 'SECRET' ? ' (secret)' : ''}
                        </option>
                    ))}
                    {accountId && <option value={NEW_BOARD}>+ New board…</option>}
                </Select>
            </Field>
            {creating && (
                <div className="sm:col-span-2 flex flex-col gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3 sm:flex-row sm:items-end">
                    <Field label="New board name" hint="Use a keyword people search, e.g. “Pharmacy POS System Ideas”." className="flex-1">
                        <Input value={creating.name} maxLength={180} autoFocus onChange={(e) => setCreating({ ...creating, name: e.target.value })} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); createBoard(); } }} />
                    </Field>
                    <Checkbox checked={!!creating.secret} onChange={(e) => setCreating({ ...creating, secret: e.target.checked })} label="Secret" />
                    <div className="flex gap-2">
                        <Button type="button" size="sm" loading={creating.busy} disabled={!creating.name.trim()} onClick={createBoard}>Create board</Button>
                        <Button type="button" size="sm" variant="ghost" onClick={() => setCreating(null)}>Cancel</Button>
                    </div>
                </div>
            )}
        </div>
    );
}

const NEW_BOARD = '__new__';

export function LanguageSelect({ value, onChange, languages }) {
    return (
        <Select value={value} onChange={(e) => onChange(e.target.value)}>
            {Object.entries(languages || { en: 'English' }).map(([code, name]) => (
                <option key={code} value={code}>
                    {name}
                </option>
            ))}
        </Select>
    );
}

export function ImageModeSelect({ value, onChange }) {
    return (
        <Select value={value} onChange={(e) => onChange(e.target.value)}>
            <option value="extract">Use best image from the page</option>
            <option value="generate">Generate a new AI image</option>
            <option value="both">Both — let me choose</option>
        </Select>
    );
}

export function ProjectSelect({ value, onChange }) {
    const [projects, setProjects] = useState([]);
    useEffect(() => {
        api.get('/projects').then((r) => setProjects(r.data.data));
    }, []);
    return (
        <Select value={value || ''} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
            <option value="">No project</option>
            {projects.map((p) => (
                <option key={p.id} value={p.id}>
                    {p.name}
                </option>
            ))}
        </Select>
    );
}

export function RegenButton({ onClick, loading }) {
    return (
        <Button type="button" size="sm" variant="ghost" loading={loading} onClick={onClick} title="Regenerate with AI">
            ↻ Regenerate
        </Button>
    );
}
