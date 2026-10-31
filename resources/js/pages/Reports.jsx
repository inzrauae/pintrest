import { useState } from 'react';
import { Link } from 'react-router-dom';
import api, { download, errorMessage } from '../lib/api';
import { useAuth } from '../lib/auth';
import { formatDate, useApi } from '../lib/hooks';
import { Badge, Button, Card, EmptyState, ErrorBox, Loading, Modal, PageHeader, Pagination, Select, Stat, useToast } from '../components/ui';
import DailyChart from '../components/DailyChart';

function Logs() {
    const [level, setLevel] = useState('');
    const [page, setPage] = useState(1);
    const [detail, setDetail] = useState(null);
    const { data, loading } = useApi('/reports/logs', { level, page });

    return (
        <Card>
            <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-3">
                <h2 className="font-semibold">Activity & error log</h2>
                <Select value={level} onChange={(e) => { setLevel(e.target.value); setPage(1); }} className="w-36">
                    <option value="">All levels</option>
                    <option value="info">Info</option>
                    <option value="warning">Warnings</option>
                    <option value="error">Errors</option>
                </Select>
            </div>
            {loading ? (
                <Loading />
            ) : !data.data.length ? (
                <EmptyState title="Nothing logged" icon="🧾" />
            ) : (
                <>
                    <ul className="divide-y divide-gray-100">
                        {data.data.map((l) => (
                            <li key={l.id} className="px-5 py-2.5 flex items-start gap-3 text-sm">
                                <Badge status={l.level} />
                                <div className="min-w-0 flex-1">
                                    <div className="text-gray-800 break-words">{l.message}</div>
                                    <div className="text-xs text-gray-400">
                                        {l.event} · {formatDate(l.created_at)}
                                        {l.http_status ? ` · HTTP ${l.http_status}` : ''}
                                        {l.generated_pin_id && <> · <Link to={`/pins/${l.generated_pin_id}`} className="text-brand-600">Pin #{l.generated_pin_id}</Link></>}
                                    </div>
                                </div>
                                {l.details && <Button size="sm" variant="ghost" onClick={() => setDetail(l)}>Details</Button>}
                            </li>
                        ))}
                    </ul>
                    <div className="px-4"><Pagination meta={{ current_page: data.current_page, last_page: data.last_page, total: data.total }} onPage={setPage} /></div>
                </>
            )}
            <Modal open={!!detail} onClose={() => setDetail(null)} title="Developer details" wide>
                <p className="text-xs text-gray-500 mb-2">Raw provider response (secrets are redacted).</p>
                <pre className="text-xs bg-gray-900 text-gray-100 rounded-lg p-4 overflow-auto max-h-[60vh]">{JSON.stringify(detail?.details, null, 2)}</pre>
            </Modal>
        </Card>
    );
}

function FailedJobs() {
    const toast = useToast();
    const { data, loading, error, reload } = useApi('/admin/failed-jobs');
    const act = async (fn, msg) => {
        try {
            await fn();
            toast(msg);
            reload(true);
        } catch (e) {
            toast(errorMessage(e), 'error');
        }
    };
    if (loading) return <Loading />;
    if (error) return <ErrorBox message={error} />;

    return (
        <Card>
            <div className="px-5 py-3 border-b border-gray-100 font-semibold">Admin · failed queue jobs</div>
            {data.data.length === 0 ? (
                <EmptyState title="No failed jobs" icon="✅" />
            ) : (
                <ul className="divide-y divide-gray-100">
                    {data.data.map((j) => (
                        <li key={j.uuid} className="px-5 py-3 text-sm">
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-medium">{j.job} <span className="text-gray-400 font-normal">· {j.queue} · {formatDate(j.failed_at)}</span></span>
                                <div className="flex gap-1">
                                    <Button size="sm" variant="secondary" onClick={() => act(() => api.post(`/admin/failed-jobs/${j.uuid}/retry`), 'Job re-queued.')}>Retry</Button>
                                    <Button size="sm" variant="ghost" onClick={() => act(() => api.delete(`/admin/failed-jobs/${j.uuid}`), 'Deleted.')}>Delete</Button>
                                </div>
                            </div>
                            <pre className="text-xs text-red-700 mt-1 whitespace-pre-wrap line-clamp-3">{j.exception}</pre>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

export default function Reports() {
    const { user } = useAuth();
    const [days, setDays] = useState(30);
    const { data, loading, error, reload } = useApi('/reports/summary', { days });

    if (loading) return <Loading />;
    if (error) return <ErrorBox message={error} onRetry={reload} />;
    const r = data.data;
    const total = (k) => r.daily.reduce((n, d) => n + d[k], 0);
    const pub = total('published');
    const gen = total('generated');

    return (
        <>
            <PageHeader
                title="Reports"
                description="Throughput, outcomes and errors."
                actions={
                    <>
                        <Select value={days} onChange={(e) => setDays(Number(e.target.value))} className="w-36">
                            <option value={7}>Last 7 days</option>
                            <option value={30}>Last 30 days</option>
                            <option value={90}>Last 90 days</option>
                        </Select>
                        <Button variant="secondary" onClick={() => download('pins/export')}>Export Pins CSV</Button>
                    </>
                }
            />

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <Stat label="URLs submitted" value={total('submitted')} />
                <Stat label="Pins generated" value={gen} tone="brand" />
                <Stat label="Pins published" value={pub} tone="green" />
                <Stat label="Publish rate" value={gen ? `${Math.round((pub / gen) * 100)}%` : '—'} hint="Published ÷ generated" />
            </div>

            <Card className="p-5 mb-6">
                <h2 className="font-semibold mb-3">Daily activity</h2>
                <DailyChart daily={r.daily} />
            </Card>

            <div className="grid lg:grid-cols-2 gap-6 mb-6">
                <Card className="p-5">
                    <h2 className="font-semibold mb-3">Pins by status</h2>
                    <ul className="space-y-2">
                        {Object.entries(r.pins_by_status).length === 0 && <li className="text-sm text-gray-500">No Pins yet.</li>}
                        {Object.entries(r.pins_by_status).map(([s, c]) => (
                            <li key={s} className="flex items-center justify-between text-sm"><Badge status={s} /><span className="tabular-nums font-medium">{c}</span></li>
                        ))}
                    </ul>
                </Card>
                <Card className="p-5">
                    <h2 className="font-semibold mb-3">Most frequent errors</h2>
                    {r.top_errors.length === 0 ? (
                        <p className="text-sm text-gray-500">No errors in this period. 🎉</p>
                    ) : (
                        <ul className="space-y-2">
                            {r.top_errors.map((e) => (
                                <li key={e.event + e.message} className="flex items-start justify-between gap-3 text-sm">
                                    <span className="text-gray-700"><span className="text-xs text-gray-400">{e.event}</span><br />{e.message}</span>
                                    <span className="tabular-nums font-medium text-red-600">{e.c}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <div className="space-y-6">
                <Logs />
                {user?.is_admin && <FailedJobs />}
            </div>
        </>
    );
}
