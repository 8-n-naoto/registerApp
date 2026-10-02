<script setup lang="ts">
// S16 テーブル・QR（12 §8.7）：一覧、追加、行をタップして編集（名前・有効 / 無効・削除）、QR の表示・印刷・作り直し。
// QR の URL は文字で出さない（画面にも印刷にも）。SVG は <img> の data: URI で出す（v-html を使わない）
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  createOrderTable,
  deleteOrderTable,
  fetchOrderTableQr,
  fetchOrderTables,
  regenerateOrderTableToken,
  updateOrderTable,
} from '@/api/orderTables'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { formatDateTime } from '@/lib/date'
import { useAuthStore } from '@/stores/auth'
import type { OrderTable } from '@/types/api'
import '@/styles/admin.css'

const t = ja.tables
const PER_SHEET = 6

interface QrCard {
  id: number
  name: string
  src: string
}

const auth = useAuthStore()
const storeName = computed(() => auth.me?.store?.name ?? '')

const tables = ref<OrderTable[]>([])
const loading = ref(true)
const loadFailed = ref<string | null>(null)
const notice = ref<string | null>(null)

const editingId = ref<number | 'new' | null>(null)
const form = ref({ name: '', is_active: true })
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const saving = ref(false)

const qrPanel = ref<HTMLElement | null>(null)
const qrTable = ref<OrderTable | null>(null)
const qrCard = ref<QrCard | null>(null)
const qrLoading = ref(false)
const qrError = ref<string | null>(null)

const confirming = ref<{ kind: 'delete' | 'regenerate'; table: OrderTable } | null>(null)
const confirmBusy = ref(false)
const confirmError = ref<string | null>(null)

const printCards = ref<QrCard[] | null>(null)
const printingAll = ref(false)
const printAllError = ref<string | null>(null)

const printSheets = computed(() => {
  const cards = printCards.value ?? []
  const sheets: QrCard[][] = []
  for (let i = 0; i < cards.length; i += PER_SHEET) sheets.push(cards.slice(i, i + PER_SHEET))
  return sheets
})

function svgSrc(svg: string): string {
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
}

