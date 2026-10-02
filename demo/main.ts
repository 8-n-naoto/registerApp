// デモの起動処理（resources/js/main.ts から CSRF と PWA を除き、API をブラウザ内のモックに差し替える）
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import DemoRoot from './DemoRoot.vue'
import { router } from '@/router'
import { http, setAuthHandlers } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { mockAdapter } from './mock/server'
import { demoNotice } from './notice'
import '@/styles/tokens.css'
import '@/styles/base.css'
import '@/styles/ui.css'

http.defaults.adapter = mockAdapter

// CSV の書き出し・バックアップのリンク（/api/...）はデモでは開かない
document.addEventListener('click', (e) => {
  const a = e.target instanceof Element ? e.target.closest('a[href]') : null
  if (a && (a.getAttribute('href') ?? '').includes('/api/')) {
    e.preventDefault()
    demoNotice('デモではファイルの書き出し・バックアップはできません')
  }
}, true)
// 印刷・確認ダイアログはアーティファクトの中では動かないことがある
window.print = () => demoNotice('デモでは印刷できません（本番ではここで印刷画面が開きます）')

const app = createApp(DemoRoot)
app.use(createPinia())

const auth = useAuthStore()
const ui = useUiStore()
setAuthHandlers({
  unauthorized: () => {
    if (!auth.loaded || !auth.me) return
    auth.clear()
    const current = router.currentRoute.value
    void router.replace({ name: 'login', query: { redirect: current.fullPath } })
  },
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
app.mount('#app')
