import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api, { download, errorMessage } from '../lib/api';
import { useApi, useInterval } from '../lib/hooks';
import { Badge, Button, Card, ErrorBox, Loading, PageHeader, Select, useToast } from '../components/ui';
import { BatchProgress } from './BulkImport';

export default function BatchDetail() {
    const { id } = useParams();
    const toast = useToast();
    const { data, setData, loading, error, reload } = useApi(`/batches/${id}`);
    const [busy, setBusy] = useState('');
    const [filter, setFilter] = useState('');

    const batch = data?.data;
    const active = batch && ['processing', 'queued'].includes(batch.status);
    useInterval(() => reload(true), 3000, active);

    const act = async (action, body = {}) => {
        setBusy(action);
        try {
            const r = await api.post(`/batches/${id}/${action}`, body);
            setData(r.data);
            toast(`Batch ${action === 'retry' ? 'retry queued' : action + 'd'}.`);
        } catch (e) {
            toast(errorMessage(e), 'error');
        } finally {
            setBusy('');
        }
    };

    if (loading) return <Loading />;
    if (error) return <ErrorBox message={error} onRetry={reload} />;

    const submissions = data.submissions.filter((s) => !filter || s.status === filter);
    const failed = batch.counts?.failed || 0;

    return (
        <>
            <Link to="/bulk" className="text-sm text-gray-500 hover:text-gray-800">← Bulk import</Link>
            <PageHeader
                title={batch.name}
                description={`${batch.total} URL(s)`}
                actions={
                    <>
                        {active && <Button variant="secondary" loading={busy === 'pause'} onClick={() => act('pause')}>Pause</Button>}
                        {batch.status === 'paused' && <Button variant="secondary" loading={busy === 'resume'} onClick={() => act('resume')}>Resume</Button>}
                        {(batch.counts?.paused || 0) > 0 && batch.status !== 'paused' && batch.status !== 'cancelled' && (
                            <Button variant="secondary" loading={busy === 'resume'} onClick={() => act('resume')}>Resume waiting</Button>
                        )}
                        {failed > 0 && <Button variant="secondary" loading={busy === 'retry'} onClick={() => act('retry')}>Retry failed ({failed})</Button>}
                        {batch.status !== 'cancelled' && batch.status !== 'completed' && (
                            <Button variant="danger" loading={busy === 'cancel'} onClick={() => window.confirm('Cancel all pending URLs in this batch?') && act('cancel')}>Cancel</Button>
                        )}
                        <Button variant="dark" onClick={() => download(`batches/${id}/export`)}>Export CSV</Button>
                    </>
                }
            />

            <Card className="p-5 mb-4">
                <div className="flex items-center gap-3 mb-3">
                    <Badge status={batch.status} />
                    {active && <span className="text-xs text-gray-500">Updating live…</span>}
                </div>
                <BatchProgress batch={batch} />
            </Card>

            <Card>
                <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-3">
                    <h2 className="font-semibold">URLs</h2>
                    <Select value={filter} onChange={(e) => setFilter(e.target.value)} className="w-40">
                        <option value="">All statuses</option>
                        {Object.keys(batch.counts || {}).map((s) => <option key={s} value={s}>{s}</option>)}
                    </Select>
                </div>
                <ul className="divide-y divide-gray-100">
                    {submissions.map((s) => (
                        <li key={s.id} className="px-5 py-3 flex flex-col sm:flex-row sm:items-center gap-2">
                            <div className="min-w-0 flex-1">
                                <div className="text-sm truncate" title={s.url}>{s.url}</div>
                                {s.pin && <div className="text-xs text-gray-500 truncate">{s.pin.title}</div>}
                                {s.error_message && <div className="text-xs text-red-600">{s.error_message}</div>}
                            </div>
                            <div className="flex items-center gap-2 shrink-0">
                                <Badge status={s.status} />
                                {s.pin && <Link to={`/pins/${s.pin.id}`}><Button size="sm" variant="secondary">Review</Button></Link>}
                                {['failed', 'cancelled'].includes(s.status) && (
                                    <Button size="sm" variant="ghost" onClick={() => act('retry', { submission_ids: [s.id], include_cancelled: true })}>Retry</Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </Card>
        </>
    );
}
