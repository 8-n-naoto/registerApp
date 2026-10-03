<script setup lang="ts">
// S09「レシートプリンター」（15 §8.4）：IP（ホスト名）と紙の幅。保存はこの欄だけ（PUT /settings/printer）。
// ［この端末でテスト印刷］は保存済みの設定で送る。［この端末の準備］は端末ごとの手順（証明書の信頼・Chrome の許可・同じ Wi-Fi）
import { computed, ref } from 'vue'
import { updatePrinterSettings } from '@/api/settings'
import BigButton from '@/components/BigButton.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import { ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { useAuthStore } from '@/stores/auth'
import { useReceiptPrinterStore } from '@/stores/receiptPrinter'
import type { StoreSettings } from '@/types/api'

const t = ja.print.settings
const auth = useAuthStore()
const printerStore = useReceiptPrinterStore()

const PAPER = [
  { value: '80', label: t.paper80 },
  { value: '58', label: t.paper58 },
] as const

const saved0 = computed(() => auth.me?.store?.printer ?? null)
const host = ref(saved0.value?.host ?? '')
const paper = ref<'80' | '58'>(saved0.value?.paper_width === 58 ? '58' : '80')

const saving = ref(false)
const errors = ref<Record<string, string>>({})
const saved = ref(false)
const saveFailed = ref<string | null>(null)
const preparing = ref(false)

const testJob = computed(() => printerStore.jobFor('test'))
/** 証明書を信頼させるため、プリンターの画面（https://<ホスト>/）を新しいタブで開く */
const printerPage = computed(() => (saved0.value ? `https://${saved0.value.host}/` : null))

async function save(): Promise<void> {
  if (saving.value) return
  saving.value = true
  errors.value = {}
  saved.value = false
  saveFailed.value = null
  try {
    const trimmed = host.value.trim()
    const store: StoreSettings = await updatePrinterSettings({ host: trimmed === '' ? null : trimmed, paper_width: paper.value === '58' ? 58 : 80 })
    host.value = store.printer?.host ?? ''
    if (auth.me) auth.me = { ...auth.me, store }
    saved.value = true
  } catch (err) {
    if (errorStatus(err) === 422) errors.value = fieldErrors(err)
    else if (!isNetworkError(err)) saveFailed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    saving.value = false
  }
}

function testPrint(): void {
  const printer = saved0.value
  const store = auth.me?.store
  if (printer && store) void printerStore.printTest(printer, store.name)
}
</script>

<template>
  <section
    class="adm-panel r-card"
    aria-labelledby="printer-heading"
    data-testid="printer-panel"
  >
    <h2
      id="printer-heading"
      class="adm-panel__title r-h2"
    >
      {{ t.title }}
    </h2>
    <p class="adm-help r-help">
      {{ t.lead }}
    </p>
    <form
      class="adm-form"
      novalidate
      @submit.prevent="save"
    >
      <div class="adm-field r-field">
        <label
          for="printer-host"
          class="adm-field__label r-label"
        >{{ t.host }}</label>
        <input
          id="printer-host"
          v-model="host"
          class="adm-input r-input"
          maxlength="100"
          inputmode="url"
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          aria-describedby="printer-host-help"
          :aria-invalid="errors.host ? 'true' : undefined"
        >
        <p
          id="printer-host-help"
          class="adm-help r-help"
        >
          {{ t.hostHint }}
        </p>
        <p
          v-if="errors.host"
          class="adm-error r-err"
        >
          {{ errors.host }}
        </p>
      </div>
      <div class="adm-field r-field">
        <span class="adm-field__label r-label">{{ t.paperWidth }}</span>
        <SegmentedControl
          v-model="paper"
          :options="PAPER"
          :label="t.paperWidth"
        />
        <p
          v-if="errors.paper_width"
          class="adm-error r-err"
        >
          {{ errors.paper_width }}
        </p>
      </div>
      <p
        v-if="saved"
        class="adm-ok"
        role="status"
      >
        {{ t.saved }}
      </p>
      <p
        v-if="saveFailed"
        class="adm-error r-err"
        role="alert"
      >
        {{ saveFailed }}
      </p>
      <div class="adm-actions">
        <BigButton
          type="submit"
          :loading="saving"
          data-save-printer
        >
          {{ t.save }}
        </BigButton>
      </div>
    </form>

    <div class="printer__device">
      <p
        v-if="!saved0"
        class="adm-help r-help"
      >
        {{ t.notConfigured }}
      </p>
      <template v-else>
        <div class="adm-actions">
          <BigButton
            variant="secondary"
            :loading="testJob?.state === 'printing'"
            data-test-print
            @click="testPrint"
          >
            {{ t.testPrint }}
          </BigButton>
          <BigButton
            variant="quiet"
            :aria-expanded="preparing"
            aria-controls="printer-prepare"
            @click="preparing = !preparing"
          >
            {{ t.prepare }}
          </BigButton>
        </div>
        <p
          v-if="testJob?.state === 'printed'"
          class="adm-ok"
          role="status"
        >
          {{ t.testOk }}
        </p>
        <p
          v-else-if="testJob?.state === 'failed'"
          class="adm-error r-err"
          role="alert"
        >
          {{ ja.print.reasons[testJob.reason ?? 'unknown'] }}
        </p>
        <div
          v-if="preparing"
          id="printer-prepare"
          class="printer__prepare"
        >
          <ol class="printer__steps">
            <li
              v-for="step in t.prepareSteps"
              :key="step"
            >
              {{ step }}
            </li>
          </ol>
          <a
            v-if="printerPage"
            class="r-btn r-btn--secondary"
            :href="printerPage"
            target="_blank"
            rel="noopener noreferrer"
          >{{ t.openPrinter }}</a>
        </div>
      </template>
    </div>
  </section>
</template>

<style scoped>
.printer__device { display: flex; flex-direction: column; gap: 12px; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--c-border-soft); }
.printer__prepare { display: flex; flex-direction: column; align-items: flex-start; gap: 12px; }
.printer__steps { display: flex; flex-direction: column; gap: 8px; margin: 0; padding-left: 1.5em; font-size: 16px; line-height: 1.6; }
</style>
