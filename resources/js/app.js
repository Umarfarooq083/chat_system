import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const inertiaApp = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, Ziggy)
            .mount(el);

        // mount visitor chat widget if the element is present (rendered server-side for guests)
        if (document.getElementById('visitor-chat-widget')) {
            import('./Components/VisitorChatWidget.vue').then(module => {
                const VisitorChatWidget = module.default;
                createApp(VisitorChatWidget).mount('#visitor-chat-widget');
            });
        }

        return inertiaApp;
    },
    progress: {
        color: '#4B5563',
    },
});
