<script setup lang="ts">
// S13 の注文の一覧（12 §8.4）：品目（−・数量・＋・×、行をタップでメモ）、点数・小計の目安、［厨房へ送信］。
// タブレットは右の列、スマホは下から出るシートに置く
import { computed, ref } from 'vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import MoneyText from '@/components/MoneyText.vue'
import { fmt, ja } from '@/i18n/ja'
import { charCount, LINE_MEMO_MAX, useOrderDraftStore } from '@/stores/orderDraft'

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
  <div class="draft">
    <div class="draft__tools">
      <span class="draft__count">{{ fmt(t.count, { n: draft.itemCount }) }}</span>
      <button
        type="button"
        class="draft__tool"
        :disabled="draft.lines.length === 0"
        @click="confirmClear = true"
      >
        {{ t.clear }}
      </button>
    </div>

    <p
      v-if="draft.lines.length === 0"
      class="draft__empty"
    >
      {{ t.empty }}
    </p>
    <ul
      v-else
      class="draft__lines"
      :aria-label="t.order"
    >
      <li
        v-for="row in rows"
        :key="row.line.key"
        class="line"
      >
        <button
          type="button"
          class="line__name"
          :aria-expanded="memoKey === row.line.key"
          @click="toggleMemo(row.line.key)"
        >
          <span class="line__title">{{ row.name }}</span>
          <span
            v-if="row.productMemo"
            class="line__sub"
          >{{ row.productMemo }}</span>
          <span
            v-if="row.options.length > 0"
            class="line__sub"
          >{{ row.options.join('・') }}</span>
          <span
            v-if="row.line.memo"
            class="line__memo"
          >{{ fmt(ja.orders.memo, { memo: row.line.memo }) }}</span>
          <span
            v-else
            class="line__hint"
          >{{ t.editMemo }}</span>
        </button>
        <div class="line__qty">
          <button
            type="button"
            class="line__btn"
            :aria-label="fmt(ja.register.decrease, { name: row.name })"
            @click="draft.decrement(row.line.key)"
          >
            −
          </button>
          <span
            class="line__count tabular"
            :aria-label="fmt(ja.register.quantity, { n: row.line.quantity })"
          >{{ row.line.quantity }}</span>
          <button
            type="button"
            class="line__btn"
            :aria-label="fmt(ja.register.increase, { name: row.name })"
            :disabled="!row.canIncrease"
            @click="draft.increment(row.line.key)"
          >
            ＋
          </button>
        </div>
        <MoneyText
          v-if="row.amount !== null"
          class="line__amount"
          :amount="row.amount"
        />
        <button
          type="button"
          class="line__remove"
          :aria-label="fmt(ja.register.removeLine, { name: row.name })"
          @click="draft.removeLine(row.line.key)"
        >
          ×
        </button>
        <div
          v-if="memoKey === row.line.key"
          class="line__memo-edit"
        >
          <label
            class="line__memo-label"
            :for="`memo-${row.line.key}`"
          >{{ t.lineMemo }}</label>
          <div class="line__memo-row">
            <input
              :id="`memo-${row.line.key}`"
              class="line__input"
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
              class="line__close"
              @click="memoKey = null"
            >
              {{ ja.common.done }}
            </button>
          </div>
          <span class="line__counter tabular">{{ charCount(row.line.memo) }} / {{ LINE_MEMO_MAX }}</span>
        </div>
      </li>
    </ul>

    <div class="draft__sum">
      <span>{{ t.subtotal }}</span>
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

.draft__tools { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.draft__count { font-weight: 700; }
.draft__tool {
  min-height: var(--tap-min);
  padding: 0 16px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text-sub);
  font-weight: 700;
}
.draft__tool:disabled { opacity: 0.5; }

.draft__empty { flex: 1 1 auto; padding: 24px 8px; color: var(--c-text-sub); text-align: center; }

.draft__lines {
  flex: 1 1 auto;
  min-height: 96px;
  overflow-y: auto;
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--c-border);
}

.line {
  display: grid;
  grid-template-columns: auto 1fr auto;
  grid-template-areas: "name name name" "qty amount remove" "memo memo memo";
  align-items: center;
  gap: 4px 8px;
  padding: 8px 0;
  border-bottom: 1px solid var(--c-border);
  font-size: var(--fs-order-line);
}

.line__name {
  display: flex;
  flex-direction: column;
  grid-area: name;
  min-height: var(--tap-min);
  min-width: 0;
  padding: 4px 0;
  border: 0;
  background: transparent;
  color: var(--c-text);
  font-size: inherit;
  text-align: left;
  overflow-wrap: anywhere;
}
.line__title { font-weight: 700; }
.line__sub { color: var(--c-text-sub); font-size: 14px; }
.line__memo { color: var(--c-change); font-size: 16px; font-weight: 700; }
.line__hint { color: var(--c-primary); font-size: 14px; text-decoration: underline; }

.line__qty { display: flex; grid-area: qty; align-items: center; gap: 4px; }
.line__count { min-width: 2.5em; text-align: center; font-weight: 700; }
.line__btn,
.line__remove {
  width: var(--qty-btn);
  height: var(--qty-btn);
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface-alt);
  font-size: 24px;
  font-weight: 700;
}
.line__btn:disabled { opacity: 0.4; }
.line__amount { grid-area: amount; justify-self: end; font-weight: 700; }
.line__remove { grid-area: remove; border-color: transparent; background: transparent; color: var(--c-text-sub); }

.line__memo-edit { display: flex; flex-direction: column; grid-area: memo; gap: 4px; }
.line__memo-label { font-size: 14px; color: var(--c-text-sub); }
.line__memo-row { display: flex; gap: 8px; }
.line__input {
  flex: 1 1 auto;
  min-width: 0;
  min-height: var(--tap-min);
  padding: 0 12px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  font-size: 16px;
}
.line__close {
  min-width: var(--tap-min);
  min-height: var(--tap-min);
  padding: 0 12px;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-primary);
  font-weight: 700;
}
.line__counter { align-self: flex-end; color: var(--c-text-sub); font-size: 14px; }

.draft__sum {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 4px;
  border-top: 2px solid var(--c-text);
  font-size: 18px;
  font-weight: 700;
}
</style>
