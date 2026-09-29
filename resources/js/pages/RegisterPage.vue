<script setup lang="ts">
// S02 会計（08 §5.3）。タブレット横は左に商品（62%）・右に注文と合計（38%）。
// スマホ縦は上に税区分とカテゴリ、中央に商品、下に固定の合計バー（注文はシートで開く）。
// 商品のタップから合計の表示までは通信しない（AC-S02-1）。起動時に GET /register/bootstrap を 1 回呼ぶ
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import BigButton from '@/components/BigButton.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import MoneyText from '@/components/MoneyText.vue'
import ProductTile from '@/components/ProductTile.vue'
import CheckoutDialog from '@/components/register/CheckoutDialog.vue'
import DiscountForm from '@/components/register/DiscountForm.vue'
import HeldList from '@/components/register/HeldList.vue'
import OptionPicker from '@/components/register/OptionPicker.vue'
import OrderPanel from '@/components/register/OrderPanel.vue'
import SaleDone from '@/components/register/SaleDone.vue'
import { fmt, ja } from '@/i18n/ja'
import { useIsTablet } from '@/lib/breakpoint'
import { canAddOne } from '@/lib/cart'
import { useWakeLock } from '@/lib/wakeLock'
import { useRegisterStore, type ConfirmExtra, type Discount } from '@/stores/register'
import type { Product, Sale } from '@/types/api'

type Tab = 'all' | 'none' | number

const t = ja.register
const router = useRouter()
const register = useRegisterStore()
const isTablet = useIsTablet()
useWakeLock()

onMounted(() => {
  void register.load()
})

// ── 商品とカテゴリ
const tab = ref<Tab>('all')
const bySort = (a: { sort_order: number; id: number }, b: { sort_order: number; id: number }): number =>
  a.sort_order - b.sort_order || a.id - b.id

const categories = computed(() => [...(register.bootstrap?.categories ?? [])].sort(bySort).filter((c) => c.product_count > 0))
const hasUncategorized = computed(() => (register.bootstrap?.products ?? []).some((p) => p.category_id === null))

/** すべて：カテゴリの並び順 → 未分類の順に、カテゴリ内は sort_order */
const visibleProducts = computed<Product[]>(() => {
  const sorted = [...(register.bootstrap?.products ?? [])].sort(bySort)
  if (tab.value === 'none') return sorted.filter((p) => p.category_id === null)
  if (typeof tab.value === 'number') return sorted.filter((p) => p.category_id === tab.value)
  const rank = new Map(categories.value.map((c, i) => [c.id, i]))
  const rankOf = (p: Product): number => (p.category_id === null ? Infinity : (rank.get(p.category_id) ?? Infinity))
  return sorted.sort((a, b) => rankOf(a) - rankOf(b) || bySort(a, b))
})

function canPress(product: Product): boolean {
  return canAddOne(register.lines, product)
}

/** 在庫の上限まで注文に入っていて押せない商品。商品 500 件でもタップ時に全ボタンを描き直さないよう v-memo の鍵に使う（08 §10） */
const blocked = computed(() => new Set(visibleProducts.value.filter((p) => !canPress(p)).map((p) => p.id)))

const picking = ref<Product | null>(null)

function press(product: Product): void {
  if (!canPress(product)) return
  if (product.options.length > 0) picking.value = product
  else register.add(product)
}

function addWithOptions(optionIds: number[]): void {
  if (picking.value) register.add(picking.value, optionIds)
  picking.value = null
}

// ── シート・ダイアログ
const orderSheet = ref(false)
const heldOpen = ref(false)
const discountOpen = ref(false)

function applyDiscount(value: Discount | null): void {
  register.setDiscount(value)
  discountOpen.value = false
}

// ── お会計
const checkoutOpen = ref(false)
const checkoutError = ref<string | null>(null)
const done = ref<Sale | null>(null)
const undoing = ref(false)

function openCheckout(): void {
  if (!register.canCheckout) return
  register.startCheckout()
  checkoutError.value = null
  orderSheet.value = false
  checkoutOpen.value = true
}

async function confirm(extra: ConfirmExtra): Promise<void> {
  checkoutError.value = null
  const outcome = await register.confirm(extra)
  if (outcome.ok) {
    checkoutOpen.value = false
    done.value = outcome.sale
    return
  }
  if (outcome.closeDialog) {
    checkoutOpen.value = false
    register.notice = { kind: 'error', text: outcome.message }
  } else {
    checkoutError.value = outcome.message
  }
}

function next(): void {
  done.value = null
  register.forgetLastSale()
}

