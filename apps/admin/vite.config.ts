import { fileURLToPath, URL } from 'node:url';
import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { quasar, transformAssetUrls } from '@quasar/vite-plugin';
import { swcScope } from './tools/postcssScope';

/** Ship only the woff2 icon font (all supported browsers); drops the 200 KB legacy woff. */
function woff2Only() {
  return {
    name: 'swc-woff2-only',
    enforce: 'pre' as const,
    transform(code: string, id: string) {
      if (!id.includes('material-icons-round.css')) return null;
      return code.replace(/,\s*url\([^)]*\.woff\d?\??[^)]*\)\s*format\((['"])woff\1\)/g, '');
    },
  };
}

const outDir = resolve(__dirname, '../../assets/admin');

/**
 * Dev server: `SWC_WP_ORIGIN=https://my-site.test npm run dev` proxies `/wp-json` to a real
 * WordPress (see README). Production output goes to ../../assets/admin.
 */
const wpOrigin = process.env.SWC_WP_ORIGIN;

export default defineConfig(() => ({
  base: './',
  plugins: [woff2Only(), vue({ template: { transformAssetUrls } }), quasar()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  css: { postcss: { plugins: [swcScope()] } },
  server: {
    port: 5173,
    host: '127.0.0.1',
    proxy: wpOrigin
      ? { '/wp-json': { target: wpOrigin, changeOrigin: true, secure: false } }
      : undefined,
  },
  build: {
    outDir,
    emptyOutDir: true,
    manifest: true,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2020',
    modulePreload: { polyfill: false },
    chunkSizeWarningLimit: 900,
    rollupOptions: {
      input: { app: resolve(__dirname, 'src/main.ts') },
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (info) => {
          const names = info.names ?? (info.name ? [info.name] : []);
          return names.some((n) => n.endsWith('.css'))
            ? 'app.css'
            : 'assets/[name]-[hash][extname]';
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    css: false,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'lcov'],
      reportsDirectory: 'coverage',
      include: ['src/**/*.{ts,js,vue}'],
    },
    server: { deps: { inline: ['quasar'] } },
  },
}));
