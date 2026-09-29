<script setup lang="ts">
// S15 注文・テーブル（12 §8.5）：タブ［テーブル］［確認待ち n］［未会計］［本日の注文］。
// 画面を開いたとき・タブを切り替えたとき・［更新］で取得する（自動更新はしない）。確認待ちの件数は毎回取り直す
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { acceptOrder, cancelOrder, fetchOrders, type OrderView } from '@/api/orders'
import { closeOrderTable, fetchOrderTables, openOrderTable } from '@/api/orderTables'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import OrderCard from '@/components/orders/OrderCard.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, isNetworkError } from '@/lib/apiError'
import { formatYen } from '@/lib/money'
import { canCancelOrder, orderPlace } from '@/lib/orders'
import type { Order, OrderTable } from '@/types/api'
import '@/styles/admin.css'

type Tab = 'tables' | OrderView

const t = ja.orders

const TABS: readonly Tab[] = ['tables', 'pending', 'unpaid', 'today']
/** 厨房の「確認待ち n 件」から ?tab=pending で開く */
const initialTab = useRoute().query.tab
const tab = ref<Tab>(TABS.find((x) => x === initialTab) ?? 'tables')
const tables = ref<OrderTable[]>([])
const orders = ref<Order[]>([])
const pendingCount = ref(0)
const loading = ref(true)
const loadFailed = ref<string | null>(null)
const notice = ref<string | null>(null)
const actionError = ref<string | null>(null)
/** 利用開始からの時間の基準（取得した時刻） */
const now = ref(Date.now())

type Confirming =
  | { kind: 'open' | 'close'; table: OrderTable }
  | { kind: 'cancel'; order: Order }
const confirming = ref<Confirming | null>(null)
const confirmBusy = ref(false)
const confirmError = ref<string | null>(null)
const acceptingId = ref<number | null>(null)

const tabs = computed<{ key: Tab; label: string }[]>(() => [
  { key: 'tables', label: t.tabTables },
  { key: 'pending', label: fmt(t.tabPending, { n: pendingCount.value }) },
  { key: 'unpaid', label: t.tabUnpaid },
  { key: 'today', label: t.tabToday },
])

/** 無効のテーブルは、利用中か未会計の注文が残っているときだけ出す */
const visibleTables = computed(() => tables.value.filter((x) => x.is_active || x.opened_at !== null || x.unpaid_order_count > 0))

function messageOf(err: unknown): string {
  return isNetworkError(err) ? t.network : (errorBody(err)?.message ?? ja.error.unexpected)
}

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    const current = tab.value
    const pendingP = fetchOrders('pending')
    if (current === 'tables') {
      const [list, pending] = await Promise.all([fetchOrderTables(), pendingP])
      tables.value = list
      pendingCount.value = pending.length
    } else if (current === 'pending') {
      const pending = await pendingP
      orders.value = pending
      pendingCount.value = pending.length
    } else {
      const [list, pending] = await Promise.all([fetchOrders(current), pendingP])
      orders.value = list
      pendingCount.value = pending.length
    }
    now.value = Date.now()
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? t.loadFailed : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function selectTab(key: Tab): void {
  if (tab.value === key) return
  tab.value = key
  orders.value = []
  notice.value = null
  actionError.value = null
  void load()
}

function refresh(): void {
  notice.value = null
  actionError.value = null
  void load()
}

function elapsedMinutes(table: OrderTable): number {
  if (table.opened_at === null) return 0
  return Math.max(0, Math.floor((now.value - Date.parse(table.opened_at)) / 60000))
}

// ── 受付（確認なし）
async function accept(order: Order): Promise<void> {
  acceptingId.value = order.id
  notice.value = null
  actionError.value = null
  try {
    await acceptOrder(order.id)
    notice.value = fmt(t.acceptDone, { no: order.order_no })
  } catch (err) {
    actionError.value = messageOf(err)
  } finally {
    acceptingId.value = null
  }
  await load()
}

