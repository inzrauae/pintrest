import { useState } from 'react';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { useApi } from '../lib/hooks';
import { Badge, Button, Card, Checkbox, EmptyState, ErrorBox, Field, Input, Loading, Modal, PageHeader, Select, useToast } from '../components/ui';

const BLANK = {
    name: '',
    text_overlay: false,
    overlay_position: 'bottom',
    background_color: '#000000',
    background_opacity: 60,
    text_color: '#ffffff',
    font_size: 56,
    image_style: '',
    is_default: false,
};

function hexToRgba(hex, opacity) {
    const n = parseInt(hex.slice(1), 16);
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${opacity / 100})`;
}

function TemplatePreview({ t }) {
    return (
        <div className="aspect-[2/3] rounded-xl bg-gradient-to-br from-rose-200 via-amber-100 to-sky-200 relative overflow-hidden">
            {t.text_overlay && (
                <div
                    className="absolute inset-x-0 h-[24%] grid place-items-center px-4 text-center font-bold"
                    style={{
                        [t.overlay_position === 'top' ? 'top' : 'bottom']: 0,
                        background: hexToRgba(t.background_color, t.background_opacity),
                        color: t.text_color,
                        fontSize: `${Math.max(10, t.font_size / 4)}px`,
                    }}
                >
                    Your Pin title appears here
                </div>
            )}
        </div>
    );
}

export default function Templates() {
    const toast = useToast();
    const { data, loading, error, reload } = useApi('/templates');
    const [editing, setEditing] = useState(null);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const save = async () => {
        setSaving(true);
        setErrors({});
        try {
            const payload = { ...editing, image_style: editing.image_style || null };
            editing.id ? await api.put(`/templates/${editing.id}`, payload) : await api.post('/templates', payload);
            toast('Template saved.');
            setEditing(null);
            reload(true);
        } catch (e) {
            setErrors(fieldErrors(e));
            toast(errorMessage(e), 'error');
        } finally {
            setSaving(false);
        }
    };

    const remove = async (t) => {
        if (!window.confirm(`Delete template “${t.name}”?`)) return;
        try {
            await api.delete(`/templates/${t.id}`);
            toast('Template deleted.');
            reload(true);
        } catch (e) {
            toast(errorMessage(e), 'error');
        }
    };

    if (loading) return <Loading />;
    if (error) return <ErrorBox message={error} onRetry={reload} />;

    const set = (k) => (e) => setEditing({ ...editing, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.type === 'number' || e.target.type === 'range' ? Number(e.target.value) : e.target.value });

    return (
        <>
            <PageHeader
                title="Templates"
                description="Optional text overlays and AI image styles. Text is only added to images when a template asks for it."
                actions={<Button onClick={() => setEditing({ ...BLANK })}>+ New template</Button>}
            />

            {data.data.length === 0 ? (
                <Card>
                    <EmptyState title="No templates" description="Create one to add a title banner to Pin images or steer the AI image style." icon="🎨" />
                </Card>
            ) : (
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                    {data.data.map((t) => (
                        <Card key={t.id} className="p-3">
                            <TemplatePreview t={t} />
                            <div className="mt-2 flex items-center justify-between gap-2">
                                <span className="font-medium text-sm truncate">{t.name}</span>
                                {t.is_default && <Badge status="approved">default</Badge>}
                            </div>
                            <div className="flex gap-1 mt-2">
                                <Button size="sm" variant="secondary" className="flex-1" onClick={() => setEditing({ ...BLANK, ...t, image_style: t.image_style || '' })}>Edit</Button>
                                <Button size="sm" variant="ghost" className="text-red-600" onClick={() => remove(t)}>Delete</Button>
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            <Modal
                open={!!editing}
                onClose={() => setEditing(null)}
                title={editing?.id ? 'Edit template' : 'New template'}
                wide
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
                        <Button loading={saving} onClick={save}>Save</Button>
                    </>
                }
            >
                {editing && (
                    <div className="grid sm:grid-cols-[1fr_180px] gap-5">
                        <div className="space-y-4">
                            <Field label="Name" error={errors.name}>
                                <Input value={editing.name} onChange={set('name')} maxLength={100} />
                            </Field>
                            <Checkbox checked={editing.text_overlay} onChange={set('text_overlay')} label="Add the Pin title as a text banner on the image" />
                            {editing.text_overlay && (
                                <div className="grid grid-cols-2 gap-3">
                                    <Field label="Position">
                                        <Select value={editing.overlay_position} onChange={set('overlay_position')}>
                                            <option value="bottom">Bottom</option>
                                            <option value="top">Top</option>
                                        </Select>
                                    </Field>
                                    <Field label={`Font size: ${editing.font_size}`}>
                                        <input type="range" min="20" max="120" value={editing.font_size} onChange={set('font_size')} className="w-full accent-brand-600" />
                                    </Field>
                                    <Field label="Banner color">
                                        <Input type="color" value={editing.background_color} onChange={set('background_color')} className="h-10 p-1" />
                                    </Field>
                                    <Field label="Text color">
                                        <Input type="color" value={editing.text_color} onChange={set('text_color')} className="h-10 p-1" />
                                    </Field>
                                    <Field label={`Banner opacity: ${editing.background_opacity}%`} className="col-span-2">
                                        <input type="range" min="0" max="100" value={editing.background_opacity} onChange={set('background_opacity')} className="w-full accent-brand-600" />
                                    </Field>
                                </div>
                            )}
                            <Field label="AI image style" hint="Appended to AI image prompts, e.g. “soft natural light, pastel palette, flat-lay”.">
                                <Input value={editing.image_style} onChange={set('image_style')} maxLength={500} />
                            </Field>
                            <Checkbox checked={editing.is_default} onChange={set('is_default')} label="Use for new Pins by default" />
                        </div>
                        <TemplatePreview t={editing} />
                    </div>
                )}
            </Modal>
        </>
    );
}
