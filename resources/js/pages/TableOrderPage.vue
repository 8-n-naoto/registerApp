<script setup lang="ts">
// C01 お客さんの注文（12 §8.9）。QR のトークンだけで開き、ログイン情報を使わない（ガードで GET /me を呼ばない）。
// 店舗名・テーブル名を常に上に出し、商品 → シート（オプション・数量・メモ）→ カート → 確認 → 送信
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import BigButton from '@/components/BigButton.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { fmt, ja } from '@/i18n/ja'
import { formatTime } from '@/lib/date'
import { formatYen } from '@/lib/money'
import { LINE_MEMO_MAX, NOTE_MAX, TOTAL_QUANTITY_MAX, useTableOrderStore } from '@/stores/tableOrder'
import type { PublicMenuProduct } from '@/types/api'

const OTHER = -1 // 分類なしの商品のタブ

const t = ja.tableOrder
const route = useRoute()
const store = useTableOrderStore()

const category = ref<number | null>(null)
const notice = ref<string | null>(null)
const sendError = ref<string | null>(null)

// 商品のシート
const picking = ref<PublicMenuProduct | null>(null)
const pickOptions = ref<number[]>([])
const pickQuantity = ref(1)
const pickMemo = ref('')
const pickError = ref<string | null>(null)

const cartOpen = ref(false)
const confirmOpen = ref(false)
const historyOpen = ref(false)

const menu = computed(() => store.menu)

const tabs = computed(() => {
  const m = menu.value
  if (!m) return []
  const list = m.categories.filter((c) => m.products.some((p) => p.category_id === c.id))
  if (m.products.some((p) => p.category_id === null || !list.some((c) => c.id === p.category_id))) {
    list.push({ id: OTHER, name: t.categoryOther })
  }
  return list
})

const visibleProducts = computed(() => {
  const m = menu.value
  if (!m) return []
  if (tabs.value.length <= 1 || category.value === null) return m.products
  const known = new Set(m.categories.map((c) => c.id))
  return m.products.filter((p) => (category.value === OTHER
    ? p.category_id === null || !known.has(p.category_id)
    : p.category_id === category.value))
})

watch(tabs, (list) => {
  if (!list.some((c) => c.id === category.value)) category.value = list[0]?.id ?? null
})

const notAcceptingMessage = computed(() => {
  const m = menu.value
  if (!m || m.accepting) return null
  return m.not_accepting_reason === 'disabled' ? t.disabled : t.notAccepting
})

const pickPrice = computed(() => {
  const p = picking.value
  if (!p) return 0
  return (p.price + pickOptions.value.reduce((sum, id) => sum + (p.options.find((o) => o.id === id)?.price ?? 0), 0)) * pickQuantity.value
})

const cartLines = computed(() => store.lines.map((l) => {
  const p = store.products.get(l.product_id)
  return {
    line: l,
    name: p?.name ?? '',
    options: l.option_ids.map((id) => p?.options.find((o) => o.id === id)?.name ?? '').filter((n) => n !== ''),
    amount: store.linePrice(l) * l.quantity,
  }
}))

const priceSuffix = computed(() => (menu.value?.price_mode === 'tax_excluded' ? t.taxExcluded : ''))

onMounted(async () => {
  const token = typeof route.params.token === 'string' ? route.params.token : ''
  await store.open(token)
})

function openProduct(product: PublicMenuProduct): void {
  if (product.sold_out) return
  picking.value = product
  pickOptions.value = []
  pickQuantity.value = 1
  pickMemo.value = ''
  pickError.value = null
}

function toggleOption(id: number): void {
  pickOptions.value = pickOptions.value.includes(id) ? pickOptions.value.filter((v) => v !== id) : [...pickOptions.value, id]
}

function addToCart(): void {
  const p = picking.value
  if (!p) return
  const error = store.add(p, pickOptions.value, pickQuantity.value, pickMemo.value)
  if (error !== null) {
    pickError.value = error
    return
  }
  notice.value = fmt(t.added, { name: p.name })
  sendError.value = null
  picking.value = null
}

