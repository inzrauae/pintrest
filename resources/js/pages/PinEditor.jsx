import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { formatDate, useApi, useInterval } from '../lib/hooks';
import {
    Alert, Badge, Button, Card, CharCount, Checkbox, ErrorBox, Field, Input, Loading, Modal, Select, Textarea, cx, useToast,
} from '../components/ui';
import { BoardPicker, PinPreview, RegenButton } from '../components/PinParts';
import ImageCropper from '../components/ImageCropper';

const EDITABLE = ['draft', 'approved', 'failed', 'scheduled'];

function toLocalInput(date) {
    const d = new Date(date);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

function ChipInput({ values, onChange, placeholder, prefix = '', disabled }) {
    const [text, setText] = useState('');
    const add = () => {
        const parts = text.split(',').map((t) => t.trim()).filter(Boolean).map((t) => (prefix && !t.startsWith(prefix) ? prefix + t.replace(/\s+/g, '') : t));
        if (parts.length) onChange([...values, ...parts.filter((p) => !values.includes(p))]);
        setText('');
    };
    return (
        <div className={cx('rounded-lg border border-gray-300 bg-white p-2 flex flex-wrap gap-1.5', disabled && 'bg-gray-100')}>
            {values.map((v) => (
                <span key={v} className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 text-xs">
                    {v}
                    {!disabled && (
                        <button type="button" onClick={() => onChange(values.filter((x) => x !== v))} className="text-gray-400 hover:text-red-600" aria-label={`Remove ${v}`}>
                            ×
                        </button>
                    )}
                </span>
            ))}
            {!disabled && (
                <input
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ',') {
                            e.preventDefault();
                            add();
                        } else if (e.key === 'Backspace' && !text && values.length) {
                            onChange(values.slice(0, -1));
                        }
                    }}
                    onBlur={add}
                    placeholder={placeholder}
                    className="flex-1 min-w-[8rem] text-sm outline-none px-1 bg-transparent"
                />
            )}
        </div>
    );
}

