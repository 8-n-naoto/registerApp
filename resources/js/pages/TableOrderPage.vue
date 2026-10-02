<script setup lang="ts">
// C01 お客さんの注文（12 §8.9）。QR のトークンだけで開き、ログイン情報を使わない（ガードで GET /me を呼ばない）。
// 店舗名・テーブル名を常に上に出し、商品 → シート（オプション・数量・メモ）→ カート → 確認 → 送信
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import BigButton from '@/components/BigButton.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import OptionChoices from '@/components/OptionChoices.vue'
import { fmt, ja } from '@/i18n/ja'
import { formatTime } from '@/lib/date'
import { formatYen } from '@/lib/money'
import { buildSections, initialSelection, missingGroups, orderedSelection } from '@/lib/optionGroups'
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

/** 一覧の写真の左上に出す、その商品がカートに入っている数 */
function inCartCount(productId: number): number {
  return store.lines.filter((l) => l.product_id === productId).reduce((sum, l) => sum + l.quantity, 0)
}

onMounted(async () => {
  const token = typeof route.params.token === 'string' ? route.params.token : ''
  await store.open(token)
})

function openProduct(product: PublicMenuProduct): void {
  if (product.sold_out) return
  picking.value = product
  pickOptions.value = initialSelection(buildSections(product.options, product.option_groups))
  pickQuantity.value = 1
  pickMemo.value = ''
  pickError.value = null
}

