<script setup lang="ts">
// 15 §8 レシートの印刷の入口と結果（完了のポップアップ・会計の詳細・領収書で共通）。
// 送信と結果は receiptPrinter ストアが持つ（ポップアップを閉じても送信は続く）。自動では送り直さない
import { computed } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
import BigButton from '@/components/BigButton.vue'
import { ja } from '@/i18n/ja'
import { saleKey, useReceiptPrinterStore } from '@/stores/receiptPrinter'
import type { PrinterSettings, Sale } from '@/types/api'

const props = withDefaults(
  defineProps<{ sale: Sale; printer: PrinterSettings; kind: 'receipt' | 'reprint'; size?: 'sm' | 'md' }>(),
  { size: 'sm' },
)
const emit = defineEmits<{ press: [] }>()

const t = ja.print
const printerStore = useReceiptPrinterStore()
const job = computed(() => printerStore.jobFor(saleKey(props.sale)))

function print(): void {
  emit('press')
  void printerStore.print(props.printer, props.sale, props.kind)
}
</script>

<template>
  <div
    class="receipt-print"
    data-testid="receipt-print"
    :data-state="job?.state ?? 'idle'"
  >
    <BigButton
      v-if="job === null"
      variant="secondary"
      :size="size"
      @click="print"
    >
      <AppIcon
        name="print"
        :size="20"
      />
      {{ t.printReceipt }}
    </BigButton>
    <p
      v-else-if="job.state === 'printing'"
      class="receipt-print__msg"
      role="status"
    >
      <span
        class="r-spinner"
        aria-hidden="true"
      />
      {{ t.printing }}
    </p>
    <p
      v-else-if="job.state === 'printed'"
      class="receipt-print__msg receipt-print__msg--ok"
      role="status"
    >
      <AppIcon
        name="check"
        :size="20"
      />
      {{ t.printed }}
    </p>
    <template v-else>
      <p
        class="receipt-print__msg receipt-print__msg--error"
        role="alert"
      >
        {{ t.reasons[job.reason ?? 'unknown'] }}
      </p>
      <BigButton
        variant="secondary"
        :size="size"
        @click="print"
      >
        {{ t.retry }}
      </BigButton>
    </template>
  </div>
</template>

<style scoped>
.receipt-print { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px 12px; width: 100%; }
.receipt-print__msg { display: inline-flex; align-items: center; gap: 8px; min-height: var(--tap-min); margin: 0; color: var(--c-text); font-size: 16px; font-weight: 700; }
.receipt-print__msg--ok { color: var(--st-ok-fg); }
.receipt-print__msg--error { width: 100%; padding: 12px 16px; border-radius: 8px; background: var(--st-danger-bg); color: var(--st-danger-fg); text-align: left; }
</style>
