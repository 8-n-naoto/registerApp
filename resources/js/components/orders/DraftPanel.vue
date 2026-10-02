<script setup lang="ts">
// S13 の注文の一覧（12 §8.4）：品目（−・数量・＋・×、行をタップでメモ）、点数・小計の目安、［厨房へ送信］。
// タブレットは右の列、スマホは下から出るシートに置く
import { computed, ref } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import MoneyText from '@/components/MoneyText.vue'
import { fmt, ja } from '@/i18n/ja'
import { charCount, LINE_MEMO_MAX, useOrderDraftStore } from '@/stores/orderDraft'

// inSheet：スマホのシートに置くとき。品目を内側でスクロールさせず、シート全体でスクロールする
defineProps<{ inSheet?: boolean }>()
const emit = defineEmits<{ send: [] }>()

const t = ja.orderNew
const draft = useOrderDraftStore()

const rows = computed(() =>
  draft.lines.map((line) => {
    const product = draft.products.get(line.product_id)
    const optionPrices = line.option_ids.map((id) => product?.options.find((o) => o.id === id)?.price ?? 0)
    return {
      line,
      name: product?.name ?? '',
      productMemo: product?.memo ?? null,
      options: line.option_ids.map((id) => product?.options.find((o) => o.id === id)?.name ?? '').filter((n) => n !== ''),
      amount: product ? (product.price + optionPrices.reduce((a, b) => a + b, 0)) * line.quantity : null,
      canIncrease: product !== undefined && draft.canAddOne(product, line.key),
    }
  }),
)

/** メモの入力欄を開いている行 */
const memoKey = ref<string | null>(null)

function toggleMemo(key: string): void {
  memoKey.value = memoKey.value === key ? null : key
}

const confirmClear = ref(false)
function clear(): void {
  draft.clear()
  memoKey.value = null
  confirmClear.value = false
}
</script>

