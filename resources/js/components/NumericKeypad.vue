<script setup lang="ts">
// 数字キー（08 §5.3 お会計ダイアログ）：0〜9、00、⌫、C。値は 0 以上 max 以下の整数（未入力は null）
import { ja } from '@/i18n/ja'

const props = withDefaults(defineProps<{ max?: number; disabled?: boolean }>(), { max: 99_999_999, disabled: false })

const model = defineModel<number | null>({ required: true })

const KEYS = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '0', '00', 'back'] as const
type Key = (typeof KEYS)[number] | 'clear'

function press(key: Key): void {
  if (key === 'clear') {
    model.value = null
    return
  }
  const current = model.value === null ? '' : String(model.value)
  if (key === 'back') {
    const next = current.slice(0, -1)
    model.value = next === '' ? null : Number(next)
    return
  }
  const next = Number(current + key)
  if (next <= props.max) model.value = next
}
</script>

<template>
  <div class="keypad">
    <button
      v-for="key in KEYS"
      :key="key"
      type="button"
      class="keypad__key tabular"
      :aria-label="key === 'back' ? ja.register.keyBackspace : undefined"
      :disabled="disabled"
      @click="press(key)"
    >
      {{ key === 'back' ? '⌫' : key }}
    </button>
    <button
      type="button"
      class="keypad__key keypad__key--clear"
      :disabled="disabled"
      @click="press('clear')"
    >
      {{ ja.register.keyClear }}
    </button>
  </div>
</template>

<style scoped>
.keypad {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}

.keypad__key {
  min-height: var(--numpad-key);
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 26px;
  font-weight: 700;
  user-select: none;
  -webkit-user-select: none;
}

.keypad__key:active:not(:disabled) { background: var(--c-surface-alt); }
.keypad__key--clear { grid-column: 1 / -1; min-height: var(--tap-min); font-size: 18px; }
.keypad__key:disabled { opacity: 0.55; }
</style>
