import { Link } from 'react-router-dom';
import { useApi, timeAgo } from '../lib/hooks';
import { Alert, Badge, Button, Card, EmptyState, ErrorBox, Loading, PageHeader, Stat } from '../components/ui';

export default function Dashboard() {
    const { data, loading, error, reload } = useApi('/dashboard');

    if (loading) return <Loading />;
    if (error) return <ErrorBox message={error} onRetry={reload} />;

    const { stats, limits, recent_activity: activity, accounts_connected: accounts } = data.data;

    return (
        <>
            <PageHeader
                title="Dashboard"
                description="Overview of your Pin pipeline."
                actions={
                    <>
                        <Link to="/bulk">
                            <Button variant="secondary">Bulk import</Button>
                        </Link>
                        <Link to="/create">
                            <Button>+ Create Pin</Button>
                        </Link>
                    </>
                }
            />

            {accounts === 0 && (
                <div className="mb-6">
                    <Alert type="info">
                        Connect a Pinterest account to publish Pins. <Link to="/accounts" className="font-medium underline">Connect now →</Link>
                    </Alert>
                </div>
            )}
            {limits.automation_paused && (
                <div className="mb-6">
                    <Alert>
                        Automation is paused — scheduled and automatic publishing will wait. <Link to="/queue" className="font-medium underline">Resume in Publishing Queue</Link>
                    </Alert>
                </div>
            )}

            <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 sm:gap-4">
                <Stat label="URLs submitted" value={stats.urls_submitted} hint={stats.processing ? `${stats.processing} processing` : undefined} />
                <Stat label="Pins generated" value={stats.pins_generated} tone="brand" />
                <Stat label="Published" value={stats.pins_published} tone="green" />
                <Stat label="Pending approval" value={stats.pending_approval} tone="amber" hint={stats.approved ? `${stats.approved} approved` : undefined} />
                <Stat label="Scheduled" value={stats.scheduled} tone="violet" />
                <Stat label="Failed" value={stats.failed} tone="red" />
            </div>

            <div className="grid lg:grid-cols-3 gap-6 mt-6">
                <Card className="lg:col-span-2">
                    <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 className="font-semibold">Recent activity</h2>
                        <Link to="/reports" className="text-sm text-brand-600">All logs</Link>
                    </div>
                    {activity.length === 0 ? (
                        <EmptyState title="No activity yet" description="Submit your first URL to generate a Pin." action={<Link to="/create"><Button>Create Pin</Button></Link>} />
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {activity.map((a) => (
                                <li key={a.id} className="px-5 py-3 flex items-start gap-3">
                                    <Badge status={a.level}>{a.event.replaceAll('_', ' ')}</Badge>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-gray-800 break-words">{a.message}</p>
                                        {a.generated_pin_id && (
                                            <Link to={`/pins/${a.generated_pin_id}`} className="text-xs text-brand-600">View Pin</Link>
                                        )}
                                    </div>
                                    <span className="text-xs text-gray-400 whitespace-nowrap">{timeAgo(a.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card className="p-5 space-y-4 h-fit">
                    <h2 className="font-semibold">Today's limits</h2>
                    <div>
                        <div className="flex justify-between text-sm">
                            <span className="text-gray-600">Published today</span>
                            <span className="font-medium tabular-nums">
                                {limits.published_today} / {limits.daily_publish_limit}
                            </span>
                        </div>
                        <div className="h-2 rounded-full bg-gray-100 mt-2 overflow-hidden">
                            <div
                                className="h-full bg-brand-600 rounded-full"
                                style={{ width: `${Math.min(100, (limits.published_today / Math.max(1, limits.daily_publish_limit)) * 100)}%` }}
                            />
                        </div>
                    </div>
                    <div className="flex justify-between text-sm">
                        <span className="text-gray-600">URLs you can still process</span>
                        <span className="font-medium tabular-nums">{limits.remaining_processing}</span>
                    </div>
                    <div className="flex justify-between text-sm">
                        <span className="text-gray-600">Auto-publish</span>
                        <Badge status={limits.auto_publish ? 'active' : 'cancelled'}>{limits.auto_publish ? 'On' : 'Off'}</Badge>
                    </div>
                    <Link to="/settings" className="block text-sm text-brand-600">Adjust limits →</Link>
                </Card>
            </div>
        </>
    );
}
