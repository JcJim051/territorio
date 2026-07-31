import { Head, Link } from '@inertiajs/react';
import cytoscape, { Core } from 'cytoscape';
import { Filter, GitBranch, Search, UsersRound, X, ZoomIn } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';

type Node = { id: string; publicId: string; name: string; status: string; children: number };
type Edge = { source: string; target: string };

export default function Graph({ nodes, edges, truncated }: { nodes: Node[]; edges: Edge[]; truncated: boolean }) {
    const graphRef = useRef<HTMLDivElement>(null);
    const graph = useRef<Core | null>(null);
    const [selected, setSelected] = useState<Node | null>(nodes[0] ?? null);
    const [query, setQuery] = useState('');
    const [searchMessage, setSearchMessage] = useState('');

    useEffect(() => {
        if (!graphRef.current) return;
        graph.current = cytoscape({
            container: graphRef.current,
            elements: [
                ...nodes.map((node) => ({ data: { ...node, label: node.name.split(' ').slice(0, 2).join(' ') } })),
                ...edges.map((edge, index) => ({ data: { id: `e-${index}`, ...edge } })),
            ],
            layout: { name: 'breadthfirst', directed: true, padding: 45, spacingFactor: 1.25 },
            style: [
                {
                    selector: 'node',
                    style: {
                        'background-color': '#d9f0e8',
                        'border-color': '#0d4d4b',
                        'border-width': 2,
                        color: '#102a33',
                        label: 'data(label)',
                        'font-size': 10,
                        'font-weight': 700,
                        'text-valign': 'bottom',
                        'text-margin-y': 8,
                        width: 42,
                        height: 42,
                    },
                },
                {
                    selector: 'node[children > 2]',
                    style: { 'background-color': '#0d4d4b', 'border-color': '#0d4d4b', color: '#102a33', width: 54, height: 54 },
                },
                {
                    selector: 'node:selected',
                    style: { 'border-color': '#e8754f', 'border-width': 5, 'background-color': '#fff3ed' },
                },
                {
                    selector: 'edge',
                    style: {
                        width: 1.5,
                        'line-color': '#b7c5c3',
                        'target-arrow-color': '#b7c5c3',
                        'target-arrow-shape': 'triangle',
                        'curve-style': 'bezier',
                    },
                },
            ],
        });
        graph.current.on('tap', 'node', (event) => {
            const found = nodes.find((node) => node.id === event.target.id());
            if (found) setSelected(found);
        });
        return () => graph.current?.destroy();
    }, [nodes, edges]);

    const normalizedQuery = normalizeSearch(query);
    const matches = normalizedQuery
        ? nodes.filter((node) => normalizeSearch(node.name).includes(normalizedQuery)).slice(0, 6)
        : [];

    const focusNode = (match: Node) => {
        if (!graph.current) return;
        const element = graph.current.getElementById(match.id);
        graph.current.$(':selected').unselect();
        element.select();
        graph.current.animate({ center: { eles: element }, zoom: 1.5 }, { duration: 350 });
        setSelected(match);
        setQuery(match.name);
        setSearchMessage('');
    };

    const search = () => {
        if (!normalizedQuery) {
            setSearchMessage('Escribe el nombre de una persona.');
            return;
        }
        const match = nodes.find((node) => normalizeSearch(node.name).includes(normalizedQuery));
        if (!match) {
            setSearchMessage('No encontramos esa persona en la red visible.');
            return;
        }
        focusNode(match);
    };

    return (
        <AppLayout title="Red territorial" eyebrow="Estructura y liderazgo">
            <Head title="Red territorial" />
            <div className="mb-5 flex flex-col justify-between gap-3 md:flex-row md:items-center">
                <div>
                    <p className="text-sm text-slate-500">Explora relaciones, líderes y capacidad de crecimiento por nodo.</p>
                    {truncated && <p className="mt-1 text-xs font-semibold text-amber-700">Vista optimizada: se muestran los 500 nodos más relevantes.</p>}
                </div>
                <div className="flex gap-2">
                    <form onSubmit={(event) => { event.preventDefault(); search(); }} className="relative">
                        <div className={`flex overflow-hidden rounded-xl border bg-white ${searchMessage ? 'border-red-300' : 'border-slate-200'}`}>
                            <input
                                className="w-64 px-3 py-2 text-sm outline-none"
                                placeholder="Buscar persona…"
                                value={query}
                                onChange={(event) => { setQuery(event.target.value); setSearchMessage(''); }}
                            />
                            {query && <button type="button" onClick={() => { setQuery(''); setSearchMessage(''); }} className="px-2 text-slate-300 hover:text-slate-600" aria-label="Limpiar búsqueda"><X size={15} /></button>}
                            <button type="submit" className="px-3 text-slate-500 hover:bg-slate-50" aria-label="Buscar"><Search size={17} /></button>
                        </div>
                        {normalizedQuery && query !== selected?.name && (
                            <div className="absolute right-0 top-full z-30 mt-2 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                                {matches.map((match) => (
                                    <button key={match.id} type="button" onClick={() => focusNode(match)} className="flex w-full items-center justify-between px-3 py-2.5 text-left hover:bg-slate-50">
                                        <span className="text-xs font-bold text-slate-700">{match.name}</span>
                                        <span className="text-[10px] font-semibold text-slate-400">{match.children} referidos</span>
                                    </button>
                                ))}
                                {matches.length === 0 && <p className="px-3 py-3 text-xs text-slate-400">No hay coincidencias en la red visible.</p>}
                            </div>
                        )}
                        {searchMessage && <p className="absolute right-0 top-full z-20 mt-1 text-xs font-semibold text-red-600">{searchMessage}</p>}
                    </form>
                    <button className="secondary-button"><Filter size={16} /> Filtros</button>
                </div>
            </div>

            <div className="grid min-h-[660px] gap-5 xl:grid-cols-[1fr_300px]">
                <section className="panel relative overflow-hidden">
                    <div className="absolute left-4 top-4 z-10 flex items-center gap-2 rounded-lg bg-white/90 px-3 py-2 text-xs font-bold text-slate-500 shadow-sm backdrop-blur">
                        <GitBranch size={15} className="text-[#0d4d4b]" /> {nodes.length} nodos · {edges.length} conexiones
                    </div>
                    <div ref={graphRef} className="h-[660px] w-full bg-[radial-gradient(circle_at_center,#ffffff_0,#f7f8f4_70%)]" />
                    <div className="absolute bottom-4 left-4 flex items-center gap-2 rounded-lg bg-white/90 px-3 py-2 text-[11px] text-slate-400 shadow-sm">
                        <ZoomIn size={14} /> Usa rueda o gesto para ampliar
                    </div>
                </section>

                <aside className="panel h-fit overflow-hidden">
                    {selected ? (
                        <>
                            <div className="bg-[#102f35] p-5 text-white">
                                <div className="grid size-14 place-items-center rounded-2xl bg-[#d9f0e8] text-xl font-black text-[#0d4d4b]">
                                    {selected.name.split(' ').map((part) => part[0]).slice(0, 2).join('')}
                                </div>
                                <h2 className="mt-4 text-lg font-black">{selected.name}</h2>
                                <span className="mt-2 inline-flex rounded-full bg-white/10 px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-[#d9f0e8]">
                                    {selected.status}
                                </span>
                            </div>
                            <div className="p-5">
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="rounded-xl bg-[#f4f1e8] p-3">
                                        <UsersRound size={17} className="text-[#e8754f]" />
                                        <div className="mt-2 text-xl font-black">{selected.children}</div>
                                        <div className="text-[10px] font-bold uppercase text-slate-400">Referidos directos</div>
                                    </div>
                                    <div className="rounded-xl bg-[#d9f0e8]/70 p-3">
                                        <GitBranch size={17} className="text-[#0d4d4b]" />
                                        <div className="mt-2 text-xl font-black">Activo</div>
                                        <div className="text-[10px] font-bold uppercase text-slate-400">Estado del nodo</div>
                                    </div>
                                </div>
                                <Link href={`/people/${selected.publicId}`} className="primary-button mt-5 w-full justify-center">Abrir ficha 360°</Link>
                            </div>
                        </>
                    ) : <div className="p-8 text-center text-sm text-slate-400">Selecciona un nodo.</div>}
                </aside>
            </div>
        </AppLayout>
    );
}

function normalizeSearch(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase()
        .trim();
}
