<script setup lang="ts">
// S14 厨房（12 §8.3・§6.1.3）。受付済みで未完了の注文を古い順に出し、品目ごと・注文ごとに提供済みにする。
// 自動更新はサーバーの設定（#64）に従い、取得・タイマーは stores/kitchen が持つ。操作は応答で 1 件を置き換える（楽観更新はしない）
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { serveAllOrder, setItemServed } from '@/api/orders'
import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import KitchenCard from '@/components/orders/KitchenCard.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, isNetworkError } from '@/lib/apiError'
import { formatTokyoClock } from '@/lib/date'
import { enableSound, loadSoundEnabled, playBeep, saveSoundEnabled } from '@/lib/kitchenSound'
import { orderPlace } from '@/lib/orders'
import { useWakeLock } from '@/lib/wakeLock'
import { useKitchenStore } from '@/stores/kitchen'
import type { Order, OrderItem } from '@/types/api'
import '@/styles/admin.css'

const NOW_TICK_MS = 15_000 // 経過分の表示を進める間隔

const t = ja.kitchen
const kitchen = useKitchenStore()

useWakeLock()

const tab = ref<'progress' | 'done'>('progress')
const sound = ref(loadSoundEnabled())
const now = ref(Date.now())
const busyId = ref<number | null>(null)
const actionError = ref<string | null>(null)

type Confirming = { kind: 'all'; order: Order; count: number } | { kind: 'complete'; order: Order; item: OrderItem }
const confirming = ref<Confirming | null>(null)

let nowTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  now.value = kitchen.serverNow()
  nowTimer = setInterval(() => {
    now.value = kitchen.serverNow()
  }, NOW_TICK_MS)
  kitchen.start({
    onNew: () => {
      if (sound.value) playBeep()
    },
  })
})

onUnmounted(() => {
  if (nowTimer !== null) clearInterval(nowTimer)
  kitchen.stop()
})

const pollingLabel = computed(() => {
  const p = kitchen.polling
  if (p === null) return ''
  if (p.active) return t.pollingOn
  if (p.next_change_at) {
    const at = Date.parse(p.next_change_at)
    if (Number.isFinite(at)) return fmt(t.pollingOffUntil, { time: formatTokyoClock(at).slice(0, 5) })
  }
  return t.pollingOff
})

const lastUpdated = computed(() => (kitchen.lastUpdatedAt === null ? '' : formatTokyoClock(kitchen.lastUpdatedAt)))
const list = computed(() => (tab.value === 'progress' ? kitchen.inProgress : kitchen.done))

function toggleSound(): void {
  const next = !sound.value
  if (next) enableSound() // Safari はタップの中でないと音を出せない
  sound.value = next
  saveSoundEnabled(next)
}

async function refresh(): Promise<void> {
  actionError.value = null
  await kitchen.refreshNow()
  now.value = kitchen.serverNow()
}

async function run(order: Order, call: () => Promise<Order>): Promise<void> {
  busyId.value = order.id
  actionError.value = null
  try {
    kitchen.applyOrder(await call())
  } catch (err) {
    actionError.value = isNetworkError(err) ? t.network : (errorBody(err)?.message ?? ja.error.unexpected)
    // 取り消された・状態が変わった：最新を取り直す
    const status = errorStatus(err)
    if (status === 404 || status === 409 || status === 422) await kitchen.refreshNow()
  } finally {
    busyId.value = null
  }
}

function onToggle(order: Order, item: OrderItem): void {
  if (item.served_at !== null) {
    void run(order, () => setItemServed(item.id, false)) // 戻すときは確認しない
    return
  }
  const rest = order.items.filter((i) => i.served_at === null)
  if (rest.length === 1 && rest[0]?.id === item.id) {
    confirming.value = { kind: 'complete', order, item }
    return
  }
  void run(order, () => setItemServed(item.id, true))
}

function onServeAll(order: Order): void {
  const count = order.items.filter((i) => i.served_at === null).length
  if (count === 0) return
  confirming.value = { kind: 'all', order, count }
}

const confirmText = computed(() => {
  const c = confirming.value
  if (!c) return { title: '', label: '' }
  const base = { no: c.order.order_no, table: orderPlace(c.order) }
  return c.kind === 'all'
    ? { title: fmt(t.serveAllTitle, { ...base, n: c.count }), label: t.serveAllConfirm }
    : { title: fmt(t.completeTitle, base), label: t.completeConfirm }
})

function runConfirm(): void {
  const c = confirming.value
  if (!c) return
  confirming.value = null
  if (c.kind === 'all') void run(c.order, () => serveAllOrder(c.order.id))
  else void run(c.order, () => setItemServed(c.item.id, true))
}
</script>

