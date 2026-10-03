<script setup lang="ts">
// 確定後の完了表示（08 §5.3）：合計・預かり・お釣りを大きく。［領収書を表示］［次の会計］。
// 確定から 5 秒間だけ［取り消す（N）］を出す（B 案。確認なし）。5 秒経つか、ほかのボタンを押すと消える。
// ［取り消す］を押したら数えるのを止め、結果をこのポップアップの中で出す：
// 成功は「会計を取り消しました」に表示を切り替え（領収書・お釣りは出さない）、失敗は理由を出して取り消しボタンを消す
// 14 §7.4 端末に保存した会計（まだ送っていない）は領収書を出さず、「端末に保存しました」を出す
// 15 §8.2 プリンターがある店舗はレシートの印刷の状態（送信中・成功・失敗＋［もう一度印刷］）か、小さく［レシートを印刷］を出す。
// 印刷した会計を取り消したら、レシートの回収のお願いを足す
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
import BigButton from '@/components/BigButton.vue'
import MoneyText from '@/components/MoneyText.vue'
import ReceiptPrint from '@/components/ReceiptPrint.vue'
import { fmt, ja } from '@/i18n/ja'
import { saleKey, useReceiptPrinterStore } from '@/stores/receiptPrinter'
import type { PrinterSettings, Sale } from '@/types/api'

const UNDO_SECONDS = 5

const props = defineProps<{ sale: Sale; undoing: boolean; undone: boolean; undoError: string | null; printer: PrinterSettings | null }>()
const emit = defineEmits<{ receipt: []; next: []; undo: []; print: [] }>()

const t = ja.register
const offline = computed(() => props.sale.is_offline && props.sale.id === 0)
const printerStore = useReceiptPrinterStore()
/** 印刷を送った（送信中を含む）会計か。取り消したときに回収のお願いを出す */
const printed = computed(() => {
  const job = printerStore.jobFor(saleKey(props.sale))
  return job !== null && job.state !== 'failed'
})
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

/** 手で［レシートを印刷］を押したら、ほかのボタンと同じく取り消しの残り時間を消す */
function pressPrint(): void {
  stopTimer()
  remaining.value = 0
  emit('print')
}

function undo(): void {
  if (props.undoing) return
  // 通信中に 0 秒になってボタンが消えないよう、押した時点で数えるのを止める
  stopTimer()
  emit('undo')
}
</script>

<template>
  <Teleport to="body">
    <div class="done-backdrop r-scrim r-scrim--center">
      <section
        v-if="undone"
        class="done r-dialog"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="done-title"
        aria-describedby="done-body"
        data-state="undone"
      >
        <span class="c-done__mark done__mark--undone">
          <AppIcon
            name="x"
            :size="48"
          />
        </span>
        <h2
          id="done-title"
          class="done__title r-h1"
        >
          {{ t.undoneTitle }}
        </h2>
        <p
          id="done-body"
          class="done__body"
        >
          {{ t.undoneBody }}
        </p>
        <p
          v-if="printed"
          class="done__collect"
          role="alert"
          data-testid="done-collect"
        >
          {{ ja.print.collectReceipt }}
        </p>
        <dl class="done__sums">
          <div class="done__row done__row--sub">
            <dt>{{ t.undoneTotal }}</dt>
            <dd class="done__void">
              <MoneyText
                :amount="sale.total"
                size="amount"
                tone="inherit"
              />
            </dd>
          </div>
        </dl>
        <BigButton
          size="xl"
          block
          @click="other('next')"
        >
          {{ t.next }}
        </BigButton>
      </section>
      <section
        v-else
        class="done r-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="done-title"
        data-state="done"
      >
        <span class="c-done__mark">
          <AppIcon
            name="check"
            :size="48"
          />
        </span>
        <h2
          id="done-title"
          class="done__title r-h1"
        >
          {{ t.doneTitle }}
        </h2>
        <dl class="done__sums">
          <div class="done__row done__row--sub">
            <dt>{{ t.total }}</dt>
            <dd>
              <MoneyText
                :amount="sale.total"
                size="amount"
                tone="default"
              />
            </dd>
          </div>
          <template v-if="sale.is_cash">
            <div class="done__row done__row--sub">
              <dt>{{ t.received }}</dt>
              <dd>
                <MoneyText
                  :amount="sale.received"
                  size="amount"
                />
              </dd>
            </div>
            <div class="done__row done__row--change">
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
        <p
          v-if="offline"
          class="done__offline"
          role="status"
          data-testid="done-offline"
        >
          {{ ja.outbox.savedOffline }}
        </p>
        <ReceiptPrint
          v-if="printer"
          :sale="sale"
          :printer="printer"
          kind="receipt"
          @press="pressPrint"
        />
        <div class="done__actions">
          <BigButton
            v-if="!offline"
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
        <p
          v-if="undoError"
          class="done__error"
          role="alert"
        >
          {{ undoError }}
        </p>
        <BigButton
          v-else-if="remaining > 0 || undoing"
          variant="danger"
          size="md"
          block
          :loading="undoing"
          @click="undo"
        >
          {{ fmt(t.undo, { sec: remaining }) }}
        </BigButton>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.done-backdrop { z-index: 100; }

.done {
  align-items: center;
  max-width: 620px;
  max-height: calc(100dvh - 32px);
  overflow-y: auto;
  padding: 32px 24px 24px;
  text-align: center;
}

.done__title { color: var(--c-text); }
.done__sums { width: 100%; margin: 0; }
.done__row { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; min-height: 48px; }
.done__row dt { color: var(--c-text-sub); font-size: 18px; font-weight: 700; }
.done__row dd { margin: 0; }
.done__row--change { flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 8px 0; }
.done__method { font-size: 24px; font-weight: 700; }
.done__actions { display: flex; flex-wrap: wrap; gap: 12px; width: 100%; }
.done__next { flex: 1 1 auto; }
.done__mark--undone { background: var(--st-danger-bg); color: var(--c-danger); }
.done__body { margin: 0; color: var(--c-text); font-size: 18px; font-weight: 700; }
.done__void { text-decoration: line-through; text-decoration-thickness: 3px; color: var(--c-text-sub); }
.done__offline { width: 100%; margin: 0; padding: 12px 16px; border-radius: 8px; background: var(--st-info-bg); color: var(--st-info-fg); font-size: 16px; font-weight: 700; }
.done__collect { width: 100%; margin: 0; padding: 12px 16px; border-radius: 8px; background: var(--st-warn-bg); color: var(--st-warn-fg); font-size: 16px; font-weight: 700; }
.done__error { width: 100%; margin: 0; padding: 12px 16px; border-radius: 8px; background: var(--st-danger-bg); color: var(--st-danger-fg); font-size: 16px; font-weight: 700; }
</style>
