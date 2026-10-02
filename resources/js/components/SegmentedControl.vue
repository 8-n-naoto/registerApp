<script setup lang="ts" generic="T extends string">
// 切替（デザインシステム「レジアプリ」の r-seg）。同じデータの見方や択一の値を選ぶ。radiogroup として読み上げる
defineProps<{
  options: readonly { value: T; label: string }[]
  label: string
  disabled?: boolean
}>()

const model = defineModel<T>({ required: true })
</script>

<template>
  <div
    class="segmented r-seg"
    role="radiogroup"
    :aria-label="label"
  >
    <button
      v-for="opt in options"
      :key="opt.value"
      type="button"
      role="radio"
      class="segmented__btn"
      :class="{ 'segmented__btn--on on': model === opt.value }"
      :aria-checked="model === opt.value"
      :disabled="disabled"
      @click="model = opt.value"
    >
      {{ opt.label }}
    </button>
  </div>
</template>
