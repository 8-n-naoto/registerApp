<script setup lang="ts">
// S14 厨房の注文カード（12 §8.3）。見出し（番号・置き場所・客 / 店・経過分）、品目ごとの［提供済］、［まとめて提供済みにする］。
// 確認ダイアログと API は親が持つ
import { computed } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
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

/** 「最初に選ぶ」のままのオプションは出さない（docs/10「オプションのグループ」）。「1つ選ぶ」の選択は目立たせる */
function kitchenOptions(item: OrderItem): OrderItem['options'] {
  return item.options.filter((o) => !o.is_default)
}
</script>

<template>
  <article
    class="kcard k-card"
    :class="{ 'kcard--new': highlighted, 'kcard--done': order.served_at !== null, 'k-card--late': late }"
    :data-order="order.id"
  >
    <header class="kcard__head k-head">
      <span class="kcard__no k-no tabular">#{{ order.order_no }}</span>
      <span class="kcard__place k-where grow clamp1">{{ orderPlace(order) }}</span>
      <span
        class="kcard__source r-chip"
        :class="order.source === 'customer' ? 'r-chip--info' : 'r-chip--neutral'"
      >{{ order.source === 'customer' ? t.fromCustomer : t.fromStaff }}</span>
      <span
        class="kcard__elapsed r-chip tabular"
        :class="[{ 'kcard__elapsed--late': late }, late ? 'r-chip--warn' : 'r-chip--neutral']"
      >
        {{ fmt(t.elapsed, { min: minutes }) }}<template v-if="late">・{{ t.late }}</template>
      </span>
    </header>

    <ul class="kcard__items">
      <li
        v-for="item in order.items"
        :key="item.id"
        class="kitem k-line"
        :class="{ 'kitem--served': item.served_at !== null, 'k-line--done': item.served_at !== null }"
        :data-item="item.id"
      >
        <div class="kitem__body grow col gap1">
          <span class="kitem__name k-line__name clamp2">{{ item.product_name }}</span>
          <span
            v-if="item.product_memo"
            class="kitem__sub sub"
          >{{ item.product_memo }}</span>
          <span
            v-if="kitchenOptions(item).length > 0"
            class="kitem__sub k-opt"
            data-options
          ><template
            v-for="(option, index) in kitchenOptions(item)"
            :key="option.product_option_id"
          ><template v-if="index > 0">・</template><em
            v-if="option.is_choice"
            class="kitem__choice"
          >{{ option.option_name }}</em><template v-else>{{ option.option_name }}</template></template></span>
          <span
            v-else-if="item.options.length > 0"
            class="kitem__sub sub"
            data-options
          >{{ t.optionsNone }}</span>
          <span
            v-if="item.memo"
            class="kitem__memo k-memo"
          >{{ fmt(ja.orders.memo, { memo: item.memo }) }}</span>
        </div>
        <span class="kitem__qty k-line__qty tabular">×{{ item.quantity }}</span>
        <button
          type="button"
          class="kitem__btn r-btn r-btn--lg k-serve"
          :class="item.served_at !== null ? 'r-btn--secondary kitem__btn--on' : 'r-btn--primary'"
          :disabled="busy"
          :aria-pressed="item.served_at !== null"
          :aria-label="fmt(item.served_at !== null ? t.markUnserved : t.markServed, { name: item.product_name })"
          @click="emit('toggle', item)"
        >
          <AppIcon
            name="check"
            :size="20"
          />
          <span>{{ item.served_at !== null ? t.servedDone : t.served }}</span>
        </button>
      </li>
    </ul>

    <p
      v-if="order.note"
      class="kcard__note k-memo"
    >
      {{ fmt(ja.orders.note, { note: order.note }) }}
    </p>

    <div
      v-if="unserved > 0"
      class="r-card__foot"
    >
      <button
        type="button"
        class="kcard__all r-btn r-btn--primary r-btn--lg r-btn--block"
        :disabled="busy"
        @click="emit('serveAll')"
      >
        <AppIcon
          name="check"
          :size="22"
        />
        <span>{{ t.serveAll }}</span>
      </button>
    </div>
  </article>
</template>

<style scoped>
.kcard { transition: box-shadow 0.3s, border-color 0.3s; }
.kcard--new { border-color: var(--c-change); box-shadow: 0 0 0 4px var(--st-warn-bg); }
.kcard__head .r-chip { flex: none; }
.kcard__items { margin: 0; padding: 0; list-style: none; }
.kitem__body { overflow-wrap: anywhere; }
.kitem__choice { font-style: normal; }
.kitem--served .kitem__choice { color: inherit; }
.kcard__note { margin: 12px 14px; border-radius: var(--radius); font-size: 18px; white-space: pre-line; overflow-wrap: anywhere; }
.kitem__btn { flex: none; }
</style>
