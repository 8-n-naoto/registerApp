<script setup lang="ts">
// 線のアイコン（デザインシステム）。色は文字色（currentColor）を使う。意味は隣の文字で伝え、アイコンは読み上げない
import { computed } from 'vue'
import { ICONS, type IconName } from '@/lib/icons'

const props = withDefaults(defineProps<{ name: IconName; size?: number }>(), { size: 24 })

const shapes = computed(() => ICONS[props.name])
</script>

<template>
  <svg
    class="ic"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <component
      :is="shape[0]"
      v-for="(shape, i) in shapes"
      :key="i"
      v-bind="shape[1]"
    />
  </svg>
</template>