<template>
  <div
    class="draft"
    :class="{ 'draft--sheet': inSheet }"
  >
    <div class="draft__tools">
      <span class="draft__count r-sum__l">{{ fmt(t.count, { n: draft.itemCount }) }}</span>
      <button
        type="button"
        class="draft__tool r-btn r-btn--quiet"
        :disabled="draft.lines.length === 0"
        @click="confirmClear = true"
      >
        {{ t.clear }}
      </button>
    </div>

    <p
      v-if="draft.lines.length === 0"
      class="draft__empty r-empty"
    >
      {{ t.empty }}
    </p>
    <ul
      v-else
      class="draft__lines r-list"
      :aria-label="t.order"
    >
      <li
        v-for="row in rows"
        :key="row.line.key"
        class="line o-line"
      >
        <button
          type="button"
          class="line__name o-line__main"
          :aria-expanded="memoKey === row.line.key"
          @click="toggleMemo(row.line.key)"
        >
          <span class="line__title o-line__name clamp2">{{ row.name }}</span>
          <span
            v-if="row.productMemo"
            class="line__sub o-line__opt"
          >{{ row.productMemo }}</span>
          <span
            v-if="row.options.length > 0"
            class="line__sub o-line__opt"
          >{{ row.options.join('・') }}</span>
          <span
            v-if="row.line.memo"
            class="line__memo o-line__memo"
          >{{ fmt(ja.orders.memo, { memo: row.line.memo }) }}</span>
          <span
            v-else
            class="line__hint"
          >{{ t.editMemo }}</span>
        </button>
        <div class="line__qty r-qty">
          <button
            type="button"
            class="line__btn r-qty__b"
            :aria-label="fmt(ja.register.decrease, { name: row.name })"
            @click="draft.decrement(row.line.key)"
          >
            <AppIcon
              name="minus"
              :size="22"
            />
          </button>
          <span
            class="line__count r-qty__v tabular"
            :aria-label="fmt(ja.register.quantity, { n: row.line.quantity })"
          >{{ row.line.quantity }}</span>
          <button
            type="button"
            class="line__btn r-qty__b"
            :aria-label="fmt(ja.register.increase, { name: row.name })"
            :disabled="!row.canIncrease"
            @click="draft.increment(row.line.key)"
          >
            <AppIcon
              name="plus"
              :size="22"
            />
          </button>
        </div>
        <MoneyText
          v-if="row.amount !== null"
          class="line__amount o-line__amt"
          :amount="row.amount"
        />
        <button
          type="button"
          class="line__remove r-qty__b"
          :aria-label="fmt(ja.register.removeLine, { name: row.name })"
          @click="draft.removeLine(row.line.key)"
        >
          <AppIcon
            name="trash"
            :size="22"
          />
        </button>
        <div
          v-if="memoKey === row.line.key"
          class="line__memo-edit"
        >
          <label
            class="line__memo-label r-label"
            :for="`memo-${row.line.key}`"
          >{{ t.lineMemo }}</label>
          <div class="line__memo-row">
            <input
              :id="`memo-${row.line.key}`"
              class="line__input r-input"
              type="text"
              :value="row.line.memo"
              :maxlength="LINE_MEMO_MAX"
              :aria-label="fmt(t.lineMemoFor, { name: row.name })"
              enterkeyhint="done"
              @input="draft.setMemo(row.line.key, ($event.target as HTMLInputElement).value)"
              @keydown.enter.prevent="memoKey = null"
            >
            <button
              type="button"
              class="line__close r-btn r-btn--secondary"
              @click="memoKey = null"
            >
              {{ ja.common.done }}
            </button>
          </div>
          <span class="line__counter tabular">{{ charCount(row.line.memo) }} / {{ LINE_MEMO_MAX }}</span>
        </div>
      </li>
    </ul>

    <div class="draft__sum r-sum">
      <span class="r-sum__l">{{ t.subtotal }}</span>
      <MoneyText
        :amount="draft.subtotal"
        size="amount"
        tone="money"
      />
    </div>

    <BigButton
      size="xl"
      block
      :disabled="!draft.canSend"
      :loading="draft.submitting"
      @click="emit('send')"
    >
      {{ t.send }}
    </BigButton>

    <ConfirmDialog
      :open="confirmClear"
      :title="t.clearTitle"
      :confirm-label="t.clearConfirm"
      danger
      @confirm="clear"
      @cancel="confirmClear = false"
    />
  </div>
</template>

<style scoped>
.draft {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 0;
  height: 100%;
}

/* シートの中では高さを中身に合わせる（シートの高さに合わせると品目が 1 行分まで潰れ、残りの行が見えなくなる） */
.draft--sheet { flex-shrink: 0; height: auto; }
.draft--sheet .draft__lines { flex: none; min-height: 0; overflow-y: visible; }

.draft__tools { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.draft__count { font-weight: 700; }

.draft__empty { flex: 1 1 auto; }

.draft__lines { flex: 1 1 auto; min-height: 96px; overflow-y: auto; }

.line { align-items: center; }
.line__name { min-height: var(--tap-min); padding: 0; border: 0; background: transparent; color: var(--c-text); font: inherit; text-align: left; overflow-wrap: anywhere; cursor: pointer; }
.line__hint { color: var(--c-primary-ink); font-size: 14px; text-decoration: underline; }
.line__count { text-align: center; }
.line__amount { flex: none; }
.line__remove { border-color: transparent; background: transparent; color: var(--c-text-sub); }

.line__memo-edit { display: flex; flex: 1 1 100%; flex-direction: column; gap: 4px; }
.line__memo-row { display: flex; gap: 8px; }
.line__input { flex: 1 1 auto; font-size: 16px; }
.line__close { flex: none; }
.line__counter { align-self: flex-end; color: var(--c-text-sub); font-size: 14px; }

.draft__sum { padding-top: 4px; }
</style>
