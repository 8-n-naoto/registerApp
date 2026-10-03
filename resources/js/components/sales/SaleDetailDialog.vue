<script setup lang="ts">
// S05 会計詳細・取消（08 §5.6）。S04 の上に開く。領収書と同じ内容に担当者・端末名・メモ・客数を加える。
// 取消の応答は cancelled で親に渡し、親は再取得せずに S04 を差し替える（AC-S05-1）
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { cancelSale, fetchSale } from '@/api/register'
import BigButton from '@/components/BigButton.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ReceiptPrint from '@/components/ReceiptPrint.vue'
import SaleReceipt from '@/components/SaleReceipt.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, isNetworkError } from '@/lib/apiError'
import { formatDateTime } from '@/lib/date'
import { formatYen } from '@/lib/money'
import { useAuthStore } from '@/stores/auth'
import type { Sale } from '@/types/api'

const props = defineProps<{
  saleId: number | null
  storeId: number | null // admin が閲覧する店舗
  readOnly: boolean // admin は閲覧のみ（AC-S04-4）
  staffDate: string | null // staff のときの現在の営業日。これ以外の日の会計は取り消せない（AC-S05-2）
}>()
const emit = defineEmits<{ close: []; cancelled: [sale: Sale]; stale: [] }>()

const t = ja.saleDetail
// 15 §8.3 プリンターがある店舗は［レシートを印刷］（「領収書（再発行）」）。admin（閲覧のみ）には出さない
const auth = useAuthStore()
const printer = computed(() => (props.readOnly ? null : (auth.me?.store?.printer ?? null)))

const sale = ref<Sale | null>(null)
const loading = ref(false)
const loadFailed = ref<string | null>(null)
const confirming = ref(false)
const cancelling = ref(false)
const cancelError = ref<string | null>(null)
const notice = ref<string | null>(null)

const canCancel = computed(() => {
  const s = sale.value
  if (s === null || props.readOnly || s.status !== 'completed') return false
  return props.staffDate === null || s.business_date === props.staffDate
})

const receiptTo = computed(() => ({
  name: 'receipt',
  params: { id: String(props.saleId) },
  query: props.storeId === null ? {} : { store_id: String(props.storeId) },
}))

watch(
  () => props.saleId,
  async (id) => {
    sale.value = null
    loadFailed.value = null
    notice.value = null
    confirming.value = false
    if (id === null) return
    loading.value = true
    try {
      sale.value = await fetchSale(id, props.storeId)
    } catch (err) {
      loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

function openConfirm(): void {
  cancelError.value = null
  confirming.value = true
}

async function doCancel(): Promise<void> {
  const current = sale.value
  if (current === null || cancelling.value) return
  cancelling.value = true
  cancelError.value = null
  try {
    const updated = await cancelSale(current.id)
    sale.value = updated
    confirming.value = false
    notice.value = t.cancelled
    emit('cancelled', updated)
  } catch (err) {
    if (errorBody(err)?.code === 'ALREADY_CANCELLED') {
      // AC-S05-3：別の端末で取り消し済み。表示を取り直す
      confirming.value = false
      notice.value = t.alreadyCancelled
      emit('stale')
      try {
        sale.value = await fetchSale(current.id, props.storeId)
      } catch {
        // 取り直せなくても取消済みの案内は出ている
      }
    } else {
      cancelError.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? ja.error.unexpected)
    }
  } finally {
    cancelling.value = false
  }
}
</script>

<template>
  <BottomSheet
    :open="saleId !== null"
    :title="t.title"
    @close="emit('close')"
  >
    <p
      v-if="loading"
      role="status"
    >
      {{ ja.common.loading }}
    </p>
    <p
      v-else-if="loadFailed"
      class="detail__error r-banner r-banner--danger"
      role="alert"
    >
      {{ loadFailed }}
    </p>
    <div
      v-else-if="sale"
      class="detail"
    >
      <p
        v-if="notice"
        class="detail__notice r-banner r-banner--info"
        role="status"
      >
        {{ notice }}
      </p>
      <SaleReceipt :sale="sale" />
      <dl class="detail__meta">
        <div class="detail__row">
          <dt>{{ t.user }}</dt>
          <dd>{{ sale.user_name }}</dd>
        </div>
        <div class="detail__row">
          <dt>{{ t.device }}</dt>
          <dd>{{ sale.device_name ?? t.none }}</dd>
        </div>
        <div class="detail__row">
          <dt>{{ t.customers }}</dt>
          <dd>{{ sale.customer_count === null ? t.none : fmt(ja.daily.customersValue, { n: sale.customer_count }) }}</dd>
        </div>
        <div class="detail__row">
          <dt>{{ t.memo }}</dt>
          <dd class="detail__memo">
            {{ sale.memo ?? t.none }}
          </dd>
        </div>
        <p
          v-if="sale.status === 'cancelled' && sale.cancelled_at"
          class="detail__cancelled"
        >
          {{ fmt(t.cancelledBy, { at: formatDateTime(sale.cancelled_at), name: sale.cancelled_by_name ?? '' }) }}
        </p>
      </dl>
      <div class="detail__actions">
        <RouterLink
          :to="receiptTo"
          class="detail__link r-btn r-btn--secondary"
        >
          {{ t.receipt }}
        </RouterLink>
        <ReceiptPrint
          v-if="printer"
          :sale="sale"
          :printer="printer"
          kind="reprint"
          size="md"
        />
        <BigButton
          v-if="canCancel"
          variant="danger"
          @click="openConfirm"
        >
          {{ t.cancel }}
        </BigButton>
      </div>
    </div>
  </BottomSheet>
  <ConfirmDialog
    :open="confirming"
    :title="t.cancelTitle"
    :message="sale ? fmt(t.confirmCancel, { amount: formatYen(sale.total) }) : ''"
    :confirm-label="t.cancelConfirm"
    :loading="cancelling"
    :error="cancelError"
    danger
    @confirm="doCancel"
    @cancel="confirming = false"
  />
</template>

<style scoped>
.detail { display: flex; flex-direction: column; gap: 16px; width: 100%; max-width: 480px; margin: 0 auto; }
.detail__error { color: var(--c-danger); font-weight: 700; }

.detail__notice { font-weight: 700; }

.detail__meta { display: flex; flex-direction: column; gap: 4px; margin: 0; }
.detail__row { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; border-bottom: 1px solid var(--c-border-soft); }
.detail__row dt { color: var(--c-text-sub); font-weight: 700; white-space: nowrap; }
.detail__row dd { margin: 0; text-align: right; }
.detail__memo { overflow-wrap: anywhere; white-space: pre-wrap; }
.detail__cancelled { color: var(--c-danger); font-weight: 700; }

.detail__actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; }

.detail__link { text-decoration: none; }
</style>
