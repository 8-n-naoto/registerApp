import { defineConfig, loadEnv } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  // process.env（コマンドで渡した値）が .env より優先される
  const env = loadEnv(mode, process.cwd(), '')
  // 本番 '/regi'、ローカル ''。APP_PATH_PREFIX が無ければ ASSET_URL を使う
  // （CLAUDE.md のビルドコマンドは ASSET_URL だけを渡すため）
  const basePath = (env.APP_PATH_PREFIX || env.ASSET_URL || '').replace(/\/+$/, '')
  const appRoot = `${basePath}/` // 本番 '/regi/'、ローカル '/'

  return {
    plugins: [
      laravel({
        input: ['resources/js/main.ts'],
        refresh: true,
      }),
      vue({
        template: {
          transformAssetUrls: { base: null, includeAbsolute: false },
        },
      }),
      VitePWA({
        // sw.js と manifest.webmanifest を public/ 直下に出す（scope を /regi/ にするため）
        outDir: 'public',
        buildBase: appRoot,
        scope: appRoot,
        injectRegister: null, // main.ts で virtual:pwa-register を使って登録する
        // 08 §9 は 'autoUpdate' だが、「会計中に勝手に再読み込みしない」「S02 以外で［更新］を出す」を
        // 満たすには 'prompt' が必要（§13 指摘 16）。onNeedRefresh で更新のお知らせを出す
        registerType: 'prompt',
        // マニフェストは public/manifest.webmanifest（静的ファイル、URL は相対）。プラグインが出力すると
        // public/build/ に置かれ、scope の /regi/ 直下に出せないため（docs/10 に記録）
        manifest: false,
        workbox: {
          // 画面の資源だけを事前キャッシュする。API はキャッシュしない（02 §9.3）
          globPatterns: ['build/assets/**/*.{js,css,woff2,svg,png}'],
          globIgnores: ['build/manifest.json'],
          navigateFallback: null,
          runtimeCaching: [
            {
              // 画面の HTML（Blade 1 枚）は通信優先。通信できないときだけキャッシュを返す。
              // SPA の HTML は Laravel が動的に返すため事前キャッシュできず、08 §9 の navigateFallback の代わりにこれを使う（§13 指摘 17）
              urlPattern: ({ request, url }) =>
                request.mode === 'navigate' &&
                !url.pathname.startsWith(`${basePath}/api/`) &&
                !url.pathname.startsWith(`${basePath}/sanctum/`),
              handler: 'NetworkFirst',
              options: { cacheName: 'regi-pages', networkTimeoutSeconds: 3 },
            },
          ],
          cleanupOutdatedCaches: true,
        },
        devOptions: { enabled: false },
      }),
    ],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
      },
    },
    server: {
      host: '0.0.0.0',
      port: 5173,
      strictPort: true,
      hmr: { host: 'localhost' }, // public/hot に http://localhost:5173 が書かれるようにする
      watch: { usePolling: true, interval: 300 },
    },
    build: {
      // iOS 16 の Safari まで動かす（§8）
      target: ['es2020', 'safari16', 'chrome110'],
      chunkSizeWarningLimit: 250,
    },
  }
})
