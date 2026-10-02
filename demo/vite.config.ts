// デモ（アーティファクト）用のビルド設定。本番のビルドには使わない
import { defineConfig, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

const shim = fileURLToPath(new URL('./routerShim.ts', import.meta.url))

function routerShim(): Plugin {
  return {
    name: 'demo-router-shim',
    enforce: 'pre',
    resolveId(id, importer) {
      if (id === 'vue-router' && importer !== undefined && !importer.startsWith(shim.replace(/\.ts$/, ''))) return shim
      return null
    },
  }
}

export default defineConfig({
  root: fileURLToPath(new URL('.', import.meta.url)),
  base: './',
  plugins: [
    routerShim(),
    vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
  ],
  resolve: {
    alias: [
      { find: /^@\/lib\/pwa$/, replacement: fileURLToPath(new URL('./pwaStub.ts', import.meta.url)) },
      { find: /^@\//, replacement: fileURLToPath(new URL('../resources/js/', import.meta.url)) },
    ],
  },
  build: {
    outDir: fileURLToPath(new URL('./.dist', import.meta.url)),
    emptyOutDir: true,
    target: 'es2020',
    cssCodeSplit: false,
    assetsInlineLimit: 100_000_000,
    modulePreload: false,
    rollupOptions: { output: { inlineDynamicImports: true } },
  },
})
