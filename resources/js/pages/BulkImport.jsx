import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { formatDate, useApi, useInterval } from '../lib/hooks';
import { Alert, Badge, Button, Card, Checkbox, EmptyState, Field, Input, Loading, PageHeader, Textarea, useToast } from '../components/ui';
import { ImageModeSelect, LanguageSelect, ProjectSelect } from '../components/PinParts';

export function BatchProgress({ batch }) {
    const counts = batch.counts || {};
    const done = (counts.generated || 0) + (counts.failed || 0) + (counts.duplicate || 0) + (counts.cancelled || 0);
    const pct = batch.total ? Math.round((done / batch.total) * 100) : 0;
    return (
        <div>
            <div className="h-2 rounded-full bg-gray-100 overflow-hidden flex">
                <div className="bg-emerald-500" style={{ width: `${((counts.generated || 0) / Math.max(1, batch.total)) * 100}%` }} />
                <div className="bg-red-400" style={{ width: `${((counts.failed || 0) / Math.max(1, batch.total)) * 100}%` }} />
                <div className="bg-gray-300" style={{ width: `${(((counts.duplicate || 0) + (counts.cancelled || 0)) / Math.max(1, batch.total)) * 100}%` }} />
            </div>
            <div className="flex flex-wrap gap-x-3 gap-y-1 mt-1.5 text-xs text-gray-500">
                <span>{pct}% done</span>
                {Object.entries(counts).map(([k, v]) => (
                    <span key={k}>
                        {k}: <span className="font-medium text-gray-700">{v}</span>
                    </span>
                ))}
            </div>
        </div>
    );
}

export default function BulkImport() {
    const toast = useToast();
    const navigate = useNavigate();
    const meta = useApi('/settings/meta');
    const prefs = useApi('/settings/preferences');
    const batches = useApi('/batches');
    const [form, setForm] = useState({ urls: '', name: '', language: 'en', image_mode: 'extract', project_id: null, skip_duplicates: true });
    const [csv, setCsv] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (prefs.data) setForm((f) => ({ ...f, language: prefs.data.data.language, image_mode: prefs.data.data.image_mode }));
    }, [prefs.data]);

    const active = batches.data?.data?.some((b) => ['processing', 'queued'].includes(b.status));
    useInterval(() => batches.reload(true), 4000, active);

    const urlCount = form.urls.split(/[\s,;]+/).filter((u) => /^https?:\/\//i.test(u)).length;

    const submit = async (e) => {
        e.preventDefault();
        setError('');
        setSubmitting(true);
        const fd = new FormData();
        Object.entries(form).forEach(([k, v]) => {
            if (v !== null && v !== '') fd.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : v);
        });
        if (csv) fd.append('csv', csv);
        try {
            const r = await api.post('/urls/bulk', fd);
            toast(`Batch created with ${r.data.data.total} URL(s).`);
            navigate(`/bulk/${r.data.data.id}`);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <>
            <PageHeader title="Bulk URL Import" description="Paste many URLs or upload a CSV. Each URL is processed independently in the background." />

            <div className="grid lg:grid-cols-5 gap-6">
                <Card className="p-5 sm:p-6 lg:col-span-3">
                    <form onSubmit={submit} className="space-y-4">
                        <Field label="URLs" hint={`One per line. ${urlCount} valid-looking URL(s).`}>
                            <Textarea rows={8} value={form.urls} onChange={(e) => setForm({ ...form, urls: e.target.value })} placeholder={'https://example.com/post-1\nhttps://example.com/post-2'} className="font-mono text-xs" />
                        </Field>
                        <Field label="…or upload CSV" hint="The first http(s) URL in each row is used. Max 2 MB.">
                            <Input type="file" accept=".csv,text/csv,text/plain" onChange={(e) => setCsv(e.target.files?.[0] || null)} />
                        </Field>
                        <div className="grid sm:grid-cols-2 gap-4">
                            <Field label="Batch name">
                                <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Optional" maxLength={100} />
                            </Field>
                            <Field label="Project">
                                <ProjectSelect value={form.project_id} onChange={(v) => setForm({ ...form, project_id: v })} />
                            </Field>
                            <Field label="Language">
                                <LanguageSelect value={form.language} onChange={(v) => setForm({ ...form, language: v })} languages={meta.data?.data?.languages} />
                            </Field>
                            <Field label="Image">
                                <ImageModeSelect value={form.image_mode} onChange={(v) => setForm({ ...form, image_mode: v })} />
                            </Field>
                        </div>
                        <Checkbox checked={form.skip_duplicates} onChange={(e) => setForm({ ...form, skip_duplicates: e.target.checked })} label="Skip URLs I already submitted" />
                        {error && <Alert type="error">{error}</Alert>}
                        <Button type="submit" loading={submitting} disabled={!urlCount && !csv}>
                            Start batch
                        </Button>
                    </form>
                </Card>

                <Card className="p-5 sm:p-6 lg:col-span-2 h-fit text-sm text-gray-600 space-y-2">
                    <h2 className="font-semibold text-gray-900">Good to know</h2>
                    <p>Generated Pins land as <Badge status="draft" /> for your review — nothing is published automatically unless you enable auto-publish for approved Pins.</p>
                    <p>Your hourly/daily processing limits apply. URLs over the limit are paused and can be resumed later.</p>
                    <p>Requires a running queue worker (<code className="text-xs bg-gray-100 px-1 rounded">php artisan queue:work</code>).</p>
                </Card>
            </div>

            <h2 className="font-semibold mt-8 mb-3">Recent batches</h2>
            <Card>
                {batches.loading ? (
                    <Loading />
                ) : !batches.data?.data?.length ? (
                    <EmptyState title="No batches yet" description="Your bulk imports will appear here." icon="🗂️" />
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {batches.data.data.map((b) => (
                            <li key={b.id}>
                                <Link to={`/bulk/${b.id}`} className="block px-5 py-4 hover:bg-gray-50">
                                    <div className="flex items-center justify-between gap-3 mb-2">
                                        <span className="font-medium truncate">{b.name}</span>
                                        <div className="flex items-center gap-3">
                                            <span className="text-xs text-gray-400 hidden sm:inline">{formatDate(b.created_at)}</span>
                                            <Badge status={b.status} />
                                        </div>
                                    </div>
                                    <BatchProgress batch={b} />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </>
    );
}