// ── 確認ダイアログ（利用開始・利用終了・取消）
function ask(value: Confirming): void {
  confirming.value = value
  confirmError.value = null
  notice.value = null
  actionError.value = null
}

const confirmText = computed(() => {
  const c = confirming.value
  if (!c) return { title: '', message: '', label: '', danger: false }
  if (c.kind === 'cancel') {
    return {
      title: fmt(t.cancelTitle, { no: c.order.order_no, table: orderPlace(c.order) }),
      message: t.cancelMessage,
      label: t.cancel,
      danger: true,
    }
  }
  if (c.kind === 'open') return { title: fmt(t.openTitle, { name: c.table.name }), message: t.openMessage, label: t.open, danger: false }
  return {
    title: fmt(t.closeTitle, { name: c.table.name }),
    message: c.table.unpaid_order_count > 0 ? fmt(t.closeMessageUnpaid, { n: c.table.unpaid_order_count }) : t.closeMessage,
    label: t.close,
    danger: true,
  }
})

async function runConfirm(): Promise<void> {
  const c = confirming.value
  if (!c) return
  confirmBusy.value = true
  confirmError.value = null
  try {
    if (c.kind === 'cancel') {
      await cancelOrder(c.order.id)
      notice.value = fmt(t.cancelDone, { no: c.order.order_no })
    } else if (c.kind === 'open') {
      await openOrderTable(c.table.id)
      notice.value = fmt(t.opened, { name: c.table.name })
    } else {
      await closeOrderTable(c.table.id)
      notice.value = fmt(t.closed, { name: c.table.name })
    }
    confirming.value = null
    await load()
  } catch (err) {
    // 状態が変わっていた（409）・対象が消えた（404）：閉じて一覧を取り直す。通信断は開いたまま押し直してもらう
    const status = errorStatus(err)
    if (status === 409 || status === 404 || status === 422) {
      confirming.value = null
      actionError.value = messageOf(err)
      await load()
    } else {
      confirmError.value = messageOf(err)
    }
  } finally {
    confirmBusy.value = false
  }
}
</script>

