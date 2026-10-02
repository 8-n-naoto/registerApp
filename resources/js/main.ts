import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from '@/App.vue'
import { router } from '@/router'
import { ensureCsrfCookie, setAuthHandlers } from '@/api/client'
import { setupPwa } from '@/lib/pwa'
import { useAuthStore } from '@/stores/auth'
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

// XSRF-TOKEN Cookie を先に受け取る（08 §8）。失敗しても画面は出し、API 側の 419 の再試行に任せる
ensureCsrfCookie().catch(() => undefined)
setupPwa()

app.mount('#app')
