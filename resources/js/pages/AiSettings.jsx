import { useEffect, useState } from 'react';
import api, { errorMessage } from '../lib/api';
import { useApi } from '../lib/hooks';
import { Alert, Button, Card, ErrorBox, Field, Input, Loading, PageHeader, Select, Textarea, useToast } from '../components/ui';

export default function AiSettings() {
    const toast = useToast();
    const meta = useApi('/settings/meta');
    const { data, loading, error } = useApi('/settings/ai');
    const [form, setForm] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (data) {
            const { text, image } = data.data;
            setForm({
                text: { provider: text.provider, model: text.model || '', temperature: text.temperature, tone: text.tone, extra_instructions: text.extra_instructions || '' },
                image: { provider: image.provider, model: image.model || '', quality: image.quality },
            });
        }
    }, [data]);

    if (loading || meta.loading || !form) return error ? <ErrorBox message={error} /> : <Loading />;
    const m = meta.data.data;
    const setText = (k, v) => setForm({ ...form, text: { ...form.text, [k]: v } });
    const setImage = (k, v) => setForm({ ...form, image: { ...form.image, [k]: v } });
    // A model name only makes sense for the provider it belongs to, so switching providers resets it to the default.
    const setTextProvider = (p) => setForm({ ...form, text: { ...form.text, provider: p, model: '' } });
    const setImageProvider = (p) => setForm({ ...form, image: { ...form.image, provider: p, model: '' } });
    const textDefault = m.default_models[form.text.provider]?.text;
    const imageDefault = m.default_models[form.image.provider]?.image;
    const missingKey = [form.text.provider, form.image.provider].find((p) => p in m.configured && !m.configured[p]);

    const save = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            await api.put('/settings/ai', form);
            toast('AI settings saved.');
        } catch (err) {
            toast(errorMessage(err), 'error');
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <PageHeader title="AI Settings" description="Choose how Pin copy and images are generated. API keys live in the server environment and are never sent to the browser." />

            {missingKey && (
                <div className="mb-5">
                    <Alert type="info">
                        <strong>{missingKey.toUpperCase()}_API_KEY</strong> is not set on the server, so the {missingKey} provider will fail. The <em>offline</em> text provider still works: it builds copy only from text found on the page.
                    </Alert>
                </div>
            )}

            <form onSubmit={save} className="grid lg:grid-cols-2 gap-6">
                <Card className="p-5 space-y-4">
                    <h2 className="font-semibold">Text generation</h2>
                    <Field label="Provider" hint="“openai” works with any OpenAI-compatible endpoint (OPENAI_BASE_URL). “gemini” uses Google Gemini (GEMINI_API_KEY). “fake” is the offline generator.">
                        <Select value={form.text.provider} onChange={(e) => setTextProvider(e.target.value)}>
                            {m.ai_providers.map((p) => <option key={p} value={p}>{p === 'fake' ? 'offline (no API)' : p}</option>)}
                        </Select>
                    </Field>
                    <Field label="Model" hint={textDefault ? `Default: ${textDefault}` : 'Not used by the offline generator.'}>
                        <Input value={form.text.model} onChange={(e) => setText('model', e.target.value)} placeholder={textDefault} disabled={!textDefault} />
                    </Field>
                    <Field label={`Creativity (temperature): ${Number(form.text.temperature).toFixed(2)}`}>
                        <input type="range" min="0" max="1.5" step="0.05" value={form.text.temperature} onChange={(e) => setText('temperature', Number(e.target.value))} className="w-full accent-brand-600" />
                    </Field>
                    <Field label="Tone">
                        <Select value={form.text.tone} onChange={(e) => setText('tone', e.target.value)}>
                            {['helpful', 'friendly', 'professional', 'inspirational', 'playful', 'luxury', 'minimal'].map((t) => <option key={t} value={t}>{t}</option>)}
                        </Select>
                    </Field>
                    <Field label="Extra instructions" hint="e.g. “Mention free shipping only if the page says so.” Max 1000 characters.">
                        <Textarea rows={4} value={form.text.extra_instructions} onChange={(e) => setText('extra_instructions', e.target.value)} maxLength={1000} />
                    </Field>
                </Card>

                <Card className="p-5 space-y-4 h-fit">
                    <h2 className="font-semibold">Image generation</h2>
                    <Field label="Provider">
                        <Select value={form.image.provider} onChange={(e) => setImageProvider(e.target.value)}>
                            {m.image_providers.map((p) => <option key={p} value={p}>{p === 'none' ? 'Disabled' : p}</option>)}
                        </Select>
                    </Field>
                    <Field label="Model" hint={imageDefault ? `Default: ${imageDefault}. Images are generated at 2:3.` : 'Images are generated at 2:3.'}>
                        <Input value={form.image.model} onChange={(e) => setImage('model', e.target.value)} placeholder={imageDefault} disabled={form.image.provider === 'none'} />
                    </Field>
                    <Field label="Quality" hint={form.image.provider === 'gemini' ? 'Gemini does not take a quality setting.' : undefined}>
                        <Select value={form.image.quality} onChange={(e) => setImage('quality', e.target.value)} disabled={form.image.provider !== 'openai'}>
                            {['low', 'medium', 'high', 'auto'].map((q) => <option key={q} value={q}>{q}</option>)}
                        </Select>
                    </Field>
                    {!m.overlay_font_available && (
                        <Alert>No TrueType font found for template text overlays. Set PINBOT_OVERLAY_FONT to a .ttf path.</Alert>
                    )}
                </Card>

                <div className="lg:col-span-2">
                    <Button type="submit" loading={saving}>Save settings</Button>
                </div>
            </form>
        </>
    );
}
