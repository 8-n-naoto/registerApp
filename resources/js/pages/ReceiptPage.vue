<script setup lang="ts">
// S03 簡易領収書（08 §5.4）：/sales/:id/receipt。admin は ?store_id= で店舗を指定する。
// ［印刷］は window.print()。印刷時はボタンを隠す（@media print）
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchSale } from '@/api/register'
import BigButton from '@/components/BigButton.vue'
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
    <div class="receipt-page__bar">
      <BigButton
        variant="secondary"
        @click="close"
      >
        {{ t.close }}
      </BigButton>
      <BigButton
        :disabled="sale === null"
        @click="print"
      >
        {{ t.print }}
      </BigButton>
    </div>
    <p
      v-if="loading"
      class="receipt-page__message"
    >
      {{ ja.common.loading }}
    </p>
    <p
      v-else-if="failed"
      class="receipt-page__message receipt-page__message--error"
      role="alert"
    >
      {{ failed }}
    </p>
    <SaleReceipt
      v-else-if="sale"
      :sale="sale"
    />
  </main>
</template>

<style scoped>
.receipt-page {
  min-height: 100dvh;
  padding: calc(16px + var(--safe-top)) calc(16px + var(--safe-right)) calc(24px + var(--safe-bottom)) calc(16px + var(--safe-left));
  background: var(--c-surface-alt);
}

.receipt-page__bar {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  max-width: 360px;
  margin: 0 auto 16px;
}

.receipt-page__message { max-width: 360px; margin: 24px auto; text-align: center; }
.receipt-page__message--error { color: var(--c-danger); font-weight: 700; }

@media print {
  .receipt-page { min-height: 0; padding: 0; background: #fff; }
  .receipt-page__bar { display: none; }
}
</style>
