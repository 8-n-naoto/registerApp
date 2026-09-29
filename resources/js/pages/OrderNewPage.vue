<script setup lang="ts">
// S13 注文入力（店員、12 §8.4）。商品エリアは S02 と同じ部品。タブレット横は右に注文、スマホは下の固定バーからシートで開く。
// 商品のタップから小計の表示までは通信しない。［厨房へ送信］の前のシートで注文メモを入れる
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import BigButton from '@/components/BigButton.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import MoneyText from '@/components/MoneyText.vue'
import DraftPanel from '@/components/orders/DraftPanel.vue'
import OptionPicker from '@/components/register/OptionPicker.vue'
import ProductArea from '@/components/register/ProductArea.vue'
import { fmt, ja } from '@/i18n/ja'
import { useIsTablet } from '@/lib/breakpoint'
import { charCount, LABEL_MAX, NOTE_MAX, useOrderDraftStore } from '@/stores/orderDraft'
import type { Product } from '@/types/api'

const SENT_MS = 3000

const t = ja.orderNew
const route = useRoute()
const draft = useOrderDraftStore()
const isTablet = useIsTablet()

type Notice = { kind: 'error' | 'info'; text: string }
const notice = ref<Notice | null>(null)
let noticeTimer: ReturnType<typeof setTimeout> | null = null

function showNotice(value: Notice | null, ms: number | null = null): void {
  if (noticeTimer !== null) clearTimeout(noticeTimer)
  noticeTimer = null
  notice.value = value
  if (value !== null && ms !== null) noticeTimer = setTimeout(() => { notice.value = null }, ms)
}

onMounted(async () => {
  const removed = await draft.load()
  if (removed.length > 0) showNotice({ kind: 'error', text: fmt(ja.register.removedItems, { names: removed.join('、') }) })
  // ?table=<id>：S15 の［注文を追加］から来たときはそのテーブルを選ぶ
  const id = Number(route.query.table)
  if (Number.isSafeInteger(id) && draft.activeTables.some((table) => table.id === id)) draft.setTable(id)
})

onBeforeUnmount(() => {
  if (noticeTimer !== null) clearTimeout(noticeTimer)
})

// ── テーブル
const tableValue = computed(() => (draft.tableId === null ? '' : String(draft.tableId)))

function onTableChange(e: Event): void {
  const value = (e.target as HTMLSelectElement).value
  draft.setTable(value === '' ? null : Number(value))
}

const destination = computed(() => {
  const table = draft.tables.find((x) => x.id === draft.tableId)
  if (table) return table.name
  const label = draft.label.trim()
  return label === '' ? t.noTable : `${t.noTable}（${label}）`
})

// ── 商品
const picking = ref<Product | null>(null)

function canPress(product: Product): boolean {
  return draft.canAddOne(product)
}

function addProduct(product: Product, optionIds: number[] = []): void {
  const error = draft.add(product, optionIds)
  if (error !== null) showNotice({ kind: 'error', text: error })
}

function press(product: Product): void {
  if (product.options.length > 0) picking.value = product
  else addProduct(product)
}

function addWithOptions(optionIds: number[]): void {
  if (picking.value) addProduct(picking.value, optionIds)
  picking.value = null
}

// ── 送信
const orderSheet = ref(false)
const sendSheet = ref(false)

function openSend(): void {
  if (!draft.canSend) return
  orderSheet.value = false
  sendSheet.value = true
}

async function send(): Promise<void> {
  const outcome = await draft.send()
  sendSheet.value = false
  if (outcome.ok) showNotice({ kind: 'info', text: fmt(t.sent, { no: outcome.order.order_no }) }, SENT_MS)
  else showNotice({ kind: 'error', text: outcome.message })
}
</script>

