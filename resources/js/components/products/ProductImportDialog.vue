<script setup lang="ts">
// S08 CSV 一括登録ダイアログ（08 §5.9・06 §7.7）。
// ファイルを選ぶと dry_run=true で自動プレビュー → エラーが 0 件のときだけ［N 件を登録する］を押せる
import { computed, onMounted, ref } from 'vue'
import { importProducts, type ImportResult } from '@/api/catalog'
import BigButton from '@/components/BigButton.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { IMPORT_TEMPLATE } from '@/lib/productColors'

const t = ja.importDialog

const emit = defineEmits<{ imported: []; close: [] }>()

const file = ref<File | null>(null)
const result = ref<ImportResult | null>(null)
const busy = ref(false)
const failed = ref<string | null>(null)
const done = ref<string | null>(null)

const canSubmit = computed(() => result.value !== null && result.value.errors.length === 0 && result.value.valid_count > 0)

const panel = ref<HTMLElement | null>(null)
onMounted(() => panel.value?.querySelector<HTMLElement>('[data-first]')?.focus())

function downloadTemplate(): void {
  const url = URL.createObjectURL(new Blob([IMPORT_TEMPLATE], { type: 'text/csv' }))
  const a = document.createElement('a')
  a.href = url
  a.download = 'products_template.csv'
  a.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

function isImportResult(value: unknown): value is ImportResult {
  return typeof value === 'object' && value !== null && 'errors' in value && 'valid_count' in value
}

async function run(dryRun: boolean): Promise<void> {
  if (!file.value || busy.value) return
  busy.value = true
  failed.value = null
  try {
    result.value = await importProducts(file.value, dryRun)
    if (!dryRun) {
      done.value = fmt(t.done, { n: result.value.valid_count })
      emit('imported')
    }
  } catch (err) {
    const details = errorBody(err)?.details
    if (isImportResult(details)) {
      result.value = details // 422 IMPORT_INVALID：プレビューと同じ形
    } else if (errorStatus(err) === 422) {
      failed.value = fieldErrors(err).file ?? errorBody(err)?.message ?? ja.error.unexpected
      result.value = null
    } else if (!isNetworkError(err)) {
      failed.value = errorBody(err)?.message ?? ja.error.unexpected
    }
  } finally {
    busy.value = false
  }
}

function onFileChange(e: Event): void {
  const input = e.target as HTMLInputElement
  file.value = input.files?.[0] ?? null
  result.value = null
  done.value = null
  void run(true)
  input.value = '' // 同じファイルを直して選び直しても change が起きるように
}
</script>

<template>
  <Teleport to="body">
    <div
      class="dialog-backdrop"
      @click.self="emit('close')"
      @keydown.esc="emit('close')"
    >
      <div
        ref="panel"
        class="dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="import-title"
      >
        <header class="adm-panel__head">
          <h2
            id="import-title"
            class="dialog__title"
          >
            {{ t.title }}
          </h2>
          <button
            type="button"
            class="adm-btn"
            @click="emit('close')"
          >
            {{ ja.common.close }}
          </button>
        </header>
        <p class="adm-help">
          {{ t.steps }}
        </p>
        <div class="adm-actions">
          <button
            type="button"
            class="adm-btn"
            data-first
            @click="downloadTemplate"
          >
            {{ t.template }}
          </button>
          <label class="file-btn">
            <input
              type="file"
              accept=".csv,text/csv"
              class="visually-hidden"
              @change="onFileChange"
            >
            {{ t.chooseFile }}
          </label>
        </div>
        <p
          v-if="file"
          class="dialog__file"
        >
          {{ file.name }}
        </p>

        <p
          v-if="busy"
          role="status"
        >
          {{ t.checking }}
        </p>
        <p
          v-if="failed"
          class="adm-error"
          role="alert"
        >
          {{ failed }}
        </p>
        <p
          v-if="done"
          class="adm-ok"
          role="status"
        >
          {{ done }}
        </p>

        <template v-if="result && !done">
          <p class="dialog__count">
            {{ fmt(t.validCount, { n: result.valid_count }) }}
          </p>
          <p v-if="result.new_categories.length > 0">
            {{ fmt(t.newCategories, { names: result.new_categories.join('、') }) }}
          </p>
          <section
            v-if="result.errors.length > 0"
            class="issues issues--error"
            aria-labelledby="import-errors"
          >
            <h3 id="import-errors">
              {{ t.errorsHeading }}
            </h3>
            <ul>
              <li
                v-for="e in result.errors"
                :key="`e${e.line}`"
              >
                <strong>{{ e.line === 0 ? t.fileError : fmt(t.line, { n: e.line }) }}</strong>：{{ e.messages.join('／') }}
              </li>
            </ul>
          </section>
          <section
            v-if="result.warnings.length > 0"
            class="issues"
            aria-labelledby="import-warnings"
          >
            <h3 id="import-warnings">
              {{ t.warningsHeading }}
            </h3>
            <ul>
              <li
                v-for="w in result.warnings"
                :key="`w${w.line}`"
              >
                <strong>{{ fmt(t.line, { n: w.line }) }}</strong>：{{ w.messages.join('／') }}
              </li>
            </ul>
          </section>
          <BigButton
            block
            :disabled="!canSubmit"
            :loading="busy"
            @click="run(false)"
          >
            {{ fmt(t.submit, { n: result.valid_count }) }}
          </BigButton>
        </template>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.dialog-backdrop {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(17, 24, 39, 0.5);
}

.dialog {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: min(640px, 100%);
  max-height: calc(100dvh - 32px);
  overflow-y: auto;
  padding: 24px;
  border-radius: var(--radius-card);
  background: var(--c-surface);
}

.dialog__title { font-size: var(--fs-heading); }
.dialog__file { font-weight: 700; overflow-wrap: anywhere; }
.dialog__count { font-size: 20px; font-weight: 700; }

.file-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: var(--tap-min);
  padding: 0 16px;
  border-radius: var(--radius);
  background: var(--c-primary);
  color: var(--c-on-primary);
  font-weight: 700;
  cursor: pointer;
}

.file-btn:focus-within { outline: 3px solid var(--c-focus); outline-offset: 2px; }

.issues { padding: 12px 16px; border: 2px solid var(--c-change); border-radius: var(--radius); }
.issues--error { border-color: var(--c-danger); }
.issues h3 { font-size: 18px; margin-bottom: 8px; }
.issues--error h3 { color: var(--c-danger); }
.issues ul { margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 4px; }
</style>
