import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { formatDate, useApi } from '../lib/hooks';
import { Alert, Badge, Button, Card, EmptyState, ErrorBox, Loading, PageHeader, useToast } from '../components/ui';

export default function Accounts() {
    const toast = useToast();
    const [params, setParams] = useSearchParams();
    const { data, loading, error, reload } = useApi('/pinterest/accounts');
    const [busy, setBusy] = useState('');

    useEffect(() => {
        const status = params.get('pinterest');
        if (status === 'connected') toast('Pinterest account connected.');
        if (status === 'error') toast(params.get('reason') || 'Pinterest connection failed.', 'error');
        if (status) setParams({}, { replace: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const connect = async (environment) => {
        setBusy(environment);
        try {
            const r = await api.post('/pinterest/connect', { environment });
            window.location.href = r.data.authorize_url;
        } catch (e) {
            toast(errorMessage(e), 'error');
            setBusy('');
        }
    };

    const act = async (key, fn, msg) => {
        setBusy(key);
        try {
            await fn();
            toast(msg);
            reload(true);
        } catch (e) {
            toast(errorMessage(e), 'error');
        } finally {
            setBusy('');
        }
    };

    if (loading) return <Loading />;
    if (error) return <ErrorBox message={error} onRetry={reload} />;

    return (
        <>
            <PageHeader
                title="Pinterest Accounts"
                description="Connect accounts through Pinterest's official OAuth. We never ask for your Pinterest password."
                actions={
                    <>
                        <Button variant="secondary" loading={busy === 'sandbox'} disabled={!data.configured} onClick={() => connect('sandbox')}>Connect sandbox</Button>
                        <Button loading={busy === 'production'} disabled={!data.configured} onClick={() => connect('production')}>Connect Pinterest</Button>
                    </>
                }
            />

            {!data.configured && (
                <div className="mb-5">
                    <Alert type="warning">
                        Pinterest is not configured on the server. Create an app at developers.pinterest.com, then set <code>PINTEREST_CLIENT_ID</code>,{' '}
                        <code>PINTEREST_CLIENT_SECRET</code> and <code>PINTEREST_REDIRECT_URI</code> in <code>.env</code>.
                    </Alert>
                </div>
            )}

            <div className="mb-5">
                <Alert type="info">
                    Apps with <strong>Trial</strong> access can only create Pins through Pinterest's <strong>sandbox</strong> API (Pins are visible only to you). Production publishing requires
                    <strong> Standard</strong> access approved by Pinterest. Scheduling is handled by PinBot, since the Pinterest API has no scheduled-publish option.
                </Alert>
            </div>

            <Card>
                {data.data.length === 0 ? (
                    <EmptyState title="No accounts connected" description="Connect a Pinterest account to load boards and publish Pins." icon="🔗" />
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {data.data.map((a) => (
                            <li key={a.id} className="px-5 py-4 flex flex-col sm:flex-row sm:items-center gap-3">
                                <div className="flex items-center gap-3 flex-1 min-w-0">
                                    {a.profile_image ? (
                                        <img src={a.profile_image} alt="" className="h-11 w-11 rounded-full bg-gray-100" referrerPolicy="no-referrer" />
                                    ) : (
                                        <div className="h-11 w-11 rounded-full bg-brand-100 text-brand-700 grid place-items-center font-semibold">{(a.username || '?')[0].toUpperCase()}</div>
                                    )}
                                    <div className="min-w-0">
                                        <div className="font-medium flex flex-wrap items-center gap-2">
                                            @{a.username}
                                            <Badge status={a.status} />
                                            {a.environment === 'sandbox' && <Badge status="sandbox">sandbox</Badge>}
                                            {data.default_account_id === a.id && <Badge status="approved">default</Badge>}
                                        </div>
                                        <div className="text-xs text-gray-500">
                                            {a.account_type} · {a.boards_count} boards · connected {formatDate(a.connected_at, false)}
                                            {a.token_expires_at && ` · token expires ${formatDate(a.token_expires_at, false)}`}
                                        </div>
                                        {a.scopes && <div className="text-[11px] text-gray-400 truncate">Scopes: {a.scopes}</div>}
                                    </div>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {a.status !== 'active' ? (
                                        <Button size="sm" onClick={() => connect(a.environment)}>Reconnect</Button>
                                    ) : (
                                        <Button size="sm" variant="secondary" loading={busy === `r${a.id}`} onClick={() => act(`r${a.id}`, () => api.post(`/pinterest/accounts/${a.id}/refresh`), 'Token refreshed.')}>Refresh token</Button>
                                    )}
                                    <Button size="sm" variant="danger" loading={busy === `d${a.id}`} onClick={() => window.confirm(`Disconnect @${a.username}? Stored tokens will be deleted.`) && act(`d${a.id}`, () => api.delete(`/pinterest/accounts/${a.id}`), 'Account disconnected.')}>
                                        Disconnect
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </>
    );
}
