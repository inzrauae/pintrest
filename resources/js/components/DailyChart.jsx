import { useState } from 'react';

const DEFAULT_SERIES = [
    { key: 'submitted', label: 'URLs submitted', color: '#7c8fa7' },
    { key: 'generated', label: 'Pins generated', color: '#dc3849' },
    { key: 'published', label: 'Pins published', color: '#688c73' },
];

const H = 220;
const PAD = { top: 12, right: 8, bottom: 26, left: 32 };

function niceMax(v) {
    if (v <= 4) return 4;
    const mag = 10 ** Math.floor(Math.log10(v));
    return Math.ceil(v / mag / 2) * 2 * mag;
}

/** Grouped daily bars with a per-day hover tooltip, legend and table view. */
export default function DailyChart({ daily = [], series = DEFAULT_SERIES }) {
    const SERIES = series;
    const [hover, setHover] = useState(null);
    const [table, setTable] = useState(false);

    const max = niceMax(Math.max(1, ...daily.flatMap((d) => SERIES.map((s) => d[s.key]))));
    const W = Math.max(560, daily.length * 22);
    const plotW = W - PAD.left - PAD.right;
    const plotH = H - PAD.top - PAD.bottom;
    const slot = plotW / Math.max(1, daily.length);
    const barW = Math.max(2, (slot - 4 - 2 * 2) / 3); // 2px surface gap between bars
    const y = (v) => PAD.top + plotH - (v / max) * plotH;
    const ticks = [0, max / 2, max];
    const labelEvery = Math.max(1, Math.ceil(daily.length / 8));

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
            <div className="mb-5 flex flex-wrap items-center gap-x-5 gap-y-3 text-xs text-gray-500">
                {SERIES.map((s) => (
                    <span key={s.key} className="inline-flex items-center gap-1.5">
                        <span className="h-2 w-2 rounded-full" style={{ background: s.color }} />
                        {s.label}
                    </span>
                ))}
                <button type="button" onClick={() => setTable(!table)} aria-pressed={table} className="ml-auto rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600 transition hover:border-brand-200 hover:text-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                    {table ? 'Show chart' : 'Show table'}
                </button>
            </div>

            {daily.length === 0 ? (
                <div className="flex min-h-48 flex-col items-center justify-center rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-5 text-center"><span className="text-sm font-medium text-gray-700">Your next chapter starts with a Pin</span><span className="mt-1 text-xs text-gray-400">Daily activity will appear here as you create and publish.</span></div>
            ) : table ? (
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
                                    <td className="py-2.5 pr-4 text-gray-600">{d.date}</td>
                                    {SERIES.map((s) => <td key={s.key} className="py-2.5 pr-4 text-right text-gray-700">{d[s.key]}</td>)}
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
                                <line x1={PAD.left} x2={W - PAD.right} y1={y(t)} y2={y(t)} stroke="#eeece9" strokeWidth="1" strokeDasharray={t ? '3 4' : undefined} />
                                <text x={PAD.left - 6} y={y(t) + 4} textAnchor="end" fontSize="10" fill="#6b7280">{t}</text>
                            </g>
                        ))}
                        {daily.map((d, i) => {
                            const x0 = PAD.left + i * slot + 2;
                            return (
                                <g key={d.date}>
                                    {hover === i && <rect x={x0 - 2} y={PAD.top} width={slot} height={plotH} fill="#f7f4f1" rx="4" />}
                                    {SERIES.map((s, j) => <g key={s.key}>{bar(x0 + j * (barW + 2), d[s.key], s.color)}</g>)}
                                    {i % labelEvery === 0 && (
                                        <text x={x0 + slot / 2 - 2} y={H - 8} textAnchor="middle" fontSize="10" fill="#6b7280">
                                            {d.date.slice(5)}
                                        </text>
                                    )}
                                    {/* Hit target spans the whole day column. */}
                                    <rect x={x0 - 2} y={PAD.top} width={slot} height={plotH} fill="transparent" onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}><title>{`${d.date}: ${SERIES.map((s) => `${s.label}: ${d[s.key]}`).join(', ')}`}</title></rect>
                                </g>
                            );
                        })}
                    </svg>
                    {hover !== null && daily[hover] && (
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
