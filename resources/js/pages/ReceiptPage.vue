<script setup lang="ts">
// S03 簡易領収書（08 §5.4）：/sales/:id/receipt。admin は ?store_id= で店舗を指定する。
// ［印刷］は window.print()。印刷時はボタンを隠す（@media print）
// 15 §8.3 プリンターがある店舗は本文の上に［レシートを印刷］（「領収書（再発行）」）。admin は店舗を持たないので出ない
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchSale } from '@/api/register'
import AppIcon from '@/components/AppIcon.vue'
import ReceiptPrint from '@/components/ReceiptPrint.vue'
import SaleReceipt from '@/components/SaleReceipt.vue'
import { ja } from '@/i18n/ja'
import { errorBody, errorStatus, isNetworkError } from '@/lib/apiError'
import { useAuthStore } from '@/stores/auth'
import type { Sale } from '@/types/api'

const t = ja.receipt
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const sale = ref<Sale | null>(null)
const loading = ref(true)
const failed = ref<string | null>(null)
const printer = computed(() => auth.me?.store?.printer ?? null)

const storeId = computed(() => {
  const raw = route.query.store_id
  return typeof raw === 'string' && /^\d+$/.test(raw) ? Number(raw) : null
})

onMounted(async () => {
  try {
    sale.value = await fetchSale(Number(route.params.id), storeId.value)
  } catch (err) {
    if (isNetworkError(err)) failed.value = ja.error.network
    else if (errorStatus(err) === 403) failed.value = t.forbidden
    else if (errorStatus(err) === 404) failed.value = t.notFound
    else failed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    loading.value = false
  }
})

function print(): void {
  window.print()
}

/** 開いた画面へ戻る。直接開いた場合は役割のホームへ */
function close(): void {
  if (typeof window.history.state?.back === 'string') router.back()
  else void router.push(auth.homeRoute)
}
</script>

<template>
  <main class="receipt-page">
    <header class="receipt-page__bar r-appbar">
      <button
        type="button"
        class="r-appbar__back"
        @click="close"
      >
        <AppIcon
          name="back"
          :size="24"
        />
        <span>{{ t.close }}</span>
      </button>
      <span class="r-appbar__title">{{ t.title }}</span>
      <button
        type="button"
        class="r-appbar__act"
        :disabled="sale === null"
        @click="print"
      >
        <AppIcon
          name="print"
          :size="24"
        />
        <span>{{ t.print }}</span>
      </button>
    </header>
    <div class="receipt-page__body">
      <p
        v-if="loading"
        class="receipt-page__message"
        role="status"
      >
        {{ ja.common.loading }}
      </p>
      <div
        v-else-if="failed"
        class="receipt-page__message r-banner r-banner--danger"
        role="alert"
      >
        <AppIcon
          name="alert"
          :size="24"
        />
        <span class="r-banner__d">{{ failed }}</span>
      </div>
      <template v-else-if="sale">
        <ReceiptPrint
          v-if="printer"
          class="receipt-page__thermal"
          :sale="sale"
          :printer="printer"
          kind="reprint"
          size="md"
        />
        <SaleReceipt :sale="sale" />
      </template>
    </div>
  </main>
</template>

<style scoped>
.receipt-page { min-height: 100dvh; background: var(--c-surface-alt); }

.receipt-page__body {
  padding: 16px calc(16px + var(--safe-right)) calc(24px + var(--safe-bottom)) calc(16px + var(--safe-left));
}

.receipt-page__message { max-width: 360px; margin: 24px auto; }
.receipt-page__bar button:disabled { opacity: 0.5; }
.receipt-page__thermal { max-width: 360px; margin: 0 auto 16px; }

@media print {
  .receipt-page { min-height: 0; background: #fff; }
  .receipt-page__body { padding: 0; }
  .receipt-page__bar { display: none; }
  .receipt-page__thermal { display: none; }
}
</style>