export default function PinEditor() {
    const { id } = useParams();
    const toast = useToast();
    const navigate = useNavigate();
    const { data, setData, loading, error, reload } = useApi(`/pins/${id}`);
    const templates = useApi('/templates');
    const [form, setForm] = useState(null);
    const [busy, setBusy] = useState('');
    const [regen, setRegen] = useState('');
    const [cropping, setCropping] = useState(null);
    const [showCandidates, setShowCandidates] = useState(false);
    const [showGenerate, setShowGenerate] = useState(false);
    const [genPrompt, setGenPrompt] = useState('');
    const [showSchedule, setShowSchedule] = useState(false);
    const [scheduleAt, setScheduleAt] = useState('');
    const [confirmDuplicate, setConfirmDuplicate] = useState(null); // 'publish' | 'schedule'
    const fileRef = useRef(null);

    const pin = data?.data;
    const editable = pin && EDITABLE.includes(pin.status);

    const resetForm = useCallback((p) => {
        setForm({
            title: p.title || '',
            description: p.description || '',
            alt_text: p.alt_text || '',
            destination_url: p.destination_url || '',
            keywords: p.keywords || [],
            hashtags: p.hashtags || [],
            pinterest_account_id: p.pinterest_account_id,
            board_id: p.board_id,
            pin_template_id: p.pin_template_id,
        });
    }, []);

    useEffect(() => {
        if (pin) resetForm(pin);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pin?.id, pin?.updated_at]);

    // Live status while publishing.
    useInterval(() => reload(true), 3000, pin?.status === 'publishing');

    const dirty = useMemo(() => {
        if (!pin || !form) return false;
        return (
            form.title !== (pin.title || '') ||
            form.description !== (pin.description || '') ||
            form.alt_text !== (pin.alt_text || '') ||
            form.destination_url !== (pin.destination_url || '') ||
            JSON.stringify(form.keywords) !== JSON.stringify(pin.keywords || []) ||
            JSON.stringify(form.hashtags) !== JSON.stringify(pin.hashtags || []) ||
            form.pinterest_account_id !== pin.pinterest_account_id ||
            form.board_id !== pin.board_id ||
            form.pin_template_id !== pin.pin_template_id
        );
    }, [pin, form]);

    const run = async (key, fn, success) => {
        setBusy(key);
        try {
            const result = await fn();
            if (success) toast(success);
            return result;
        } catch (e) {
            toast(errorMessage(e), 'error');
            throw e;
        } finally {
            setBusy('');
        }
    };

    const save = async (silent = false) => {
        if (!dirty) return;
        const r = await run('save', () => api.patch(`/pins/${id}`, form), silent ? null : 'Changes saved.');
        setData(r.data);
    };

    const regenerate = async (field) => {
        setRegen(field);
        try {
            await save(true);
            await api.post(`/pins/${id}/regenerate`, { fields: [field] });
            await reload(true);
            toast('Regenerated.');
        } catch (e) {
            toast(errorMessage(e), 'error');
        } finally {
            setRegen('');
        }
    };

    const imageAction = async (key, fn, success) => {
        await run(key, fn, success).catch(() => {});
        await reload(true);
    };

    const publish = async (allowDuplicate = false) => {
        if (data.duplicate_of && !allowDuplicate) return setConfirmDuplicate('publish');
        try {
            await save(true);
            await run('publish', () => api.post(`/pins/${id}/publish`, { allow_duplicate: allowDuplicate }), 'Publishing started…');
            setConfirmDuplicate(null);
        } catch {
            /* toast already shown */
        }
        reload(true);
    };

    const schedule = async (allowDuplicate = false) => {
        if (data.duplicate_of && !allowDuplicate) return setConfirmDuplicate('schedule');
        try {
            await save(true);
            await run(
                'schedule',
                () => api.post(`/pins/${id}/schedule`, {
                    scheduled_at: scheduleAt,
                    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                    allow_duplicate: allowDuplicate,
                }),
                'Pin scheduled.',
            );
            setShowSchedule(false);
            setConfirmDuplicate(null);
        } catch {
            /* toast already shown */
        }
        reload(true);
    };

    if (loading) return <Loading />;
    if (error) return <ErrorBox message={error} onRetry={reload} />;
    if (!form) return <Loading />;

    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
    const preview = { ...pin, ...form };
    const candidates = data.image_candidates || [];

    return (
        <>
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <div className="min-w-0">
                    <Link to="/pins" className="text-sm text-gray-500 hover:text-gray-800">← Pin Library</Link>
                    <div className="flex items-center gap-3 mt-1">
                        <h1 className="text-2xl font-semibold tracking-tight truncate">Review Pin</h1>
                        <Badge status={pin.status} />
                        {pin.account?.environment === 'sandbox' && <Badge status="sandbox">sandbox</Badge>}
                    </div>
                    {pin.source_url && <p className="text-xs text-gray-500 mt-1 truncate">Source: {pin.source_url}</p>}
                </div>
                {editable && (
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" onClick={() => save()} loading={busy === 'save'} disabled={!dirty}>
                            Save
                        </Button>
                        {['draft', 'failed'].includes(pin.status) && (
                            <Button variant="secondary" loading={busy === 'approve'} onClick={async () => { await save(true); await imageAction('approve', () => api.post(`/pins/${id}/approve`), 'Pin approved.'); }}>
                                Approve
                            </Button>
                        )}
                        <Button variant="secondary" onClick={() => { setScheduleAt(pin.schedule?.scheduled_at ? toLocalInput(pin.schedule.scheduled_at) : toLocalInput(Date.now() + 3600e3)); setShowSchedule(true); }}>
                            {pin.status === 'scheduled' ? 'Reschedule' : 'Schedule'}
                        </Button>
                        <Button onClick={() => publish()} loading={busy === 'publish'}>
                            Publish now
                        </Button>
                    </div>
                )}
            </div>

            <div className="space-y-3 mb-6">
                {pin.status === 'published' && (
                    <Alert type="success">
                        Published {formatDate(pin.published_at)}.{' '}
                        {pin.pinterest_url && <a href={pin.pinterest_url} target="_blank" rel="noreferrer" className="font-medium underline">View on Pinterest ↗</a>}
                    </Alert>
                )}
                {pin.status === 'publishing' && <Alert type="info">Publishing to Pinterest… {pin.error_message}</Alert>}
                {pin.status === 'scheduled' && pin.schedule && (
                    <Alert type="info">
                        Scheduled for {formatDate(pin.schedule.scheduled_at)}.{' '}
                        <button className="underline font-medium" onClick={() => imageAction('unschedule', () => api.delete(`/pins/${id}/schedule`), 'Schedule cancelled.')}>Cancel schedule</button>
                    </Alert>
                )}
                {pin.status === 'failed' && pin.error_message && <Alert type="error">{pin.error_message}</Alert>}
                {pin.warnings?.map((w) => <Alert key={w}>{w}</Alert>)}
                {editable && data.preflight?.length > 0 && (
                    <Alert type="warning">
                        <div className="font-medium mb-1">Before publishing:</div>
                        <ul className="list-disc list-inside space-y-0.5">
                            {data.preflight.map((p) => <li key={p}>{p}</li>)}
                        </ul>
                    </Alert>
                )}
            </div>

            <div className="grid lg:grid-cols-[1fr_340px] gap-6 items-start">
                <div className="space-y-6 min-w-0">
                    <Card className="p-5 space-y-5">
                        <Field
                            label={<span className="flex items-center justify-between">Title <span className="flex items-center gap-2"><CharCount value={form.title} max={100} />{editable && <RegenButton loading={regen === 'title'} onClick={() => regenerate('title')} />}</span></span>}
                        >
                            <Input value={form.title} onChange={set('title')} disabled={!editable} maxLength={100} />
                        </Field>
                        <Field
                            label={<span className="flex items-center justify-between">Description <span className="flex items-center gap-2"><CharCount value={form.description} max={500} />{editable && <RegenButton loading={regen === 'description'} onClick={() => regenerate('description')} />}</span></span>}
                        >
                            <Textarea rows={5} value={form.description} onChange={set('description')} disabled={!editable} maxLength={500} />
                        </Field>
                        <Field label={<span className="flex items-center justify-between">Keywords {editable && <RegenButton loading={regen === 'keywords'} onClick={() => regenerate('keywords')} />}</span>} hint="Press Enter or comma to add.">
                            <ChipInput values={form.keywords} onChange={(v) => setForm({ ...form, keywords: v })} placeholder="Add keyword…" disabled={!editable} />
                        </Field>
                        <Field label={<span className="flex items-center justify-between">Hashtags {editable && <RegenButton loading={regen === 'hashtags'} onClick={() => regenerate('hashtags')} />}</span>} hint="Appended to the description when published (if it fits in 500 characters).">
                            <ChipInput values={form.hashtags} onChange={(v) => setForm({ ...form, hashtags: v })} placeholder="#hashtag" prefix="#" disabled={!editable} />
                        </Field>
                        <Field label="Destination URL">
                            <Input type="url" value={form.destination_url} onChange={set('destination_url')} disabled={!editable} />
                        </Field>
                        <Field
                            label={<span className="flex items-center justify-between">Image alt text <span className="flex items-center gap-2"><CharCount value={form.alt_text} max={500} />{editable && <RegenButton loading={regen === 'alt_text'} onClick={() => regenerate('alt_text')} />}</span></span>}
                        >
                            <Textarea rows={2} value={form.alt_text} onChange={set('alt_text')} disabled={!editable} maxLength={500} />
                        </Field>
                    </Card>

                    <Card className="p-5 space-y-4">
                        <h2 className="font-semibold">Destination board</h2>
                        <BoardPicker
                            accountId={form.pinterest_account_id}
                            boardId={form.board_id}
                            disabled={!editable}
                            suggested={pin.suggested_board}
                            onChange={(v) => setForm({ ...form, ...v })}
                        />
                    </Card>

                    <Card className="p-5 space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="font-semibold">Images</h2>
                            {editable && (
                                <div className="flex flex-wrap gap-2">
                                    <Button size="sm" variant="secondary" onClick={() => fileRef.current?.click()} loading={busy === 'upload'}>Upload</Button>
                                    <Button size="sm" variant="secondary" onClick={() => setShowCandidates(true)} disabled={!candidates.length}>From page ({candidates.length})</Button>
                                    <Button size="sm" variant="secondary" onClick={() => { setGenPrompt(''); setShowGenerate(true); }}>AI generate</Button>
                                    <input
                                        ref={fileRef}
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,image/gif"
                                        className="hidden"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];
                                            e.target.value = '';
                                            if (!file) return;
                                            const fd = new FormData();
                                            fd.append('image', file);
                                            imageAction('upload', () => api.post(`/pins/${id}/images/upload`, fd), 'Image uploaded.');
                                        }}
                                    />
                                </div>
                            )}
                        </div>
                        {pin.image_selection_reason && <p className="text-xs text-gray-500">AI: {pin.image_selection_reason}</p>}
                        {pin.images?.length ? (
                            <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-4">
                                {pin.images.map((img) => (
                                    <div key={img.id} className={cx('rounded-xl border-2 p-1.5', img.is_selected ? 'border-brand-600' : 'border-transparent')}>
                                        <button type="button" disabled={!editable} onClick={() => imageAction('select', () => api.post(`/pin-images/${img.id}/select`))} className="block w-full">
                                            <img src={img.url} alt="" className="w-full aspect-[2/3] object-cover rounded-lg bg-gray-100" loading="lazy" />
                                        </button>
                                        <div className="flex items-center justify-between mt-1.5 gap-1">
                                            <Badge status={img.source === 'generated' ? 'scheduled' : img.source === 'uploaded' ? 'approved' : 'info'}>{img.source}</Badge>
                                            <span className="text-[11px] text-gray-400">{img.width}×{img.height}</span>
                                        </div>
                                        {editable && (
                                            <div className="flex gap-1 mt-1">
                                                <Button size="sm" variant="ghost" className="flex-1" onClick={() => setCropping(img)}>Crop</Button>
                                                <Button size="sm" variant="ghost" className="flex-1 text-red-600" onClick={() => imageAction('delete', () => api.delete(`/pin-images/${img.id}`), 'Image removed.')}>Remove</Button>
                                            </div>
                                        )}
                                        {img.source === 'extracted' && (
                                            <div className="mt-1.5">
                                                <Checkbox
                                                    checked={img.rights_confirmed}
                                                    disabled={!editable}
                                                    onChange={(e) => imageAction('rights', () => api.patch(`/pin-images/${img.id}`, { rights_confirmed: e.target.checked }))}
                                                    label={<span className="text-xs">I have the right to use this image</span>}
                                                />
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <Alert>No image yet. Upload one, pick from the page, or generate one with AI.</Alert>
                        )}
                    </Card>

                    {editable && (
                        <Card className="p-5 grid sm:grid-cols-2 gap-4">
                            <Field label="Template" hint="Templates can add a text overlay to the image.">
                                <Select value={form.pin_template_id || ''} onChange={(e) => setForm({ ...form, pin_template_id: e.target.value ? Number(e.target.value) : null })}>
                                    <option value="">No template</option>
                                    {templates.data?.data?.map((t) => <option key={t.id} value={t.id}>{t.name}{t.text_overlay ? ' (text overlay)' : ''}</option>)}
                                </Select>
                            </Field>
                            <div className="flex items-end justify-end">
                                <Button
                                    variant="danger"
                                    onClick={async () => {
                                        if (!window.confirm('Delete this Pin? This cannot be undone.')) return;
                                        await run('delete', () => api.delete(`/pins/${id}`), 'Pin deleted.');
                                        navigate('/pins');
                                    }}
                                >
                                    Delete Pin
                                </Button>
                            </div>
                        </Card>
                    )}
                </div>

                <div className="lg:sticky lg:top-6 space-y-3">
                    <Card className="p-4">
                        <div className="text-xs font-medium uppercase tracking-wide text-gray-500 mb-3">Preview</div>
                        <PinPreview pin={preview} />
                    </Card>
                    {dirty && <p className="text-xs text-amber-700 text-center">Unsaved changes</p>}
                </div>
            </div>

            <ImageCropper
                image={cropping}
                open={!!cropping}
                onClose={() => setCropping(null)}
                saving={busy === 'crop'}
                onSave={async (crop) => {
                    await imageAction('crop', () => api.patch(`/pin-images/${cropping.id}`, { crop }), 'Crop applied.');
                    setCropping(null);
                }}
            />

            <Modal open={showCandidates} onClose={() => setShowCandidates(false)} title="Images found on the page" wide>
                <p className="text-xs text-gray-500 mb-3">Only use images you are authorized to republish. Images are downloaded securely and cropped to 2:3.</p>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    {candidates.map((c) => (
                        <button
                            key={c.url}
                            type="button"
                            disabled={!!busy}
                            className="text-left rounded-lg border border-gray-200 p-1.5 hover:border-brand-500 disabled:opacity-50"
                            onClick={async () => { await imageAction('import', () => api.post(`/pins/${id}/images/import`, { url: c.url }), 'Image added.'); setShowCandidates(false); }}
                        >
                            <img src={c.url} alt={c.alt || ''} referrerPolicy="no-referrer" className="w-full aspect-square object-cover rounded bg-gray-100" loading="lazy" />
                            <span className="block text-[11px] text-gray-500 mt-1 truncate">{c.source}{c.width ? ` · ${c.width}×${c.height}` : ''}</span>
                        </button>
                    ))}
                </div>
                {busy === 'import' && <p className="text-sm text-gray-500 mt-3">Downloading image…</p>}
            </Modal>

            <Modal
                open={showGenerate}
                onClose={() => setShowGenerate(false)}
                title="Generate an AI image"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowGenerate(false)}>Cancel</Button>
                        <Button
                            loading={busy === 'generate'}
                            onClick={async () => { await imageAction('generate', () => api.post(`/pins/${id}/images/generate`, { prompt: genPrompt }), 'Image generated.'); setShowGenerate(false); }}
                        >
                            Generate (2:3)
                        </Button>
                    </>
                }
            >
                <Field label="Custom prompt (optional)" hint="Leave empty to illustrate the Pin's title and description. The image will not contain text unless a template adds an overlay.">
                    <Textarea rows={5} value={genPrompt} onChange={(e) => setGenPrompt(e.target.value)} placeholder="Leave empty to use the Pin's title and description" />
                </Field>
                {busy === 'generate' && <p className="text-sm text-gray-500 mt-3">This can take up to a minute…</p>}
            </Modal>

            <Modal
                open={showSchedule}
                onClose={() => setShowSchedule(false)}
                title="Schedule Pin"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowSchedule(false)}>Cancel</Button>
                        <Button loading={busy === 'schedule'} onClick={() => schedule()} disabled={!scheduleAt}>Schedule</Button>
                    </>
                }
            >
                <Field label="Publish at (your local time)" hint="Publishing respects your publishing window and daily limit; if outside them, the Pin waits for the next allowed slot.">
                    <Input type="datetime-local" value={scheduleAt} min={toLocalInput(Date.now() + 120e3)} onChange={(e) => setScheduleAt(e.target.value)} />
                </Field>
            </Modal>

            <Modal
                open={!!confirmDuplicate}
                onClose={() => setConfirmDuplicate(null)}
                title="Possible duplicate"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setConfirmDuplicate(null)}>Cancel</Button>
                        <Button onClick={() => (confirmDuplicate === 'publish' ? publish(true) : schedule(true))}>Continue anyway</Button>
                    </>
                }
            >
                <p className="text-sm text-gray-600">
                    A Pin for this URL was already published to the same board: <Link className="text-brand-600" to={`/pins/${data.duplicate_of?.id}`}>“{data.duplicate_of?.title}”</Link>. Publish another one?
                </p>
            </Modal>
        </>
    );
}