function addToCart(): void {
  const p = picking.value
  if (!p) return
  const sections = buildSections(p.options, p.option_groups)
  const missing = missingGroups(sections, pickOptions.value)[0]
  if (missing) {
    pickError.value = fmt(ja.optionChoices.missing, { name: missing.name })
    return
  }
  const error = store.add(p, orderedSelection(sections, pickOptions.value), pickQuantity.value, pickMemo.value)
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
        class="c01__head c-head"
      >
        <div class="c01__where col grow">
          <span class="c01__store c-head__store clamp1">{{ menu.store_name }}</span>
          <span class="c01__table c-head__table">{{ menu.table_name }}</span>
        </div>
        <button
          type="button"
          class="c01__history-btn c-head__btn"
          @click="openHistory"
        >
          <AppIcon name="history" />
          <span>{{ t.history }}</span>
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
            class="c01__done r-banner r-banner--ok"
            role="status"
          >
            <AppIcon name="check" />
            <div>
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
          </div>
          <p
            v-if="notAcceptingMessage"
            class="c01__closed r-banner r-banner--danger"
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
            class="c01__tabs r-chips"
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
              class="c01__product c-item"
              :class="{ 'c01__product--soldout': p.sold_out, 'c-item--sold': p.sold_out }"
              :disabled="p.sold_out"
              :data-product="p.id"
              @click="openProduct(p)"
            >
              <span
                class="c-photo"
                :class="`pc-${p.color}`"
              >
                <AppIcon
                  name="coffee"
                  :size="40"
                />
                <span
                  v-if="inCartCount(p.id) > 0"
                  class="c-incart"
                >{{ inCartCount(p.id) }}</span>
              </span>
              <span class="c-item__body">
                <span class="c01__pname c-item__name clamp2">{{ p.name }}</span>
                <span
                  v-if="p.memo"
                  class="c01__pmemo c-item__desc clamp2"
                >{{ p.memo }}</span>
                <span class="c01__pfoot c-item__foot">
                  <span
                    class="c01__pprice c-item__price tabular"
                    :class="{ sub: p.sold_out }"
                  >{{ formatYen(p.price) }}</span>
                  <span class="grow" />
                  <span
                    v-if="p.sold_out"
                    class="c01__mark c01__mark--soldout r-chip r-chip--danger"
                  >{{ t.soldOut }}</span>
                  <span
                    v-else-if="p.options.length > 0"
                    class="c01__mark r-chip r-chip--info r-chip--plain"
                  >{{ t.hasOptions }}</span>
                </span>
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
          class="c01__cart-btn r-btn r-btn--primary r-btn--lg r-btn--block"
          data-cart
          @click="openCart"
        >
          <AppIcon name="cart" />
          <span>{{ store.itemCount > 0 ? fmt(t.viewCart, { n: store.itemCount, amount: formatYen(store.subtotal) }) : t.viewCartEmpty }}</span>
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
        <!-- 写真は現在 1 枚も持てない（API に画像が無い）ので、色の面とアイコンで代える -->
        <div
          class="pick__photo c-gallery"
          :class="`pc-${picking.color}`"
        >
          <span class="c-gallery__img">
            <AppIcon
              name="coffee"
              :size="72"
            />
          </span>
        </div>
        <p
          v-if="picking.memo"
          class="pick__memo"
        >
          {{ picking.memo }}
        </p>
        <p class="pick__price num tabular">
          {{ formatYen(picking.price) }}{{ priceSuffix }}
        </p>

        <fieldset
          v-if="picking.options.length > 0"
          class="pick__group"
        >
          <legend class="pick__label">
            {{ t.options }}
          </legend>
          <OptionChoices
            v-model="pickOptions"
            :options="picking.options"
            :groups="picking.option_groups"
            :base-price="picking.price"
            :price-suffix="priceSuffix"
          />
        </fieldset>

        <div class="pick__group">
          <span class="pick__label">{{ t.quantity }}</span>
          <div class="stepper r-qty r-qty--lg">
            <button
              type="button"
              class="stepper__btn r-qty__b"
              :aria-label="t.decrease"
              :disabled="pickQuantity <= 1"
              @click="pickQuantity -= 1"
            >
              <AppIcon name="minus" />
            </button>
            <span
              class="stepper__value r-qty__v tabular"
              data-quantity
            >{{ pickQuantity }}</span>
            <button
              type="button"
              class="stepper__btn r-qty__b"
              :aria-label="t.increase"
              :disabled="pickQuantity >= Math.min(store.maxQuantity, TOTAL_QUANTITY_MAX - store.itemCount)"
              @click="pickQuantity += 1"
            >
              <AppIcon name="plus" />
            </button>
          </div>
        </div>

        <label class="pick__group">
          <span class="pick__label">{{ t.memo }}</span>
          <input
            v-model="pickMemo"
            class="c01__input r-input"
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
            class="cart__line o-line"
            :data-line="row.line.key"
          >
            <div class="cart__text o-line__main">
              <span class="cart__name o-line__name">{{ row.name }}</span>
              <span
                v-if="row.options.length > 0"
                class="cart__sub o-line__opt"
              >{{ row.options.join('・') }}</span>
              <span
                v-if="row.line.memo"
                class="cart__sub o-line__opt"
              >{{ fmt(t.itemMemo, { memo: row.line.memo }) }}</span>
            </div>
            <div class="cart__controls">
              <div class="stepper r-qty">
                <button
                  type="button"
                  class="stepper__btn r-qty__b"
                  :aria-label="t.decrease"
                  @click="store.setQuantity(row.line.key, row.line.quantity - 1)"
                >
                  <AppIcon name="minus" />
                </button>
                <span class="stepper__value r-qty__v tabular">{{ row.line.quantity }}</span>
                <button
                  type="button"
                  class="stepper__btn r-qty__b"
                  :aria-label="t.increase"
                  :disabled="row.line.quantity >= store.maxQuantity || store.itemCount >= TOTAL_QUANTITY_MAX"
                  @click="store.setQuantity(row.line.key, row.line.quantity + 1)"
                >
                  <AppIcon name="plus" />
                </button>
              </div>
              <span class="cart__amount o-line__amt tabular">{{ formatYen(row.amount) }}</span>
              <button
                type="button"
                class="cart__remove r-btn r-btn--quiet"
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
            class="c01__input c01__textarea r-input"
            :value="store.note"
            :maxlength="NOTE_MAX"
            :placeholder="t.notePlaceholder"
            rows="2"
            @input="onNoteInput"
          />
        </label>

        <div class="cart__total r-sum">
          <span class="r-sum__l">{{ t.estimate }}{{ priceSuffix }}</span>
          <span
            class="cart__total-amount r-sum__v tabular"
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
          class="c01__closed r-banner r-banner--danger"
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
          class="history__order r-card"
          :class="{ 'history__order--cancelled': o.status === 'cancelled' }"
          :data-history="o.order_no"
        >
          <header class="history__head r-card__head">
            <span class="history__no num tabular">{{ fmt(t.orderNo, { no: o.order_no }) }}</span>
            <span class="history__time sub tabular">{{ formatTime(o.created_at) }}</span>
            <span
              v-if="o.status !== 'active'"
              class="history__status r-chip r-chip--warn"
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
                class="history__served r-chip"
                :class="item.served ? 'history__served--on r-chip--ok' : 'r-chip--info'"
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
  padding-bottom: calc(80px + var(--safe-bottom));
  background: var(--c-surface-alt);
  color: var(--c-text);
  font-size: 16px;
}
.c01__invalid { margin: 0; padding: 48px var(--gutter); font-size: 20px; font-weight: 700; text-align: center; }

