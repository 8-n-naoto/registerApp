import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from '@/App.vue'
import { router } from '@/router'
import { ensureCsrfCookie } from '@/api/client'
import { setupPwa } from '@/lib/pwa'
import '@/styles/tokens.css'
import '@/styles/base.css'

const app = createApp(App)
app.use(createPinia())
app.use(router)

// XSRF-TOKEN Cookie を先に受け取る（08 §8）。失敗しても画面は出し、API 側の 419 の再試行に任せる
ensureCsrfCookie().catch(() => undefined)
setupPwa()

app.mount('#app')
