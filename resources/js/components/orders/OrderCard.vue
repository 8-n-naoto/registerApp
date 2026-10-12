<script setup lang="ts">
// 注文 1 件の表示（12 §8.5）：番号・置き場所・時刻・状態・小計の目安、品目（数量・オプション・メモ）、注文メモ。
// 操作のボタンは呼び出し側がスロットで置く。
// 17 §8：取消済みの品目は取り消し線と「取消」の札。cancellable のときは取り消せる品目の行をボタンにし、押すと cancelItem を出す
import { computed } from 'vue'
import MoneyText from '@/components/MoneyText.vue'
import { fmt, ja } from '@/i18n/ja'
import { formatTime } from '@/lib/date'
import { canCancelItem, isItemCancelled, orderDisplayStatus, orderPlace } from '@/lib/orders'
import type { Order, OrderItem } from '@/types/api'

const props = defineProps<{ order: Order; cancellable?: boolean }>()
const emit = defineEmits<{ cancelItem: [item: OrderItem] }>()

const t = ja.orders
const STATUS_CHIP: Record<string, string> = {
  pending: 'r-chip--warn',
  active: 'r-chip--info',
  served: 'r-chip--ok',
  paid: 'r-chip--neutral',
  cancelled: 'r-chip--danger',
}
const status = computed(() => orderDisplayStatus(props.order))
function tappable(item: OrderItem): boolean {
  return props.cancellable === true && canCancelItem(props.order, item)
}
function onItem(item: OrderItem): void {
  if (tappable(item)) emit('cancelItem', item)
}
const who = computed(() => (props.order.source === 'customer' ? t.fromCustomer : (props.order.user_name ?? '')))
</script>

<template>
  <article
    class="card r-card"
    :class="`card--${status}`"
    :data-order="order.id"
  >
    <header class="card__head">
      <span class="card__no tabular">#{{ order.order_no }}</span>
      <span class="card__place clamp1 grow">{{ orderPlace(order) }}</span>
      <span
        class="card__status r-chip"
        :class="STATUS_CHIP[status]"
      >{{ t.status[status] }}</span>
      <span class="card__meta">{{ formatTime(order.created_at) }}<template v-if="who"> ・{{ who }}</template></span>
      <MoneyText
        class="card__amount num"
        :amount="order.subtotal"
      />
    </header>
    <ul class="card__items">
      <li
        v-for="item in order.items"
        :key="item.id"
      >
        <component
          :is="tappable(item) ? 'button' : 'div'"
          :type="tappable(item) ? 'button' : undefined"
          class="item"
          :class="{
            'item--served': item.served_at !== null,
            'item--cancelled': isItemCancelled(item),
            'item--button': tappable(item),
          }"
          :aria-label="tappable(item) ? fmt(t.itemCancel.open, { name: item.product_name }) : undefined"
          :data-item="item.id"
          @click="onItem(item)"
        >
          <span class="item__qty tabular">{{ item.quantity }}</span>
          <span class="item__body">
            <span class="item__name clamp2">{{ item.product_name }}</span>
            <span
              v-if="item.options.length > 0"
              class="item__sub sub"
            >{{ item.options.map((o) => o.option_name).join('・') }}</span>
            <span
              v-if="item.memo"
              class="item__memo"
            >{{ fmt(t.memo, { memo: item.memo }) }}</span>
          </span>
          <span
            v-if="isItemCancelled(item)"
            class="item__chip r-chip r-chip--danger"
          >{{ t.itemCancelled }}</span>
        </component>
      </li>
    </ul>
    <p
      v-if="order.note"
      class="card__note k-memo"
    >
      {{ fmt(t.note, { note: order.note }) }}
    </p>
    <div
      v-if="$slots.default"
      class="card__actions"
    >
      <slot />
    </div>
  </article>
</template>

<style scoped>
.card { display: flex; flex-direction: column; gap: 8px; padding: 12px 16px; }
.card--pending { border: 2px solid var(--c-change); }
.card--cancelled,
.card--paid { background: var(--c-surface-alt); }

.card__head { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 12px; }
.card__no { font-size: 22px; font-weight: 800; }
.card__place { font-size: 20px; font-weight: 700; }
.card__status { flex: none; }
.card__meta { color: var(--c-text-sub); font-size: 16px; }
.card__amount { margin-left: auto; font-weight: 800; white-space: nowrap; }

.card__items { display: flex; flex-direction: column; gap: 4px; margin: 0; padding: 0; list-style: none; }
.item { display: flex; gap: 12px; font-size: 18px; }
.item--button { width: 100%; min-height: 56px; align-items: center; padding: 4px 8px; border: 1px solid var(--c-border); border-radius: var(--radius); background: var(--c-surface); color: var(--c-text); font-family: inherit; line-height: inherit; text-align: left; cursor: pointer; }
.item--served,
.item--cancelled { color: var(--c-text-sub); }
.item--cancelled .item__qty,
.item--cancelled .item__name { text-decoration: line-through; }
.item__chip { flex: none; margin-left: auto; align-self: center; }
.item__qty { min-width: 2em; font-weight: 800; text-align: right; }
.item__body { display: flex; flex-direction: column; min-width: 0; overflow-wrap: anywhere; }
.item__name { font-weight: 700; }
.item__sub { font-size: 16px; }
.item__memo { color: var(--st-warn-fg); font-size: 16px; font-weight: 700; }

.card__note { margin: 0; border-radius: var(--radius); font-size: 16px; white-space: pre-line; overflow-wrap: anywhere; }

.card__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
</style>