function openCart(): void {
  sendError.value = null
  cartOpen.value = true
}

function onNoteInput(event: Event): void {
  store.setNote((event.target as HTMLTextAreaElement).value)
}

function askSend(): void {
  if (store.lines.length === 0 || store.submitting) return
  sendError.value = null
  confirmOpen.value = true
}

async function send(): Promise<void> {
  if (store.submitting) return
  const outcome = await store.send()
  confirmOpen.value = false
  if (outcome.ok) {
    cartOpen.value = false
    notice.value = null
    window.scrollTo({ top: 0 })
  } else if (outcome.message !== '') {
    sendError.value = outcome.message
  }
}

function openHistory(): void {
  historyOpen.value = true
  void store.loadOrders()
}
</script>

<template>
  <div class="c01">
    <p
      v-if="store.loadState === 'invalid'"
      class="c01__invalid"
      role="alert"
    >
      {{ t.invalid }}
    </p>

    <template v-else>
      <header
        v-if="menu"
        class="c01__head"
      >
        <div class="c01__where">
          <span class="c01__store">{{ menu.store_name }}</span>
          <span class="c01__table">{{ menu.table_name }}</span>
        </div>
        <button
          type="button"
          class="c01__history-btn"
          @click="openHistory"
        >
          {{ t.history }}
        </button>
      </header>

      <main class="c01__main">
        <p
          v-if="store.loadState === 'loading' && !menu"
          role="status"
        >
          {{ ja.common.loading }}
        </p>
        <div
          v-else-if="store.loadState === 'error' && !menu"
          class="c01__panel"
        >
          <p
            class="c01__error"
            role="alert"
          >
            {{ store.loadError }}
          </p>
          <BigButton @click="store.loadMenu()">
            {{ t.retry }}
          </BigButton>
        </div>

        <template v-else-if="menu">
          <div
            v-if="store.lastOrder"
            class="c01__done"
            role="status"
          >
            <p class="c01__done-title">
              {{ fmt(t.done, { no: store.lastOrder.order_no }) }}
            </p>
            <p
              v-if="store.lastOrder.status === 'pending'"
              class="c01__done-sub"
            >
              {{ t.donePending }}
            </p>
          </div>
          <p
            v-if="notAcceptingMessage"
            class="c01__closed"
            role="status"
          >
            {{ notAcceptingMessage }}
          </p>
          <p
            v-if="notice"
            class="c01__notice"
            role="status"
          >
            {{ notice }}
          </p>

          <nav
            v-if="tabs.length > 1"
            class="c01__tabs"
          >
            <button
              v-for="c in tabs"
              :key="c.id"
              type="button"
              class="c01__tab"
              :class="{ 'c01__tab--on': category === c.id }"
              :aria-pressed="category === c.id"
              @click="category = c.id"
            >
              {{ c.name }}
            </button>
          </nav>

          <p
            v-if="visibleProducts.length === 0"
            class="c01__help"
          >
            {{ t.empty }}
          </p>
          <div
            v-else
            class="c01__grid"
          >
            <button
              v-for="p in visibleProducts"
              :key="p.id"
              type="button"
              class="c01__product"
              :class="[`c01__product--${p.color}`, { 'c01__product--soldout': p.sold_out }]"
              :disabled="p.sold_out"
              :data-product="p.id"
              @click="openProduct(p)"
            >
              <span class="c01__pname">{{ p.name }}</span>
              <span
                v-if="p.memo"
                class="c01__pmemo"
              >{{ p.memo }}</span>
              <span class="c01__pfoot">
                <span class="c01__pprice tabular">{{ formatYen(p.price) }}</span>
                <span
                  v-if="p.sold_out"
                  class="c01__mark c01__mark--soldout"
                >{{ t.soldOut }}</span>
                <span
                  v-else-if="p.options.length > 0"
                  class="c01__mark"
                >{{ t.hasOptions }}</span>
              </span>
            </button>
          </div>
        </template>
      </main>

      <div
        v-if="menu"
        class="c01__bar"
      >
        <button
          type="button"
          class="c01__cart-btn"
          data-cart
          @click="openCart"
        >
          {{ store.itemCount > 0 ? fmt(t.viewCart, { n: store.itemCount, amount: formatYen(store.subtotal) }) : t.viewCartEmpty }}
        </button>
      </div>
    </template>

    <!-- 商品のシート -->
    <BottomSheet
      :open="picking !== null"
      :title="picking?.name ?? ''"
      @close="picking = null"
    >
      <div
        v-if="picking"
        class="pick"
      >
        <p
          v-if="picking.memo"
          class="pick__memo"
        >
          {{ picking.memo }}
        </p>
        <p class="pick__price tabular">
          {{ formatYen(picking.price) }}{{ priceSuffix }}
        </p>

        <fieldset
          v-if="picking.options.length > 0"
          class="pick__group"
        >
          <legend class="pick__label">
            {{ t.options }}
          </legend>
          <button
            v-for="o in picking.options"
            :key="o.id"
            type="button"
            role="checkbox"
            class="pick__option"
            :class="{ 'pick__option--on': pickOptions.includes(o.id) }"
            :aria-checked="pickOptions.includes(o.id)"
            @click="toggleOption(o.id)"
          >
            <span>{{ o.name }}</span>
            <span class="tabular">＋{{ formatYen(o.price) }}</span>
          </button>
        </fieldset>

        <div class="pick__group">
          <span class="pick__label">{{ t.quantity }}</span>
          <div class="stepper">
            <button
              type="button"
              class="stepper__btn"
              :aria-label="t.decrease"
              :disabled="pickQuantity <= 1"
              @click="pickQuantity -= 1"
            >
              −
            </button>
            <span
              class="stepper__value tabular"
              data-quantity
            >{{ pickQuantity }}</span>
            <button
              type="button"
              class="stepper__btn"
              :aria-label="t.increase"
              :disabled="pickQuantity >= Math.min(store.maxQuantity, TOTAL_QUANTITY_MAX - store.itemCount)"
              @click="pickQuantity += 1"
            >
              ＋
            </button>
          </div>
        </div>

        <label class="pick__group">
          <span class="pick__label">{{ t.memo }}</span>
          <input
            v-model="pickMemo"
            class="c01__input"
            type="text"
            :maxlength="LINE_MEMO_MAX"
            :placeholder="t.memoPlaceholder"
            enterkeyhint="done"
          >
        </label>

        <p
          v-if="pickError"
          class="c01__error"
          role="alert"
        >
          {{ pickError }}
        </p>
        <BigButton
          size="lg"
          block
          data-add
          @click="addToCart"
        >
          {{ fmt(t.addToCart, { amount: formatYen(pickPrice) }) }}
        </BigButton>
      </div>
    </BottomSheet>

    <!-- 注文内容のシート -->
    <BottomSheet
      :open="cartOpen"
      :title="t.cartTitle"
      @close="cartOpen = false"
    >
      <div class="cart">
        <p
          v-if="cartLines.length === 0"
          class="c01__help"
        >
          {{ t.cartEmpty }}
        </p>
        <ul
          v-else
          class="cart__lines"
        >
          <li
            v-for="row in cartLines"
            :key="row.line.key"
            class="cart__line"
            :data-line="row.line.key"
          >
            <div class="cart__text">
              <span class="cart__name">{{ row.name }}</span>
              <span
                v-if="row.options.length > 0"
                class="cart__sub"
              >{{ row.options.join('・') }}</span>
              <span
                v-if="row.line.memo"
                class="cart__sub"
              >{{ fmt(t.itemMemo, { memo: row.line.memo }) }}</span>
              <span class="cart__amount tabular">{{ formatYen(row.amount) }}</span>
            </div>
            <div class="cart__controls">
              <div class="stepper">
                <button
                  type="button"
                  class="stepper__btn"
                  :aria-label="t.decrease"
                  @click="store.setQuantity(row.line.key, row.line.quantity - 1)"
                >
                  −
                </button>
                <span class="stepper__value tabular">{{ row.line.quantity }}</span>
                <button
                  type="button"
                  class="stepper__btn"
                  :aria-label="t.increase"
                  :disabled="row.line.quantity >= store.maxQuantity || store.itemCount >= TOTAL_QUANTITY_MAX"
                  @click="store.setQuantity(row.line.key, row.line.quantity + 1)"
                >
                  ＋
                </button>
              </div>
              <button
                type="button"
                class="cart__remove"
                :aria-label="fmt(t.remove, { name: row.name })"
                @click="store.remove(row.line.key)"
              >
                {{ t.removeShort }}
              </button>
            </div>
          </li>
        </ul>

        <label class="pick__group">
          <span class="pick__label">{{ t.note }}</span>
          <textarea
            class="c01__input c01__textarea"
            :value="store.note"
            :maxlength="NOTE_MAX"
            :placeholder="t.notePlaceholder"
            rows="2"
            @input="onNoteInput"
          />
        </label>

        <div class="cart__total">
          <span>{{ t.estimate }}{{ priceSuffix }}</span>
          <span
            class="cart__total-amount tabular"
            data-total
          >{{ formatYen(store.subtotal) }}</span>
        </div>
        <p class="c01__help">
          {{ t.estimateHelp }}
        </p>

        <p
          v-if="sendError"
          class="c01__error c01__error--pre"
          role="alert"
        >
          {{ sendError }}
        </p>
        <p
          v-if="notAcceptingMessage"
          class="c01__closed"
        >
          {{ notAcceptingMessage }}
        </p>
        <BigButton
          v-else
          size="xl"
          block
          :disabled="cartLines.length === 0"
          :loading="store.submitting"
          data-order
          @click="askSend"
        >
          {{ t.order }}
        </BigButton>
      </div>
    </BottomSheet>

    <!-- 注文履歴のシート -->
    <BottomSheet
      :open="historyOpen"
      :title="t.history"
      @close="historyOpen = false"
    >
      <div class="history">
        <BigButton
          variant="secondary"
          :loading="store.ordersLoading"
          @click="store.loadOrders()"
        >
          {{ t.refresh }}
        </BigButton>
        <p
          v-if="store.ordersError"
          class="c01__error"
          role="alert"
        >
          {{ store.ordersError }}
        </p>
        <p
          v-if="!store.ordersLoading && store.orders.length === 0 && !store.ordersError"
          class="c01__help"
        >
          {{ t.historyEmpty }}
        </p>
        <article
          v-for="o in store.orders"
          :key="o.order_no"
          class="history__order"
          :class="{ 'history__order--cancelled': o.status === 'cancelled' }"
          :data-history="o.order_no"
        >
          <header class="history__head">
            <span class="history__no tabular">{{ fmt(t.orderNo, { no: o.order_no }) }}</span>
            <span class="history__time tabular">{{ formatTime(o.created_at) }}</span>
            <span
              v-if="o.status !== 'active'"
              class="history__status"
            >{{ o.status === 'pending' ? t.statusPending : t.statusCancelled }}</span>
          </header>
          <ul class="history__items">
            <li
              v-for="(item, i) in o.items"
              :key="i"
              class="history__item"
            >
              <span class="history__name">
                {{ item.product_name }}<template v-if="item.options.length > 0">（{{ item.options.join('・') }}）</template> ×{{ item.quantity }}
              </span>
              <span
                v-if="o.status === 'active'"
                class="history__served"
                :class="{ 'history__served--on': item.served }"
              >{{ item.served ? t.served : t.preparing }}</span>
            </li>
          </ul>
        </article>
      </div>
    </BottomSheet>

    <ConfirmDialog
      :open="confirmOpen"
      :title="t.confirmTitle"
      :message="fmt(t.confirmMessage, { n: store.itemCount, amount: formatYen(store.subtotal) + priceSuffix })"
      :confirm-label="t.confirm"
      :loading="store.submitting"
      @confirm="send"
      @cancel="confirmOpen = false"
    />
  </div>
