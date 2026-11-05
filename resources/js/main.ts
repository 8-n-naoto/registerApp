import { createApp, watch } from 'vue'
import { createPinia } from 'pinia'
import App from '@/App.vue'
import { router } from '@/router'
import { ensureCsrfCookie, setAuthHandlers } from '@/api/client'
import { prefetchMenu } from '@/api/publicTable'
import { basePath } from '@/lib/basePath'
import { setupPwa } from '@/lib/pwa'
import { serverSaysGuest } from '@/lib/serverHints'
import { useAuthStore } from '@/stores/auth'
import { useCatalogStore } from '@/stores/catalog'
import { useOutboxStore } from '@/stores/outbox'
import { useUiStore } from '@/stores/ui'
import '@/styles/tokens.css'
import '@/styles/base.css'
import '@/styles/ui.css'

const app = createApp(App)
app.use(createPinia())

const auth = useAuthStore()
const ui = useUiStore()
setAuthHandlers({
  // 401：ログインが切れた。起動時の GET /me はガードが扱う
  unauthorized: () => {
    if (!auth.loaded || !auth.me) return
    auth.clear()
    const current = router.currentRoute.value
    void router.replace({ name: 'login', query: { redirect: current.fullPath } })
  },
  // 403 停止：サーバーはセッションを破棄済み。ログイン画面にメッセージを出す
  suspended: (err) => {
    auth.clear()
    ui.loginNotice = err.response?.data?.message ?? null
    if (router.currentRoute.value.name !== 'login') void router.replace({ name: 'login' })
  },
  network: (failed) => {
    ui.networkError = failed
  },
})

app.use(router)

// お客さんの画面（C01）：画面の JS を読み込むあいだに、QR のメニューを先に取りに行く
// （トークンは英数字と - _ だけなので、パスの文字列をそのまま使う。それ以外の形は先読みしない）
const tablePath = /^\/t\/([A-Za-z0-9_-]+)$/.exec(location.pathname.slice(basePath.length))
if (tablePath?.[1]) prefetchMenu(tablePath[1])

// 即時性の要る画面（レジ・注文の入力・注文確認・厨房）を先に用意する：ログイン後、商品マスタと画面の JS を裏で読んでおく
const catalog = useCatalogStore()
// レジ・注文の入力を直接開いた（再読み込み・ホーム画面のアイコン）ときは、GET /me と並べて商品マスタを取りに行く
// （HTML の時点で未ログインなら呼ばない。QA-004）
const startPath = location.pathname.slice(basePath.length)
if ((startPath === '/register' || startPath === '/orders/new') && !serverSaysGuest()) catalog.prefetch()
// S00・S02 は別チャンク（PERF-002）。ログイン済みで直接開いたときは、GET /me と並べて画面の JS を読み始める
if ((startPath === '/' || startPath === '/register') && !serverSaysGuest()) {
  for (const load of [() => import('@/pages/HomePage.vue'), () => import('@/pages/RegisterPage.vue')]) load().catch(() => undefined)
}
let pagesWarmed = false
router.afterEach(() => {
  if (!auth.me || auth.isAdmin || auth.needsClockIn) return
  setTimeout(() => {
    catalog.prefetch()
    if (pagesWarmed) return
    pagesWarmed = true
    for (const load of [
      () => import('@/pages/HomePage.vue'),
      () => import('@/pages/RegisterPage.vue'),
      () => import('@/pages/OrderNewPage.vue'),
      () => import('@/pages/OrdersPage.vue'),
      () => import('@/pages/KitchenPage.vue'),
    ]) load().catch(() => undefined)
  }, 300)
})

const OFFLINE_SESSION_RECHECK_MS = 30_000

// 14 §7 オフライン会計：ログイン中の店舗の送信待ちを読み、送れるときに送る。
// 通信できないまま端末に残した利用者で起動したときは、通信が戻ったら GET /me で確かめる（切れていれば 401 でログイン画面へ）
const outbox = useOutboxStore()
watch(
  () => (auth.isOwner || auth.isStaff ? (auth.me?.store?.id ?? null) : null),
  (storeId) => {
    catalog.cacheStoreId = storeId
    void outbox.init(storeId)
  },
  { immediate: true },
)
window.addEventListener('online', () => auth.recheckOfflineSession())
window.setInterval(() => auth.recheckOfflineSession(), OFFLINE_SESSION_RECHECK_MS)

// XSRF-TOKEN Cookie を先に受け取る（08 §8）。失敗しても画面は出し、API 側の 419 の再試行に任せる
ensureCsrfCookie().catch(() => undefined)
setupPwa()

app.mount('#app')
