import { useEffect, useRef, useState } from 'react';
import { Button, Modal } from './ui';

const RATIO = 2 / 3; // width / height

/**
 * Lets the user position a 2:3 crop box over the original image.
 * Produces {x, y, width, height} as fractions of the original image.
 */
export default function ImageCropper({ image, open, onClose, onSave, saving }) {
    const [natural, setNatural] = useState(null);
    const [zoom, setZoom] = useState(1); // 1 = largest 2:3 box that fits
    const [cx, setCx] = useState(0.5); // centre, fraction of width
    const [cy, setCy] = useState(0.5);
    const imgRef = useRef(null);

    useEffect(() => {
        if (!open) return;
        setNatural(null);
        const c = image?.crop;
        if (c && c.width) {
            setCx(c.x + c.width / 2);
            setCy(c.y + c.height / 2);
        } else {
            setCx(0.5);
            setCy(0.5);
        }
        setZoom(1);
    }, [open, image]);

    if (!open || !image) return null;
    const src = image.url.replace('/file?', '/file?original=1&');

    // Crop box size in pixels of the original.
    let box = null;
    if (natural) {
        const { w, h } = natural;
        const maxW = Math.min(w, h * RATIO);
        const bw = maxW * zoom;
        const bh = bw / RATIO;
        const x = Math.min(Math.max(cx * w - bw / 2, 0), w - bw);
        const y = Math.min(Math.max(cy * h - bh / 2, 0), h - bh);
        box = { x: x / w, y: y / h, width: bw / w, height: bh / h };
    }

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Crop image (2:3)"
            wide
            footer={
                <>
                    <Button variant="ghost" onClick={() => onSave(null)} disabled={saving}>Reset to auto-crop</Button>
                    <Button variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button onClick={() => onSave(box)} loading={saving} disabled={!box}>Apply crop</Button>
                </>
            }
        >
            <div className="grid md:grid-cols-[1fr_220px] gap-5">
                <div className="relative bg-gray-900 rounded-lg overflow-hidden select-none">
                    <img
                        ref={imgRef}
                        src={src}
                        alt=""
                        className="w-full h-auto max-h-[60vh] object-contain mx-auto block"
                        onLoad={(e) => setNatural({ w: e.target.naturalWidth, h: e.target.naturalHeight })}
                    />
                    {box && (
                        <div
                            className="absolute border-2 border-white shadow-[0_0_0_9999px_rgba(0,0,0,0.55)] pointer-events-none"
                            style={{
                                left: `${box.x * 100}%`,
                                top: `${box.y * 100}%`,
                                width: `${box.width * 100}%`,
                                height: `${box.height * 100}%`,
                            }}
                        />
                    )}
                </div>
                <div className="space-y-4 text-sm">
                    <label className="block">
                        <span className="text-gray-700 font-medium">Zoom</span>
                        <input type="range" min="0.3" max="1" step="0.01" value={zoom} onChange={(e) => setZoom(Number(e.target.value))} className="w-full accent-brand-600" />
                    </label>
                    <label className="block">
                        <span className="text-gray-700 font-medium">Horizontal position</span>
                        <input type="range" min="0" max="1" step="0.005" value={cx} onChange={(e) => setCx(Number(e.target.value))} className="w-full accent-brand-600" />
                    </label>
                    <label className="block">
                        <span className="text-gray-700 font-medium">Vertical position</span>
                        <input type="range" min="0" max="1" step="0.005" value={cy} onChange={(e) => setCy(Number(e.target.value))} className="w-full accent-brand-600" />
                    </label>
                    {natural && (
                        <p className="text-xs text-gray-500">
                            Original: {natural.w}×{natural.h}px. Output is up to 1000×1500px and is never upscaled beyond the source quality.
                        </p>
                    )}
                </div>
            </div>
        </Modal>
    );
}