<template>
  <div class="adm-page">
    <AppHeader :title="t.title" />
    <div
      v-if="kitchen.offline"
      class="kitchen__offline"
      role="alert"
    >
      {{ fmt(t.offline, { time: lastUpdated || '--:--:--' }) }}
    </div>
    <main class="adm-body kitchen">
      <div class="kitchen__bar">
        <span
          class="kitchen__polling"
          data-polling
        >{{ pollingLabel }}</span>
        <span
          v-if="lastUpdated"
          class="kitchen__updated tabular"
        >{{ fmt(t.lastUpdated, { time: lastUpdated }) }}</span>
        <RouterLink
          v-if="kitchen.pendingCount > 0"
          class="r-btn r-btn--secondary kitchen__pending"
          :to="{ name: 'orders', query: { tab: 'pending' } }"
        >
          {{ fmt(t.pending, { n: kitchen.pendingCount }) }}
        </RouterLink>
        <span class="kitchen__spacer" />
        <button
          type="button"
          class="r-btn"
          :class="sound ? 'r-btn--primary' : 'r-btn--secondary'"
          :aria-pressed="sound"
          :aria-label="t.soundLabel"
          data-sound
          @click="toggleSound"
        >
          <AppIcon
            name="sound"
            :size="20"
          />
          <span>{{ sound ? t.soundOn : t.soundOff }}</span>
        </button>
        <button
          type="button"
          class="r-btn r-btn--secondary"
          :disabled="kitchen.loading"
          data-refresh
          @click="refresh"
        >
          <AppIcon
            name="refresh"
            :size="20"
          />
          <span>{{ t.refresh }}</span>
        </button>
      </div>

      <nav
        class="kitchen__tabs r-seg"
        :aria-label="t.title"
      >
        <button
          type="button"
          class="kitchen__tab"
          :class="{ 'kitchen__tab--on': tab === 'progress', on: tab === 'progress' }"
          :aria-pressed="tab === 'progress'"
          data-tab="progress"
          @click="tab = 'progress'"
        >
          {{ fmt(t.tabInProgress, { n: kitchen.inProgress.length }) }}
        </button>
        <button
          type="button"
          class="kitchen__tab"
          :class="{ 'kitchen__tab--on': tab === 'done', on: tab === 'done' }"
          :aria-pressed="tab === 'done'"
          data-tab="done"
          @click="tab = 'done'"
        >
          {{ fmt(t.tabDone, { n: kitchen.done.length }) }}
        </button>
      </nav>

      <p
        v-if="actionError"
        class="adm-error"
        role="alert"
      >
        {{ actionError }}
      </p>

      <p
        v-if="!kitchen.loaded && kitchen.failures === 0"
        role="status"
      >
        {{ ja.common.loading }}
      </p>
      <div
        v-else-if="!kitchen.loaded"
        class="r-banner r-banner--danger kitchen__fail"
      >
        <p
          class="adm-error"
          role="alert"
        >
          {{ t.loadFailed }}
        </p>
        <div class="adm-actions">
          <BigButton @click="refresh">
            {{ t.retry }}
          </BigButton>
        </div>
      </div>
      <p
        v-else-if="list.length === 0"
        class="adm-help sub"
      >
        {{ tab === 'progress' ? t.emptyInProgress : t.emptyDone }}
      </p>
      <div
        v-else
        class="kitchen__grid k-grid"
      >
        <KitchenCard
          v-for="order in list"
          :key="order.id"
          :order="order"
          :now="now"
          :busy="busyId === order.id"
          :highlighted="kitchen.highlighted.has(order.id)"
          @toggle="(item) => onToggle(order, item)"
          @serve-all="onServeAll(order)"
        />
      </div>
    </main>

    <ConfirmDialog
      :open="confirming !== null"
      :title="confirmText.title"
      :confirm-label="confirmText.label"
      @confirm="runConfirm"
      @cancel="confirming = null"
    />
  </div>
</template>

<style scoped>
.kitchen { max-width: 1400px; gap: 12px; }
.kitchen__offline {
  position: sticky;
  top: 0;
  z-index: 5;
  padding: 12px var(--gutter);
  background: var(--c-danger);
  color: var(--c-on-primary);
  font-size: 18px;
  font-weight: 800;
  text-align: center;
}
.kitchen__bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
.kitchen__polling { font-size: 18px; font-weight: 700; }
.kitchen__updated { color: var(--c-text-sub); font-size: 16px; }
.kitchen__pending { text-decoration: none; }
.kitchen__spacer { flex: 1 1 auto; }
.kitchen__tabs { width: 100%; max-width: 520px; }
.kitchen__fail { flex-direction: column; align-items: flex-start; }
.kitchen__grid { padding: 0; }
</style>
