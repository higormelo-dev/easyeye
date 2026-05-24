import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import path from 'path';

/**
 * Config do Vitest separada do vite.config.js para evitar conflitos
 * com plugins de build (laravel-vite-plugin, imageInputs, etc).
 *
 * Reaproveita o mesmo alias '@' do projeto para importar componentes
 * exatamente como no app real.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@':    path.resolve(__dirname, './resources/js'),
            '@img': path.resolve(__dirname, './resources/img'),
        },
    },
    test: {
        environment: 'happy-dom',
        globals: true,
        setupFiles: ['./tests/JavaScript/setup.js'],
        include: ['./tests/JavaScript/**/*.{test,spec}.js'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'html'],
            include: ['resources/js/**/*.vue', 'resources/js/**/*.js'],
            exclude: [
                'resources/js/ziggy.js',
                'resources/js/**/*.test.{js,vue}',
                'resources/js/**/*.spec.{js,vue}',
            ],
        },
    },
});
