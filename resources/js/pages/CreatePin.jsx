import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { useApi } from '../lib/hooks';
import { Alert, Button, Card, Field, Input, Modal, PageHeader, Spinner, useToast } from '../components/ui';
import { ImageModeSelect, LanguageSelect, ProjectSelect } from '../components/PinParts';

const STEPS = ['Fetching page securely', 'Extracting metadata & images', 'Writing title, description & keywords', 'Preparing images'];

export default function CreatePin() {
    const toast = useToast();
    const navigate = useNavigate();
    const meta = useApi('/settings/meta');
    const prefs = useApi('/settings/preferences');
    const [form, setForm] = useState({ url: '', language: 'en', image_mode: 'extract', project_id: null });
    const [loading, setLoading] = useState(false);
    const [step, setStep] = useState(0);
    const [error, setError] = useState('');
    const [duplicate, setDuplicate] = useState(null);

    useEffect(() => {
        if (prefs.data) setForm((f) => ({ ...f, language: prefs.data.data.language, image_mode: prefs.data.data.image_mode }));
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
            <PageHeader title="Create Pin" description="Paste a page URL. We extract its content, write SEO-optimized Pin copy and pick the best image." />

            <div className="grid lg:grid-cols-3 gap-6">
                <Card className="p-5 sm:p-6 lg:col-span-2">
                    <form onSubmit={submit} className="space-y-5">
                        <Field label="Page URL">
                            <Input
                                type="url"
                                required
                                placeholder="https://example.com/blog/your-article"
                                value={form.url}
                                onChange={(e) => setForm({ ...form, url: e.target.value })}
                                disabled={loading}
                                className="text-base"
                            />
                        </Field>

                        <div className="grid sm:grid-cols-3 gap-4">
                            <Field label="Language">
                                <LanguageSelect value={form.language} onChange={(v) => setForm({ ...form, language: v })} languages={metaData?.languages} />
                            </Field>
                            <Field label="Image">
                                <ImageModeSelect value={form.image_mode} onChange={(v) => setForm({ ...form, image_mode: v })} />
                            </Field>
                            <Field label="Project">
                                <ProjectSelect value={form.project_id} onChange={(v) => setForm({ ...form, project_id: v })} />
                            </Field>
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
                            <div className="rounded-lg bg-gray-50 border border-gray-200 p-4 space-y-2">
                                {STEPS.map((s, i) => (
                                    <div key={s} className={`flex items-center gap-3 text-sm ${i <= step ? 'text-gray-900' : 'text-gray-400'}`}>
                                        {i < step ? <span className="text-emerald-600 w-4">✓</span> : i === step ? <Spinner className="h-4 w-4 text-brand-600" /> : <span className="w-4">•</span>}
                                        {s}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <Button type="submit" className="w-full sm:w-auto" disabled={!form.url}>
                                Generate Pin
                            </Button>
                        )}
                    </form>
                </Card>

                <Card className="p-5 sm:p-6 h-fit">
                    <h2 className="font-semibold mb-3">How it works</h2>
                    <ol className="space-y-3 text-sm text-gray-600 list-decimal list-inside">
                        <li>We safely fetch the page (private networks are blocked).</li>
                        <li>AI writes a title, description and keywords using only facts from the page.</li>
                        <li>You review, edit, crop the image and choose a board.</li>
                        <li>Publish now or schedule — nothing is posted without your approval.</li>
                    </ol>
                    <p className="text-xs text-gray-400 mt-4">Many URLs? Use <Link to="/bulk" className="text-brand-600">Bulk URL Import</Link>.</p>
                </Card>
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