.c01__head { position: sticky; top: 0; z-index: 10; padding-top: calc(8px + var(--safe-top)); }
.c01__where { min-width: 0; }
.c01__history-btn { flex-shrink: 0; border: 0; background: transparent; }

.c01__main { display: flex; flex-direction: column; gap: 12px; max-width: 720px; margin: 0 auto; padding: 0 0 12px; }
.c01__main > p,
.c01__main > .c01__panel,
.c01__main > .c01__done { margin: 0 var(--gutter); }
.c01__main > :first-child { margin-top: 12px; }
.c01__panel { display: flex; flex-direction: column; gap: 12px; }
.c01__error { margin: 0; color: var(--c-danger); font-weight: 700; }
.c01__error--pre { white-space: pre-line; }
.c01__help { margin: 0; color: var(--c-text-sub); }
.c01__notice { margin: 0; color: var(--c-success); font-weight: 700; }
.c01__done-title { margin: 0; font-size: 18px; font-weight: 800; }
.c01__done-sub { margin: 4px 0 0; }
.c01__closed { font-weight: 800; }

.c01__tabs { position: sticky; top: 64px; z-index: 9; background: var(--c-surface); border-bottom: 1px solid var(--c-border-soft); }
.c01__tab { flex-shrink: 0; min-height: var(--tab-h); white-space: nowrap; }

.c01__grid { background: var(--c-surface); }
.c01__product {
  width: 100%;
  border: 0;
  border-top: 1px solid var(--c-border-soft);
  background: var(--c-surface);
  color: var(--c-text);
  font: inherit;
  text-align: left;
}
.c01__product:first-child { border-top: 0; }
.c01__product:disabled { cursor: default; }
.c01__product:active:not(:disabled) { background: var(--c-surface-alt); }
.c01__product .c-photo :deep(svg) { width: 40px; height: 40px; fill: none; opacity: 0.6; }

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
.c01__cart-btn { max-width: 720px; margin: 0 auto; }

/* 入力欄は 16px 以上（iOS Safari の自動拡大を防ぐ。AC-C01-5） */
.c01__input { width: 100%; min-height: var(--tap-min); font-size: 16px; }
.c01__textarea { resize: vertical; }

.pick, .cart, .history { display: flex; flex-direction: column; gap: 16px; }
/* 商品の写真は 1 枚（API に写真が無い間は色の面とアイコン）。シートでは高さを抑える */
.pick__photo { height: 180px; aspect-ratio: auto; border-radius: var(--radius); }
.pick__photo :deep(svg) { fill: none; opacity: 0.6; }
.pick__memo { margin: 0; color: var(--c-text-sub); }
.pick__price { margin: 0; font-size: 24px; font-weight: 800; }
.pick__group { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; border: 0; }
.pick__label { font-weight: 700; }

.stepper { align-self: flex-start; }

.cart__lines { margin: 0; padding: 0; list-style: none; border: 1px solid var(--c-border-soft); border-radius: var(--radius); }
.cart__line { padding: 12px; }
.cart__text { min-width: 0; overflow-wrap: anywhere; }
.cart__controls { display: flex; flex: 1 1 100%; align-items: center; justify-content: space-between; gap: 8px; }
.cart__controls .stepper { align-self: auto; }
.cart__remove { color: var(--c-danger); }

.history__order { overflow: hidden; }
.history__no { font-size: 20px; font-weight: 800; }
.history__status { margin-left: auto; }
.history__order--cancelled { opacity: 0.6; }
.history__items { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 12px 16px; list-style: none; }
.history__item { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.history__name { min-width: 0; overflow-wrap: anywhere; }
.history__served { flex-shrink: 0; }
</style>