<template>
  <div
    class="order-new"
    :class="{ 'order-new--tablet': isTablet }"
  >
    <header class="order-new__top">
      <RouterLink
        :to="{ name: 'home' }"
        class="order-new__home"
      >
        <span aria-hidden="true">←</span> {{ ja.common.home }}
      </RouterLink>
      <h1 class="order-new__title">
        {{ t.title }}
      </h1>
    </header>

    <div
      v-if="draft.bootstrap"
      class="order-new__dest"
    >
      <label
        class="order-new__field"
        for="order-table"
      >
        <span class="order-new__label">{{ t.table }}</span>
        <select
          id="order-table"
          class="order-new__select"
          :value="tableValue"
          @change="onTableChange"
        >
          <option value="">{{ t.noTable }}</option>
          <option
            v-for="table in draft.activeTables"
            :key="table.id"
            :value="String(table.id)"
          >
            {{ table.name }}{{ table.opened_at ? t.inUse : '' }}
          </option>
        </select>
      </label>
      <label
        v-if="draft.tableId === null"
        class="order-new__field order-new__field--grow"
        for="order-label"
      >
        <span class="order-new__label">{{ t.label }}</span>
        <input
          id="order-label"
          class="order-new__input"
          type="text"
          :value="draft.label"
          :maxlength="LABEL_MAX"
          :placeholder="t.labelPlaceholder"
          @input="draft.setLabel(($event.target as HTMLInputElement).value)"
        >
      </label>
    </div>

    <div
      v-if="notice"
      class="order-new__notice"
      :class="`order-new__notice--${notice.kind}`"
      :role="notice.kind === 'error' ? 'alert' : 'status'"
    >
      <span>{{ notice.text }}</span>
      <button
        type="button"
        class="order-new__notice-close"
        @click="showNotice(null)"
      >
        {{ ja.common.close }}
      </button>
    </div>
    <p
      v-if="!draft.storageOk"
      class="order-new__notice order-new__notice--error"
    >
      {{ t.storageUnavailable }}
    </p>

    <p
      v-if="draft.loading && !draft.bootstrap"
      class="order-new__message"
    >
      {{ ja.common.loading }}
    </p>
    <div
      v-else-if="draft.loadError && !draft.bootstrap"
      class="order-new__message"
      role="alert"
    >
      <p>{{ draft.loadError }}</p>
      <BigButton @click="draft.load()">
        {{ t.retry }}
      </BigButton>
    </div>

    <div
      v-else-if="draft.bootstrap"
      class="order-new__body"
    >
      <ProductArea
        :products="draft.bootstrap.products"
        :categories="draft.bootstrap.categories"
        :can-press="canPress"
        :tablet="isTablet"
        :label="t.title"
        @press="press"
      />
      <aside
        v-if="isTablet"
        class="order-new__order"
      >
        <DraftPanel @send="openSend" />
      </aside>
    </div>

    <div
      v-if="!isTablet && draft.bootstrap"
      class="bar"
    >
      <button
        type="button"
        class="bar__summary"
        @click="orderSheet = true"
      >
        <span class="bar__label">{{ t.subtotal }}・{{ fmt(t.count, { n: draft.itemCount }) }}</span>
        <MoneyText
          :amount="draft.subtotal"
          size="amount"
          tone="inherit"
        />
        <span class="bar__view">{{ t.viewOrder }}</span>
      </button>
      <BigButton
        size="lg"
        :disabled="!draft.canSend"
        :loading="draft.submitting"
        @click="openSend"
      >
        {{ t.send }}
      </BigButton>
    </div>

    <BottomSheet
      :open="orderSheet"
      :title="t.order"
      @close="orderSheet = false"
    >
      <DraftPanel @send="openSend" />
    </BottomSheet>

    <BottomSheet
      :open="sendSheet"
      :title="t.sendTitle"
      @close="sendSheet = false"
    >
      <div class="send">
        <p class="send__dest">
          {{ fmt(t.sendTo, { table: destination }) }}
        </p>
        <p class="send__sum">
          <span>{{ fmt(t.count, { n: draft.itemCount }) }}・{{ t.subtotal }}</span>
          <MoneyText
            :amount="draft.subtotal"
            size="amount"
            tone="money"
          />
        </p>
        <label
          class="send__label"
          for="order-note"
        >{{ t.note }}</label>
        <textarea
          id="order-note"
          class="send__note"
          rows="3"
          :value="draft.note"
          :maxlength="NOTE_MAX"
          :placeholder="t.notePlaceholder"
          @input="draft.setNote(($event.target as HTMLTextAreaElement).value)"
        />
        <span class="send__counter tabular">{{ charCount(draft.note) }} / {{ NOTE_MAX }}</span>
        <BigButton
          size="xl"
          block
          :disabled="!draft.canSend"
          :loading="draft.submitting"
          @click="send"
        >
          {{ t.send }}
        </BigButton>
      </div>
    </BottomSheet>

    <OptionPicker
      :product="picking"
      @add="addWithOptions"
      @cancel="picking = null"
    />
  </div>