</template>

<style scoped>
.c01 {
  min-height: 100dvh;
  padding-bottom: calc(64px + 16px + var(--safe-bottom));
  background: var(--c-surface-alt);
  color: var(--c-text);
  font-size: 16px;
}
.c01__invalid { margin: 0; padding: 48px var(--gutter); font-size: 20px; font-weight: 700; text-align: center; }

.c01__head {
  position: sticky;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: calc(8px + var(--safe-top)) var(--gutter) 8px;
  border-bottom: 1px solid var(--c-border);
  background: var(--c-surface);
}
.c01__where { display: flex; flex: 1 1 auto; flex-direction: column; min-width: 0; }
.c01__store { color: var(--c-text-sub); font-size: 16px; overflow-wrap: anywhere; }
.c01__table { font-size: 20px; font-weight: 800; overflow-wrap: anywhere; }
.c01__history-btn {
  flex-shrink: 0;
  min-height: var(--tap-min);
  padding: 0 16px;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-primary);
  font-size: 16px;
  font-weight: 700;
}

.c01__main { display: flex; flex-direction: column; gap: 12px; max-width: 720px; margin: 0 auto; padding: 12px var(--gutter); }
.c01__panel { display: flex; flex-direction: column; gap: 12px; }
.c01__error { margin: 0; color: var(--c-danger); font-weight: 700; }
.c01__error--pre { white-space: pre-line; }
.c01__help { margin: 0; color: var(--c-text-sub); }
.c01__notice { margin: 0; color: var(--c-success); font-weight: 700; }
.c01__closed { margin: 0; padding: 12px; border-radius: var(--radius); background: #FEF3C7; color: var(--c-change); font-weight: 800; }
.c01__done { padding: 12px 16px; border: 2px solid var(--c-success); border-radius: var(--radius-card); background: var(--c-surface); }
.c01__done-title { margin: 0; color: var(--c-success); font-size: 20px; font-weight: 800; }
.c01__done-sub { margin: 4px 0 0; }

.c01__tabs { display: flex; gap: 8px; overflow-x: auto; scrollbar-width: none; }
.c01__tab {
  flex-shrink: 0;
  min-height: var(--tab-h);
  padding: 0 16px;
  border: 2px solid var(--c-border);
  border-radius: 999px;
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 16px;
  font-weight: 700;
  white-space: nowrap;
}
.c01__tab--on { border-color: var(--c-primary); background: var(--c-primary); color: var(--c-on-primary); }

.c01__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
@media (min-width: 600px) {
  .c01__grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
.c01__product {
  --tile-bg: var(--c-surface);
  --tile-fg: var(--c-text);
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  min-height: 104px;
  padding: 12px;
  border: 0;
  border-radius: var(--radius-card);
  background: var(--tile-bg);
  color: var(--tile-fg);
  text-align: left;
}
.c01__product--gray { --tile-bg: var(--pc-gray-bg); --tile-fg: var(--pc-gray-fg); }
.c01__product--red { --tile-bg: var(--pc-red-bg); --tile-fg: var(--pc-red-fg); }
.c01__product--orange { --tile-bg: var(--pc-orange-bg); --tile-fg: var(--pc-orange-fg); }
.c01__product--yellow { --tile-bg: var(--pc-yellow-bg); --tile-fg: var(--pc-yellow-fg); }
.c01__product--green { --tile-bg: var(--pc-green-bg); --tile-fg: var(--pc-green-fg); }
.c01__product--teal { --tile-bg: var(--pc-teal-bg); --tile-fg: var(--pc-teal-fg); }
.c01__product--blue { --tile-bg: var(--pc-blue-bg); --tile-fg: var(--pc-blue-fg); }
.c01__product--indigo { --tile-bg: var(--pc-indigo-bg); --tile-fg: var(--pc-indigo-fg); }
.c01__product--purple { --tile-bg: var(--pc-purple-bg); --tile-fg: var(--pc-purple-fg); }
.c01__product--pink { --tile-bg: var(--pc-pink-bg); --tile-fg: var(--pc-pink-fg); }
.c01__product--soldout { opacity: 0.55; }
.c01__pname { font-size: 18px; font-weight: 700; line-height: 1.3; overflow-wrap: anywhere; }
.c01__pmemo { font-size: 16px; opacity: 0.85; overflow-wrap: anywhere; }
.c01__pfoot { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; margin-top: auto; }
.c01__pprice { font-size: 18px; font-weight: 700; }
.c01__mark { padding: 0 6px; border-radius: 6px; background: rgba(255, 255, 255, 0.7); color: var(--c-text); font-size: 16px; font-weight: 700; }
.c01__mark--soldout { background: var(--c-text); color: var(--c-on-primary); }

.c01__bar {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 20;
  padding: 8px var(--gutter) calc(8px + var(--safe-bottom));
  border-top: 1px solid var(--c-border);
  background: var(--c-surface);
}
.c01__cart-btn {
  display: block;
  width: 100%;
  max-width: 720px;
  min-height: 64px;
  margin: 0 auto;
  border: 0;
  border-radius: var(--radius);
  background: var(--c-primary);
  color: var(--c-on-primary);
  font-size: 18px;
  font-weight: 800;
}
.c01__cart-btn:active { background: var(--c-primary-press); }

/* 入力欄は 16px 以上（iOS Safari の自動拡大を防ぐ。AC-C01-5） */
.c01__input {
  width: 100%;
  min-height: var(--tap-min);
  padding: 8px 12px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 16px;
}
.c01__textarea { resize: vertical; }

.pick, .cart, .history { display: flex; flex-direction: column; gap: 16px; }
.pick__memo { margin: 0; color: var(--c-text-sub); }
.pick__price { margin: 0; font-size: 20px; font-weight: 800; }
.pick__group { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; border: 0; }
.pick__label { font-weight: 700; }
.pick__option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 56px;
  padding: 0 16px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 16px;
  font-weight: 700;
  text-align: left;
}
.pick__option--on { border-color: var(--c-primary); background: #EEF3FF; color: var(--c-primary); }

.stepper { display: flex; align-items: center; gap: 8px; }
.stepper__btn {
  min-width: 56px;
  min-height: 56px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 24px;
  font-weight: 800;
}
.stepper__btn:disabled { opacity: 0.4; }
.stepper__value { min-width: 40px; font-size: 22px; font-weight: 800; text-align: center; }

.cart__lines { display: flex; flex-direction: column; margin: 0; padding: 0; list-style: none; }
.cart__line { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 0; border-bottom: 1px solid var(--c-border); }
.cart__text { display: flex; flex: 1 1 160px; flex-direction: column; min-width: 0; }
.cart__name { font-size: 18px; font-weight: 700; overflow-wrap: anywhere; }
.cart__sub { color: var(--c-text-sub); overflow-wrap: anywhere; }
.cart__amount { font-weight: 700; }
.cart__controls { display: flex; align-items: center; gap: 8px; }
.cart__remove {
  min-width: var(--tap-min);
  min-height: var(--tap-min);
  padding: 0 12px;
  border: 0;
  background: transparent;
  color: var(--c-danger);
  font-size: 16px;
  font-weight: 700;
}
.cart__total { display: flex; align-items: baseline; justify-content: space-between; font-size: 18px; font-weight: 700; }
.cart__total-amount { font-size: 24px; font-weight: 800; }

.history__order { display: flex; flex-direction: column; gap: 8px; padding: 12px; border: 1px solid var(--c-border); border-radius: var(--radius); }
.history__order--cancelled { opacity: 0.6; }
.history__head { display: flex; align-items: baseline; gap: 12px; }
.history__no { font-size: 20px; font-weight: 800; }
.history__time { color: var(--c-text-sub); }
.history__status { margin-left: auto; color: var(--c-change); font-weight: 800; }
.history__items { display: flex; flex-direction: column; gap: 4px; margin: 0; padding: 0; list-style: none; }
.history__item { display: flex; justify-content: space-between; gap: 8px; }
.history__name { overflow-wrap: anywhere; }
.history__served { flex-shrink: 0; color: var(--c-text-sub); font-weight: 700; }
.history__served--on { color: var(--c-success); }
</style>
