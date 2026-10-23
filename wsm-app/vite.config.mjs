import { copyFile, mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

const appRoot = dirname(fileURLToPath(import.meta.url));
const shellAssets = [
  '.htaccess',
  'dexie-4.4.6.min.js',
  'icon-192.png',
  'icon-512.png',
  'manifest.json',
  'sw.js',
];

export default defineConfig({
  root: appRoot,
  // Relative URLs keep the generated app shell usable under Tauri's asset protocol.
  base: './',
  // The service worker and app-shell files are copied byte-for-byte after the HTML build.
  publicDir: false,
  plugins: [
    {
      name: 'wsm-copy-app-shell-assets',
      apply: 'build',
      async closeBundle() {
        const outputDir = resolve(appRoot, 'dist');
        await mkdir(outputDir, { recursive: true });
        await Promise.all(
          shellAssets.map((name) =>
            copyFile(resolve(appRoot, name), resolve(outputDir, name)),
          ),
        );
      },
    },
  ],
  build: {
    outDir: resolve(appRoot, 'dist'),
    emptyOutDir: true,
    rollupOptions: {
      input: {
        index: resolve(appRoot, 'index.html'),
        club: resolve(appRoot, 'club.html'),
        desktop: resolve(appRoot, 'desktop.html'),
      },
    },
  },
});