</template>

<style scoped>
.order-new {
  display: flex;
  flex-direction: column;
  min-height: 100dvh;
  padding: var(--safe-top) var(--safe-right) 0 var(--safe-left);
  background: var(--c-surface-alt);
  color: var(--c-text);
}

.order-new__top {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 12px;
  background: var(--c-primary);
  color: var(--c-on-primary);
}

.order-new__home {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  gap: 4px;
  min-height: var(--tap-min);
  padding: 0 12px;
  color: var(--c-on-primary);
  font-weight: 700;
  text-decoration: none;
}

.order-new__title { margin: 0; font-size: 20px; }

.order-new__dest {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 8px 12px;
  padding: 8px 12px;
  border-bottom: 1px solid var(--c-border);
  background: var(--c-surface);
}
.order-new__field { display: flex; flex-direction: column; gap: 2px; }
.order-new__field--grow { flex: 1 1 200px; }
.order-new__label { color: var(--c-text-sub); font-size: 14px; font-weight: 700; }
.order-new__select,
.order-new__input {
  min-height: var(--tap-min);
  padding: 0 12px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 18px;
}
.order-new__select { min-width: 200px; font-weight: 700; }

.order-new__notice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 4px 12px;
  font-weight: 700;
  white-space: pre-line;
}
.order-new__notice--error { background: #FEE2E2; color: var(--c-danger); }
.order-new__notice--info { background: #DCFCE7; color: var(--c-success); font-size: 20px; }
.order-new__notice-close {
  flex-shrink: 0;
  min-width: var(--tap-min);
  min-height: var(--tap-min);
  border: 0;
  background: transparent;
  color: inherit;
  font-weight: 700;
}

.order-new__message { display: flex; flex-direction: column; align-items: center; gap: 16px; padding: 48px 16px; text-align: center; }

.order-new__body { display: flex; flex: 1 1 auto; min-height: 0; }

/* タブレット：画面の高さに収め、商品と注文の列をそれぞれスクロールする */
.order-new--tablet { height: 100dvh; }
.order-new--tablet .order-new__body { overflow: hidden; }
.order-new__order {
  flex: 0 0 38%;
  min-width: 0;
  overflow-y: auto;
  padding: 12px 12px calc(12px + var(--safe-bottom));
  border-left: 1px solid var(--c-border);
  background: var(--c-surface);
}

/* スマホ：小計と送信のバーを画面の下に固定する */
.bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 50;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px calc(12px + var(--safe-right)) calc(8px + var(--safe-bottom)) calc(12px + var(--safe-left));
  background: var(--c-money);
  color: var(--c-on-primary);
  box-shadow: 0 -2px 12px rgba(0, 0, 0, 0.2);
}
.bar__summary {
  display: grid;
  flex: 1 1 auto;
  grid-template-columns: 1fr auto;
  align-items: center;
  min-height: var(--tap-min);
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
}
.bar__label { font-size: 14px; }
.bar__view { grid-column: 1 / -1; font-size: 14px; text-decoration: underline; }

.send { display: flex; flex-direction: column; gap: 8px; }
.send__dest { margin: 0; font-size: 20px; font-weight: 700; }
.send__sum { display: flex; align-items: center; justify-content: space-between; margin: 0; font-weight: 700; }
.send__label { color: var(--c-text-sub); font-size: 14px; font-weight: 700; }
.send__note {
  min-height: 96px;
  padding: 8px 12px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  font: inherit;
  font-size: 16px;
  resize: vertical;
}
.send__counter { align-self: flex-end; color: var(--c-text-sub); font-size: 14px; }
</style>