function messageOf(err: unknown, fallback: string): string {
  return isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? fallback)
}

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    tables.value = await fetchOrderTables()
  } catch (err) {
    loadFailed.value = messageOf(err, t.loadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function replace(saved: OrderTable): void {
  tables.value = tables.value.map((x) => (x.id === saved.id ? saved : x))
}

function resetForms(): void {
  errors.value = {}
  failed.value = null
  notice.value = null
}

function startNew(): void {
  resetForms()
  editingId.value = 'new'
  form.value = { name: '', is_active: true }
}

function startEdit(table: OrderTable): void {
  if (editingId.value === table.id) return
  resetForms()
  editingId.value = table.id
  form.value = { name: table.name, is_active: table.is_active }
}

function cancel(): void {
  editingId.value = null
}

async function save(): Promise<void> {
  if (saving.value) return
  errors.value = {}
  failed.value = null
  const id = editingId.value
  saving.value = true
  try {
    const name = form.value.name.trim()
    if (id === 'new') {
      const created = await createOrderTable(name)
      tables.value = [...tables.value, created]
      notice.value = fmt(t.created, { name: created.name })
    } else if (id !== null) {
      const current = tables.value.find((x) => x.id === id)
      const saved = await updateOrderTable(id, { name, sort_order: current?.sort_order ?? 0, is_active: form.value.is_active })
      replace(saved)
      notice.value = t.updated
    }
    editingId.value = null
  } catch (err) {
    if (errorStatus(err) === 422) errors.value = fieldErrors(err)
    else failed.value = messageOf(err, ja.error.unexpected)
  } finally {
    saving.value = false
  }
}

async function showQr(table: OrderTable): Promise<void> {
  qrTable.value = table
  qrCard.value = null
  qrError.value = null
  qrLoading.value = true
  void nextTick(() => qrPanel.value?.focus())
  try {
    const qr = await fetchOrderTableQr(table.id)
    if (qrTable.value?.id === table.id) qrCard.value = { id: table.id, name: table.name, src: svgSrc(qr.svg) }
  } catch (err) {
    qrError.value = messageOf(err, t.qrFailed)
  } finally {
    qrLoading.value = false
  }
}

function closeQr(): void {
  qrTable.value = null
  qrCard.value = null
}

function askDelete(table: OrderTable): void {
  confirmError.value = null
  confirming.value = { kind: 'delete', table }
}

function askRegenerate(table: OrderTable): void {
  confirmError.value = null
  confirming.value = { kind: 'regenerate', table }
}

async function confirmAction(): Promise<void> {
  const target = confirming.value
  if (!target || confirmBusy.value) return
  confirmBusy.value = true
  confirmError.value = null
  try {
    if (target.kind === 'delete') {
      await deleteOrderTable(target.table.id)
      tables.value = tables.value.filter((x) => x.id !== target.table.id)
      editingId.value = null
      notice.value = fmt(t.deleted, { name: target.table.name })
    } else {
      replace(await regenerateOrderTableToken(target.table.id))
      notice.value = fmt(t.regenerated, { name: target.table.name })
      if (qrTable.value?.id === target.table.id) closeQr()
    }
    confirming.value = null
  } catch (err) {
    confirmError.value = messageOf(err, ja.error.unexpected)
  } finally {
    confirmBusy.value = false
  }
}

// 印刷：印刷用の要素を body の直下に出し、印刷の間だけ画面の他の部分を隠す（下の <style> の @media print）
function endPrint(): void {
  document.body.classList.remove('qr-printing')
  printCards.value = null
}

async function print(cards: QrCard[]): Promise<void> {
  printCards.value = cards
  await nextTick()
  const images = [...document.querySelectorAll<HTMLImageElement>('.qr-print img')]
  await Promise.all(images.map((img) => (typeof img.decode === 'function' ? img.decode().catch(() => undefined) : undefined)))
  document.body.classList.add('qr-printing')
  window.print()
}

async function printAll(): Promise<void> {
  if (printingAll.value) return
  printAllError.value = null
  const targets = tables.value.filter((x) => x.is_active)
  if (targets.length === 0) {
    printAllError.value = t.noActive
    return
  }
  printingAll.value = true
  try {
    const cards: QrCard[] = []
    for (const table of targets) {
      const qr = await fetchOrderTableQr(table.id)
      cards.push({ id: table.id, name: table.name, src: svgSrc(qr.svg) })
    }
    await print(cards)
  } catch (err) {
    printAllError.value = messageOf(err, t.qrFailed)
  } finally {
    printingAll.value = false
  }
}

onMounted(() => window.addEventListener('afterprint', endPrint))
onBeforeUnmount(() => {
  window.removeEventListener('afterprint', endPrint)
  endPrint()
})

const confirmTitle = computed(() => {
  const c = confirming.value
  if (!c) return ''
  return fmt(c.kind === 'delete' ? t.deleteTitle : t.regenerateTitle, { name: c.table.name })
})
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
      <template v-else>
        <section
          class="adm-panel r-card"
          aria-labelledby="tables-heading"
        >
          <div class="adm-panel__head">
            <h2
              id="tables-heading"
              class="adm-panel__title r-h2"
            >
              {{ t.title }}
            </h2>
            <button
              v-if="editingId !== 'new'"
              type="button"
              class="adm-btn"
              @click="startNew"
            >
              {{ t.add }}
            </button>
          </div>
          <p
            v-if="notice"
            class="adm-ok"
            role="status"
          >
            {{ notice }}
          </p>
          <p
            v-if="failed"
            class="adm-error r-err"
            role="alert"
          >
            {{ failed }}
          </p>

          <form
            v-if="editingId === 'new'"
            class="adm-form tbl-edit"
            novalidate
            :aria-label="t.addTitle"
            @submit.prevent="save"
          >
            <h3>{{ t.addTitle }}</h3>
            <div class="adm-field r-field">
              <label
                for="table-new-name"
                class="adm-field__label r-label"
              >{{ t.name }}</label>
              <input
                id="table-new-name"
                v-model="form.name"
                class="adm-input r-input"
                maxlength="20"
                autocomplete="off"
                :aria-invalid="errors.name ? 'true' : undefined"
              >
              <p
                v-if="errors.name"
                class="adm-error r-err"
              >
                {{ errors.name }}
              </p>
            </div>
            <div class="adm-actions">
              <BigButton
                type="submit"
                :loading="saving"
              >
                {{ t.create }}
              </BigButton>
              <BigButton
                variant="secondary"
                @click="cancel"
              >
                {{ ja.common.cancel }}
              </BigButton>
            </div>
          </form>

          <p v-if="tables.length === 0 && editingId !== 'new'">
            {{ t.empty }}
          </p>
          <ul
            v-else
            class="adm-list"
          >
            <li
              v-for="table in tables"
              :key="table.id"
            >
              <form
                v-if="editingId === table.id"
                class="adm-form tbl-edit"
                novalidate
                :aria-label="t.editTitle"
                @submit.prevent="save"
              >
                <h3>{{ t.editTitle }}</h3>
                <div class="adm-field r-field">
                  <label
                    :for="`table-name-${table.id}`"
                    class="adm-field__label r-label"
                  >{{ t.name }}</label>
                  <input
                    :id="`table-name-${table.id}`"
                    v-model="form.name"
                    class="adm-input r-input"
                    maxlength="20"
                    autocomplete="off"
                    :aria-invalid="errors.name ? 'true' : undefined"
                  >
                  <p
                    v-if="errors.name"
                    class="adm-error r-err"
                  >
                    {{ errors.name }}
                  </p>
                </div>
                <label class="adm-check"><input
                  v-model="form.is_active"
                  type="checkbox"
                >{{ t.isActive }}</label>
                <div class="adm-actions">
                  <BigButton
                    type="submit"
                    :loading="saving"
                  >
                    {{ ja.common.save }}
                  </BigButton>
                  <BigButton
                    variant="secondary"
                    @click="cancel"
                  >
                    {{ ja.common.cancel }}
                  </BigButton>
                </div>
                <div class="adm-actions tbl-edit__more">
                  <button
                    type="button"
                    class="adm-btn"
                    @click="askRegenerate(table)"
                  >
                    {{ t.regenerate }}
                  </button>
                  <button
                    type="button"
                    class="adm-btn tbl-danger"
                    @click="askDelete(table)"
                  >
                    {{ ja.common.delete }}
                  </button>
                </div>
              </form>
              <div
                v-else
                class="tbl-line"
              >
                <button
                  type="button"
                  class="adm-row tbl-row"
                  :class="{ 'adm-row--off': !table.is_active }"
                  :aria-label="fmt(ja.common.editNamed, { name: table.name })"
                  @click="startEdit(table)"
                >
                  <span class="adm-row__main">{{ table.name }}</span>
                  <span
                    class="adm-badge r-chip"
                    :class="{ 'adm-badge--primary': table.is_active, 'r-chip--ok': table.is_active, 'r-chip--neutral': !table.is_active }"
                  >{{ table.is_active ? t.active : ja.common.inactive }}</span>
                  <span
                    v-if="table.opened_at"
                    class="adm-badge r-chip r-chip--neutral"
                  >{{ t.inUse }}</span>
                  <span class="tbl-row__sub">{{ fmt(t.qrCreated, { at: formatDateTime(table.token_rotated_at) }) }}</span>
                </button>
                <button
                  type="button"
                  class="adm-btn"
                  :aria-label="`${table.name} ${t.showQr}`"
                  @click="showQr(table)"
                >
                  {{ t.showQr }}
                </button>
              </div>
            </li>
          </ul>
        </section>

        <section
          v-if="tables.length > 0"
          class="adm-panel r-card"
          aria-labelledby="tables-print-heading"
        >
          <h2
            id="tables-print-heading"
            class="adm-panel__title r-h2"
          >
            {{ t.printAll }}
          </h2>
          <p class="adm-help r-help">
            {{ t.printAllHelp }}
          </p>
          <p
            v-if="printAllError"
            class="adm-error r-err"
            role="alert"
          >
            {{ printAllError }}
          </p>
          <div class="adm-actions">
            <BigButton
              variant="secondary"
              :loading="printingAll"
              @click="printAll"
            >
              {{ t.printAll }}
            </BigButton>
          </div>
        </section>
      </template>
    </main>

    <Teleport to="body">
      <div
        v-if="qrTable"
        class="tbl-qr-backdrop"
        @click.self="closeQr"
        @keydown.esc="closeQr"
      >
        <div
          ref="qrPanel"
          class="tbl-qr"
          tabindex="-1"
          role="dialog"
          aria-modal="true"
          aria-labelledby="tbl-qr-title"
        >
          <h2
            id="tbl-qr-title"
            class="tbl-qr__title"
          >
            {{ qrTable.name }}
          </h2>
          <p
            v-if="qrLoading"
            role="status"
          >
            {{ t.qrLoading }}
          </p>
          <p
            v-else-if="qrError"
            class="adm-error r-err"
            role="alert"
          >
            {{ qrError }}
          </p>
          <div
            v-else-if="qrCard"
            class="tbl-qr__card"
          >
            <p class="tbl-qr__store">
              {{ storeName }}
            </p>
            <img
              class="tbl-qr__img"
              :src="qrCard.src"
              :alt="fmt(t.qrAlt, { name: qrCard.name })"
            >
            <p class="tbl-qr__name">
              {{ qrCard.name }}
            </p>
            <p class="tbl-qr__guide">
              {{ t.qrGuide }}
            </p>
          </div>
          <div class="adm-actions tbl-qr__actions">
            <BigButton
              v-if="qrCard"
              @click="print([qrCard])"
            >
              {{ t.print }}
            </BigButton>
            <BigButton
              variant="secondary"
              @click="closeQr"
            >
              {{ ja.common.close }}
            </BigButton>
          </div>
        </div>
      </div>

      <div
        v-if="printCards"
        class="qr-print"
        :class="{ 'qr-print--single': printCards.length === 1 }"
        aria-hidden="true"
      >
        <div
          v-for="(sheet, i) in printSheets"
          :key="i"
          class="qr-print__sheet"
        >
          <div
            v-for="card in sheet"
            :key="card.id"
            class="qr-print__card"
          >
            <p class="qr-print__store">
              {{ storeName }}
            </p>
            <img
              class="qr-print__img"
              :src="card.src"
              alt=""
            >
            <p class="qr-print__name">
              {{ card.name }}
            </p>
          </div>
        </div>
      </div>
    </Teleport>

    <ConfirmDialog
      :open="confirming !== null"
      :title="confirmTitle"
      :message="confirming?.kind === 'delete' ? t.deleteMessage : t.regenerateMessage"
      :confirm-label="confirming?.kind === 'delete' ? ja.common.delete : t.regenerateConfirm"
      danger
      :loading="confirmBusy"
      :error="confirmError"
      @confirm="confirmAction"
      @cancel="confirming = null"
    />
  </div>
</template>

<style scoped>
.tbl-line { display: flex; gap: 8px; align-items: stretch; }
.tbl-row { flex: 1 1 auto; min-width: 0; text-align: left; cursor: pointer; }
.tbl-row__sub { color: var(--c-text-sub); font-size: 16px; }
.tbl-edit {
  padding: 16px;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
}
.tbl-edit__more { padding-top: 16px; border-top: 1px solid var(--c-border); }
.tbl-danger { border-color: var(--c-danger); color: var(--c-danger); }

.tbl-qr-backdrop {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(17, 24, 39, 0.5);
}

.tbl-qr {
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

.tbl-qr__title { font-size: var(--fs-heading); }
.tbl-qr__card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 16px;
  border-radius: var(--radius);
  background: #FFFFFF; /* QR は白地に置く（読み取りやすさ。ダーク表示でも白） */
  color: #111827;
  text-align: center;
}

.tbl-qr__store { font-weight: 700; }
.tbl-qr__img { width: 260px; max-width: 100%; height: auto; aspect-ratio: 1; }
.tbl-qr__name { font-size: var(--fs-heading); font-weight: 700; }
.tbl-qr__guide { font-size: 16px; }
.tbl-qr__actions { justify-content: flex-end; }
</style>

<style>
/* 印刷用（S16）。scoped にしない：Teleport で body の直下に出した .qr-print 以外を印刷の間だけ隠す */
.qr-print { display: none; }

@media print {
  body.qr-printing > *:not(.qr-print) { display: none !important; }
  body.qr-printing .qr-print { display: block; background: #FFFFFF; color: #000000; }
  .qr-print__sheet {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8mm;
    break-after: page;
  }

  .qr-print__sheet:last-child { break-after: auto; }
  .qr-print__card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2mm;
    padding: 4mm;
    border: 1px dashed #9CA3AF;
    break-inside: avoid;
    text-align: center;
  }

  .qr-print__img { width: 55mm; height: 55mm; }
  .qr-print__store { font-size: 11pt; font-weight: 700; }
  .qr-print__name { font-size: 18pt; font-weight: 700; }
  .qr-print--single .qr-print__sheet { grid-template-columns: 1fr; }
  .qr-print--single .qr-print__img { width: 100mm; height: 100mm; }
  .qr-print--single .qr-print__name { font-size: 28pt; }
}
</style>
