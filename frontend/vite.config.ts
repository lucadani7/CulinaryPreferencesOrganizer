import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// Local: Laravel runs on http://127.0.0.1:8000
// In Docker (Sail): the Laravel container is called laravel.test and listens on port 80
const apiTarget = process.env.VITE_PROXY_TARGET ?? 'http://127.0.0.1:8000';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: { '@': path.resolve(__dirname, 'src') },
  },
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    // On Docker Desktop (macOS/Windows) file changes in mounted volumes don't always reach inotify
    watch: process.env.VITE_USE_POLLING ? { usePolling: true, interval: 300 } : undefined,
    // /api and /storage are proxied to Laravel
    proxy: {
      '/api': { target: apiTarget, changeOrigin: true },
      '/storage': { target: apiTarget, changeOrigin: true },
    },
  },
});
