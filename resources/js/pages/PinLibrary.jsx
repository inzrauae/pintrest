import { useState } from 'react';
import { Link } from 'react-router-dom';
import { download } from '../lib/api';
import { useApi } from '../lib/hooks';
import { Button, Card, EmptyState, ErrorBox, Input, Loading, PageHeader, Pagination, cx } from '../components/ui';
import { PinCard, ProjectSelect } from '../components/PinParts';

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
    const { data, loading, error, reload } = useApi('/pins', { status, search: query, page, project_id: project || undefined });

    return (
        <>
            <PageHeader
                title="Pin Library"
                description="Every Pin you've generated."
                actions={
                    <>
                        <Button variant="secondary" onClick={() => download('pins/export', status ? { status } : {})}>Export CSV</Button>
                        <Link to="/create"><Button>+ Create Pin</Button></Link>
                    </>
                }
            />

            <div className="flex flex-col md:flex-row gap-3 mb-5">
                <div className="flex gap-1 overflow-x-auto pb-1 -mx-1 px-1">
                    {TABS.map(([value, label]) => (
                        <button
                            key={value}
                            onClick={() => { setStatus(value); setPage(1); }}
                            className={cx('px-3 py-1.5 rounded-full text-sm whitespace-nowrap', status === value ? 'bg-gray-900 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50')}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <form className="flex gap-2 md:ml-auto" onSubmit={(e) => { e.preventDefault(); setQuery(search); setPage(1); }}>
                    <Input placeholder="Search title or URL…" value={search} onChange={(e) => setSearch(e.target.value)} className="w-full md:w-60" />
                    <div className="w-40"><ProjectSelect value={project} onChange={(v) => { setProject(v); setPage(1); }} /></div>
                </form>
            </div>

            {loading ? (
                <Loading />
            ) : error ? (
                <ErrorBox message={error} onRetry={reload} />
            ) : !data.data.length ? (
                <Card>
                    <EmptyState title="No Pins here" description={status ? 'No Pins with this status.' : 'Generate your first Pin from a URL.'} action={<Link to="/create"><Button>Create Pin</Button></Link>} />
                </Card>
            ) : (
                <>
                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6 gap-4">
                        {data.data.map((pin) => <PinCard key={pin.id} pin={pin} />)}
                    </div>
                    <Pagination meta={data.meta} onPage={setPage} />
                </>
            )}
        </>
    );
}
