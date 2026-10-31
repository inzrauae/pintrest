import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { useApi } from '../lib/hooks';
import { Alert, Button, Card, Field, Input, Modal, PageHeader, Spinner, useToast } from '../components/ui';
import { BoardPicker, ImageModeSelect, LanguageSelect, ProjectSelect } from '../components/PinParts';

const STEPS = ['Fetching page securely', 'Extracting metadata & images', 'Writing title, description & keywords', 'Preparing images'];

const WORKFLOW = [
    ['Start with a link', 'We securely fetch your page and find its content and images.'],
    ['Give it a little AI magic', 'Get a title, description, and keywords grounded in your page.'],
    ['Make it your own', 'Review the copy, crop your image, and choose the right board.'],
    ['Share when you are ready', 'Publish now or schedule for later. You approve every Pin.'],
];

function Sparkle({ className = 'h-5 w-5' }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3ZM20 2v4m-2-2h4" /></svg>;
}

export default function CreatePin() {
    const toast = useToast();
    const navigate = useNavigate();
    const meta = useApi('/settings/meta');
    const prefs = useApi('/settings/preferences');
    const [form, setForm] = useState({ url: '', language: 'en', image_mode: 'extract', project_id: null, pinterest_account_id: null, board_id: null });
    const [loading, setLoading] = useState(false);
    const [step, setStep] = useState(0);
    const [error, setError] = useState('');
    const [duplicate, setDuplicate] = useState(null);

    useEffect(() => {
        if (prefs.data) setForm((f) => ({ ...f, language: prefs.data.data.language, image_mode: prefs.data.data.image_mode, pinterest_account_id: prefs.data.data.default_pinterest_account_id, board_id: prefs.data.data.default_board_id }));
    }, [prefs.data]);

    useEffect(() => {
        if (!loading) return undefined;
        setStep(0);
        const id = setInterval(() => setStep((s) => Math.min(s + 1, STEPS.length - 1)), 3500);
        return () => clearInterval(id);
    }, [loading]);

    const submit = async (e, force = false) => {
        e?.preventDefault();
        setError('');
        setDuplicate(null);
        setLoading(true);
        try {
            const r = await api.post('/urls/analyze', { ...form, url: form.url.trim(), force });
            toast('Pin generated — review and publish.');
            navigate(`/pins/${r.data.pin.id}`);
        } catch (err) {
            if (err.response?.status === 409) setDuplicate(err.response.data.existing);
            else setError(errorMessage(err));
        } finally {
            setLoading(false);
        }
    };

    const metaData = meta.data?.data;

    return (
        <>
            <PageHeader title="Create a new Pin." description="One link. A fresh idea. Your next Pinterest-ready Pin."
                actions={<Link to="/bulk" className="ui-button ui-button-secondary"><svg viewBox="0 0 20 20" fill="none" className="h-4 w-4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" aria-hidden="true"><path d="M4 5h12M4 10h12M4 15h8" /></svg>Bulk URL Import</Link>}
            />

            <div className="grid lg:grid-cols-3 gap-6">
                <Card className="overflow-hidden lg:col-span-2">
                    <div className="flex items-center gap-3.5 border-b border-gray-100 bg-[#fdfcfb] px-5 py-5 sm:px-7">
                        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-brand-50 text-brand-600"><Sparkle /></span>
                        <div><h2 className="text-sm font-semibold text-gray-900">From URL to something worth saving</h2><p className="mt-1 text-xs leading-5 text-gray-500">Bring your content. We will help with the creative part.</p></div>
                    </div>
                    <form onSubmit={submit} className="space-y-6 p-5 sm:p-7">
                        <Field label="Page URL" hint="Use a publicly accessible article, product, or web page. Private networks are blocked.">
                            <Input
                                type="url"
                                required
                                placeholder="https://example.com/blog/your-article"
                                value={form.url}
                                onChange={(e) => setForm({ ...form, url: e.target.value })}
                                disabled={loading}
                                className="text-sm sm:text-base"
                            />
                        </Field>

                        <div className="border-t border-gray-100 pt-6">
                            <div className="mb-4 flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[.16em] text-gray-400"><span className="h-1 w-1 rounded-full bg-brand-500" />Make it yours</div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Language">
                                    <LanguageSelect value={form.language} onChange={(v) => setForm({ ...form, language: v })} languages={metaData?.languages} />
                                </Field>
                                <Field label="Project">
                                    <ProjectSelect value={form.project_id} onChange={(v) => setForm({ ...form, project_id: v })} />
                                </Field>
                                <Field label="Image source" className="sm:col-span-2">
                                    <ImageModeSelect value={form.image_mode} onChange={(v) => setForm({ ...form, image_mode: v })} />
                                </Field>
                                <div className="sm:col-span-2">
                                    <span className="ui-field-label">Publish to board</span>
                                    <BoardPicker accountId={form.pinterest_account_id} boardId={form.board_id} onChange={({ pinterest_account_id: a, board_id: b }) => setForm((f) => ({ ...f, pinterest_account_id: a, board_id: b }))} />
                                    <span className="mt-1 block text-xs text-gray-500">Every Pin from this goes to this board. Pick an existing board or choose “+ New board…”.</span>
                                </div>
                            </div>
                        </div>

                        {form.image_mode !== 'extract' && metaData && !metaData.ai_configured && (
                            <Alert>Image generation needs OPENAI_API_KEY or GEMINI_API_KEY on the server and an image provider selected in <Link to="/ai-settings" className="underline">AI Settings</Link>.</Alert>
                        )}
                        {metaData && !metaData.ai_configured && (
                            <Alert type="info">
                                No AI key (OPENAI_API_KEY or GEMINI_API_KEY) is configured, so the offline generator is used: it writes copy only from text found on the page. Add a key for full AI copywriting.
                            </Alert>
                        )}
                        {error && <Alert type="error">{error}</Alert>}

                        {loading ? (
                            <div className="space-y-3 rounded-2xl border border-brand-100 bg-brand-50/50 p-5" role="status" aria-live="polite">
                                {STEPS.map((s, i) => (
                                    <div key={s} className={`flex items-center gap-3 text-sm ${i <= step ? 'text-gray-900' : 'text-gray-400'}`}>
                                        {i < step ? <span className="text-emerald-600 w-4">✓</span> : i === step ? <Spinner className="h-4 w-4 text-brand-600" /> : <span className="w-4">•</span>}
                                        {s}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col-reverse gap-4 border-t border-gray-100 pt-5 sm:flex-row sm:items-center sm:justify-between">
                                <span className="text-center text-[11px] text-gray-400 sm:text-left">Your Pin is saved for review first.</span>
                                <Button type="submit" className="w-full sm:w-auto" disabled={!form.url}><Sparkle className="h-4 w-4" />Generate Pin</Button>
                            </div>
                        )}
                    </form>
                </Card>

                <div className="space-y-5">
                    <Card className="p-5 sm:p-6">
                        <div className="mb-6 flex items-center justify-between"><h2 className="text-sm font-semibold text-gray-900">A simple creative flow</h2><span className="text-[10px] text-gray-400">01 — 04</span></div>
                        <ol className="space-y-6">
                            {WORKFLOW.map(([title, description], index) => <li key={title} className="flex gap-3"><span className={`grid h-7 w-7 shrink-0 place-items-center rounded-full text-[10px] font-semibold ${index === 0 ? 'bg-brand-50 text-brand-600' : 'bg-gray-50 text-gray-400'}`}>{String(index + 1).padStart(2, '0')}</span><div className="pt-0.5"><h3 className="text-xs font-semibold text-gray-800">{title}</h3><p className="mt-1.5 text-xs leading-5 text-gray-500">{description}</p></div></li>)}
                        </ol>
                    </Card>
                    <div className="rounded-2xl border border-[#e5e8df] bg-[#f0f3ec] p-5">
                        <div className="mb-2 flex items-center gap-2 text-xs font-semibold text-[#57634c]"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.4" className="h-4 w-4" aria-hidden="true"><path d="m10 2 6 3v5c0 3-6 7-6 7s-6-4-6-7V5l6-3Z" strokeLinejoin="round" /><path d="m7 9 2 2 4-4" strokeLinecap="round" strokeLinejoin="round" /></svg>You are always in control</div>
                        <p className="text-xs leading-5 text-[#75806b]">Fine-tune every detail before it goes live. Nothing is published without your approval.</p>
                    </div>
                    <p className="px-1 text-xs leading-5 text-gray-400">A whole collection of ideas? <Link to="/bulk" className="font-medium text-brand-600 hover:underline">Try Bulk URL Import <span aria-hidden="true">↗</span></Link></p>
                </div>
            </div>

            <Modal
                open={!!duplicate}
                onClose={() => setDuplicate(null)}
                title="URL already submitted"
                footer={
                    <>
                        {duplicate?.pin && (
                            <Button variant="secondary" onClick={() => navigate(`/pins/${duplicate.pin.id}`)}>
                                Open existing Pin
                            </Button>
                        )}
                        <Button onClick={() => submit(null, true)}>Generate another Pin</Button>
                    </>
                }
            >
                <p className="text-sm text-gray-600">
                    You already submitted <span className="font-medium break-all">{duplicate?.url}</span>
                    {duplicate?.pin ? <> and generated “{duplicate.pin.title}” ({duplicate.pin.status}).</> : '.'}
                </p>
                <p className="text-sm text-gray-600 mt-2">Generating again creates a separate Pin with fresh copy. Publishing duplicates to the same board still needs confirmation.</p>
            </Modal>
        </>
    );
}
