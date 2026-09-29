<script setup lang="ts">
// 確認ダイアログ（08 §6）。危険な操作は赤いボタン。開いたら［やめる］にフォーカスし、Esc で閉じる
import { nextTick, ref, watch } from 'vue'
import BigButton from '@/components/BigButton.vue'
import { ja } from '@/i18n/ja'

const props = withDefaults(
  defineProps<{
    open: boolean
    title: string
    message?: string
    confirmLabel: string
    danger?: boolean
    loading?: boolean
    error?: string | null
  }>(),
  { message: '', danger: false, loading: false, error: null },
)

const emit = defineEmits<{ confirm: []; cancel: [] }>()

const panel = ref<HTMLElement | null>(null)

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    await nextTick()
    panel.value?.querySelector<HTMLElement>('[data-cancel]')?.focus()
  },
)

function cancel(): void {
  if (!props.loading) emit('cancel')
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="dialog-backdrop"
      @click.self="cancel"
      @keydown.esc="cancel"
    >
      <div
        ref="panel"
        class="dialog"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-dialog-title"
        :aria-describedby="message ? 'confirm-dialog-message' : undefined"
      >
        <h2
          id="confirm-dialog-title"
          class="dialog__title"
        >
          {{ title }}
        </h2>
        <p
          v-if="message"
          id="confirm-dialog-message"
          class="dialog__message"
        >
          {{ message }}
        </p>
        <slot />
        <p
          v-if="error"
          class="dialog__error"
          role="alert"
        >
          {{ error }}
        </p>
        <div class="dialog__actions">
          <button
            type="button"
            class="dialog__cancel"
            data-cancel
            :disabled="loading"
            @click="cancel"
          >
            {{ ja.common.cancel }}
          </button>
          <BigButton
            :variant="danger ? 'danger' : 'primary'"
            :loading="loading"
            @click="emit('confirm')"
          >
            {{ confirmLabel }}
          </BigButton>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.dialog-backdrop {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(17, 24, 39, 0.5);
}

.dialog {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: min(480px, 100%);
  max-height: calc(100dvh - 32px);
  overflow-y: auto;
  padding: 24px;
  border-radius: var(--radius-card);
  background: var(--c-surface);
  color: var(--c-text);
}

.dialog__title { font-size: var(--fs-heading); }
.dialog__message { white-space: pre-line; }
.dialog__error { color: var(--c-danger); font-weight: 700; }
.dialog__actions { display: flex; justify-content: flex-end; gap: 12px; flex-wrap: wrap; }

.dialog__cancel {
  min-width: var(--tap-min);
  min-height: var(--btn-h);
  padding: 0 24px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  font-size: 18px;
  font-weight: 700;
}
</style>