function showReceipt(): void {
  const sale = done.value
  next()
  if (sale) void router.push({ name: 'receipt', params: { id: sale.id } })
}

async function undo(): Promise<void> {
  undoing.value = true
  await register.undoLastSale()
  undoing.value = false
  done.value = null
}
</script>

<template>
  <div
    class="register"
    :class="{ 'register--tablet': isTablet }"
  >
    <header class="register__top">
      <RouterLink
        :to="{ name: 'home' }"
        class="register__home"
      >
        <span aria-hidden="true">←</span> {{ ja.common.home }}
      </RouterLink>
      <div
        v-if="register.bootstrap"
        class="tabs tabs--tax"
        role="radiogroup"
        :aria-label="t.taxType"
      >
        <button
          v-for="tax in register.bootstrap.tax_types"
          :key="tax.id"
          type="button"
          role="radio"
          class="tab"
          :class="{ 'tab--on': register.taxTypeId === tax.id }"
          :aria-checked="register.taxTypeId === tax.id"
          @click="register.setTaxType(tax.id)"
        >
          {{ tax.name }}
        </button>
      </div>
    </header>

    <div
      v-if="register.notice"
      class="register__notice"
      :class="`register__notice--${register.notice.kind}`"
      :role="register.notice.kind === 'error' ? 'alert' : 'status'"
    >
      <span>{{ register.notice.text }}</span>
      <button
        type="button"
        class="register__notice-close"
        @click="register.notice = null"
      >
        {{ ja.common.close }}
      </button>
    </div>
    <p
      v-if="!register.storageOk"
      class="register__notice register__notice--error"
    >
      {{ t.storageUnavailable }}
    </p>

    <p
      v-if="register.loading && !register.bootstrap"
      class="register__message"
    >
      {{ ja.common.loading }}
    </p>
    <div
      v-else-if="register.loadError && !register.bootstrap"
      class="register__message"
      role="alert"
    >
      <p>{{ register.loadError }}</p>
      <BigButton @click="register.load()">
        {{ t.retry }}
      </BigButton>
    </div>

    <div
      v-else-if="register.bootstrap"
      class="register__body"
    >
      <section
        class="register__products"
        :aria-label="t.title"
      >
        <nav
          class="tabs tabs--category"
          :aria-label="t.category"
        >
          <button
            type="button"
            class="tab"
            :class="{ 'tab--on': tab === 'all' }"
            :aria-pressed="tab === 'all'"
            @click="tab = 'all'"
          >
            {{ t.all }}
          </button>
          <button
            v-for="category in categories"
            :key="category.id"
            type="button"
            class="tab"
            :class="{ 'tab--on': tab === category.id }"
            :aria-pressed="tab === category.id"
            @click="tab = category.id"
          >
            {{ category.name }}
          </button>
          <button
            v-if="hasUncategorized && categories.length > 0"
            type="button"
            class="tab"
            :class="{ 'tab--on': tab === 'none' }"
            :aria-pressed="tab === 'none'"
            @click="tab = 'none'"
          >
            {{ t.uncategorized }}
          </button>
        </nav>
        <div class="grid">
          <button
            v-for="product in visibleProducts"
            :key="product.id"
            v-memo="[product, blocked.has(product.id)]"
            type="button"
            class="grid__item"
            :disabled="blocked.has(product.id)"
            :data-product="product.id"
            @click="press(product)"
          >
            <ProductTile :product="product" />
          </button>
        </div>
      </section>

      <aside
        v-if="isTablet"
        class="register__order"
      >
        <OrderPanel
          @checkout="openCheckout"
          @discount="discountOpen = true"
          @held="heldOpen = true"
        />
      </aside>
    </div>

    <div
      v-if="!isTablet && register.bootstrap"
      class="total-bar"
    >
      <button
        type="button"
        class="total-bar__summary"
        @click="orderSheet = true"
      >
        <span class="total-bar__label">{{ t.totalBar }}・{{ fmt(t.count, { n: register.itemCount }) }}</span>
        <MoneyText
          :amount="register.amounts?.total ?? 0"
          size="amount"
          tone="inherit"
        />
        <span class="total-bar__view">{{ t.viewOrder }}</span>
      </button>
      <BigButton
        size="lg"
        :disabled="!register.canCheckout"
        @click="openCheckout"
      >
        {{ t.toCheckout }}
      </BigButton>
    </div>

    <BottomSheet
      :open="orderSheet"
      :title="t.order"
      @close="orderSheet = false"
    >
      <OrderPanel
        @checkout="openCheckout"
        @discount="discountOpen = true"
        @held="heldOpen = true"
      />
    </BottomSheet>

    <OptionPicker
      :product="picking"
      @add="addWithOptions"
      @cancel="picking = null"
    />
    <DiscountForm
      :open="discountOpen"
      :current="register.discount"
      @apply="applyDiscount"
      @cancel="discountOpen = false"
    />
    <HeldList
      :open="heldOpen"
      @close="heldOpen = false"
    />
    <CheckoutDialog
      v-if="register.paymentMethod && register.amounts"
      :open="checkoutOpen"
      :total="register.amounts.total"
      :payment-method="register.paymentMethod"
      :submitting="register.submitting"
      :error="checkoutError"
      @confirm="confirm"
      @back="checkoutOpen = false"
    />
    <SaleDone
      v-if="done"
      :sale="done"
      :undoing="undoing"
      @receipt="showReceipt"
      @next="next"
      @undo="undo"
    />
  </div>
