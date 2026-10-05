<script setup lang="ts">
// S09 店舗設定（08 §5.10）：店舗名・価格の扱い・端数処理・締め時刻、注文（12 §8.8）、レシートプリンター（15 §8.4）、税区分、支払方法
import { onMounted, ref } from 'vue'
import { fetchStoreSettings, updateStoreSettings } from '@/api/settings'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import OrderSettingsPanel from '@/components/settings/OrderSettingsPanel.vue'
import PrinterSettingsPanel from '@/components/settings/PrinterSettingsPanel.vue'
import PaymentMethodPanel from '@/components/settings/PaymentMethodPanel.vue'
import TaxTypePanel from '@/components/settings/TaxTypePanel.vue'
import { ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { useAuthStore } from '@/stores/auth'
import type { PaymentMethod, PriceMode, Rounding, TaxType } from '@/types/api'
import '@/styles/admin.css'

const t = ja.storeSettings
const auth = useAuthStore()

const PRICE_MODES = [
  { value: 'tax_included', label: t.priceModeIncluded },
  { value: 'tax_excluded', label: t.priceModeExcluded },
] as const satisfies readonly { value: PriceMode; label: string }[]
const ROUNDINGS = [
  { value: 'floor', label: t.roundingFloor },
  { value: 'round', label: t.roundingRound },
  { value: 'ceil', label: t.roundingCeil },
] as const satisfies readonly { value: Rounding; label: string }[]
const pad = (n: number): string => String(n).padStart(2, '0')
const HOURS = Array.from({ length: 12 }, (_, i) => pad(i)) // 00〜11（06 §8.2）
const MINUTES = Array.from({ length: 60 }, (_, i) => pad(i))

const loading = ref(true)
const loadFailed = ref<string | null>(null)

const name = ref('')
const priceMode = ref<PriceMode>('tax_included')
const rounding = ref<Rounding>('floor')
const cutoffHour = ref('00')
const cutoffMinute = ref('00')
const stockEnabled = ref(true)
const invoiceNumber = ref('')
const taxTypes = ref<TaxType[]>([])
const paymentMethods = ref<PaymentMethod[]>([])

const saving = ref(false)
const errors = ref<Record<string, string>>({})
const saved = ref(false)
const saveFailed = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    const data = await fetchStoreSettings()
    name.value = data.store.name
    priceMode.value = data.store.price_mode
    rounding.value = data.store.rounding
    const [h = '00', m = '00'] = data.store.day_cutoff_time.split(':')
    cutoffHour.value = h
    cutoffMinute.value = m
    stockEnabled.value = data.store.stock_enabled
    invoiceNumber.value = data.store.invoice_number ?? ''
    taxTypes.value = data.tax_types
    paymentMethods.value = data.payment_methods
  } catch (err) {
    if (!isNetworkError(err)) loadFailed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    loading.value = false
  }
}

async function saveStore(): Promise<void> {
  if (saving.value) return
  saving.value = true
  errors.value = {}
  saved.value = false
  saveFailed.value = null
  try {
    const store = await updateStoreSettings({
      name: name.value.trim(),
      price_mode: priceMode.value,
      rounding: rounding.value,
      day_cutoff_time: `${cutoffHour.value}:${cutoffMinute.value}`,
      stock_enabled: stockEnabled.value,
      invoice_number: invoiceNumber.value.trim() === '' ? null : invoiceNumber.value.trim(),
    })
    name.value = store.name
    invoiceNumber.value = store.invoice_number ?? ''
    if (auth.me) auth.me = { ...auth.me, store }
    saved.value = true
  } catch (err) {
    if (errorStatus(err) === 422) errors.value = fieldErrors(err)
    else if (!isNetworkError(err)) saveFailed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    saving.value = false
  }
}

