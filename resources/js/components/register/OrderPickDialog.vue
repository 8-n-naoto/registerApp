<script setup lang="ts">
// S02［注文から会計］（12 §8.6）：未会計の注文をテーブルごとにまとめて出す。テーブルを選ぶとその注文をすべて選び、
// 注文ごとに外せる。［カートに入れる］でカートに足す（カートが空でなければ確認）。開くたびに取り直す。
// preselectOrder は S13［送信して会計へ］から来たときの 1 件の注文
import { computed, ref, watch } from 'vue'
import { fetchOrders } from '@/api/orders'
import BigButton from '@/components/BigButton.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { fmt, ja } from '@/i18n/ja'
import { formatYen } from '@/lib/money'
import { orderPlace } from '@/lib/orders'
import { useRegisterStore } from '@/stores/register'
import type { Order } from '@/types/api'

const props = defineProps<{ open: boolean; preselectTable?: number | null; preselectOrder?: number | null }>()
const emit = defineEmits<{ close: []; loaded: [count: number] }>()

const t = ja.register
const register = useRegisterStore()

const orders = ref<Order[]>([])
const loading = ref(false)
const failed = ref(false)
const selected = ref<number[]>([])
const confirmAppend = ref(false)
const addError = ref<string | null>(null)

interface Group { key: string; place: string; orders: Order[]; subtotal: number }

const groups = computed<Group[]>(() => {
  const result: Group[] = []
  for (const order of orders.value) {
    if (order.order_table_id !== null) {
      const key = `t${order.order_table_id}`
      const group = result.find((g) => g.key === key)
      if (group) {
        group.orders.push(order)
        group.subtotal += order.subtotal
        continue
      }
      result.push({ key, place: order.table_name ?? '', orders: [order], subtotal: order.subtotal })
    } else {
      result.push({ key: `o${order.id}`, place: orderPlace(order), orders: [order], subtotal: order.subtotal })
    }
  }
  return result
})

function linked(order: Order): boolean {
  return register.orderIds.includes(order.id)
}

function selectable(group: Group): Order[] {
  return group.orders.filter((o) => !linked(o))
}

function groupState(group: Group): 'true' | 'false' | 'mixed' {
  const ids = selectable(group).map((o) => o.id)
  const n = ids.filter((id) => selected.value.includes(id)).length
  if (n === 0) return 'false'
  return n === ids.length ? 'true' : 'mixed'
}

function toggleGroup(group: Group): void {
  const ids = selectable(group).map((o) => o.id)
  selected.value = groupState(group) === 'true'
    ? selected.value.filter((id) => !ids.includes(id))
    : [...new Set([...selected.value, ...ids])]
}

function toggleOrder(order: Order): void {
  if (linked(order)) return
  selected.value = selected.value.includes(order.id)
    ? selected.value.filter((id) => id !== order.id)
    : [...selected.value, order.id]
}

function summary(order: Order): string {
  return order.items.map((i) => `${i.product_name} ×${i.quantity}`).join('、')
}

