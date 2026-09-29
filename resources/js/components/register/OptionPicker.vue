<script setup lang="ts">
// オプションのある商品を押したときの選択（08 §5.3）。複数選べる。何も選ばずに追加もできる
import { computed, ref, watch } from 'vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { fmt, ja } from '@/i18n/ja'
import { formatYen } from '@/lib/money'
import type { Product } from '@/types/api'

const props = defineProps<{ product: Product | null }>()
const emit = defineEmits<{ add: [optionIds: number[]]; cancel: [] }>()

const t = ja.register
const selected = ref<number[]>([])

watch(() => props.product, () => { selected.value = [] })

const options = computed(() =>
  (props.product?.options ?? []).filter((o) => o.is_active).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id),
)

function toggle(id: number): void {
  selected.value = selected.value.includes(id) ? selected.value.filter((v) => v !== id) : [...selected.value, id]
}
</script>

<template>
  <ConfirmDialog
    :open="product !== null"
    :title="fmt(t.optionsTitle, { name: product?.name ?? '' })"
    :message="t.optionsHelp"
    :confirm-label="t.optionsAdd"
    @confirm="emit('add', selected)"
    @cancel="emit('cancel')"
  >
    <div class="options">
      <button
        v-for="option in options"
        :key="option.id"
        type="button"
        role="checkbox"
        class="option"
        :class="{ 'option--on': selected.includes(option.id) }"
        :aria-checked="selected.includes(option.id)"
        @click="toggle(option.id)"
      >
        <span>{{ option.name }}</span>
        <span class="tabular">＋{{ formatYen(option.price) }}</span>
      </button>
    </div>
  </ConfirmDialog>
</template>

<style scoped>
.options { display: flex; flex-direction: column; gap: 8px; }
.option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: var(--btn-h);
  padding: 0 16px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  font-size: 18px;
  font-weight: 700;
  text-align: left;
}
.option--on { border-color: var(--c-primary); background: var(--c-primary); color: var(--c-on-primary); }
</style>
