import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { useApi } from '../lib/hooks';
import { Alert, Badge, Button, Card, Checkbox, Field, Input, PageHeader, Select, useToast } from '../components/ui';
import { BoardPicker, ImageModeSelect, LanguageSelect, ProjectSelect } from '../components/PinParts';

export default function SiteImport() {
    const toast = useToast();
    const navigate = useNavigate();
    const meta = useApi('/settings/meta');
    const prefs = useApi('/settings/preferences');
    const templates = useApi('/templates');
    const [form, setForm] = useState({ url: '', include: '', exclude: '', limit: 100, name: '', language: 'en', image_mode: 'both', project_id: null, template_id: null, skip_duplicates: true, pinterest_account_id: null, board_id: null });
    const [found, setFound] = useState(null);
    const [selected, setSelected] = useState(new Set());
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        if (prefs.data) setForm((f) => ({ ...f, language: prefs.data.data.language, pinterest_account_id: prefs.data.data.default_pinterest_account_id, board_id: prefs.data.data.default_board_id }));
    }, [prefs.data]);

    useEffect(() => {
        const list = templates.data?.data || [];
        const preferred = list.find((t) => t.is_default) || list.find((t) => t.text_overlay);
        if (preferred) setForm((f) => (f.template_id ? f : { ...f, template_id: preferred.id }));
    }, [templates.data]);

    const set = (k) => (v) => setForm((f) => ({ ...f, [k]: v }));
    const discoveryParams = () => ({ url: form.url.trim(), include: form.include, exclude: form.exclude, limit: Number(form.limit) || undefined });

    const discover = async (e) => {
        e.preventDefault();
        setError('');
        setBusy('discover');
        try {
            const r = await api.post('/sites/discover', discoveryParams());
            const data = r.data.data;
            const skip = new Set(form.skip_duplicates ? data.already_submitted : []);
            setFound(data);
            setSelected(new Set(data.urls.filter((u) => !skip.has(u))));
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy('');
        }
    };

    const start = async (urls) => {
        setError('');
        setBusy('import');
        try {
            const payload = { ...discoveryParams(), ...form, urls };
            Object.keys(payload).forEach((k) => (payload[k] === null || payload[k] === '' || payload[k] === undefined) && delete payload[k]);
            const r = await api.post('/sites/import', payload);
            toast(r.data.message || `Creating Pins for ${r.data.data.total} page(s).`);
            navigate(`/bulk/${r.data.data.id}`);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy('');
        }
    };

    const toggle = (u) => setSelected((s) => {
        const next = new Set(s);
        next.has(u) ? next.delete(u) : next.add(u);
        return next;
    });

    const already = new Set(found?.already_submitted || []);
    const templateList = templates.data?.data || [];

    return (
        <>
            <PageHeader title="Website to Pins" description="Enter a website or sitemap URL. PinBot finds every page and creates an SEO-optimized draft Pin with a 2:3 image for each one." />

            <div className="grid lg:grid-cols-5 gap-6">
                <Card className="p-5 sm:p-6 lg:col-span-3">
                    <form onSubmit={discover} className="space-y-4">
                        <Field label="Website or sitemap URL" hint="e.g. https://example.com or https://example.com/sitemap.xml">
                            <Input value={form.url} onChange={(e) => set('url')(e.target.value)} placeholder="https://example.com/sitemap.xml" required />
                        </Field>
                        <div className="grid sm:grid-cols-3 gap-4">
                            <Field label="Only URLs containing" hint="Comma-separated, optional">
                                <Input value={form.include} onChange={(e) => set('include')(e.target.value)} placeholder="/blog/, /recipes/" />
                            </Field>
                            <Field label="Skip URLs containing" hint="Comma-separated, optional">
                                <Input value={form.exclude} onChange={(e) => set('exclude')(e.target.value)} placeholder="/category/" />
                            </Field>
                            <Field label="Max pages">
                                <Input type="number" min={1} max={500} value={form.limit} onChange={(e) => set('limit')(e.target.value)} />
                            </Field>
                        </div>
                        <div className="grid sm:grid-cols-2 gap-4">
                            <Field label="Image" hint="“Both” imports the page image and also generates an AI image (needs an image API key).">
                                <ImageModeSelect value={form.image_mode} onChange={set('image_mode')} />
                            </Field>
                            <Field label="Design template" hint={templateList.length ? 'Adds the Pin title as a headline on the image.' : <>No templates yet — <Link to="/templates" className="underline">create one</Link> for headline overlays.</>}>
                                <Select value={form.template_id || ''} onChange={(e) => set('template_id')(e.target.value ? Number(e.target.value) : null)}>
                                    <option value="">No overlay (plain image)</option>
                                    {templateList.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name}{t.text_overlay ? ' — headline overlay' : ''}{t.is_default ? ' (default)' : ''}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Language">
                                <LanguageSelect value={form.language} onChange={set('language')} languages={meta.data?.data?.languages} />
                            </Field>
                            <Field label="Project">
                                <ProjectSelect value={form.project_id} onChange={set('project_id')} />
                            </Field>
                            <div className="sm:col-span-2">
                                <span className="ui-field-label">Publish to board</span>
                                <BoardPicker accountId={form.pinterest_account_id} boardId={form.board_id} onChange={({ pinterest_account_id: a, board_id: b }) => setForm((f) => ({ ...f, pinterest_account_id: a, board_id: b }))} />
                                <span className="mt-1 block text-xs text-gray-500">Every Pin from this goes to this board. Pick an existing board or choose “+ New board…”.</span>
                            </div>
                        </div>
                        <Checkbox checked={form.skip_duplicates} onChange={(e) => set('skip_duplicates')(e.target.checked)} label="Skip pages I already made Pins for" />
                        {error && <Alert type="error">{error}</Alert>}
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" variant="secondary" loading={busy === 'discover'} disabled={!form.url || !!busy}>
                                Find pages
                            </Button>
                            <Button type="button" loading={busy === 'import' && !found} disabled={!form.url || !!busy} onClick={() => start(undefined)}>
                                Find pages & create all Pins
                            </Button>
                        </div>
                    </form>
                </Card>

                <Card className="p-5 sm:p-6 lg:col-span-2 h-fit text-sm text-gray-600 space-y-2">
                    <h2 className="font-semibold text-gray-900">How it works</h2>
                    <p>1. PinBot reads the sitemap (or robots.txt, or crawls the site if there is none).</p>
                    <p>2. Each page gets an AI-written title, description, keywords, hashtags and alt text, plus a 2:3 image with your template’s headline.</p>
                    <p>3. Pins land as <Badge status="draft" /> — open each one, tweak if needed, then publish or schedule it.</p>
                    <p>Needs a running queue worker. Your hourly/daily processing limits apply; extra pages wait and can be resumed.</p>
                </Card>
            </div>

            {found && (
                <Card className="mt-6">
                    <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
                        <div>
                            <h2 className="font-semibold">{found.urls.length} page(s) found <span className="text-gray-400 font-normal">via {found.source}</span></h2>
                            {found.total_found > found.urls.length && <p className="text-xs text-gray-500">{found.total_found} matched — showing the first {found.urls.length}. Raise “Max pages” to include more.</p>}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" variant="ghost" onClick={() => setSelected(new Set(found.urls))}>Select all</Button>
                            <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>Clear</Button>
                            <Button size="sm" loading={busy === 'import'} disabled={!selected.size || !!busy} onClick={() => start([...selected])}>
                                Create {selected.size} Pin(s)
                            </Button>
                        </div>
                    </div>
                    {found.urls.length ? (
                        <ul className="divide-y divide-gray-100 max-h-[32rem] overflow-y-auto">
                            {found.urls.map((u) => (
                                <li key={u} className="px-5 py-2 flex items-center gap-3 text-sm">
                                    <input type="checkbox" checked={selected.has(u)} onChange={() => toggle(u)} className="shrink-0" aria-label={`Include ${u}`} />
                                    <a href={u} target="_blank" rel="noopener noreferrer" className="truncate font-mono text-xs text-gray-700 hover:underline min-w-0">{u}</a>
                                    {already.has(u) && <span className="shrink-0 text-xs text-amber-600">already has a Pin</span>}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="px-5 py-6 text-sm text-gray-500">No content pages matched. Try a different URL or loosen the filters.</p>
                    )}
                </Card>
            )}
        </>
    );
}
