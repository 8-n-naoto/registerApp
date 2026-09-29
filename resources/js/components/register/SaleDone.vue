<script setup lang="ts">
// 確定後の完了表示（08 §5.3）：合計・預かり・お釣りを大きく。［領収書を表示］［次の会計］。
// 確定から 5 秒間だけ［取り消す（N）］を出す（B 案。確認なし）。5 秒経つか、ほかのボタンを押すと消える
import { onBeforeUnmount, onMounted, ref } from 'vue'
import BigButton from '@/components/BigButton.vue'
import MoneyText from '@/components/MoneyText.vue'
import { fmt, ja } from '@/i18n/ja'
import type { Sale } from '@/types/api'

const UNDO_SECONDS = 5

defineProps<{ sale: Sale; undoing: boolean }>()
const emit = defineEmits<{ receipt: []; next: []; undo: [] }>()

const t = ja.register
const remaining = ref(UNDO_SECONDS)
let timer: ReturnType<typeof setInterval> | null = null

function stopTimer(): void {
  if (timer !== null) clearInterval(timer)
  timer = null
}

onMounted(() => {
  timer = setInterval(() => {
    remaining.value -= 1
    if (remaining.value <= 0) stopTimer()
  }, 1000)
})
onBeforeUnmount(stopTimer)

function other(action: 'receipt' | 'next'): void {
  stopTimer()
  remaining.value = 0
  if (action === 'receipt') emit('receipt')
  else emit('next')
}
</script>

<template>
  <Teleport to="body">
    <div class="done-backdrop">
      <section
        class="done"
        role="dialog"
        aria-modal="true"
        aria-labelledby="done-title"
      >
        <h2
          id="done-title"
          class="done__title"
        >
          {{ t.doneTitle }}
        </h2>
        <dl class="done__sums">
          <div class="done__row">
            <dt>{{ t.total }}</dt>
            <dd>
              <MoneyText
                :amount="sale.total"
                size="total"
                tone="money"
              />
            </dd>
          </div>
          <template v-if="sale.is_cash">
            <div class="done__row">
              <dt>{{ t.received }}</dt>
              <dd>
                <MoneyText
                  :amount="sale.received"
                  size="amount"
                />
              </dd>
            </div>
            <div class="done__row">
              <dt>{{ t.change }}</dt>
              <dd>
                <MoneyText
                  :amount="sale.change_amount"
                  size="change"
                  tone="change"
                />
              </dd>
            </div>
          </template>
          <div
            v-else
            class="done__row"
          >
            <dt>{{ t.paymentMethod }}</dt>
            <dd class="done__method">
              {{ sale.payment_method_name }}
            </dd>
          </div>
        </dl>
        <div class="done__actions">
          <BigButton
            variant="secondary"
            size="lg"
            @click="other('receipt')"
          >
            {{ t.receipt }}
          </BigButton>
          <BigButton
            size="xl"
            class="done__next"
            @click="other('next')"
          >
            {{ t.next }}
          </BigButton>
        </div>
        <BigButton
          v-if="remaining > 0"
          variant="danger"
          size="md"
          block
          :loading="undoing"
          @click="emit('undo')"
        >
          {{ fmt(t.undo, { sec: remaining }) }}
        </BigButton>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.done-backdrop {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(17, 24, 39, 0.5);
}

.done {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: min(560px, 100%);
  max-height: calc(100dvh - 32px);
  overflow-y: auto;
  padding: 24px;
  border-radius: var(--radius-card);
  background: var(--c-surface);
  color: var(--c-text);
}

.done__title { font-size: var(--fs-heading); color: var(--c-success); }
.done__sums { margin: 0; }
.done__row { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; min-height: 48px; }
.done__row dt { font-size: 20px; font-weight: 700; color: var(--c-text-sub); }
.done__row dd { margin: 0; }
.done__method { font-size: 24px; font-weight: 700; }
.done__actions { display: flex; gap: 12px; flex-wrap: wrap; }
.done__next { flex: 1 1 auto; }
</style>
