<script setup lang="ts">
// 注文 1 件の表示（12 §8.5）：番号・置き場所・時刻・状態・小計の目安、品目（数量・オプション・メモ）、注文メモ。
// 操作のボタンは呼び出し側がスロットで置く
import { computed } from 'vue'
import MoneyText from '@/components/MoneyText.vue'
import { fmt, ja } from '@/i18n/ja'
import { formatTime } from '@/lib/date'
import { orderDisplayStatus, orderPlace } from '@/lib/orders'
import type { Order } from '@/types/api'

const props = defineProps<{ order: Order }>()

const t = ja.orders
const status = computed(() => orderDisplayStatus(props.order))
const who = computed(() => (props.order.source === 'customer' ? t.fromCustomer : (props.order.user_name ?? '')))
</script>

<template>
  <article
    class="card"
    :class="`card--${status}`"
    :data-order="order.id"
  >
    <header class="card__head">
      <span class="card__no tabular">#{{ order.order_no }}</span>
      <span class="card__place">{{ orderPlace(order) }}</span>
      <span class="card__status">{{ t.status[status] }}</span>
      <span class="card__meta">{{ formatTime(order.created_at) }}<template v-if="who"> ・{{ who }}</template></span>
      <MoneyText
        class="card__amount"
        :amount="order.subtotal"
      />
    </header>
    <ul class="card__items">
      <li
        v-for="item in order.items"
        :key="item.id"
        class="item"
        :class="{ 'item--served': item.served_at !== null }"
      >
        <span class="item__qty tabular">{{ item.quantity }}</span>
        <span class="item__body">
          <span class="item__name">{{ item.product_name }}</span>
          <span
            v-if="item.options.length > 0"
            class="item__sub"
          >{{ item.options.map((o) => o.option_name).join('・') }}</span>
          <span
            v-if="item.memo"
            class="item__memo"
          >{{ fmt(t.memo, { memo: item.memo }) }}</span>
        </span>
      </li>
    </ul>
    <p
      v-if="order.note"
      class="card__note"
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
.card {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 16px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
}
.card--pending { border-color: var(--c-change); }
.card--cancelled,
.card--paid { background: var(--c-surface-alt); }

.card__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; }
.card__no { font-size: 22px; font-weight: 800; }
.card__place { font-size: 20px; font-weight: 700; }
.card__status {
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--c-surface-alt);
  color: var(--c-text);
  font-size: 14px;
  font-weight: 700;
}
.card--pending .card__status { background: #FEF3C7; color: #92400E; }
.card--active .card__status { background: #DBEAFE; color: var(--c-primary-press); }
.card--served .card__status { background: #DCFCE7; color: var(--c-success); }
.card--cancelled .card__status { background: #FEE2E2; color: var(--c-danger); }
.card__meta { color: var(--c-text-sub); font-size: 16px; }
.card__amount { margin-left: auto; font-weight: 700; }

.card__items { display: flex; flex-direction: column; gap: 4px; margin: 0; padding: 0; list-style: none; }
.item { display: flex; gap: 12px; font-size: 18px; }
.item--served { color: var(--c-text-sub); }
.item__qty { min-width: 2em; font-weight: 800; text-align: right; }
.item__body { display: flex; flex-direction: column; min-width: 0; overflow-wrap: anywhere; }
.item__name { font-weight: 700; }
.item__sub { color: var(--c-text-sub); font-size: 16px; }
.item__memo { color: var(--c-change); font-size: 16px; font-weight: 700; }

.card__note { margin: 0; padding: 8px; border-radius: var(--radius); background: #FEF3C7; font-size: 16px; white-space: pre-line; overflow-wrap: anywhere; }

.card__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
</style>
