<script setup lang="ts">
// 14 §7.5 送信待ちの会計の帯（レジ S02・ホーム S00）。
// 件数・送れなかった理由・［今すぐ送る］［もう一度送る］［書き出す］。送れなかった会計は書き出してから端末から消せる
import { ref } from 'vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import MoneyText from '@/components/MoneyText.vue'
import { fmt, ja } from '@/i18n/ja'
import { useAuthStore } from '@/stores/auth'
import { useCatalogStore } from '@/stores/catalog'
import { useOutboxStore } from '@/stores/outbox'

const t = ja.outbox
const auth = useAuthStore()
const catalog = useCatalogStore()
const outbox = useOutboxStore()

const showDetail = ref(false)
const deleting = ref<string | null>(null)
const exportError = ref<string | null>(null)

function exportFile(): void {
  exportError.value = null
  try {
    const blob = new Blob([outbox.exportJson()], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    const stamp = new Date().toISOString().replace(/[:.]/g, '-')
    a.href = url
    a.download = `regi-offline-sales-${stamp}.json`
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch {
    exportError.value = t.exportFailed
  }
}

async function confirmDelete(): Promise<void> {
  const uuid = deleting.value
  if (uuid === null) return
  await outbox.deleteFailed(uuid)
  deleting.value = null
}

function time(iso: string): string {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('ja-JP', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div
    v-if="auth.offlineSession || catalog.fromCache || outbox.count > 0"
    class="outbox-wrap"
  >
    <div
      v-if="auth.offlineSession || catalog.fromCache"
      class="outbox r-banner r-banner--warn"
      role="status"
      data-testid="offline-session"
    >
      <span class="grow">{{ auth.offlineSession ? t.offlineSession : t.catalogCached }}</span>
    </div>
    <div
      v-if="outbox.count > 0"
      class="outbox r-banner"
      :class="outbox.failed.length > 0 ? 'r-banner--danger' : 'r-banner--info'"
      role="status"
      data-testid="outbox-banner"
    >
      <div class="outbox__text grow">
        <span
          v-if="outbox.pending.length > 0"
          class="r-banner__t"
        >{{ fmt(t.pending, { n: outbox.pending.length }) }}</span>
        <span
          v-if="outbox.failed.length > 0"
          class="r-banner__t"
        >{{ fmt(t.failed, { n: outbox.failed.length }) }}</span>
        <span
          v-if="outbox.syncing"
          class="r-banner__d"
        >{{ t.sending }}</span>
        <span
          v-else-if="outbox.authNeeded"
          class="r-banner__d"
        >{{ t.authNeeded }}</span>
        <span
          v-else-if="outbox.lastError"
          class="r-banner__d"
        >{{ outbox.lastError }}</span>
        <span
          v-if="exportError"
          class="r-banner__d"
          role="alert"
        >{{ exportError }}</span>
      </div>
      <div class="outbox__actions">
        <button
          v-if="outbox.pending.length > 0"
          type="button"
          class="r-btn r-btn--secondary r-btn--sm"
          :disabled="outbox.syncing"
          data-testid="outbox-send"
          @click="outbox.syncNow()"
        >
          {{ t.sendNow }}
        </button>
        <button
          v-if="outbox.failed.length > 0"
          type="button"
          class="r-btn r-btn--secondary r-btn--sm"
          :disabled="outbox.syncing"
          data-testid="outbox-retry"
          @click="outbox.retryFailed()"
        >
          {{ t.retry }}
        </button>
        <button
          type="button"
          class="r-btn r-btn--plain r-btn--sm"
          data-testid="outbox-export"
          @click="exportFile"
        >
          {{ t.export }}
        </button>
        <button
          type="button"
          class="r-btn r-btn--plain r-btn--sm"
          :aria-expanded="showDetail"
          @click="showDetail = !showDetail"
        >
          {{ showDetail ? ja.common.close : t.detailTitle }}
        </button>
      </div>
      <ul
        v-if="showDetail"
        class="outbox__list"
      >
        <li
          v-for="e in outbox.entries"
          :key="e.client_uuid"
          class="outbox__item"
        >
          <span class="outbox__when">{{ time(e.created_at) }}</span>
          <MoneyText
            :amount="e.sale.total"
            size="body"
          />
          <span
            v-if="e.status === 'failed'"
            class="outbox__reason"
          >{{ fmt(t.failedReason, { reason: e.last_error ?? '' }) }}</span>
          <BigButton
            v-if="e.status === 'failed'"
            variant="danger"
            size="md"
            @click="deleting = e.client_uuid"
          >
            {{ t.deleteFailed }}
          </BigButton>
        </li>
      </ul>
    </div>
    <ConfirmDialog
      :open="deleting !== null"
      :title="t.deleteTitle"
      :confirm-label="t.deleteConfirm"
      danger
      @confirm="confirmDelete"
      @cancel="deleting = null"
    />
  </div>
</template>

<style scoped>
.outbox-wrap { display: flex; flex-direction: column; gap: 8px; }
.outbox { flex-wrap: wrap; align-items: center; }
.outbox__text { display: flex; flex-direction: column; gap: 2px; min-width: 200px; }
.outbox__actions { display: flex; flex-wrap: wrap; gap: 8px; }
.outbox__list { width: 100%; margin: 0; padding: 0; list-style: none; }
.outbox__item { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; min-height: 48px; padding: 8px 0; border-top: 1px solid var(--c-border-soft); color: var(--c-text); font-size: 16px; }
.outbox__when { font-weight: 700; }
.outbox__reason { flex: 1 1 200px; color: var(--st-danger-fg); }
</style>
