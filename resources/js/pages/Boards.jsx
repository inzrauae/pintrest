import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { formatDate, useApi } from '../lib/hooks';
import { Badge, Button, Card, EmptyState, ErrorBox, Field, Input, Loading, Modal, PageHeader, Select, Textarea, useToast } from '../components/ui';

export default function Boards() {
    const toast = useToast();
    const accounts = useApi('/pinterest/accounts');
    const boards = useApi('/pinterest/boards');
    const [refreshing, setRefreshing] = useState(false);
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({ account_id: '', name: '', description: '', privacy: 'PUBLIC' });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const grouped = useMemo(() => {
        const map = {};
        (boards.data?.data || []).forEach((b) => (map[b.pinterest_account_id] ||= []).push(b));
        return map;
    }, [boards.data]);

    const refresh = async () => {
        setRefreshing(true);
        try {
            const r = await api.get('/pinterest/boards', { params: { refresh: 1 } });
            boards.setData(r.data);
            r.data.errors?.length ? toast(r.data.errors.join(' '), 'error') : toast('Boards synced from Pinterest.');
        } catch (e) {
            toast(errorMessage(e), 'error');
        } finally {
            setRefreshing(false);
        }
    };

    const setDefault = async (accountId, boardId) => {
        try {
            await api.post('/pinterest/default-board', { account_id: accountId, board_id: boardId });
            toast('Default board saved. New Pins will use it.');
            accounts.reload(true);
        } catch (e) {
            toast(errorMessage(e), 'error');
        }
    };

    const create = async () => {
        setSaving(true);
        setErrors({});
        try {
            await api.post('/pinterest/boards', form);
            toast('Board created.');
            setCreating(false);
            boards.reload(true);
        } catch (e) {
            setErrors(fieldErrors(e));
            toast(errorMessage(e), 'error');
        } finally {
            setSaving(false);
        }
    };

    if (accounts.loading || boards.loading) return <Loading />;
    if (accounts.error || boards.error) return <ErrorBox message={accounts.error || boards.error} />;

    const accountList = accounts.data.data;

    return (
        <>
            <PageHeader
                title="Boards"
                description="Boards cached from your connected Pinterest accounts."
                actions={
                    accountList.length > 0 && (
                        <>
                            <Button variant="secondary" onClick={() => { setForm({ ...form, account_id: accountList[0].id }); setCreating(true); }}>New board</Button>
                            <Button loading={refreshing} onClick={refresh}>Sync from Pinterest</Button>
                        </>
                    )
                }
            />

            {accountList.length === 0 ? (
                <Card>
                    <EmptyState title="No Pinterest account" description="Connect an account to see its boards." action={<Link to="/accounts"><Button>Connect account</Button></Link>} icon="🧷" />
                </Card>
            ) : (
                <div className="space-y-6">
                    {accountList.map((a) => (
                        <Card key={a.id}>
                            <div className="px-5 py-3 border-b border-gray-100 flex items-center gap-2">
                                <span className="font-semibold">@{a.username}</span>
                                {a.environment === 'sandbox' && <Badge status="sandbox">sandbox</Badge>}
                                <span className="text-xs text-gray-500 ml-auto">{(grouped[a.id] || []).length} boards</span>
                            </div>
                            {(grouped[a.id] || []).length === 0 ? (
                                <EmptyState title="No boards cached" description="Click “Sync from Pinterest” or create a board." icon="📋" />
                            ) : (
                                <ul className="grid sm:grid-cols-2 lg:grid-cols-3 gap-px bg-gray-100">
                                    {grouped[a.id].map((b) => {
                                        const isDefault = accounts.data.default_account_id === a.id && accounts.data.default_board_id === b.board_id;
                                        return (
                                            <li key={b.id} className="bg-white p-4 flex gap-3">
                                                {b.image_url ? (
                                                    <img src={b.image_url} alt="" referrerPolicy="no-referrer" className="h-14 w-14 rounded-lg object-cover bg-gray-100 shrink-0" />
                                                ) : (
                                                    <div className="h-14 w-14 rounded-lg bg-gray-100 shrink-0" />
                                                )}
                                                <div className="min-w-0 flex-1">
                                                    <div className="font-medium truncate">{b.name}</div>
                                                    <div className="text-xs text-gray-500">
                                                        {b.pin_count} pins · {b.privacy?.toLowerCase()} · synced {formatDate(b.synced_at, false)}
                                                    </div>
                                                    {isDefault ? (
                                                        <Badge status="approved" className="mt-1">default</Badge>
                                                    ) : (
                                                        <button className="text-xs text-brand-600 mt-1" onClick={() => setDefault(a.id, b.board_id)}>Set as default</button>
                                                    )}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </Card>
                    ))}
                </div>
            )}

            <Modal
                open={creating}
                onClose={() => setCreating(false)}
                title="Create board"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setCreating(false)}>Cancel</Button>
                        <Button loading={saving} onClick={create}>Create</Button>
                    </>
                }
            >
                <div className="space-y-4">
                    <Field label="Account">
                        <Select value={form.account_id} onChange={(e) => setForm({ ...form, account_id: Number(e.target.value) })}>
                            {accountList.map((a) => <option key={a.id} value={a.id}>@{a.username}</option>)}
                        </Select>
                    </Field>
                    <Field label="Name" error={errors.name}>
                        <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} maxLength={180} />
                    </Field>
                    <Field label="Description">
                        <Textarea rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} maxLength={500} />
                    </Field>
                    <Field label="Privacy">
                        <Select value={form.privacy} onChange={(e) => setForm({ ...form, privacy: e.target.value })}>
                            <option value="PUBLIC">Public</option>
                            <option value="SECRET">Secret</option>
                        </Select>
                    </Field>
                </div>
            </Modal>
        </>
    );
}
