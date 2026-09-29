<script setup lang="ts">
// 商品エリア（08 §5.3）：カテゴリのタブと商品ボタン。S02 会計と S13 注文入力（12 §8.4）で使う。
// 押せるかどうか（在庫・数量の上限）は呼び出し側が canPress で決める
import { computed, ref } from 'vue'
import ProductTile from '@/components/ProductTile.vue'
import { ja } from '@/i18n/ja'
import type { Category, Product } from '@/types/api'

type Tab = 'all' | 'none' | number

const props = defineProps<{
  products: Product[]
  categories: Category[]
  canPress: (product: Product) => boolean
  tablet: boolean
  label: string
}>()
const emit = defineEmits<{ press: [product: Product] }>()

const t = ja.register
const tab = ref<Tab>('all')
const bySort = (a: { sort_order: number; id: number }, b: { sort_order: number; id: number }): number =>
  a.sort_order - b.sort_order || a.id - b.id

const categories = computed(() => [...props.categories].sort(bySort).filter((c) => c.product_count > 0))
const hasUncategorized = computed(() => props.products.some((p) => p.category_id === null))

/** すべて：カテゴリの並び順 → 未分類の順に、カテゴリ内は sort_order */
const visibleProducts = computed<Product[]>(() => {
  const sorted = [...props.products].sort(bySort)
  if (tab.value === 'none') return sorted.filter((p) => p.category_id === null)
  if (typeof tab.value === 'number') return sorted.filter((p) => p.category_id === tab.value)
  const rank = new Map(categories.value.map((c, i) => [c.id, i]))
  const rankOf = (p: Product): number => (p.category_id === null ? Infinity : (rank.get(p.category_id) ?? Infinity))
  return sorted.sort((a, b) => rankOf(a) - rankOf(b) || bySort(a, b))
})

/** 押せない商品。商品 500 件でもタップ時に全ボタンを描き直さないよう v-memo の鍵に使う（08 §10） */
const blocked = computed(() => new Set(visibleProducts.value.filter((p) => !props.canPress(p)).map((p) => p.id)))

function press(product: Product): void {
  if (!blocked.value.has(product.id)) emit('press', product)
}
</script>

<template>
  <section
    class="products"
    :class="{ 'products--tablet': tablet }"
    :aria-label="label"
  >
    <nav
      class="tabs"
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
</template>

<style scoped>
.products { display: flex; flex: 1 1 auto; flex-direction: column; min-width: 0; }

.tabs {
  display: flex;
  flex-shrink: 0;
  gap: 8px;
  overflow-x: auto;
  padding: 8px 12px;
  scrollbar-width: none;
}

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

/* タブレット：画面の高さに収め、商品の列だけをスクロールする（AC-S02-13） */
.products--tablet { flex: 0 0 62%; overflow: hidden; }
.products--tablet .grid {
  grid-template-columns: repeat(auto-fill, minmax(var(--product-min-w, 140px), 1fr));
  overflow-y: auto;
  padding-bottom: calc(12px + var(--safe-bottom));
}
</style>
