<script setup lang="ts">
// S14 厨房の注文カード（12 §8.3）。見出し（番号・置き場所・客 / 店・経過分）、品目ごとの［提供済］、［まとめて提供済みにする］。
// 確認ダイアログと API は親が持つ
import { computed } from 'vue'
import { fmt, ja } from '@/i18n/ja'
import { orderPlace } from '@/lib/orders'
import type { Order, OrderItem } from '@/types/api'

const LATE_MINUTES = 10 // 12 §8.3 これを超えたら遅延の色

const props = defineProps<{
  order: Order
  /** サーバー時刻で補正した現在時刻（ミリ秒） */
  now: number
  busy: boolean
  highlighted: boolean
}>()

const emit = defineEmits<{ toggle: [item: OrderItem]; serveAll: [] }>()

const t = ja.kitchen

const minutes = computed(() => Math.max(0, Math.floor((props.now - Date.parse(props.order.created_at)) / 60000)))
const late = computed(() => props.order.served_at === null && minutes.value > LATE_MINUTES)
const unserved = computed(() => props.order.items.filter((i) => i.served_at === null).length)
</script>

<template>
  <article
    class="kcard"
    :class="{ 'kcard--new': highlighted, 'kcard--done': order.served_at !== null }"
    :data-order="order.id"
  >
    <header class="kcard__head">
      <span class="kcard__no tabular">#{{ order.order_no }}</span>
      <span class="kcard__place">{{ orderPlace(order) }}</span>
      <span
        class="kcard__source"
        :class="`kcard__source--${order.source}`"
      >{{ order.source === 'customer' ? t.fromCustomer : t.fromStaff }}</span>
      <span
        class="kcard__elapsed tabular"
        :class="{ 'kcard__elapsed--late': late }"
      >
        {{ fmt(t.elapsed, { min: minutes }) }}<template v-if="late">・{{ t.late }}</template>
      </span>
    </header>

    <ul class="kcard__items">
      <li
        v-for="item in order.items"
        :key="item.id"
        class="kitem"
        :class="{ 'kitem--served': item.served_at !== null }"
        :data-item="item.id"
      >
        <div class="kitem__body">
          <span class="kitem__name">{{ item.product_name }}</span>
          <span
            v-if="item.product_memo"
            class="kitem__sub"
          >{{ item.product_memo }}</span>
          <span
            v-if="item.options.length > 0"
            class="kitem__sub"
          >{{ item.options.map((o) => o.option_name).join('・') }}</span>
          <span
            v-if="item.memo"
            class="kitem__memo"
          >{{ fmt(ja.orders.memo, { memo: item.memo }) }}</span>
        </div>
        <span class="kitem__qty tabular">×{{ item.quantity }}</span>
        <button
          type="button"
          class="kitem__btn"
          :class="{ 'kitem__btn--on': item.served_at !== null }"
          :disabled="busy"
          :aria-pressed="item.served_at !== null"
          :aria-label="fmt(item.served_at !== null ? t.markUnserved : t.markServed, { name: item.product_name })"
          @click="emit('toggle', item)"
        >
          {{ item.served_at !== null ? t.servedDone : t.served }}
        </button>
      </li>
    </ul>

    <p
      v-if="order.note"
      class="kcard__note"
    >
      {{ fmt(ja.orders.note, { note: order.note }) }}
    </p>

    <button
      v-if="unserved > 0"
      type="button"
      class="kcard__all"
      :disabled="busy"
      @click="emit('serveAll')"
    >
      {{ t.serveAll }}
    </button>
  </article>
</template>

<style scoped>
.kcard {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 16px 16px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius-card);
  background: var(--c-surface);
  transition: box-shadow 0.3s, border-color 0.3s;
}
.kcard--new { border-color: var(--c-change); box-shadow: 0 0 0 4px #FDE68A; }
.kcard--done { background: var(--c-surface-alt); }

.kcard__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; padding-bottom: 8px; border-bottom: 1px solid var(--c-border); }
.kcard__no { font-size: 28px; font-weight: 800; }
.kcard__place { font-size: 22px; font-weight: 700; overflow-wrap: anywhere; }
.kcard__source {
  padding: 2px 8px;
  border: 2px solid currentColor;
  border-radius: 6px;
  font-size: 16px;
  font-weight: 800;
}
.kcard__source--customer { color: var(--c-primary); }
.kcard__source--staff { color: var(--c-text-sub); }
.kcard__elapsed { margin-left: auto; color: var(--c-text-sub); font-size: 18px; font-weight: 700; }
.kcard__elapsed--late { color: var(--c-change); }

.kcard__items { display: flex; flex-direction: column; margin: 0; padding: 0; list-style: none; }
.kitem {
  display: grid;
  grid-template-columns: 1fr auto auto;
  align-items: center;
  gap: 8px 12px;
  padding: 8px 0;
  border-bottom: 1px solid var(--c-border);
}
.kitem__body { display: flex; flex-direction: column; min-width: 0; overflow-wrap: anywhere; }
.kitem__name { font-size: 20px; font-weight: 700; }
.kitem__sub { color: var(--c-text-sub); font-size: 16px; }
.kitem__memo { color: var(--c-change); font-size: 18px; font-weight: 800; }
.kitem__qty { font-size: 24px; font-weight: 800; }
.kitem__btn {
  min-width: 120px;
  min-height: 56px;
  padding: 0 12px;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-primary);
  font-size: 18px;
  font-weight: 800;
}
.kitem__btn--on { border-color: var(--c-success); background: var(--c-success); color: var(--c-on-primary); }
.kitem__btn:disabled { opacity: 0.5; }
.kitem--served .kitem__name,
.kitem--served .kitem__qty { color: var(--c-soldout); text-decoration: line-through; }
.kitem--served .kitem__sub,
.kitem--served .kitem__memo { color: var(--c-soldout); }

.kcard__note { margin: 0; padding: 8px; border-radius: var(--radius); background: #FEF3C7; font-size: 18px; white-space: pre-line; overflow-wrap: anywhere; }

.kcard__all {
  width: 100%;
  min-height: 56px;
  border: 0;
  border-radius: var(--radius);
  background: var(--c-primary);
  color: var(--c-on-primary);
  font-size: 18px;
  font-weight: 800;
}
.kcard__all:active { background: var(--c-primary-press); }
.kcard__all:disabled { opacity: 0.5; }
</style>
