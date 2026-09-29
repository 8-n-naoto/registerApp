<script setup lang="ts">
// 下から出るシート（スマホの注文一覧・保留一覧など）。背景のタップと Esc で閉じる
import { nextTick, ref, watch } from 'vue'
import { ja } from '@/i18n/ja'

const props = defineProps<{ open: boolean; title: string }>()
const emit = defineEmits<{ close: [] }>()

const panel = ref<HTMLElement | null>(null)

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    await nextTick()
    panel.value?.focus()
  },
)
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="sheet-backdrop"
      @click.self="emit('close')"
      @keydown.esc="emit('close')"
    >
      <section
        ref="panel"
        class="sheet"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
        tabindex="-1"
      >
        <header class="sheet__head">
          <h2 class="sheet__title">
            {{ title }}
          </h2>
          <button
            type="button"
            class="sheet__close"
            @click="emit('close')"
          >
            {{ ja.common.close }}
          </button>
        </header>
        <div class="sheet__body">
          <slot />
        </div>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.sheet-backdrop {
  position: fixed;
  inset: 0;
  z-index: 90;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  background: rgba(17, 24, 39, 0.5);
}

.sheet {
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - 40px - var(--safe-top));
  border-radius: var(--radius-card) var(--radius-card) 0 0;
  background: var(--c-surface);
  color: var(--c-text);
  outline: none;
}

.sheet__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 8px 8px 16px;
  border-bottom: 1px solid var(--c-border);
}

.sheet__title { font-size: 20px; }

.sheet__close {
  min-width: var(--tap-min);
  min-height: var(--tap-min);
  padding: 0 16px;
  border: 0;
  background: transparent;
  color: var(--c-primary);
  font-weight: 700;
}

.sheet__body {
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow-y: auto;
  padding: 12px 16px calc(12px + var(--safe-bottom));
}
</style>