onMounted(load)
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
      <p
        v-else-if="loadFailed"
        class="adm-error r-err"
        role="alert"
      >
        {{ loadFailed }}
      </p>
      <template v-else>
        <section
          class="adm-panel r-card"
          aria-labelledby="store-heading"
        >
          <h2
            id="store-heading"
            class="adm-panel__title r-h2"
          >
            {{ t.storeHeading }}
          </h2>
          <form
            class="adm-form"
            novalidate
            @submit.prevent="saveStore"
          >
            <div class="adm-field r-field">
              <label
                for="store-name"
                class="adm-field__label r-label"
              >{{ t.name }}</label>
              <input
                id="store-name"
                v-model="name"
                class="adm-input r-input"
                maxlength="100"
                autocomplete="organization"
                :aria-invalid="errors.name ? 'true' : undefined"
              >
              <p
                v-if="errors.name"
                class="adm-error r-err"
              >
                {{ errors.name }}
              </p>
            </div>

            <div class="adm-field r-field">
              <span class="adm-field__label r-label">{{ t.priceMode }}</span>
              <SegmentedControl
                v-model="priceMode"
                :options="PRICE_MODES"
                :label="t.priceMode"
              />
              <p class="adm-help r-help">
                {{ priceMode === 'tax_included' ? t.priceModeHelpIncluded : t.priceModeHelpExcluded }}
              </p>
              <p
                v-if="errors.price_mode"
                class="adm-error r-err"
              >
                {{ errors.price_mode }}
              </p>
            </div>

            <div class="adm-field r-field">
              <span class="adm-field__label r-label">{{ t.rounding }}</span>
              <SegmentedControl
                v-model="rounding"
                :options="ROUNDINGS"
                :label="t.rounding"
              />
              <p class="adm-help r-help">
                {{ t.roundingHelp }}
              </p>
              <p
                v-if="errors.rounding"
                class="adm-error r-err"
              >
                {{ errors.rounding }}
              </p>
            </div>

            <fieldset class="adm-field r-field cutoff">
              <legend class="adm-field__label r-label">
                {{ t.cutoff }}
              </legend>
              <div class="adm-actions">
                <select
                  v-model="cutoffHour"
                  class="adm-select tabular"
                  :aria-label="t.cutoffHour"
                >
                  <option
                    v-for="h in HOURS"
                    :key="h"
                    :value="h"
                  >
                    {{ h }}
                  </option>
                </select>
                <span aria-hidden="true">:</span>
                <select
                  v-model="cutoffMinute"
                  class="adm-select tabular"
                  :aria-label="t.cutoffMinute"
                >
                  <option
                    v-for="m in MINUTES"
                    :key="m"
                    :value="m"
                  >
                    {{ m }}
                  </option>
                </select>
              </div>
              <p class="adm-help r-help">
                {{ t.cutoffHelp }}
              </p>
              <p
                v-if="errors.day_cutoff_time"
                class="adm-error r-err"
              >
                {{ errors.day_cutoff_time }}
              </p>
            </fieldset>

            <div class="adm-field r-field">
              <label class="adm-check"><input
                v-model="stockEnabled"
                type="checkbox"
                aria-describedby="stock-enabled-help"
              >{{ t.stockEnabled }}</label>
              <p
                id="stock-enabled-help"
                class="adm-help r-help"
              >
                {{ t.stockEnabledHelp }}
              </p>
              <p
                v-if="errors.stock_enabled"
                class="adm-error r-err"
              >
                {{ errors.stock_enabled }}
              </p>
            </div>

            <div class="adm-field r-field">
              <label
                for="store-invoice-number"
                class="adm-field__label r-label"
              >{{ t.invoiceNumber }}</label>
              <input
                id="store-invoice-number"
                v-model="invoiceNumber"
                class="adm-input r-input tabular"
                maxlength="30"
                inputmode="text"
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                placeholder="T1234567890123"
                aria-describedby="store-invoice-number-help"
                :aria-invalid="errors.invoice_number ? 'true' : undefined"
              >
              <p
                id="store-invoice-number-help"
                class="adm-help r-help"
              >
                {{ t.invoiceNumberHelp }}
              </p>
              <p
                v-if="errors.invoice_number"
                class="adm-error r-err"
              >
                {{ errors.invoice_number }}
              </p>
            </div>

            <p
              v-if="saved"
              class="adm-ok"
              role="status"
            >
              {{ ja.common.saved }}
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
              >
                {{ ja.common.save }}
              </BigButton>
            </div>
          </form>
        </section>

        <OrderSettingsPanel />
        <PrinterSettingsPanel />
        <TaxTypePanel v-model="taxTypes" />
        <PaymentMethodPanel v-model="paymentMethods" />
      </template>
    </main>
  </div>
</template>

<style scoped>
.cutoff { margin: 0; padding: 0; border: 0; }
</style>
