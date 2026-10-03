<script setup lang="ts">
// 通信エラーの赤い帯（08 §6・§8）。次に通信できたとき、または［閉じる］で消える
// 14 §7.5 送信待ちの会計があるときは「会計は保存されていません」と出さず、端末に保存していることを出す
import AppIcon from '@/components/AppIcon.vue'
import { ja } from '@/i18n/ja'
import { useOutboxStore } from '@/stores/outbox'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const outbox = useOutboxStore()
</script>

<template>
  <div
    v-if="ui.networkError"
    class="network-bar r-netbar"
    role="alert"
  >
    <AppIcon
      name="wifioff"
      :size="24"
    />
    <span class="network-bar__msg">{{ outbox.count > 0 ? ja.outbox.networkBar : ja.error.network }}</span>
    <button
      type="button"
      class="network-bar__close r-btn r-btn--on-blue r-btn--sm"
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
  padding: calc(8px + var(--safe-top)) calc(16px + var(--safe-right)) 8px calc(16px + var(--safe-left));
}

.network-bar__msg { flex: 1; min-width: 0; }
</style>