<template>
  <div class="adm-page">
    <AppHeader :title="t.title" />
    <main class="adm-body orders">
      <div class="orders__bar">
        <nav
          class="orders__tabs"
          :aria-label="t.title"
        >
          <button
            v-for="item in tabs"
            :key="item.key"
            type="button"
            class="orders__tab"
            :class="{ 'orders__tab--on': tab === item.key, 'orders__tab--alert': item.key === 'pending' && pendingCount > 0 }"
            :aria-pressed="tab === item.key"
            :data-tab="item.key"
            @click="selectTab(item.key)"
          >
            {{ item.label }}
          </button>
        </nav>
        <button
          type="button"
          class="adm-btn"
          :disabled="loading"
          @click="refresh"
        >
          {{ t.refresh }}
        </button>
      </div>

      <p
        v-if="notice"
        class="adm-ok"
        role="status"
      >
        {{ notice }}
      </p>
      <p
        v-if="actionError"
        class="adm-error"
        role="alert"
      >
        {{ actionError }}
      </p>

      <p
        v-if="loading && tables.length === 0 && orders.length === 0"
        role="status"
      >
        {{ ja.common.loading }}
      </p>
      <div
        v-else-if="loadFailed"
        class="adm-panel"
      >
        <p
          class="adm-error"
          role="alert"
        >
          {{ loadFailed }}
        </p>
        <div class="adm-actions">
          <BigButton @click="refresh">
            {{ t.retry }}
          </BigButton>
        </div>
      </div>

      <template v-else-if="tab === 'tables'">
        <p
          v-if="visibleTables.length === 0"
          class="adm-help"
        >
          {{ t.noTables }}
        </p>
        <ul
          v-else
          class="tables"
        >
          <li
            v-for="table in visibleTables"
            :key="table.id"
            class="table"
            :class="{ 'table--open': table.opened_at !== null }"
            :data-table="table.id"
          >
            <div class="table__head">
              <span class="table__name">{{ table.name }}</span>
              <span class="table__state">{{ table.opened_at !== null ? t.inUse : t.vacant }}</span>
            </div>
            <p
              v-if="table.opened_at !== null"
              class="table__line"
            >
              {{ fmt(t.elapsed, { min: elapsedMinutes(table) }) }}
            </p>
            <p
              v-if="table.unpaid_order_count > 0"
              class="table__line table__line--unpaid"
            >
              {{ fmt(t.unpaid, { n: table.unpaid_order_count, amount: formatYen(table.unpaid_subtotal) }) }}
            </p>
            <div class="table__actions">
              <button
                v-if="table.opened_at === null && table.is_active"
                type="button"
                class="adm-btn"
                @click="ask({ kind: 'open', table })"
              >
                {{ t.open }}
              </button>
              <button
                v-if="table.opened_at !== null"
                type="button"
                class="adm-btn"
                @click="ask({ kind: 'close', table })"
              >
                {{ t.close }}
              </button>
              <RouterLink
                v-if="table.is_active"
                class="adm-btn adm-btn--on"
                :to="{ name: 'order-new', query: { table: table.id } }"
              >
                {{ t.addOrder }}
              </RouterLink>
            </div>
          </li>
        </ul>
      </template>

      <template v-else>
        <p
          v-if="orders.length === 0 && !loading"
          class="adm-help"
        >
          {{ t.noOrders }}
        </p>
        <div
          v-else
          class="orders__list"
        >
          <OrderCard
            v-for="order in orders"
            :key="order.id"
            :order="order"
          >
            <button
              v-if="order.status === 'pending'"
              type="button"
              class="adm-btn adm-btn--on"
              :disabled="acceptingId !== null"
              @click="accept(order)"
            >
              {{ t.accept }}
            </button>
            <button
              v-if="canCancelOrder(order)"
              type="button"
              class="adm-btn adm-btn--danger"
              @click="ask({ kind: 'cancel', order })"
            >
              {{ t.cancel }}
            </button>
          </OrderCard>
        </div>
      </template>
    </main>

    <ConfirmDialog
      :open="confirming !== null"
      :title="confirmText.title"
      :message="confirmText.message"
      :confirm-label="confirmText.label"
      :danger="confirmText.danger"
      :loading="confirmBusy"
      :error="confirmError"
      @confirm="runConfirm"
      @cancel="confirming = null"
    />
  </div>
</template>

<style scoped>
.orders { max-width: 1200px; gap: 16px; }

.orders__bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.orders__tabs { display: flex; flex: 1 1 auto; gap: 8px; overflow-x: auto; scrollbar-width: none; }
.orders__tab {
  flex-shrink: 0;
  min-height: var(--tab-h);
  padding: 0 16px;
  border: 2px solid var(--c-border);
  border-radius: 999px;
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 18px;
  font-weight: 700;
  white-space: nowrap;
}
.orders__tab--alert { border-color: var(--c-change); color: var(--c-change); }
.orders__tab--on { border-color: var(--c-primary); background: var(--c-primary); color: var(--c-on-primary); }

.tables {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.table {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius-card);
  background: var(--c-surface);
}
.table--open { border-color: var(--c-primary); }
.table__head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.table__name { font-size: 22px; font-weight: 800; overflow-wrap: anywhere; }
.table__state { flex-shrink: 0; color: var(--c-text-sub); font-weight: 700; }
.table--open .table__state { color: var(--c-primary); }
.table__line { margin: 0; color: var(--c-text-sub); }
.table__line--unpaid { color: var(--c-money); font-weight: 700; }
.table__actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: auto; }
.table__actions .adm-btn { flex: 1 1 auto; text-decoration: none; }

.orders__list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 12px;
  align-items: start;
}
</style>
