<script setup lang="ts">
// 値引きの入力（08 §5.3・06 §4.2）：金額（1〜9,999,999 円）または率（1〜100%）。1 会計に 1 つ
import { computed, ref, watch } from 'vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import { ja } from '@/i18n/ja'
import type { Discount } from '@/stores/register'
import type { DiscountType } from '@/types/api'

const props = defineProps<{ open: boolean; current: Discount | null }>()
const emit = defineEmits<{ apply: [discount: Discount | null]; cancel: [] }>()

const t = ja.register
const type = ref<DiscountType>('amount')
const text = ref('')
const error = ref<string | null>(null)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    type.value = props.current?.type ?? 'amount'
    text.value = props.current ? String(props.current.value) : ''
    error.value = null
  },
)

const typeOptions = computed(() => [
  { value: 'amount' as const, label: t.discountAmount },
  { value: 'percent' as const, label: t.discountPercent },
])

function apply(): void {
  const raw = text.value.trim()
  const value = /^\d{1,8}$/.test(raw) ? Number(raw) : NaN
  const max = type.value === 'amount' ? 9_999_999 : 100
  if (!Number.isSafeInteger(value) || value < 1 || value > max) {
    error.value = type.value === 'amount' ? t.discountAmountInvalid : t.discountPercentInvalid
    return
  }
  emit('apply', { type: type.value, value })
}
</script>

<template>
  <ConfirmDialog
    :open="open"
    :title="t.discountTitle"
    :confirm-label="t.discountApply"
    :error="error"
    @confirm="apply"
    @cancel="emit('cancel')"
  >
    <SegmentedControl
      v-model="type"
      :options="typeOptions"
      :label="t.discountTitle"
    />
    <label class="discount__field r-field">
      <span>{{ t.discountValue }}</span>
      <input
        v-model="text"
        class="r-input"
        type="text"
        inputmode="numeric"
        pattern="[0-9]*"
        autocomplete="off"
        @keydown.enter.prevent="apply"
      >
    </label>
    <button
      v-if="current"
      type="button"
      class="discount__remove r-btn r-btn--danger"
      @click="emit('apply', null)"
    >
      {{ t.discountRemove }}
    </button>
  </ConfirmDialog>
</template>

<style scoped>
.discount__field { font-weight: 700; }
.discount__field input { font-size: 24px; }
.discount__remove { align-self: flex-start; }
</style>
