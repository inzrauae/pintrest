import { useState } from 'react';

// Categorical slots 1-3 of the validated reference palette (fixed order, never cycled).
const SERIES = [
    { key: 'submitted', label: 'URLs submitted', color: '#2a78d6' },
    { key: 'generated', label: 'Pins generated', color: '#eb6834' },
    { key: 'published', label: 'Pins published', color: '#1baf7a' },
];

const H = 220;
const PAD = { top: 12, right: 8, bottom: 26, left: 32 };

function niceMax(v) {
    if (v <= 4) return 4;
    const mag = 10 ** Math.floor(Math.log10(v));
    return Math.ceil(v / mag / 2) * 2 * mag;
}

/** Grouped daily bars with a per-day hover tooltip, legend and table view. */
export default function DailyChart({ daily }) {
    const [hover, setHover] = useState(null);
    const [table, setTable] = useState(false);

    const max = niceMax(Math.max(1, ...daily.flatMap((d) => SERIES.map((s) => d[s.key]))));
    const W = Math.max(560, daily.length * 22);
    const plotW = W - PAD.left - PAD.right;
    const plotH = H - PAD.top - PAD.bottom;
    const slot = plotW / daily.length;
    const barW = Math.max(2, (slot - 4 - 2 * 2) / 3); // 2px surface gap between bars
    const y = (v) => PAD.top + plotH - (v / max) * plotH;
    const ticks = [0, max / 2, max];
    const labelEvery = Math.ceil(daily.length / 8);

    const bar = (x, v, color) => {
        if (!v) return null;
        const top = y(v);
        const h = PAD.top + plotH - top;
        const r = Math.min(4, barW / 2, h);
        // Rounded data-end, square at the baseline.
        return (
            <path
                d={`M${x},${top + h} V${top + r} Q${x},${top} ${x + r},${top} H${x + barW - r} Q${x + barW},${top} ${x + barW},${top + r} V${top + h} Z`}
                fill={color}
            />
        );
    };

    return (
        <div>
            <div className="flex flex-wrap items-center gap-4 mb-3 text-sm text-gray-600">
                {SERIES.map((s) => (
                    <span key={s.key} className="inline-flex items-center gap-1.5">
                        <span className="h-2.5 w-2.5 rounded-sm" style={{ background: s.color }} />
                        {s.label}
                    </span>
                ))}
                <button onClick={() => setTable(!table)} className="ml-auto text-xs text-brand-600 hover:underline">
                    {table ? 'Show chart' : 'Show table'}
                </button>
            </div>

            {table ? (
                <div className="overflow-x-auto max-h-80">
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs uppercase text-gray-500 sticky top-0 bg-white">
                            <tr>
                                <th className="py-1.5 pr-4">Date</th>
                                {SERIES.map((s) => <th key={s.key} className="py-1.5 pr-4 text-right">{s.label}</th>)}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 tabular-nums">
                            {daily.map((d) => (
                                <tr key={d.date}>
                                    <td className="py-1.5 pr-4">{d.date}</td>
                                    {SERIES.map((s) => <td key={s.key} className="py-1.5 pr-4 text-right">{d[s.key]}</td>)}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="relative overflow-x-auto">
                    <svg viewBox={`0 0 ${W} ${H}`} className="w-full min-w-[560px] h-auto" role="img" aria-label="Daily URLs submitted, Pins generated and Pins published">
                        {ticks.map((t) => (
                            <g key={t}>
                                <line x1={PAD.left} x2={W - PAD.right} y1={y(t)} y2={y(t)} stroke="#e5e7eb" strokeWidth="1" />
                                <text x={PAD.left - 6} y={y(t) + 4} textAnchor="end" fontSize="10" fill="#6b7280">{t}</text>
                            </g>
                        ))}
                        {daily.map((d, i) => {
                            const x0 = PAD.left + i * slot + 2;
                            return (
                                <g key={d.date}>
                                    {hover === i && <rect x={x0 - 2} y={PAD.top} width={slot} height={plotH} fill="#f3f4f6" />}
                                    {SERIES.map((s, j) => <g key={s.key}>{bar(x0 + j * (barW + 2), d[s.key], s.color)}</g>)}
                                    {i % labelEvery === 0 && (
                                        <text x={x0 + slot / 2 - 2} y={H - 8} textAnchor="middle" fontSize="10" fill="#6b7280">
                                            {d.date.slice(5)}
                                        </text>
                                    )}
                                    {/* Hit target spans the whole day column. */}
                                    <rect x={x0 - 2} y={PAD.top} width={slot} height={plotH} fill="transparent" onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} />
                                </g>
                            );
                        })}
                    </svg>
                    {hover !== null && (
                        <div
                            className="pointer-events-none absolute top-2 z-10 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs shadow-lg"
                            style={{ left: `min(calc(${((PAD.left + hover * slot) / W) * 100}% + 12px), calc(100% - 170px))` }}
                        >
                            <div className="font-medium text-gray-900 mb-1">{daily[hover].date}</div>
                            {SERIES.map((s) => (
                                <div key={s.key} className="flex items-center gap-2 text-gray-600">
                                    <span className="h-2 w-2 rounded-sm" style={{ background: s.color }} />
                                    {s.label}
                                    <span className="ml-auto pl-3 font-medium text-gray-900 tabular-nums">{daily[hover][s.key]}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
