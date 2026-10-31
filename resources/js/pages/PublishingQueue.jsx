import { useState } from 'react';
import { Link } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { formatDate, useApi, useInterval } from '../lib/hooks';
import { Alert, Badge, Button, Card, EmptyState, ErrorBox, Loading, PageHeader, cx, useToast } from '../components/ui';

function Row({ pin, right }) {
    return (
        <li className="px-5 py-3 flex items-center gap-3">
            <Link to={`/pins/${pin.id}`} className="shrink-0">
                {pin.image?.url ? (
                    <img src={pin.image.url} alt="" className="h-16 w-11 object-cover rounded-md bg-gray-100" loading="lazy" />
                ) : (
                    <div className="h-16 w-11 rounded-md bg-gray-100" />
                )}
            </Link>
            <div className="min-w-0 flex-1">
                <Link to={`/pins/${pin.id}`} className="text-sm font-medium hover:text-brand-600 line-clamp-1">{pin.title || 'Untitled'}</Link>
                <div className="text-xs text-gray-500 truncate">
                    {pin.account ? `@${pin.account.username}` : 'No account'} {pin.board_id ? '· board selected' : '· no board'}
                </div>
                {pin.error_message && <div className="text-xs text-red-600 line-clamp-2">{pin.error_message}</div>}
            </div>
            <div className="shrink-0 flex items-center gap-2">{right}</div>
        </li>
    );
}

const TABS = [
    ['scheduled', 'Scheduled'],
    ['approved', 'Approved'],
    ['publishing', 'Publishing'],
    ['failed', 'Failed'],
    ['jobs', 'Job history'],
];

export default function PublishingQueue() {
    const toast = useToast();
    const { data, loading, error, reload } = useApi('/publishing-queue');
    const [tab, setTab] = useState('scheduled');
    const [busy, setBusy] = useState(null);

    useInterval(() => reload(true), 5000, (data?.data?.publishing?.length || 0) > 0);

    const act = async (key, fn, msg) => {
        setBusy(key);
        try {
            await fn();
            toast(msg);
            await reload(true);
        } catch (e) {
            toast(errorMessage(e), 'error');
        } finally {
            setBusy(null);
        }
    };

    if (loading) return <Loading />;
    if (error) return <ErrorBox message={error} onRetry={reload} />;
    const q = data.data;

    return (
        <>
            <PageHeader
                title="Publishing Queue"
                description="Scheduled, approved, in-flight and failed Pins."
                actions={
                    <Button
                        variant={q.paused ? 'primary' : 'secondary'}
                        loading={busy === 'pause'}
                        onClick={() => act('pause', () => api.post('/publishing-queue/automation', { paused: !q.paused }), q.paused ? 'Automation resumed.' : 'Automation paused.')}
                    >
                        {q.paused ? '▶ Resume automation' : '❚❚ Pause automation'}
                    </Button>
                }
            />

            {q.paused && <div className="mb-4"><Alert>Automation is paused. Scheduled Pins will wait until you resume.</Alert></div>}

            <div className="flex gap-1 overflow-x-auto mb-4 -mx-1 px-1">
                {TABS.map(([key, label]) => (
                    <button
                        key={key}
                        onClick={() => setTab(key)}
                        className={cx('px-3 py-1.5 rounded-full text-sm whitespace-nowrap', tab === key ? 'bg-gray-900 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50')}
                    >
                        {label} {key !== 'jobs' && <span className="opacity-60">({q[key].length})</span>}
                    </button>
                ))}
            </div>

            <Card>
                {tab === 'jobs' ? (
                    q.jobs.length === 0 ? (
                        <EmptyState title="No publishing jobs yet" icon="🕓" />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs uppercase text-gray-500 border-b border-gray-100">
                                    <tr>
                                        <th className="px-5 py-2">Pin</th>
                                        <th className="px-3 py-2">Trigger</th>
                                        <th className="px-3 py-2">Status</th>
                                        <th className="px-3 py-2">Attempts</th>
                                        <th className="px-3 py-2">Created</th>
                                        <th className="px-3 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {q.jobs.map((j) => (
                                        <tr key={j.id}>
                                            <td className="px-5 py-2 max-w-xs">
                                                <Link to={`/pins/${j.generated_pin_id}`} className="hover:text-brand-600 line-clamp-1">{j.pin?.title || `Pin #${j.generated_pin_id}`}</Link>
                                                {j.last_error && <div className="text-xs text-red-600 line-clamp-1">{j.last_error}</div>}
                                            </td>
                                            <td className="px-3 py-2 capitalize">{j.trigger}</td>
                                            <td className="px-3 py-2"><Badge status={j.status} /></td>
                                            <td className="px-3 py-2 tabular-nums">{j.attempts}</td>
                                            <td className="px-3 py-2 whitespace-nowrap text-gray-500">{formatDate(j.created_at)}</td>
                                            <td className="px-3 py-2">
                                                {j.status === 'queued' && (
                                                    <Button size="sm" variant="ghost" onClick={() => act(`job${j.id}`, () => api.post(`/publishing-queue/jobs/${j.id}/cancel`), 'Cancelled.')}>Cancel</Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )
                ) : q[tab].length === 0 ? (
                    <EmptyState
                        title={`No ${tab} Pins`}
                        description={tab === 'approved' ? 'Approved Pins waiting to be published or scheduled appear here.' : undefined}
                        icon="📭"
                    />
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {q[tab].map((pin) => (
                            <Row
                                key={pin.id}
                                pin={pin}
                                right={
                                    <>
                                        {tab === 'scheduled' && (
                                            <>
                                                <span className="text-xs text-gray-600 hidden sm:inline">{formatDate(pin.schedule?.scheduled_at)}</span>
                                                <Button size="sm" variant="ghost" loading={busy === `u${pin.id}`} onClick={() => act(`u${pin.id}`, () => api.delete(`/pins/${pin.id}/schedule`), 'Schedule cancelled.')}>Unschedule</Button>
                                            </>
                                        )}
                                        {tab === 'approved' && (
                                            <Button size="sm" loading={busy === `p${pin.id}`} onClick={() => act(`p${pin.id}`, () => api.post(`/pins/${pin.id}/publish`), 'Publishing started.')}>Publish</Button>
                                        )}
                                        {tab === 'publishing' && <Badge status="publishing" />}
                                        {tab === 'failed' && (
                                            <Button size="sm" variant="secondary" loading={busy === `r${pin.id}`} onClick={() => act(`r${pin.id}`, () => api.post(`/publishing-queue/pins/${pin.id}/retry`), 'Retry queued.')}>Retry</Button>
                                        )}
                                    </>
                                }
                            />
                        ))}
                    </ul>
                )}
            </Card>
        </>
    );
}
