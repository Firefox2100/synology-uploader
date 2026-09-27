import { defineConfig } from 'vite'

// Builds into ../public/assets with fixed filenames. The PHP templates append ?v=<mtime>
// for cache busting, so no manifest is needed.
export default defineConfig({
  base: './',
  build: {
    outDir: '../public/assets',
    emptyOutDir: true,
    target: 'es2022',
    sourcemap: false,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/main.js',
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'app-[name].js',
        assetFileNames: (asset) => (asset.names?.[0]?.endsWith('.css') ? 'app.css' : '[name][extname]'),
      },
    },
  },
})
