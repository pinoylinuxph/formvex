import { resolve } from 'node:path';

import { defineConfig } from 'vite';

export default defineConfig({
  build: {
    emptyOutDir: true,
    lib: {
      entry: {
        client: resolve(import.meta.dirname, 'packages/client/src/index.js'),
        'platform-ui': resolve(import.meta.dirname, 'packages/platform-ui/assets/index.js'),
        'spoke-admin': resolve(import.meta.dirname, 'apps/spoke/assets/app.js'),
        'hub-platform': resolve(import.meta.dirname, 'apps/hub/assets/app.js'),
      },
      cssFileName: 'formvex-ui',
      formats: ['es'],
    },
    outDir: 'build',
    rollupOptions: {
      output: {
        entryFileNames: '[name].js',
      },
    },
    sourcemap: false,
  },
});
