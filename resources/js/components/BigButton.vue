<script setup lang="ts">
// ボタン（デザインシステム「レジアプリ」の r-btn）。送信中は無効化してスピナーを出す
// primary：主操作（画面に 1 つ） / secondary：白地に青枠 / quiet：薄い青の面 / plain：文字だけ
// danger：取り消せない操作の確定（赤い面） / danger-outline：削除などの入口（赤い枠） / ghost-on-blue：青い面の上
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'quiet' | 'plain' | 'danger' | 'danger-outline' | 'ghost-on-blue'
    size?: 'sm' | 'md' | 'lg' | 'xl'
    type?: 'button' | 'submit'
    loading?: boolean
    disabled?: boolean
    block?: boolean
  }>(),
  { variant: 'primary', size: 'md', type: 'button', loading: false, disabled: false, block: false },
)

defineEmits<{ click: [event: MouseEvent] }>()

const VARIANT: Record<NonNullable<typeof props.variant>, string> = {
  primary: 'r-btn--primary',
  secondary: 'r-btn--secondary',
  quiet: 'r-btn--quiet',
  plain: 'r-btn--plain',
  danger: 'r-btn--danger-fill',
  'danger-outline': 'r-btn--danger',
  'ghost-on-blue': 'r-btn--on-blue',
}
const SIZE: Record<NonNullable<typeof props.size>, string> = { sm: 'r-btn--sm', md: '', lg: 'r-btn--lg', xl: 'r-btn--xl' }

const isDisabled = computed(() => props.disabled || props.loading)
</script>

<template>
  <button
    :type="type"
    class="big-btn r-btn"
    :class="[`big-btn--${variant}`, VARIANT[variant], SIZE[size], { 'r-btn--block': block }]"
    :disabled="isDisabled"
    :aria-busy="loading"
    @click="$emit('click', $event)"
  >
    <span
      v-if="loading"
      class="r-spinner"
      aria-hidden="true"
    />
    <slot name="icon" />
    <span class="big-btn__label"><slot /></span>
  </button>
</template>
