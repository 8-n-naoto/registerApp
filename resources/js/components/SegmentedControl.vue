<script setup lang="ts" generic="T extends string">
// 択一ボタン（08 §6）。［税込で登録］［税抜で登録］など。radiogroup として読み上げる
defineProps<{
  options: readonly { value: T; label: string }[]
  label: string
  disabled?: boolean
}>()

const model = defineModel<T>({ required: true })
</script>

<template>
  <div
    class="segmented"
    role="radiogroup"
    :aria-label="label"
  >
    <button
      v-for="opt in options"
      :key="opt.value"
      type="button"
      role="radio"
      class="segmented__btn"
      :class="{ 'segmented__btn--on': model === opt.value }"
      :aria-checked="model === opt.value"
      :disabled="disabled"
      @click="model = opt.value"
    >
      {{ opt.label }}
    </button>
  </div>
</template>

<style scoped>
.segmented {
  display: flex;
  flex-wrap: wrap;
  gap: 0;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
  overflow: hidden;
}

.segmented__btn {
  flex: 1 1 0;
  min-width: 96px;
  min-height: var(--btn-h);
  padding: 0 12px;
  border: 0;
  border-left: 2px solid var(--c-primary);
  background: var(--c-surface);
  color: var(--c-primary);
  font-size: 18px;
  font-weight: 700;
}

.segmented__btn:first-child { border-left: 0; }
.segmented__btn--on { background: var(--c-primary); color: var(--c-on-primary); }
.segmented__btn:disabled { opacity: 0.55; }
</style>
