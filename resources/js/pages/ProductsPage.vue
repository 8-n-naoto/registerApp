<script setup lang="ts">
// S08 商品管理（08 §5.9）：カテゴリのタブ、商品の一覧（タブレットはグリッド、スマホはリスト）、
// 並び替え、編集パネル、CSV 一括登録。?import=1 で CSV 一括登録ダイアログを開く
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  createCategory, deleteCategory, fetchCatalog, reorderCategories, reorderProducts, updateCategory,
} from '@/api/catalog'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ProductEditPanel from '@/components/products/ProductEditPanel.vue'
import ProductImportDialog from '@/components/products/ProductImportDialog.vue'
import ProductTile from '@/components/ProductTile.vue'
import SortableList from '@/components/SortableList.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { useIsTablet } from '@/lib/breakpoint'
import type { Category, Product } from '@/types/api'
import '@/styles/admin.css'

type Tab = 'all' | 'none' | number

const t = ja.products
const route = useRoute()
const router = useRouter()
const isTablet = useIsTablet()

const loading = ref(true)
const loadFailed = ref<string | null>(null)
const categories = ref<Category[]>([])
const products = ref<Product[]>([])
const tab = ref<Tab>('all')

const bySort = (a: { sort_order: number; id: number }, b: { sort_order: number; id: number }): number =>
  a.sort_order - b.sort_order || a.id - b.id

/** すべて：カテゴリの並び順 → 未分類の順に、カテゴリ内は sort_order */
const visibleProducts = computed<Product[]>(() => {
  const sorted = [...products.value].sort(bySort)
  if (tab.value === 'none') return sorted.filter((p) => p.category_id === null)
  if (typeof tab.value === 'number') return sorted.filter((p) => p.category_id === tab.value)
  const rank = new Map(categories.value.map((c, i) => [c.id, i]))
  const rankOf = (p: Product): number => (p.category_id === null ? Infinity : (rank.get(p.category_id) ?? Infinity))
  return sorted.sort((a, b) => rankOf(a) - rankOf(b) || bySort(a, b))
})

/** 読み上げ用の名前。同名の商品をメモとコードで区別できるようにする */
const productLabel = (p: Product): string => [p.name, p.memo ? `（${p.memo}）` : '', ` ${p.code}`].join('')

const selectedCategory = computed(() => (typeof tab.value === 'number' ? categories.value.find((c) => c.id === tab.value) ?? null : null))

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    const data = await fetchCatalog()
    categories.value = [...data.categories].sort(bySort)
    products.value = data.products
    if (typeof tab.value === 'number' && !categories.value.some((c) => c.id === tab.value)) tab.value = 'all'
  } catch (err) {
    if (!isNetworkError(err)) loadFailed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    loading.value = false
  }
}

function productCount(categoryId: number): number {
  return products.value.filter((p) => p.category_id === categoryId).length
}

// ── 編集パネル
const editing = ref<Product | null | 'new'>(null)

function onSaved(saved: Product): void {
  const exists = products.value.some((p) => p.id === saved.id)
  products.value = exists ? products.value.map((p) => (p.id === saved.id ? saved : p)) : [...products.value, saved]
}

function onDeleted(id: number): void {
  products.value = products.value.filter((p) => p.id !== id)
  editing.value = null
}

// ── 並び替え（すべて：カテゴリの並び、各タブ：商品の並び）
const reordering = ref(false)
const productDraft = ref<Product[]>([])
const categoryDraft = ref<Category[]>([])
const reorderSaving = ref(false)
const reorderFailed = ref<string | null>(null)

function startReorder(): void {
  reorderFailed.value = null
  if (tab.value === 'all') categoryDraft.value = [...categories.value]
  else productDraft.value = [...visibleProducts.value]
  reordering.value = true
}

async function finishReorder(): Promise<void> {
  if (reorderSaving.value) return
  reorderSaving.value = true
  reorderFailed.value = null
  try {
    if (tab.value === 'all') {
      await reorderCategories(categoryDraft.value.map((c) => c.id))
      categories.value = categoryDraft.value.map((c, i) => ({ ...c, sort_order: i }))
    } else {
      await reorderProducts(productDraft.value.map((p) => p.id))
      const order = new Map(productDraft.value.map((p, i) => [p.id, i]))
      products.value = products.value.map((p) => (order.has(p.id) ? { ...p, sort_order: order.get(p.id) ?? p.sort_order } : p))
    }
    reordering.value = false
  } catch (err) {
    if (!isNetworkError(err)) reorderFailed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    reorderSaving.value = false
  }
}

function selectTab(next: Tab): void {
  if (reordering.value) return
  tab.value = next
}

