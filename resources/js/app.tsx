import '../css/app.css';
import './bootstrap';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    title: (title) => title ? `${title} · Territorio` : 'Territorio',
    resolve: async (name) => {
        const pages = import.meta.glob('./Pages/**/*.tsx');
        const page = pages[`./Pages/${name}.tsx`];
        if (!page) throw new Error(`Página no encontrada: ${name}`);
        return await page() as never;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#e8754f',
        showSpinner: false,
    },
});

if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js');
    });
}