</template>

<style scoped>
.register {
  display: flex;
  flex-direction: column;
  min-height: 100dvh;
  padding: var(--safe-top) var(--safe-right) 0 var(--safe-left);
  background: var(--c-surface-alt);
  color: var(--c-text);
}

.register__top {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 12px;
  background: var(--c-primary);
  color: var(--c-on-primary);
}

.register__home {
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

.tabs {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  scrollbar-width: none;
}

.tabs--tax { flex: 1 1 auto; justify-content: flex-end; }
.tabs--category { flex-shrink: 0; padding: 8px 12px; }

.tab {
  flex-shrink: 0;
  min-width: var(--tap-min);
  min-height: var(--tab-h);
  padding: 0 16px;
  border: 2px solid var(--c-border);
  border-radius: 999px;
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 18px;
  font-weight: 700;
  white-space: nowrap;
}

.tab--on { border-color: var(--c-primary); background: var(--c-primary); color: var(--c-on-primary); }
.tabs--tax .tab { min-height: var(--tap-min); border-color: var(--c-on-primary); background: transparent; color: var(--c-on-primary); }
.tabs--tax .tab--on { background: var(--c-surface); color: var(--c-primary); }

.register__notice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 4px 12px;
  font-weight: 700;
  white-space: pre-line;
}
.register__notice--error { background: #FEE2E2; color: var(--c-danger); }
.register__notice--info { background: #DCFCE7; color: var(--c-success); }
.register__notice-close {
  flex-shrink: 0;
  min-width: var(--tap-min);
  min-height: var(--tap-min);
  border: 0;
  background: transparent;
  color: inherit;
  font-weight: 700;
}

.register__message { display: flex; flex-direction: column; align-items: center; gap: 16px; padding: 48px 16px; text-align: center; }

.register__body { display: flex; flex: 1 1 auto; min-height: 0; }
.register__products { display: flex; flex: 1 1 auto; flex-direction: column; min-width: 0; }

.grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  grid-auto-rows: minmax(var(--product-min-h), auto);
  gap: var(--product-gap);
  align-content: start;
  padding: 0 12px calc(96px + var(--safe-bottom));
}

.grid__item {
  display: block;
  padding: 0;
  border: 0;
  border-radius: var(--radius);
  background: transparent;
  text-align: left;
  user-select: none;
  -webkit-user-select: none;
  touch-action: manipulation;
}
.grid__item:active:not(:disabled) { transform: scale(0.97); }
.grid__item:disabled { cursor: not-allowed; }
.grid__item:deep(.tile__name) {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* タブレット：画面の高さに収め、商品と注文の列をそれぞれスクロールする（AC-S02-13） */
.register--tablet { height: 100dvh; }
.register--tablet .register__body { overflow: hidden; }
.register--tablet .register__products { flex: 0 0 62%; overflow: hidden; }
.register--tablet .grid {
  grid-template-columns: repeat(auto-fill, minmax(var(--product-min-w, 140px), 1fr));
  overflow-y: auto;
  padding-bottom: calc(12px + var(--safe-bottom));
}
.register__order {
  flex: 0 0 38%;
  min-width: 0;
  overflow-y: auto;
  padding: 12px 12px calc(12px + var(--safe-bottom));
  border-left: 1px solid var(--c-border);
  background: var(--c-surface);
}

/* スマホ：合計バーを画面の下に固定し、ホームバーに重ねない（AC-S02-14） */
.total-bar {
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

.total-bar__summary {
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
.total-bar__label { font-size: 14px; }
.total-bar__view { grid-column: 1 / -1; font-size: 14px; text-decoration: underline; }
</style>
