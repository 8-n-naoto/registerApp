<script setup lang="ts">
// S12 操作ログ（08 §5.14）：日時・担当者・操作・対象・変更内容。操作の種類で絞り込み、［もっと見る］で次のページ。
// admin は ?store_id= があればその店舗、無ければ全店舗（06 §10）
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchLogs } from '@/api/logs'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, isNetworkError } from '@/lib/apiError'
import { AUDIT_ACTIONS, describeChanges, describeTarget, isAuditAction, type AuditActionCode } from '@/lib/auditLog'
import { formatDateTime } from '@/lib/date'
import { useAdminStore } from '@/stores/admin'
import { useAuthStore } from '@/stores/auth'
import type { AuditLogRow } from '@/types/api'
import '@/styles/admin.css'

const t = ja.auditLog
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const admin = useAdminStore()

const storeId = computed(() => {
  const raw = route.query.store_id
  return auth.isAdmin && typeof raw === 'string' && /^\d+$/.test(raw) ? Number(raw) : null
})
const action = computed<AuditActionCode | null>(() => (isAuditAction(route.query.action) ? route.query.action : null))
const showStore = computed(() => auth.isAdmin && storeId.value === null)

const rows = ref<AuditLogRow[]>([])
const page = ref(0)
const lastPage = ref(0)
const total = ref(0)
const loading = ref(false)
const loadFailed = ref<string | null>(null)

const hasMore = computed(() => page.value < lastPage.value)
const actionOptions = AUDIT_ACTIONS.map((code) => ({ code, label: t.actions[code] }))

/** 次のページを読む。reset なら 1 ページ目から読み直す */
async function load(reset: boolean): Promise<void> {
  if (loading.value) return
  loading.value = true
  loadFailed.value = null
  const next = reset ? 1 : page.value + 1
  try {
    const res = await fetchLogs(next, action.value, storeId.value)
    rows.value = reset ? res.data : [...rows.value, ...res.data]
    page.value = res.meta.current_page
    lastPage.value = res.meta.last_page
    total.value = res.meta.total
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

watch([action, storeId], () => load(true), { immediate: true })

function onFilter(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  const query = { ...route.query }
  if (isAuditAction(value)) query.action = value
  else delete query.action
  void router.replace({ query })
}
</script>

<template>
  <div class="adm-page">
    <AppHeader
      :title="t.title"
      :viewing-store-name="auth.isAdmin ? admin.viewingLabel(storeId) : null"
    />
    <main class="adm-body">
      <section
        class="adm-panel"
        aria-labelledby="log-heading"
      >
        <div class="adm-panel__head">
          <h2
            id="log-heading"
            class="adm-panel__title"
          >
            {{ t.title }}
          </h2>
          <label class="adm-field log-filter">
            <span class="adm-field__label">{{ t.filter }}</span>
            <select
              class="adm-select r-input"
              :value="action ?? ''"
              data-test="filter"
              @change="onFilter"
            >
              <option value="">{{ t.filterAll }}</option>
              <option
                v-for="o in actionOptions"
                :key="o.code"
                :value="o.code"
              >
                {{ o.label }}
              </option>
            </select>
          </label>
        </div>

        <p
          v-if="page > 0"
          class="adm-help"
        >
          {{ fmt(t.total, { n: total }) }}
        </p>
        <p
          v-if="page > 0 && rows.length === 0"
          data-test="no-rows"
        >
          {{ t.noRows }}
        </p>
        <ol
          v-else
          class="log-list r-list"
        >
          <li
            v-for="row in rows"
            :key="row.id"
            class="log-row"
            data-test="log-row"
          >
            <div class="log-row__head">
              <time
                class="log-row__at tabular"
                :datetime="row.created_at"
              >{{ formatDateTime(row.created_at) }}</time>
              <span class="log-row__action r-chip r-chip--neutral r-chip--plain">{{ row.action_label }}</span>
              <span class="log-row__target">{{ describeTarget(row) }}</span>
            </div>
            <div class="log-row__meta">
              <span>{{ t.user }}：{{ row.user_name ?? t.unknownUser }}</span>
              <span v-if="showStore">{{ t.store }}：{{ row.store_name ?? t.empty }}</span>
            </div>
            <p
              v-if="describeChanges(row) !== ''"
              class="log-row__changes"
            >
              {{ describeChanges(row) }}
            </p>
          </li>
        </ol>

        <p
          v-if="loading"
          role="status"
        >
          {{ ja.common.loading }}
        </p>
        <template v-else-if="loadFailed">
          <p
            class="adm-error"
            role="alert"
          >
            {{ loadFailed }}
          </p>
          <div class="adm-actions">
            <BigButton @click="load(page === 0)">
              {{ t.retry }}
            </BigButton>
          </div>
        </template>
        <div
          v-else-if="hasMore"
          class="adm-actions"
        >
          <BigButton
            variant="secondary"
            block
            data-test="more"
            @click="load(false)"
          >
            {{ t.more }}
          </BigButton>
        </div>
      </section>
    </main>
  </div>
</template>

<style scoped>
.log-filter { flex-direction: row; align-items: center; gap: 12px; }
.log-filter .r-input { width: auto; min-width: 0; }
.log-list { display: flex; flex-direction: column; }
.log-row { display: flex; flex-direction: column; gap: 4px; padding: 12px 16px; border-top: 1px solid var(--c-border-soft); }
.log-row:first-child { border-top: 0; }
.log-row__head { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 12px; min-width: 0; }
.log-row__at { color: var(--c-text-sub); white-space: nowrap; }
.log-row__action { font-weight: 800; }
.log-row__target { min-width: 0; color: var(--c-text-sub); overflow-wrap: anywhere; }
.log-row__meta { display: flex; flex-wrap: wrap; gap: 4px 16px; color: var(--c-text-sub); font-size: 16px; }
.log-row__changes { overflow-wrap: anywhere; }
</style>
