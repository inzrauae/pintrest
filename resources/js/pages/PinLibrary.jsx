import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { download } from '../lib/api';
import { useApi } from '../lib/hooks';
import { Button, Card, EmptyState, ErrorBox, Input, Loading, PageHeader, Pagination, cx } from '../components/ui';
import { PendingPinCard, PinCard, ProjectSelect } from '../components/PinParts';
import { NowBar } from '../components/RunningTasks';

const TABS = [
    ['', 'All'],
    ['draft', 'Needs review'],
    ['approved', 'Approved'],
    ['scheduled', 'Scheduled'],
    ['published', 'Published'],
    ['failed', 'Failed'],
];

export default function PinLibrary() {
    const [status, setStatus] = useState('');
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [project, setProject] = useState(null);
    const [page, setPage] = useState(1);
    const { data, setData, loading, error, reload } = useApi('/pins', { status, search: query, page, project_id: project || undefined });
    const replacePin = (updated) => setData((d) => ({ ...d, data: d.data.map((p) => (p.id === updated.id ? { ...p, ...updated } : p)) }));
    // While Pins are being generated or published, refresh the grid with each status update (and once more when it stops).
    const wasActive = useRef(false);
    const [inProgress, setInProgress] = useState([]);
    const onTick = (active, tasks) => {
        if (active || wasActive.current) reload(true);
        wasActive.current = active;
        setInProgress(tasks?.generation?.items || []);
    };
    // Pins still being made go first, but only where new Pins would appear (first page, All / Needs review, no search).
    const showInProgress = page === 1 && !query && !project && ['', 'draft'].includes(status) ? inProgress : [];

    return (
        <>
            <PageHeader
                title="Pin Library"
                description="Your ideas, all in one place. Find, review, and share your next great Pin."
                actions={
                    <>
                        <Button variant="secondary" onClick={() => download('pins/export', status ? { status } : {})}>Export CSV</Button>
                        <Link to="/create" className="ui-button ui-button-primary"><span aria-hidden="true">+</span> Create Pin</Link>
                    </>
                }
            />

            <NowBar onTick={onTick} />

            <div className="mb-6 space-y-4">
                <div className="flex min-w-0 gap-1.5 overflow-x-auto pb-1" aria-label="Filter Pins by status">
                    {TABS.map(([value, label]) => (
                        <button
                            key={value}
                            onClick={() => { setStatus(value); setPage(1); }}
                            aria-pressed={status === value}
                            className={cx('shrink-0 rounded-full border px-3.5 py-2 text-xs font-medium whitespace-nowrap transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600', status === value ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-500 hover:border-gray-300 hover:text-gray-900')}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <form className="flex min-w-0 flex-col gap-2 rounded-2xl border border-gray-200 bg-white p-3 sm:flex-row" role="search" onSubmit={(e) => { e.preventDefault(); setQuery(search); setPage(1); }}>
                    <div className="relative min-w-0 flex-1">
                        <svg className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5" /><path d="m13 13 4 4" strokeLinecap="round" /></svg>
                        <Input type="search" aria-label="Search Pins by title or URL" placeholder="Search by title or URL…" value={search} onChange={(e) => setSearch(e.target.value)} className="w-full !pl-10" />
                    </div>
                    <label className="min-w-0 sm:w-44 sm:shrink-0"><span className="sr-only">Filter by project</span><ProjectSelect value={project} onChange={(v) => { setProject(v); setPage(1); }} /></label>
                    <Button type="submit" variant="secondary" className="sm:shrink-0">Search</Button>
                </form>
            </div>

            {loading ? (
                <Loading />
            ) : error ? (
                <ErrorBox message={error} onRetry={reload} />
            ) : !data.data.length && !showInProgress.length ? (
                <Card>
                    <EmptyState title="No Pins here" description={status ? 'No Pins with this status.' : 'Generate your first Pin from a URL.'} action={<Link to="/create"><Button>Create Pin</Button></Link>} />
                </Card>
            ) : (
                <>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        {showInProgress.map((item) => <PendingPinCard key={`s${item.id}`} item={item} />)}
                        {data.data.map((pin) => <PinCard key={pin.id} pin={pin} onChange={replacePin} />)}
                    </div>
                    <Pagination meta={data.meta} onPage={setPage} />
                </>
            )}
        </>
    );
}