function timeOf(iso: string): string {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '' : `${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    orders.value = await fetchOrders('unpaid')
    emit('loaded', orders.value.length)
    const ids = new Set(orders.value.map((o) => o.id))
    selected.value = selected.value.filter((id) => ids.has(id))
    if (props.preselectTable != null) {
      selected.value = orders.value.filter((o) => o.order_table_id === props.preselectTable && !linked(o)).map((o) => o.id)
    }
    if (props.preselectOrder != null) {
      selected.value = orders.value.filter((o) => o.id === props.preselectOrder && !linked(o)).map((o) => o.id)
    }
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

watch(
  () => props.open,
  (open) => {
    if (!open) return
    selected.value = []
    addError.value = null
    void load()
  },
  { immediate: true },
)

function submit(): void {
  if (selected.value.length === 0) return
  if (register.lines.length > 0) {
    confirmAppend.value = true
    return
  }
  add()
}

function add(): void {
  confirmAppend.value = false
  const picked = orders.value.filter((o) => selected.value.includes(o.id))
  if (register.addOrders(picked)) {
    emit('close')
    return
  }
  // 入れられなかった（件数の上限など）ときはシートの中に理由を出す
  addError.value = register.notice?.text ?? null
  register.notice = null
}
</script>

<template>
  <BottomSheet
    :open="open"
    :title="t.ordersTitle"
    @close="emit('close')"
  >
    <p
      v-if="loading && orders.length === 0"
      class="pick__message"
    >
      {{ ja.common.loading }}
    </p>
    <div
      v-else-if="failed"
      class="pick__message"
      role="alert"
    >
      <p>{{ t.ordersLoadFailed }}</p>
      <BigButton
        variant="secondary"
        @click="load"
      >
        {{ t.ordersRetry }}
      </BigButton>
    </div>
    <p
      v-else-if="orders.length === 0"
      class="pick__message"
    >
      {{ t.ordersEmpty }}
    </p>
    <template v-else>
      <ul class="pick">
        <li
          v-for="group in groups"
          :key="group.key"
          class="pick__group"
          :data-group="group.key"
        >
          <button
            type="button"
            role="checkbox"
            class="pick__head"
            :aria-checked="groupState(group)"
            :aria-label="fmt(t.ordersSelect, { place: group.place })"
            :disabled="selectable(group).length === 0"
            @click="toggleGroup(group)"
          >
            <span
              class="pick__box"
              aria-hidden="true"
            >{{ groupState(group) === 'true' ? '✓' : groupState(group) === 'mixed' ? '−' : '' }}</span>
            <span>{{ fmt(t.ordersGroup, { place: group.place, n: group.orders.length, amount: formatYen(group.subtotal) }) }}</span>
          </button>
          <ul class="pick__orders">
            <li
              v-for="order in group.orders"
              :key="order.id"
            >
              <button
                type="button"
                role="checkbox"
                class="pick__order"
                :data-order="order.id"
                :aria-checked="selected.includes(order.id)"
                :disabled="linked(order)"
                @click="toggleOrder(order)"
              >
                <span
                  class="pick__box"
                  aria-hidden="true"
                >{{ selected.includes(order.id) ? '✓' : '' }}</span>
                <span class="pick__text">
                  <span class="pick__no">#{{ order.order_no }} {{ timeOf(order.created_at) }}<template v-if="linked(order)">・{{ t.ordersInCart }}</template></span>
                  <span class="pick__items">{{ summary(order) }}</span>
                </span>
                <span class="pick__amount tabular">{{ formatYen(order.subtotal) }}</span>
              </button>
            </li>
          </ul>
        </li>
      </ul>
      <p
        v-if="addError"
        class="pick__error"
        role="alert"
      >
        {{ addError }}
      </p>
      <BigButton
        size="lg"
        block
        data-pick-submit
        :disabled="selected.length === 0"
        @click="submit"
      >
        {{ fmt(t.ordersAddToCart, { n: selected.length }) }}
      </BigButton>
    </template>
  </BottomSheet>
  <ConfirmDialog
    :open="confirmAppend"
    :title="t.ordersAppendTitle"
    :message="t.ordersAppendMessage"
    :confirm-label="t.ordersAppend"
    @confirm="add"
    @cancel="confirmAppend = false"
  />
</template>

<style scoped>
.pick { display: flex; flex-direction: column; gap: 12px; margin: 0 0 16px; padding: 0; list-style: none; }
.pick__message { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 24px 0; color: var(--c-text-sub); text-align: center; }
.pick__group { border: 1px solid var(--c-border); border-radius: var(--radius-card); overflow: hidden; }
.pick__orders { margin: 0; padding: 0; list-style: none; }
.pick__head,
.pick__order {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: var(--tap-min);
  padding: 8px 12px;
  border: 0;
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 16px;
  text-align: left;
}
.pick__head { background: var(--c-surface-alt); font-size: 18px; font-weight: 700; }
.pick__order { border-top: 1px solid var(--c-border); }
.pick__head:disabled,
.pick__order:disabled { opacity: 0.6; }
.pick__box {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: 2px solid var(--c-primary);
  border-radius: 6px;
  background: var(--c-surface);
  color: var(--c-primary);
  font-weight: 800;
}
.pick__text { display: flex; flex: 1 1 auto; flex-direction: column; min-width: 0; overflow-wrap: anywhere; }
.pick__no { font-weight: 700; }
.pick__items { color: var(--c-text-sub); font-size: 14px; }
.pick__amount { flex-shrink: 0; font-weight: 700; }
.pick__error { margin: 0 0 12px; color: var(--c-danger); font-weight: 700; }
</style>
