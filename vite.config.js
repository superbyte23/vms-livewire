import {
    defineConfig,
    loadEnv,
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const viteHost = env.VITE_HOST || 'localhost';

    const https = env.VITE_HTTPS === 'true' ? {
        cert: env.VITE_HTTPS_CERT,
        key: env.VITE_HTTPS_KEY,
    } : undefined;

    return {
        plugins: [
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.js',
                    'resources/js/passkeys.js',
                ],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            strictPort: true,
            cors: true,
            https,
            hmr: {
                host: viteHost,
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
