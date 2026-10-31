import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { Badge, Button, Field, Select, useToast } from './ui';

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

export function PinCard({ pin }) {
    return (
        <Link to={`/pins/${pin.id}`} className="group block">
            <div className="transition group-hover:opacity-90">
                <PinPreview pin={pin} compact />
            </div>
            <div className="flex items-center justify-between gap-2 px-1 mt-1">
                <Badge status={pin.status} />
                {pin.board_name && <span className="text-xs text-gray-500 truncate">{pin.board_name}</span>}
            </div>
        </Link>
    );
}

/** Account + board selector with refresh. Calls onChange({pinterest_account_id, board_id}). */
export function BoardPicker({ accountId, boardId, onChange, disabled, suggested }) {
    const toast = useToast();
    const [accounts, setAccounts] = useState([]);
    const [boards, setBoards] = useState([]);
    const [refreshing, setRefreshing] = useState(false);

    useEffect(() => {
        api.get('/pinterest/accounts').then((r) => setAccounts(r.data.data));
        api.get('/pinterest/boards').then((r) => setBoards(r.data.data));
    }, []);

    const accountBoards = useMemo(() => boards.filter((b) => b.pinterest_account_id === Number(accountId)), [boards, accountId]);

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
                            {a.environment === 'sandbox' ? ' (sandbox)' : ''}
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
                <Select value={boardId || ''} disabled={disabled || !accountId} onChange={(e) => onChange({ pinterest_account_id: accountId, board_id: e.target.value || null })}>
                    <option value="">{accountId ? (accountBoards.length ? 'Select board…' : 'No boards — refresh or create one') : 'Select an account first'}</option>
                    {accountBoards.map((b) => (
                        <option key={b.id} value={b.board_id}>
                            {b.name}
                            {b.privacy === 'SECRET' ? ' (secret)' : ''}
                        </option>
                    ))}
                </Select>
            </Field>
        </div>
    );
}

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
