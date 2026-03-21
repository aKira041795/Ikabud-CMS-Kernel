import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  // Base path must match where assets are served from
  base: '/assets/cms/builder/',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  build: {
    // Output to public/assets/cms/builder/ so DiSyL template can reference it
    outDir: path.resolve(__dirname, '../../../public/assets/cms/builder'),
    emptyOutDir: true,
    rollupOptions: {
      input: path.resolve(__dirname, 'index.html'),
      output: {
        // Stable filenames for the DiSyL shell to reference
        entryFileNames: 'builder.js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (assetInfo) => {
          // Stable name for the main CSS bundle
          if (assetInfo.name?.endsWith('.css')) {
            return 'builder.css';
          }
          return 'assets/[name]-[hash][extname]';
        },
      },
    },
  },
  server: {
    // Dev server proxies API calls to the PHP backend
    proxy: {
      '/api': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
});