// ── カテゴリの追加・名前変更・削除
const categoryForm = ref<{ mode: 'new' | 'edit'; name: string } | null>(null)
const categoryError = ref<string | null>(null)
const categoryBusy = ref(false)
const confirmingCategoryDelete = ref(false)

function openNewCategory(): void {
  categoryForm.value = { mode: 'new', name: '' }
  categoryError.value = null
}

function openEditCategory(): void {
  if (!selectedCategory.value) return
  categoryForm.value = { mode: 'edit', name: selectedCategory.value.name }
  categoryError.value = null
}

async function saveCategory(): Promise<void> {
  const f = categoryForm.value
  if (!f || categoryBusy.value) return
  categoryBusy.value = true
  categoryError.value = null
  try {
    if (f.mode === 'new') {
      const created = await createCategory(f.name.trim())
      categories.value = [...categories.value, created]
      tab.value = created.id
    } else if (selectedCategory.value) {
      const saved = await updateCategory(selectedCategory.value.id, f.name.trim())
      categories.value = categories.value.map((c) => (c.id === saved.id ? saved : c))
    }
    categoryForm.value = null
  } catch (err) {
    if (errorStatus(err) === 422) categoryError.value = fieldErrors(err).name ?? errorBody(err)?.message ?? null
    else if (!isNetworkError(err)) categoryError.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    categoryBusy.value = false
  }
}

async function removeCategory(): Promise<void> {
  const target = selectedCategory.value
  if (!target || categoryBusy.value) return
  categoryBusy.value = true
  categoryError.value = null
  try {
    await deleteCategory(target.id)
    // 所属していた商品は未分類に移る（06 §7.8・AC-S08-4）
    categories.value = categories.value.filter((c) => c.id !== target.id)
    products.value = products.value.map((p) => (p.category_id === target.id ? { ...p, category_id: null } : p))
    confirmingCategoryDelete.value = false
    categoryForm.value = null
    tab.value = 'none'
  } catch (err) {
    if (!isNetworkError(err)) categoryError.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    categoryBusy.value = false
  }
}

// ── CSV 一括登録（?import=1）
const importing = computed(() => route.query.import === '1')

function openImport(): void {
  void router.replace({ query: { ...route.query, import: '1' } })
}

function closeImport(): void {
  const query = { ...route.query }
  delete query.import
  void router.replace({ query })
}

onMounted(load)
</script>

