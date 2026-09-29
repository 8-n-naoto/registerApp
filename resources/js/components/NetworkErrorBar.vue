<script setup lang="ts">
// 通信エラーの赤い帯（08 §6・§8）。次に通信できたとき、または［閉じる］で消える
import { ja } from '@/i18n/ja'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
</script>

<template>
  <div
    v-if="ui.networkError"
    class="network-bar"
    role="alert"
  >
    <span>{{ ja.error.network }}</span>
    <button
      type="button"
      class="network-bar__close"
      @click="ui.networkError = false"
    >
      {{ ja.common.close }}
    </button>
  </div>
</template>

<style scoped>
.network-bar {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 200;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: calc(8px + var(--safe-top)) calc(var(--gutter) + var(--safe-right)) 8px calc(var(--gutter) + var(--safe-left));
  background: var(--c-danger);
  color: var(--c-on-primary);
  font-weight: 700;
}

.network-bar__close {
  flex-shrink: 0;
  min-width: var(--tap-min);
  min-height: var(--tap-min);
  padding: 0 16px;
  border: 2px solid var(--c-on-primary);
  border-radius: var(--radius);
  background: transparent;
  color: var(--c-on-primary);
  font-weight: 700;
}
</style>
