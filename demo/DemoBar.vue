<script setup lang="ts">
// デモの操作パネル：役割の切り替え・画面へのリンク・お客さんの注文・初期化
import { computed, ref } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { demoCustomerOrder, demoSessionUser, demoTableToken, resetDemo, setDemoSession } from './mock/server'
import { demoNotice, noticeText } from './notice'

const router = useRouter()
const auth = useAuthStore()
const open = ref(false)
const busy = ref(false)

const roles = [
  { id: 2, label: '店長', sub: 'owner' },
  { id: 3, label: 'スタッフ', sub: 'staff1' },
  { id: 1, label: '運営', sub: 'admin' },
] as const

const currentId = computed(() => auth.me?.user.id ?? null)

const links = computed<{ label: string; to: RouteLocationRaw }[]>(() => {
  const role = auth.me?.user.role
  if (role === 'admin') {
    return [
      { label: '店舗一覧', to: { name: 'admin-stores' } },
      { label: '日次売上', to: { name: 'sales-daily' } },
      { label: '期間集計', to: { name: 'sales-summary' } },
      { label: '操作ログ', to: { name: 'logs' } },
    ]
  }
  if (role === undefined) return []
  const common = [
    { label: 'ホーム', to: { name: 'home' } },
    { label: 'レジ', to: { name: 'register' } },
    { label: '注文入力', to: { name: 'order-new' } },
    { label: '注文確認', to: { name: 'orders' } },
    { label: '厨房', to: { name: 'kitchen' } },
    { label: '日次売上', to: { name: 'sales-daily' } },
    { label: 'レジ締め', to: { name: 'closing' } },
  ]
  if (role === 'staff') return [...common, { label: 'アカウント', to: { name: 'account' } }]
  return [
    ...common,
    { label: '期間集計', to: { name: 'sales-summary' } },
    { label: '商品', to: { name: 'products' } },
    { label: '店舗設定', to: { name: 'settings-store' } },
    { label: 'スタッフ', to: { name: 'settings-staff' } },
    { label: 'テーブル', to: { name: 'settings-tables' } },
    { label: '操作ログ', to: { name: 'logs' } },
  ]
})

async function switchTo(userId: number | null): Promise<void> {
  if (busy.value) return
  busy.value = true
  try {
    setDemoSession(userId)
    auth.clear()
    if (userId === null) {
      await router.replace({ name: 'login' })
    } else {
      await auth.fetchMe()
      await router.replace(auth.homeRoute)
    }
    open.value = false
  } finally {
    busy.value = false
  }
}

async function go(to: RouteLocationRaw): Promise<void> {
  await router.push(to)
  open.value = false
}

async function openCustomer(): Promise<void> {
  const token = demoTableToken('T1')
  if (token === null) {
    demoNotice('T1 のテーブルがありません')
    return
  }
  await router.push({ name: 'table-order', params: { token } })
  open.value = false
}

function deliver(): void {
  demoNotice(demoCustomerOrder())
}

function reset(): void {
  resetDemo()
  location.hash = '#/'
  location.reload()
}

const who = computed(() => {
  const u = auth.me?.user ?? demoSessionUser()
  return u ? `${u.name}（${u.login_id}）` : '未ログイン'
})
</script>

<template>
  <div class="demo">
    <button type="button" class="demo-fab" :aria-expanded="open" aria-controls="demo-panel" @click="open = !open">
      {{ open ? '閉じる' : 'デモ' }}
    </button>
    <section v-if="open" id="demo-panel" class="demo-panel" aria-label="デモの操作">
      <p class="demo-lead">
        架空のデータで動くデモです。サーバーには接続せず、操作した内容はこのブラウザの中だけに残ります。
      </p>
      <p class="demo-who">
        いまの利用者：{{ who }}
      </p>

      <h2 class="demo-h">
        役割を切り替える
      </h2>
      <div class="demo-grid">
        <button
          v-for="r in roles"
          :key="r.id"
          type="button"
          class="demo-btn"
          :class="{ 'is-current': currentId === r.id }"
          :disabled="busy"
          @click="switchTo(r.id)"
        >
          {{ r.label }}<small>{{ r.sub }}</small>
        </button>
        <button type="button" class="demo-btn" :disabled="busy" @click="switchTo(null)">
          ログイン画面<small>パスワード demo</small>
        </button>
      </div>

      <template v-if="links.length > 0">
        <h2 class="demo-h">
          画面を開く
        </h2>
        <div class="demo-grid">
          <button v-for="l in links" :key="l.label" type="button" class="demo-btn" @click="go(l.to)">
            {{ l.label }}
          </button>
        </div>
      </template>

      <h2 class="demo-h">
        お客さんの注文
      </h2>
      <div class="demo-grid">
        <button type="button" class="demo-btn" @click="openCustomer">
          T1 の注文画面<small>QR から開く画面</small>
        </button>
        <button type="button" class="demo-btn" @click="deliver">
          注文を 1 件届ける<small>厨房の自動更新の確認用</small>
        </button>
      </div>

      <h2 class="demo-h">
        データ
      </h2>
      <button type="button" class="demo-btn demo-reset" @click="reset">
        最初の状態に戻す
      </button>
    </section>
    <p v-if="noticeText" class="demo-toast" role="status">
      {{ noticeText }}
    </p>
  </div>
</template>

<style scoped>
.demo-fab {
  position: fixed;
  left: 12px;
  bottom: 12px;
  z-index: 1000;
  min-width: 64px;
  min-height: 48px;
  padding: 0 14px;
  border: 2px solid #fff;
  border-radius: 24px;
  background: #B45309;
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  cursor: pointer;
}
.demo-panel {
  position: fixed;
  left: 12px;
  bottom: 72px;
  z-index: 1000;
  width: min(360px, calc(100vw - 24px));
  max-height: calc(100vh - 96px);
  overflow-y: auto;
  padding: 16px;
  border-radius: 12px;
  background: #fff;
  color: #111827;
  box-shadow: 0 8px 28px rgba(0, 0, 0, 0.35);
  font-size: 15px;
  line-height: 1.5;
}
.demo-lead { margin: 0 0 8px; color: #4B5563; }
.demo-who { margin: 0 0 4px; font-weight: 700; }
.demo-h { margin: 14px 0 6px; font-size: 14px; color: #4B5563; }
.demo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.demo-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 48px;
  padding: 6px 8px;
  border: 1px solid #D1D5DB;
  border-radius: 8px;
  background: #F3F6FC;
  color: #111827;
  font-size: 16px;
  cursor: pointer;
}
.demo-btn small { font-size: 12px; color: #4B5563; }
.demo-btn.is-current { border: 2px solid #2F63DB; background: #E3EBFB; font-weight: 700; }
.demo-btn:disabled { opacity: 0.6; }
.demo-reset { width: 100%; color: #DC2626; }
.demo-toast {
  position: fixed;
  left: 50%;
  bottom: 72px;
  z-index: 1001;
  transform: translateX(-50%);
  max-width: calc(100vw - 32px);
  margin: 0;
  padding: 12px 16px;
  border-radius: 8px;
  background: #111827;
  color: #fff;
  font-size: 16px;
}
</style>
