<script setup lang="ts">
// S08 商品の編集パネル（08 §5.9）。タブレットは右側のパネル、スマホは全画面
import { computed, onMounted, ref } from 'vue'
import { changeStock, createProduct, deleteProduct, updateProduct, type StockMode } from '@/api/catalog'
import AppIcon from '@/components/AppIcon.vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import OptionEditor from '@/components/products/OptionEditor.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { parseNonNegativeInt } from '@/lib/numberInput'
import { PRODUCT_COLORS } from '@/lib/productColors'
import { useAuthStore } from '@/stores/auth'
import type { Category, Product, ProductColor, ProductOption, ProductOptionGroup } from '@/types/api'

const PRICE_MAX = 9_999_999
const STOCK_MAX = 999_999
const t = ja.products
const auth = useAuthStore()
/** 店舗の在庫管理（12 §6.6）。OFF でも在庫の欄は編集できるが、会計で減らないことを知らせる */
const stockEnabled = computed(() => auth.me?.store?.stock_enabled ?? true)

const props = defineProps<{
  product: Product | null // null は新規
  categories: Category[]
  defaultCategoryId: number | null
}>()

const emit = defineEmits<{ saved: [product: Product]; deleted: [id: number]; close: [] }>()

const current = ref<Product | null>(props.product)
const isNew = computed(() => current.value === null)

const code = ref(props.product?.code ?? '')
const name = ref(props.product?.name ?? '')
const memo = ref(props.product?.memo ?? '')
const price = ref(props.product ? String(props.product.price) : '')
const categoryId = ref<number | null>(props.product ? props.product.category_id : props.defaultCategoryId)
const color = ref<ProductColor>(props.product?.color ?? 'gray')
const isActive = ref(props.product?.is_active ?? true)
const trackStock = ref(props.product?.track_stock ?? false)
const customerVisible = ref(props.product?.customer_visible ?? true)
/** 割引の商品（docs/10）。価格は正の数で入力し、レジでは −価格 になる。在庫・オプション・お客さんのメニューは使わない */
const isDiscount = ref(props.product?.is_discount ?? false)
const initialStock = ref('0')
const options = ref<ProductOption[]>(props.product ? [...props.product.options] : [])
const optionGroups = ref<ProductOptionGroup[]>(props.product ? [...props.product.option_groups] : [])
const hasOptions = computed(() => options.value.length > 0 || optionGroups.value.length > 0)

const saving = ref(false)
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const savedMessage = ref<string | null>(null)

const stockValue = ref('')
const stockError = ref<string | null>(null)
const stockBusy = ref(false)

const confirmingDelete = ref(false)
const deleteFailed = ref<string | null>(null)

const nameInput = ref<HTMLInputElement | null>(null)
onMounted(() => nameInput.value?.focus())

async function save(): Promise<void> {
  if (saving.value) return
  errors.value = {}
  failed.value = null
  savedMessage.value = null
  const codeValue = code.value.trim()
  // 新規の空欄はサーバーで自動採番。既存の商品はコードを消せない（06 §7.3）
  if (!isNew.value && codeValue === '') errors.value.code = t.codeRequired
  const priceValue = parseNonNegativeInt(price.value)
  if (priceValue === null || priceValue > PRICE_MAX || (isDiscount.value && priceValue === 0)) {
    errors.value.price = isDiscount.value ? t.discountPriceInvalid : t.priceInvalid
  }
  const tracked = trackStock.value && !isDiscount.value
  const stockQty = parseNonNegativeInt(initialStock.value)
  if (isNew.value && tracked && (stockQty === null || stockQty > STOCK_MAX)) errors.value.stock_qty = t.stockInvalid
  if (Object.keys(errors.value).length > 0 || priceValue === null) return

  saving.value = true
  try {
    const memoValue = memo.value.trim()
    const input = {
      code: codeValue,
      name: name.value.trim(),
      memo: memoValue === '' ? null : memoValue,
      price: priceValue,
      category_id: categoryId.value,
      color: color.value,
      is_active: isActive.value,
      track_stock: tracked,
      customer_visible: customerVisible.value && !isDiscount.value,
      is_discount: isDiscount.value,
    }
    const saved = current.value
      ? await updateProduct(current.value.id, input)
      : await createProduct({ ...input, stock_qty: tracked ? (stockQty ?? 0) : 0 })
    current.value = saved
    code.value = saved.code
    trackStock.value = saved.track_stock
    customerVisible.value = saved.customer_visible
    options.value = [...saved.options]
    optionGroups.value = [...saved.option_groups]
    savedMessage.value = ja.common.saved
    emit('saved', saved)
  } catch (err) {
    if (errorStatus(err) === 422) errors.value = fieldErrors(err)
    else if (!isNetworkError(err)) failed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    saving.value = false
  }
}

