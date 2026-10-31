import { Link } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import { useApi, timeAgo } from '../lib/hooks';
import { Alert, Badge, Button, Card, EmptyState, ErrorBox, Loading, PageHeader, Stat } from '../components/ui';
import RunningTasks from '../components/RunningTasks';

function Glyph({ path, className = 'h-5 w-5' }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={path} /></svg>;
}
const ARROW = 'M5 12h14m-5-5 5 5-5 5';
const SPARKLE = 'm12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z';

function CreativeIllustration() {
    return (
        <div className="pointer-events-none relative mx-auto h-64 w-full max-w-sm select-none sm:h-72" aria-hidden="true">
            <div className="absolute left-12 top-4 h-56 w-56 rounded-full border border-[#e6c8c0] sm:h-64 sm:w-64" />
            <div className="absolute left-8 top-9 h-56 w-56 rounded-full border border-[#e6c8c0]/60 sm:h-64 sm:w-64" />
            <div className="absolute left-7 top-7 h-52 w-36 -rotate-12 overflow-hidden rounded-xl border-[5px] border-white bg-[#dbe3d5] shadow-[0_12px_30px_#50342a18] sm:left-8 sm:h-60 sm:w-40">
                <svg viewBox="0 0 160 240" className="h-full w-full" fill="none">
                    <path d="M0 0h160v240H0z" fill="#dce4d6" /><path d="M0 173c28-26 43-24 79-13 36 12 52 7 81-17v97H0z" fill="#c2ceba" />
                    <path d="M79 170c2-34-5-55 5-101m-6 63-24-26m28 6 21-25m-20 2-15-20" stroke="#536b52" strokeWidth="2" />
                    <path d="M58 111c-22-1-27-17-26-26 19 1 30 9 26 26Zm20 17c17 4 31-4 35-17-19-6-32 1-35 17Zm6-35c-7-16-1-29 11-35 7 17 3 29-11 35Zm-15-9c-14-4-22-16-18-29 17 6 22 16 18 29Zm35 5c-1-15 8-27 23-28-1 18-9 27-23 28Z" fill="#627d5e" />
                    <path d="M52 163h55l-8 47H61z" fill="#ac6e53" /><path d="M49 161h61v9H49z" fill="#bd8166" /><path d="M17 220h127" stroke="#b6c1ad" />
                </svg>
                <div className="absolute left-3 top-3 text-[9px] font-semibold uppercase tracking-[0.18em] text-[#4d6350]">A slower kind of living</div>
            </div>
            <div className="absolute right-7 top-12 h-52 w-36 rotate-[11deg] overflow-hidden rounded-xl border-[5px] border-white bg-[#f0c4a8] shadow-[0_12px_30px_#50342a20] sm:right-8 sm:top-14 sm:h-60 sm:w-40">
                <svg viewBox="0 0 160 240" className="h-full w-full" fill="none"><path d="M0 0h160v240H0z" fill="#f0c4a8" /><circle cx="118" cy="65" r="35" fill="#f9dfbc" /><path d="M0 163c41-37 67-32 104-8 20 13 39 16 56 14v71H0z" fill="#cb866d" /><path d="M0 194c40 10 54-25 91-13 25 9 45 30 69 19v40H0z" fill="#a85e52" /><path d="M0 217c38-18 67-15 100 4 21 12 38 7 60 0v19H0z" fill="#8a4e48" /></svg>
                <div className="absolute inset-x-3 top-7 text-center text-[24px] font-semibold leading-[1.05] tracking-tight text-[#743e35] sm:text-[27px]">Find your<br /><span className="font-serif italic font-normal">next escape.</span></div>
                <div className="absolute inset-x-0 bottom-5 text-center text-[8px] font-medium uppercase tracking-[0.22em] text-white/90">Little moments, big inspiration</div>
            </div>
            <div className="absolute bottom-3 left-1/2 flex -translate-x-1/2 items-center gap-2 whitespace-nowrap rounded-full border border-white bg-white/95 px-3.5 py-2 shadow-[0_8px_24px_#50342a12] sm:bottom-1">
                <span className="grid h-6 w-6 place-items-center rounded-full bg-brand-50 text-brand-600"><Glyph path={SPARKLE} className="h-3.5 w-3.5" /></span><span className="text-[11px] font-semibold text-gray-700">A little inspiration goes a long way</span>
            </div>
            <span className="absolute right-2 top-6 text-[#c37862]"><Glyph path={SPARKLE} className="h-6 w-6" /></span><span className="absolute bottom-8 left-2 text-[#c37862]"><Glyph path={SPARKLE} className="h-4 w-4" /></span>
        </div>
    );
}

