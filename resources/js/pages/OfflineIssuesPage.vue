<script setup lang="ts">
// 14 §7.6 オフライン会計の確認（owner）：送信時に問題を記録した会計を新しい順に出し、内容を見て［確認済みにする］
import { onMounted, ref } from 'vue'
import { fetchOfflineIssues, reviewOfflineSale } from '@/api/register'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import MoneyText from '@/components/MoneyText.vue'
import SaleDetailDialog from '@/components/sales/SaleDetailDialog.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, isNetworkError } from '@/lib/apiError'
import { formatDateTime } from '@/lib/date'
import { describeIssues } from '@/lib/offlineIssues'
import type { Sale } from '@/types/api'
import '@/styles/admin.css'

const t = ja.offlineIssues

const sales = ref<Sale[]>([])
const loading = ref(true)
const loadFailed = ref<string | null>(null)
const notice = ref<string | null>(null)
const failed = ref<string | null>(null)
const reviewing = ref<number | null>(null)
const openSaleId = ref<number | null>(null)

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    sales.value = await fetchOfflineIssues()
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function review(sale: Sale): Promise<void> {
  if (reviewing.value !== null) return
  reviewing.value = sale.id
  notice.value = null
  failed.value = null
  try {
    await reviewOfflineSale(sale.id)
    sales.value = sales.value.filter((s) => s.id !== sale.id)
    notice.value = t.reviewed
  } catch (err) {
    failed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? ja.error.unexpected)
  } finally {
    reviewing.value = null
  }
}
</script>

<template>
  <div class="adm-page">
    <AppHeader :title="t.title" />
    <main class="adm-body">
      <p
        v-if="loading"
        role="status"
      >
        {{ ja.common.loading }}
      </p>
      <div
        v-else-if="loadFailed"
        class="adm-panel r-card"
      >
        <p
          class="adm-error r-err"
          role="alert"
        >
          {{ loadFailed }}
        </p>
        <div class="adm-actions">
          <BigButton @click="load">
            {{ t.retry }}
          </BigButton>
        </div>
      </div>
      <section
        v-else
        class="adm-panel r-card"
        aria-labelledby="offline-heading"
      >
        <h2
          id="offline-heading"
          class="adm-panel__title r-h2"
        >
          {{ t.title }}
        </h2>
        <p class="adm-help">
          {{ t.lead }}
        </p>
        <p
          v-if="notice"
          class="adm-ok"
          role="status"
        >
          {{ notice }}
        </p>
        <p
          v-if="failed"
          class="adm-error"
          role="alert"
        >
          {{ failed }}
        </p>
        <p
          v-if="sales.length === 0"
          class="adm-help"
        >
          {{ t.empty }}
        </p>
        <ul
          v-else
          class="adm-list"
        >
          <li
            v-for="sale in sales"
            :key="sale.id"
            class="offline-row"
            data-testid="offline-issue"
          >
            <div class="offline-row__head">
              <MoneyText
                :amount="sale.total"
                size="amount"
              />
              <span>{{ sale.payment_method_name }}・{{ sale.user_name }}</span>
            </div>
            <p class="offline-row__meta">
              {{ fmt(t.soldAt, { time: formatDateTime(sale.client_sold_at ?? sale.sold_at) }) }}
              <template v-if="sale.synced_at">
                ／{{ fmt(t.syncedAt, { time: formatDateTime(sale.synced_at) }) }}
              </template>
            </p>
            <ul class="offline-row__issues">
              <li
                v-for="(text, i) in describeIssues(sale.sync_issues)"
                :key="i"
              >
                {{ text }}
              </li>
            </ul>
            <div class="adm-actions">
              <button
                type="button"
                class="adm-btn"
                @click="openSaleId = sale.id"
              >
                {{ t.detail }}
              </button>
              <BigButton
                :loading="reviewing === sale.id"
                @click="review(sale)"
              >
                {{ t.review }}
              </BigButton>
            </div>
          </li>
        </ul>
      </section>
    </main>
    <SaleDetailDialog
      :sale-id="openSaleId"
      :store-id="null"
      :read-only="false"
      :staff-date="null"
      @close="openSaleId = null"
      @cancelled="load"
      @stale="load"
    />
  </div>
</template>

<style scoped>
.offline-row { display: flex; flex-direction: column; gap: 8px; padding: 16px; border: 1px solid var(--c-border-soft); border-radius: var(--radius); background: var(--c-surface); }
.offline-row__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 12px; font-size: 16px; font-weight: 700; }
.offline-row__meta { margin: 0; color: var(--c-text-sub); font-size: 15px; }
.offline-row__issues { margin: 0; padding-left: 20px; color: var(--c-text); font-size: 16px; line-height: 1.6; }
</style>