async function applyStock(mode: StockMode): Promise<void> {
  const target = current.value
  if (!target || stockBusy.value) return
  stockError.value = null
  const value = parseNonNegativeInt(stockValue.value)
  if (value === null || value > STOCK_MAX) {
    stockError.value = t.stockInvalid
    return
  }
  stockBusy.value = true
  try {
    const saved = await changeStock(target.id, mode, value)
    current.value = saved
    stockValue.value = ''
    emit('saved', saved)
  } catch (err) {
    if (errorStatus(err) === 422) stockError.value = Object.values(fieldErrors(err))[0] ?? errorBody(err)?.message ?? null
    else if (!isNetworkError(err)) stockError.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    stockBusy.value = false
  }
}

function onOptionsChange(next: ProductOption[]): void {
  options.value = next
  if (current.value) {
    current.value = { ...current.value, options: next }
    emit('saved', current.value)
  }
}

function onGroupsChange(next: ProductOptionGroup[]): void {
  optionGroups.value = next
  if (current.value) {
    current.value = { ...current.value, option_groups: next }
    emit('saved', current.value)
  }
}

async function confirmDelete(): Promise<void> {
  const target = current.value
  if (!target || saving.value) return
  saving.value = true
  deleteFailed.value = null
  try {
    await deleteProduct(target.id)
    emit('deleted', target.id)
  } catch (err) {
    if (!isNetworkError(err)) deleteFailed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div
    class="edit-backdrop"
    @click.self="emit('close')"
  >
    <aside
      class="edit"
      role="dialog"
      aria-modal="true"
      aria-labelledby="edit-title"
      @keydown.esc="emit('close')"
    >
      <header class="edit__head">
        <h2
          id="edit-title"
          class="edit__title"
        >
          {{ isNew ? t.newTitle : t.editTitle }}
        </h2>
        <button
          type="button"
          class="adm-btn"
          @click="emit('close')"
        >
          {{ ja.common.close }}
        </button>
      </header>

      <div class="edit__body">
        <form
          class="adm-form"
          novalidate
          @submit.prevent="save"
        >
          <div class="adm-field r-field">
            <label
              for="product-name"
              class="adm-field__label r-label"
            >{{ t.name }}</label>
            <input
              id="product-name"
              ref="nameInput"
              v-model="name"
              class="adm-input"
              maxlength="50"
              :aria-invalid="errors.name ? 'true' : undefined"
            >
            <p
              v-if="errors.name"
              class="adm-error r-err"
            >
              {{ errors.name }}
            </p>
          </div>

          <div class="adm-field r-field">
            <label
              for="product-memo"
              class="adm-field__label r-label"
            >{{ t.memo }}</label>
            <textarea
              id="product-memo"
              v-model="memo"
              class="adm-input r-input edit__memo"
              maxlength="200"
              rows="2"
              aria-describedby="product-memo-help"
              :aria-invalid="errors.memo ? 'true' : undefined"
            />
            <p
              id="product-memo-help"
              class="adm-help r-help"
            >
              {{ t.memoHelp }}
            </p>
            <p
              v-if="errors.memo"
              class="adm-error r-err"
            >
              {{ errors.memo }}
            </p>
          </div>

          <div class="adm-field r-field">
            <label
              for="product-code"
              class="adm-field__label r-label"
            >{{ t.code }}</label>
            <input
              id="product-code"
              v-model="code"
              class="adm-input r-input tabular"
              maxlength="20"
              autocapitalize="characters"
              autocomplete="off"
              spellcheck="false"
              :placeholder="isNew ? 'P0001' : undefined"
              aria-describedby="product-code-help"
              :aria-invalid="errors.code ? 'true' : undefined"
            >
            <p
              id="product-code-help"
              class="adm-help r-help"
            >
              {{ t.codeHelp }}
            </p>
            <p
              v-if="errors.code"
              class="adm-error r-err"
            >
              {{ errors.code }}
            </p>
          </div>

          <div class="adm-field r-field">
            <label class="adm-check"><input
              v-model="isDiscount"
              type="checkbox"
              :disabled="hasOptions"
              aria-describedby="is-discount-help"
            >{{ t.isDiscount }}</label>
            <p
              id="is-discount-help"
              class="adm-help r-help"
            >
              {{ hasOptions ? t.isDiscountHasOptions : t.isDiscountHelp }}
            </p>
            <p
              v-if="errors.is_discount"
              class="adm-error r-err"
            >
              {{ errors.is_discount }}
            </p>
          </div>

          <div class="adm-field r-field">
            <label
              for="product-price"
              class="adm-field__label r-label"
            >{{ isDiscount ? t.discountPrice : t.price }}</label>
            <input
              id="product-price"
              v-model="price"
              class="adm-input r-input tabular"
              inputmode="numeric"
              maxlength="10"
              :aria-invalid="errors.price ? 'true' : undefined"
            >
            <p
              v-if="errors.price"
              class="adm-error r-err"
            >
              {{ errors.price }}
            </p>
          </div>

          <div class="adm-field r-field">
            <label
              for="product-category"
              class="adm-field__label r-label"
            >{{ t.category }}</label>
            <select
              id="product-category"
              v-model="categoryId"
              class="adm-select r-input edit__category"
            >
              <option :value="null">
                {{ t.uncategorized }}
              </option>
              <option
                v-for="c in categories"
                :key="c.id"
                :value="c.id"
              >
                {{ c.name }}
              </option>
            </select>
            <p
              v-if="errors.category_id"
              class="adm-error r-err"
            >
              {{ errors.category_id }}
            </p>
          </div>

          <fieldset class="adm-field r-field edit__fieldset">
            <legend class="adm-field__label r-label">
              {{ t.color }}
            </legend>
            <div
              class="colors"
              role="radiogroup"
              :aria-label="t.color"
            >
              <button
                v-for="c in PRODUCT_COLORS"
                :key="c.key"
                type="button"
                role="radio"
                class="colors__btn"
                :class="`colors__btn--${c.key}`"
                :aria-checked="color === c.key"
                :aria-label="c.label"
                :title="c.label"
                @click="color = c.key"
              >
                <span
                  v-if="color === c.key"
                  aria-hidden="true"
                >✓</span>
              </button>
            </div>
          </fieldset>

          <label class="adm-check"><input
            v-model="isActive"
            type="checkbox"
          >{{ t.isActive }}</label>

          <div
            v-if="!isDiscount"
            class="adm-field r-field"
          >
            <label class="adm-check"><input
              v-model="trackStock"
              type="checkbox"
              aria-describedby="track-stock-help"
            >{{ t.trackStock }}</label>
            <p
              id="track-stock-help"
              class="adm-help r-help"
            >
              {{ t.trackStockHelp }}
            </p>
            <p
              v-if="trackStock && !stockEnabled"
              class="adm-help r-help"
              role="note"
            >
              {{ t.stockDisabledNotice }}
            </p>
          </div>

          <div
            v-if="!isDiscount"
            class="adm-field r-field"
          >
            <label class="adm-check"><input
              v-model="customerVisible"
              type="checkbox"
              aria-describedby="customer-visible-help"
            >{{ t.customerVisible }}</label>
            <p
              id="customer-visible-help"
              class="adm-help r-help"
            >
              {{ t.customerVisibleHelp }}
            </p>
          </div>

          <div
            v-if="trackStock && !isDiscount && isNew"
            class="adm-field r-field"
          >
            <label
              for="product-stock"
              class="adm-field__label r-label"
            >{{ t.stockQty }}</label>
            <input
              id="product-stock"
              v-model="initialStock"
              class="adm-input r-input tabular"
              inputmode="numeric"
              maxlength="7"
              :aria-invalid="errors.stock_qty ? 'true' : undefined"
            >
            <p
              v-if="errors.stock_qty"
              class="adm-error r-err"
            >
              {{ errors.stock_qty }}
            </p>
          </div>

          <p
            v-if="failed"
            class="adm-error r-err"
            role="alert"
          >
            {{ failed }}
          </p>
          <p
            v-if="savedMessage"
            class="adm-ok"
            role="status"
          >
            {{ savedMessage }}
          </p>
          <div class="r-savebar edit__savebar">
            <BigButton
              type="submit"
              :loading="saving"
              block
            >
              {{ ja.common.save }}
            </BigButton>
          </div>
        </form>

        <section
          v-if="trackStock && !isDiscount && current"
          class="edit__section r-card"
          aria-labelledby="stock-heading"
        >
          <h3
            id="stock-heading"
            class="edit__subtitle"
          >
            {{ t.stockQty }}
          </h3>
          <p class="edit__stock tabular">
            {{ fmt(t.stockCurrent, { n: current.stock_qty }) }}
          </p>
          <div class="adm-field r-field">
            <label
              for="stock-value"
              class="adm-field__label r-label"
            >{{ t.stockValue }}</label>
            <input
              id="stock-value"
              v-model="stockValue"
              class="adm-input r-input tabular"
              inputmode="numeric"
              maxlength="7"
              :aria-invalid="stockError ? 'true' : undefined"
            >
          </div>
          <p
            v-if="stockError"
            class="adm-error r-err"
            role="alert"
          >
            {{ stockError }}
          </p>
          <div class="adm-actions">
            <button
              type="button"
              class="adm-btn"
              :disabled="stockBusy"
              @click="applyStock('add')"
            >
              {{ t.stockAdd }}
            </button>
            <button
              type="button"
              class="adm-btn"
              :disabled="stockBusy"
              @click="applyStock('set')"
            >
              {{ t.stockSet }}
            </button>
          </div>
        </section>

        <section
          v-if="!isDiscount"
          class="edit__section r-card"
        >
          <OptionEditor
            v-if="current"
            :product-id="current.id"
            :options="options"
            :groups="optionGroups"
            @update:options="onOptionsChange"
            @update:groups="onGroupsChange"
          />
          <p
            v-else
            class="adm-help r-help"
          >
            {{ t.optionsSaveFirst }}
          </p>
        </section>

        <div
          v-if="current"
          class="edit__section"
        >
          <BigButton
            variant="danger"
            @click="confirmingDelete = true"
          >
            <template #icon>
              <AppIcon name="trash" />
            </template>
            {{ ja.common.delete }}
          </BigButton>
        </div>
      </div>
    </aside>

    <ConfirmDialog
      :open="confirmingDelete"
      :title="fmt(t.deleteTitle, { name: current?.name ?? '' })"
      :message="t.deleteMessage"
      :confirm-label="ja.common.delete"
      danger
      :loading="saving"
      :error="deleteFailed"
      @confirm="confirmDelete"
      @cancel="confirmingDelete = false"
    />
  </div>
</template>

<style scoped>
.edit-backdrop {
  position: fixed;
  inset: 0;
  z-index: 50;
  background: rgba(17, 24, 39, 0.35);
}

.edit {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  background: var(--c-surface);
}

.edit__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: calc(8px + var(--safe-top)) calc(16px + var(--safe-right)) 8px 16px;
  border-bottom: 1px solid var(--c-border);
}

.edit__title { font-size: var(--fs-heading); }

.edit__body {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  gap: 24px;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 16px calc(16px + var(--safe-right)) calc(32px + var(--safe-bottom)) calc(16px + var(--safe-left));
}

.edit__section { display: flex; flex-direction: column; gap: 12px; }
.edit__section.r-card { padding: 16px; }
/* 本文の下の余白（32px＋安全域）の分だけ下げて、パネルの下端に付ける（帯の下から入力欄が透けて見えないように） */
.edit__savebar { bottom: calc(-32px - var(--safe-bottom)); margin: 0 calc(-1 * var(--sp-4)); border-top: 1px solid var(--c-border-soft); }
.edit__subtitle { font-size: 20px; }
.edit__stock { font-size: 20px; font-weight: 700; }
.edit__category { width: 100%; }
.edit__memo { min-height: 96px; padding: 12px 14px; }
.edit__fieldset { margin: 0; padding: 0; border: 0; }

.colors { display: grid; grid-template-columns: repeat(5, minmax(var(--tap-min), 1fr)); gap: 8px; }

.colors__btn {
  min-height: var(--tap-min);
  border: 2px solid transparent;
  border-radius: 12px;
  font-size: 22px;
  font-weight: 700;
}

.colors__btn[aria-checked='true'] { border-color: transparent; box-shadow: 0 0 0 3px var(--c-surface), 0 0 0 6px var(--c-primary); }
.colors__btn--gray { background: var(--pc-gray-bg); color: var(--pc-gray-fg); }
.colors__btn--red { background: var(--pc-red-bg); color: var(--pc-red-fg); }
.colors__btn--orange { background: var(--pc-orange-bg); color: var(--pc-orange-fg); }
.colors__btn--yellow { background: var(--pc-yellow-bg); color: var(--pc-yellow-fg); }
.colors__btn--green { background: var(--pc-green-bg); color: var(--pc-green-fg); }
.colors__btn--teal { background: var(--pc-teal-bg); color: var(--pc-teal-fg); }
.colors__btn--blue { background: var(--pc-blue-bg); color: var(--pc-blue-fg); }
.colors__btn--indigo { background: var(--pc-indigo-bg); color: var(--pc-indigo-fg); }
.colors__btn--purple { background: var(--pc-purple-bg); color: var(--pc-purple-fg); }
.colors__btn--pink { background: var(--pc-pink-bg); color: var(--pc-pink-fg); }

@media (min-width: 768px) {
  .edit {
    inset: 0 0 0 auto;
    width: min(480px, 100%);
    box-shadow: var(--sh-sheet, -8px 0 24px rgba(0, 0, 0, 0.2));
  }
}
</style>