export default function Dashboard() {
    const { user } = useAuth();
    const { data, loading, error, reload } = useApi('/dashboard');
    if (loading) return <Loading />;
    if (error) return <ErrorBox message={error} onRetry={reload} />;
    const { stats, limits, recent_activity: activity, accounts_connected: accounts } = data.data;
    const firstName = user?.name?.trim().split(/\s+/)[0];
    const publishedPercent = Math.min(100, Math.max(0, (limits.published_today / Math.max(1, limits.daily_publish_limit)) * 100));

    return (
        <>
            <PageHeader title={firstName ? `Welcome back, ${firstName}` : 'Your creative overview'} description="Your ideas, your Pins, your next big thing." actions={<><Link to="/bulk"><Button variant="secondary"><Glyph path="M12 16V4m-4 4 4-4 4 4M4 15v5h16v-5" className="h-4 w-4" />Bulk import</Button></Link><Link to="/create"><Button><Glyph path="M12 5v14M5 12h14" className="h-4 w-4" />Create Pin</Button></Link></>} />
            <section className="relative mb-7 overflow-hidden rounded-2xl border border-[#ecd9d2] bg-[#f7eae4]">
                <div className="relative grid items-center gap-1 px-6 py-7 sm:px-8 lg:grid-cols-[1.15fr_1fr] lg:gap-5 lg:px-9 lg:py-4">
                    <div className="relative z-10 py-2 lg:py-6">
                        <div className="mb-3 flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-[#a85d50]"><Glyph path={SPARKLE} className="h-3.5 w-3.5" />A space for your next idea</div>
                        <h2 className="max-w-md text-3xl font-semibold leading-[1.15] tracking-[-0.035em] text-[#3f302e] sm:text-[36px]">Big ideas.<br />Beautiful Pins. <span className="font-serif font-normal italic text-[#be675b]">Less effort.</span></h2>
                        <p className="mb-5 mt-3 max-w-sm text-sm leading-relaxed text-[#806a64]">Turn the content you love into Pins worth saving. Start with a link and let your creativity take it from there.</p>
                        <Link to="/create" className="inline-flex items-center gap-2 rounded-lg bg-[#3f302e] px-4 py-2.5 text-xs font-semibold text-white shadow-sm transition hover:bg-[#59403b] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-600">Create something new<Glyph path={ARROW} className="h-4 w-4" /></Link>
                    </div>
                    <CreativeIllustration />
                </div>
            </section>
            {accounts === 0 && <div className="mb-5"><Alert type="info">Connect a Pinterest account to start publishing your ideas. <Link to="/accounts" className="font-semibold underline underline-offset-2">Connect account &rarr;</Link></Alert></div>}
            <RunningTasks />
            <div className="mb-3 flex items-center justify-between gap-3"><h2 className="text-sm font-semibold tracking-tight text-gray-800">Your workspace at a glance</h2><span className="text-[11px] text-gray-400">All-time overview</span></div>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <Stat label="URLs submitted" value={stats.urls_submitted} hint={stats.processing ? `${stats.processing} processing` : 'Where ideas begin'} />
                <Stat label="Pins generated" value={stats.pins_generated} tone="brand" hint="Ready to make an impression" />
                <Stat label="Published" value={stats.pins_published} tone="green" hint="Out in the world" />
                <Stat label="Needs review" value={stats.pending_approval} tone="amber" hint={stats.approved ? `${stats.approved} approved` : 'A final creative touch'} />
                <Stat label="Scheduled" value={stats.scheduled} tone="violet" hint="Something to look forward to" />
                <Stat label="Failed" value={stats.failed} tone="red" hint={stats.failed ? 'Check your activity log' : 'Looking good'} />
            </div>
            <div className="mt-6 grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_300px]">
                <Card className="overflow-hidden">
                    <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 sm:px-6"><div><h2 className="text-sm font-semibold">Recent activity</h2><p className="mt-0.5 text-xs text-gray-400">The latest from your creative workspace</p></div><Link to="/reports" className="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 transition hover:text-brand-600">View all<Glyph path={ARROW} className="h-3.5 w-3.5" /></Link></div>
                    {activity.length === 0 ? <EmptyState title="Your story starts here" description="Create your first Pin and watch your workspace come to life." icon={<span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-brand-50 text-brand-600"><Glyph path="M8 4h8a2 2 0 0 1 2 2v14l-6-4-6 4V6a2 2 0 0 1 2-2Z" className="h-6 w-6" /></span>} action={<Link to="/create"><Button size="sm">Create your first Pin<Glyph path={ARROW} className="h-3.5 w-3.5" /></Button></Link>} /> : <ul className="divide-y divide-gray-100">{activity.map((a) => (
                        <li key={a.id} className="flex items-start gap-3 px-5 py-4 sm:px-6">
                            <span className={`mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg ${a.level === 'error' ? 'bg-red-50 text-red-500' : a.level === 'warning' ? 'bg-amber-50 text-amber-600' : 'bg-[#f1f4ee] text-[#718367]'}`}><Glyph path={a.level === 'error' || a.level === 'warning' ? 'M12 8v5m0 3h.01M10.3 4.5 2.5 18a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 4.5a2 2 0 0 0-3.4 0Z' : 'm7 12 3 3 7-7M21 12a9 9 0 1 1-5-8'} className="h-4 w-4" /></span>
                            <div className="min-w-0 flex-1"><p className="break-words text-sm leading-relaxed text-gray-700">{a.message}</p><div className="mt-1.5 flex flex-wrap items-center gap-2"><Badge status={a.level}>{a.event.replaceAll('_', ' ')}</Badge>{a.generated_pin_id && <Link to={`/pins/${a.generated_pin_id}`} className="text-xs font-medium text-brand-600 hover:underline">View Pin &rarr;</Link>}</div></div>
                            <time dateTime={a.created_at} className="pt-1 text-right text-[10px] whitespace-nowrap text-gray-400 sm:text-xs">{timeAgo(a.created_at)}</time>
                        </li>
                    ))}</ul>}
                </Card>
                <div className="space-y-5">
                    <Card className="p-5">
                        <div className="flex items-center justify-between"><h2 className="text-sm font-semibold">Today's publishing</h2><Glyph path="M8 2v4m8-4v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z" className="h-4 w-4 text-gray-400" /></div>
                        <div className="my-5 flex items-center gap-4"><div className="relative grid h-20 w-20 shrink-0 place-items-center"><svg viewBox="0 0 80 80" className="absolute inset-0 h-full w-full -rotate-90" aria-hidden="true"><circle cx="40" cy="40" r="34" fill="none" stroke="#f1eeeb" strokeWidth="6" /><circle cx="40" cy="40" r="34" fill="none" stroke="currentColor" className="text-brand-600" strokeWidth="6" strokeLinecap="round" pathLength="100" strokeDasharray={`${publishedPercent} 100`} /></svg><span className="text-2xl font-semibold tracking-tight text-gray-800 tabular-nums">{limits.published_today}</span></div><div><p className="text-sm font-medium text-gray-700">Pins published</p><p className="mt-1 text-xs text-gray-400">of {limits.daily_publish_limit} daily limit</p><Link to="/settings" className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">Adjust limits &rarr;</Link></div></div>
                        <div className="space-y-3 border-t border-gray-100 pt-4 text-xs"><div className="flex items-center justify-between gap-3"><span className="text-gray-500">URLs left to process</span><span className="font-semibold text-gray-700 tabular-nums">{limits.remaining_processing}</span></div><div className="flex items-center justify-between gap-3"><span className="text-gray-500">Auto-publish</span><Badge status={limits.auto_publish ? 'active' : 'cancelled'}>{limits.auto_publish ? 'On' : 'Off'}</Badge></div><div className="flex items-center justify-between gap-3"><span className="text-gray-500">Connected accounts</span><Link to="/accounts" className="font-semibold text-gray-700 hover:text-brand-600">{accounts}</Link></div></div>
                    </Card>
                    <Card className="overflow-hidden">
                        <div className="px-5 pb-1 pt-4 text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400">Keep the ideas flowing</div>
                        <Link to="/pins" className="group flex items-center gap-3 px-5 py-3.5 transition hover:bg-brand-50/50"><span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-[#f7eee4] text-[#a88150]"><Glyph path="M4 4h6v7H4zM14 4h6v7h-6zM4 15h6v5H4zM14 15h6v5h-6z" className="h-4 w-4" /></span><span className="min-w-0 flex-1"><span className="block text-xs font-semibold text-gray-800">Visit your Pin Library</span><span className="mt-0.5 block text-[11px] text-gray-400">{stats.pending_approval ? `${stats.pending_approval} Pins ready for your review` : 'A home for all your ideas'}</span></span><Glyph path={ARROW} className="h-4 w-4 shrink-0 text-gray-400 group-hover:text-brand-600" /></Link>
                        <Link to="/queue" className="group flex items-center gap-3 border-t border-gray-100 px-5 py-3.5 transition hover:bg-brand-50/50"><span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-[#eeedf7] text-[#8b7baa]"><Glyph path="M12 7v5l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" className="h-4 w-4" /></span><span className="min-w-0 flex-1"><span className="block text-xs font-semibold text-gray-800">Plan what's next</span><span className="mt-0.5 block text-[11px] text-gray-400">{stats.scheduled ? `${stats.scheduled} Pins on your schedule` : 'Make room for consistency'}</span></span><Glyph path={ARROW} className="h-4 w-4 shrink-0 text-gray-400 group-hover:text-brand-600" /></Link>
                    </Card>
                </div>
            </div>
        </>
    );
}
