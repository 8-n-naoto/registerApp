<script setup lang="ts">
// 主要ボタン（08 §6）。送信中は無効化してスピナーを出す
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost-on-blue'
    size?: 'md' | 'lg' | 'xl'
    type?: 'button' | 'submit'
    loading?: boolean
    disabled?: boolean
    block?: boolean
  }>(),
  { variant: 'primary', size: 'md', type: 'button', loading: false, disabled: false, block: false },
)

defineEmits<{ click: [event: MouseEvent] }>()

const isDisabled = computed(() => props.disabled || props.loading)
</script>

<template>
  <button
    :type="type"
    class="big-btn"
    :class="[`big-btn--${variant}`, `big-btn--${size}`, { 'big-btn--block': block }]"
    :disabled="isDisabled"
    :aria-busy="loading"
    @click="$emit('click', $event)"
  >
    <span
      v-if="loading"
      class="big-btn__spinner"
      aria-hidden="true"
    />
    <span class="big-btn__label"><slot /></span>
  </button>
</template>

<style scoped>
.big-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-width: var(--tap-min);
  padding: 0 24px;
  border: 2px solid transparent;
  border-radius: var(--radius);
  font-weight: 700;
  line-height: 1.2;
  user-select: none;
  -webkit-user-select: none;
}

.big-btn--md { min-height: var(--btn-h); font-size: 18px; }
.big-btn--lg { min-height: var(--btn-h-lg); font-size: 20px; }
.big-btn--xl { min-height: var(--btn-h-confirm); font-size: 24px; }
.big-btn--block { display: flex; width: 100%; }

.big-btn--primary { background: var(--c-primary); color: var(--c-on-primary); }
.big-btn--primary:active:not(:disabled) { background: var(--c-primary-press); }

.big-btn--secondary { background: var(--c-surface); color: var(--c-primary); border-color: var(--c-primary); }
.big-btn--secondary:active:not(:disabled) { background: var(--c-surface-alt); }

.big-btn--danger { background: var(--c-danger); color: var(--c-on-primary); }
.big-btn--danger:active:not(:disabled) { background: var(--c-danger-press); }

.big-btn--ghost-on-blue { background: transparent; color: var(--c-on-primary); border-color: var(--c-on-primary); }
.big-btn--ghost-on-blue:active:not(:disabled) { background: rgba(255, 255, 255, 0.2); }

.big-btn:disabled { opacity: 0.55; cursor: not-allowed; }

.big-btn__spinner {
  width: 1em;
  height: 1em;
  border: 3px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  animation: big-btn-spin 0.8s linear infinite;
}

@keyframes big-btn-spin {
  to { transform: rotate(360deg); }
}
</style>
