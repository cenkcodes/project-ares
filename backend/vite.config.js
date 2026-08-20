import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/video-monetization.js',
                'resources/js/video-adapter.js',
                'resources/js/video-banner-renderer.js',
            ],
            refresh: true,
        }),
    ],
});