<template>
  <div class="adm-page">
    <AppHeader :title="t.title" />
    <main class="adm-body products">
      <div class="adm-actions">
        <BigButton
          :disabled="loading || reordering"
          @click="editing = 'new'"
        >
          {{ t.addProduct }}
        </BigButton>
        <BigButton
          variant="secondary"
          :disabled="reordering"
          @click="openImport"
        >
          {{ t.import }}
        </BigButton>
      </div>

      <p
        v-if="loading"
        role="status"
      >
        {{ ja.common.loading }}
      </p>
      <p
        v-else-if="loadFailed"
        class="adm-error"
        role="alert"
      >
        {{ loadFailed }}
      </p>

      <template v-else>
        <nav
          class="tabs"
          :aria-label="t.category"
        >
          <button
            type="button"
            class="tabs__tab"
            :aria-pressed="tab === 'all'"
            :disabled="reordering && tab !== 'all'"
            @click="selectTab('all')"
          >
            {{ t.all }}
          </button>
          <button
            type="button"
            class="tabs__tab"
            :aria-pressed="tab === 'none'"
            :disabled="reordering && tab !== 'none'"
            @click="selectTab('none')"
          >
            {{ t.uncategorized }}
          </button>
          <button
            v-for="c in categories"
            :key="c.id"
            type="button"
            class="tabs__tab"
            :aria-pressed="tab === c.id"
            :disabled="reordering && tab !== c.id"
            @click="selectTab(c.id)"
          >
            {{ c.name }}
          </button>
          <button
            type="button"
            class="tabs__tab tabs__tab--add"
            :disabled="reordering"
            @click="openNewCategory"
          >
            {{ t.addCategory }}
          </button>
        </nav>

        <div class="adm-actions">
          <button
            v-if="selectedCategory && !reordering"
            type="button"
            class="adm-btn"
            @click="openEditCategory"
          >
            {{ ja.common.edit }}
          </button>
          <button
            v-if="!reordering"
            type="button"
            class="adm-btn"
            :disabled="tab === 'all' ? categories.length < 2 : visibleProducts.length < 2"
            @click="startReorder"
          >
            {{ tab === 'all' ? t.categoryReorder : ja.common.reorder }}
          </button>
          <BigButton
            v-else
            :loading="reorderSaving"
            @click="finishReorder"
          >
            {{ ja.common.done }}
          </BigButton>
        </div>
        <p
          v-if="reorderFailed"
          class="adm-error"
          role="alert"
        >
          {{ reorderFailed }}
        </p>

        <template v-if="reordering">
          <p class="adm-help">
            {{ tab === 'all' ? ja.storeSettings.reorderHelp : t.reorderHelp }}
          </p>
          <SortableList
            v-if="tab === 'all'"
            v-model:items="categoryDraft"
            :label="t.categoryReorder"
            :handle-label="(c) => fmt(ja.common.moveHandle, { name: c.name })"
          >
            <template #default="{ item }">
              <div class="adm-row">
                <span class="adm-row__main">{{ item.name }}</span>
              </div>
            </template>
          </SortableList>
          <SortableList
            v-else
            v-model:items="productDraft"
            :layout="isTablet ? 'grid' : 'list'"
            :label="t.reorderHelp"
            :handle-label="(p) => fmt(ja.common.moveHandle, { name: p.name })"
          >
            <template #default="{ item }">
              <ProductTile
                :product="item"
                :layout="isTablet ? 'tile' : 'row'"
                show-code
              />
            </template>
          </SortableList>
        </template>

        <p
          v-else-if="visibleProducts.length === 0"
          class="adm-help"
        >
          {{ t.empty }}
        </p>

        <ul
          v-else
          class="items"
          :class="isTablet ? 'items--grid' : 'items--list'"
        >
          <li
            v-for="p in visibleProducts"
            :key="p.id"
          >
            <button
              type="button"
              class="items__btn"
              :aria-label="fmt(ja.common.editNamed, { name: productLabel(p) })"
              @click="editing = p"
            >
              <ProductTile
                :product="p"
                :layout="isTablet ? 'tile' : 'row'"
                show-code
              />
            </button>
          </li>
        </ul>
      </template>
    </main>

    <ProductEditPanel
      v-if="editing !== null"
      :key="editing === 'new' ? 'new' : editing.id"
      :product="editing === 'new' ? null : editing"
      :categories="categories"
      :default-category-id="typeof tab === 'number' ? tab : null"
      @saved="onSaved"
      @deleted="onDeleted"
      @close="editing = null"
    />

    <ConfirmDialog
      :open="categoryForm !== null && !confirmingCategoryDelete"
      :title="categoryForm?.mode === 'new' ? t.categoryNew : t.categoryEdit"
      :confirm-label="ja.common.save"
      :loading="categoryBusy"
      :error="categoryError"
      @confirm="saveCategory"
      @cancel="categoryForm = null"
    >
      <div
        v-if="categoryForm"
        class="adm-field"
      >
        <label
          for="category-name"
          class="adm-field__label"
        >{{ t.categoryName }}</label>
        <input
          id="category-name"
          v-model="categoryForm.name"
          class="adm-input"
          maxlength="30"
          @keydown.enter.prevent="saveCategory"
        >
      </div>
      <button
        v-if="categoryForm?.mode === 'edit'"
        type="button"
        class="adm-btn adm-btn--danger"
        @click="confirmingCategoryDelete = true"
      >
        {{ ja.common.delete }}
      </button>
    </ConfirmDialog>

    <ConfirmDialog
      :open="confirmingCategoryDelete"
      :title="fmt(t.categoryDeleteTitle, { name: selectedCategory?.name ?? '' })"
      :message="fmt(t.categoryDeleteMessage, { n: selectedCategory ? productCount(selectedCategory.id) : 0 })"
      :confirm-label="ja.common.delete"
      danger
      :loading="categoryBusy"
      :error="categoryError"
      @confirm="removeCategory"
      @cancel="confirmingCategoryDelete = false"
    />

    <ProductImportDialog
      v-if="importing"
      @imported="load"
      @close="closeImport"
    />
  </div>
</template>

<style scoped>
.products { max-width: 1200px; }

.tabs {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 4px;
  -webkit-overflow-scrolling: touch;
}

.tabs__tab {
  flex: 0 0 auto;
  min-width: var(--tap-min);
  min-height: var(--tab-h);
  padding: 0 18px;
  border: 2px solid var(--c-primary);
  border-radius: 999px;
  background: var(--c-surface);
  color: var(--c-primary);
  font-size: 18px;
  font-weight: 700;
  white-space: nowrap;
}

.tabs__tab[aria-pressed='true'] { background: var(--c-primary); color: var(--c-on-primary); }
.tabs__tab:disabled { opacity: 0.45; }
.tabs__tab--add { border-style: dashed; }

.items { margin: 0; padding: 0; list-style: none; }
.items--list { display: flex; flex-direction: column; gap: 8px; }

.items--grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(var(--product-min-w, 140px), 1fr));
  gap: var(--product-gap);
}

.items__btn {
  display: block;
  width: 100%;
  height: 100%;
  padding: 0;
  border: 0;
  border-radius: var(--radius);
  background: none;
  text-align: left;
}
</style>
