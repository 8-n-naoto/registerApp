<script setup lang="ts">
// オプションのある商品を押したときの選択（08 §5.3、docs/10「オプションのグループ」）
// 「1つ選ぶ」グループは「最初に選ぶ」を選んだ状態で開き、1 つ選ぶまで追加できない。「いくつでも」とグループなしは選ばずに追加もできる
import { computed, ref, watch } from 'vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import OptionChoices from '@/components/OptionChoices.vue'
import { fmt, ja } from '@/i18n/ja'
import { buildSections, initialSelection, isSingle, missingGroups, orderedSelection } from '@/lib/optionGroups'
import type { Product } from '@/types/api'

const props = defineProps<{ product: Product | null }>()
const emit = defineEmits<{ add: [optionIds: number[]]; cancel: [] }>()

const t = ja.register
const selected = ref<number[]>([])
const error = ref<string | null>(null)

const options = computed(() =>
  (props.product?.options ?? []).filter((o) => o.is_active).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id),
)
const groups = computed(() => props.product?.option_groups ?? [])
const sections = computed(() => buildSections(options.value, groups.value))
const hasSingle = computed(() => sections.value.some((s) => isSingle(s.group)))

watch(() => props.product, () => {
  selected.value = initialSelection(sections.value)
  error.value = null
})

watch(selected, () => { error.value = null })

function add(): void {
  const missing = missingGroups(sections.value, selected.value)[0]
  if (missing) {
    error.value = fmt(ja.optionChoices.missing, { name: missing.name })
    return
  }
  emit('add', orderedSelection(sections.value, selected.value))
}
</script>

<template>
  <ConfirmDialog
    :open="product !== null"
    :title="fmt(t.optionsTitle, { name: product?.name ?? '' })"
    :message="hasSingle ? t.optionsHelpChoose : t.optionsHelp"
    :confirm-label="t.optionsAdd"
    :error="error"
    @confirm="add"
    @cancel="emit('cancel')"
  >
    <OptionChoices
      v-if="product"
      v-model="selected"
      :options="options"
      :groups="groups"
      :base-price="product.price"
    />
  </ConfirmDialog>
</template>
