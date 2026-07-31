import { AttributionControl, Map, Marker, NavigationControl } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { Crosshair, MapPin, Navigation, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Props = {
    latitude: string;
    longitude: string;
    onChange: (latitude: string, longitude: string) => void;
    error?: string;
};

const DEFAULT_CENTER: [number, number] = [-73.6266, 4.1420];

export default function MapLocationPicker({ latitude, longitude, onChange, error }: Props) {
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<Map | null>(null);
    const marker = useRef<Marker | null>(null);
    const changeHandler = useRef(onChange);
    const [locating, setLocating] = useState(false);
    const hasPoint = latitude !== '' && longitude !== '';

    useEffect(() => {
        changeHandler.current = onChange;
    }, [onChange]);

    useEffect(() => {
        if (!container.current || map.current) return;
        const initialCenter: [number, number] = hasPoint
            ? [Number(longitude), Number(latitude)]
            : DEFAULT_CENTER;

        const mapInstance = new Map({
            container: container.current,
            center: initialCenter,
            zoom: hasPoint ? 16 : 12,
            attributionControl: false,
            style: {
                version: 8,
                sources: {
                    osm: {
                        type: 'raster',
                        tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                        tileSize: 256,
                        attribution: '© OpenStreetMap contributors',
                    },
                },
                layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
            },
        });
        map.current = mapInstance;
        mapInstance.addControl(new NavigationControl({ showCompass: false }), 'top-right');
        mapInstance.addControl(new AttributionControl({ compact: true }), 'bottom-right');
        mapInstance.on('click', (event) => setPoint(event.lngLat.lng, event.lngLat.lat));
        window.setTimeout(() => mapInstance.resize(), 100);

        if (hasPoint) setPoint(Number(longitude), Number(latitude), false);

        return () => {
            marker.current?.remove();
            map.current?.remove();
            marker.current = null;
            map.current = null;
        };
        // The map lifecycle is intentionally independent from controlled coordinate updates.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!map.current || !hasPoint) return;
        const lng = Number(longitude);
        const lat = Number(latitude);
        if (!marker.current) {
            setPoint(lng, lat, false);
            return;
        }
        marker.current.setLngLat([lng, lat]);
    }, [latitude, longitude, hasPoint]);

    const setPoint = (lng: number, lat: number, move = true) => {
        if (!map.current) return;
        if (!marker.current) {
            const markerInstance = new Marker({ color: '#e8754f', draggable: true })
                .setLngLat([lng, lat])
                .addTo(map.current);
            marker.current = markerInstance;
            markerInstance.on('dragend', () => {
                const point = markerInstance.getLngLat();
                if (point) changeHandler.current(point.lat.toFixed(7), point.lng.toFixed(7));
            });
        } else {
            marker.current.setLngLat([lng, lat]);
        }
        changeHandler.current(lat.toFixed(7), lng.toFixed(7));
        if (move) map.current.easeTo({ center: [lng, lat], zoom: Math.max(map.current.getZoom(), 15) });
    };

    const useCurrentLocation = () => {
        if (!navigator.geolocation) return;
        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                setPoint(position.coords.longitude, position.coords.latitude);
                setLocating(false);
            },
            () => setLocating(false),
            { enableHighAccuracy: true, timeout: 10000 },
        );
    };

    const clearPoint = () => {
        marker.current?.remove();
        marker.current = null;
        onChange('', '');
    };

    return (
        <div>
            <div className={`relative overflow-hidden rounded-2xl border bg-slate-100 ${error ? 'border-red-300' : 'border-slate-200'}`}>
                <div ref={container} className="h-80 w-full" />
                {!hasPoint && (
                    <div className="pointer-events-none absolute inset-x-12 top-1/2 -translate-y-1/2 rounded-2xl bg-white/95 p-4 text-center shadow-xl backdrop-blur">
                        <MapPin size={24} className="mx-auto text-[#e8754f]" />
                        <div className="mt-2 text-sm font-black text-slate-800">Marca el lugar de la reunión</div>
                        <p className="mt-1 text-xs leading-5 text-slate-500">Acércate al sector y haz clic sobre el punto exacto.</p>
                    </div>
                )}
                <div className="absolute left-3 top-3 flex flex-col gap-2">
                    <button type="button" onClick={useCurrentLocation} disabled={locating} className="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-xs font-black text-[#0d4d4b] shadow-lg disabled:opacity-60">
                        <Navigation size={14} /> {locating ? 'Ubicando…' : 'Mi ubicación'}
                    </button>
                    {hasPoint && <button type="button" onClick={clearPoint} className="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-xs font-black text-red-600 shadow-lg"><Trash2 size={14} /> Quitar punto</button>}
                </div>
                {hasPoint && <div className="absolute bottom-3 left-3 flex items-center gap-2 rounded-xl bg-[#102f35]/95 px-3 py-2 text-[11px] font-bold text-white shadow-lg"><Crosshair size={14} className="text-[#a8d7c8]" /> Punto guardado · puedes arrastrarlo</div>}
            </div>
            {error && <p className="mt-1 text-xs font-semibold text-red-600">{error}</p>}
        </div>
    );
}
