<script setup lang="ts">
// 13 §6.6 S20［労働条件］（owner）：週の上限・起算の曜日・法定休日・最低賃金（#77・#78）、人ごとの時給と区分（#79・#80）
import { onMounted, ref } from 'vue'
import { fetchLaborMembers, fetchLaborSettings, updateLaborMember, updateLaborSettings } from '@/api/attendance'
import BigButton from '@/components/BigButton.vue'
import LaborWarningBanner from '@/components/LaborWarningBanner.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { weekdayLabel } from '@/lib/labor'
import { useAuthStore } from '@/stores/auth'
import type { LaborMember, LaborWarning } from '@/types/api'

const emit = defineEmits<{ changed: [] }>()

const t = ja.attendance
const auth = useAuthStore()
const WEEKDAYS = [0, 1, 2, 3, 4, 5, 6] as const

const loading = ref(true)
const loadFailed = ref<string | null>(null)
const warnings = ref<LaborWarning[]>([])

const settings = ref({ weekly_hours_limit: null as number | null, week_start_day: null as number | null, legal_holiday_day: null as number | null, minimum_wage: '' })
const settingsErrors = ref<Record<string, string>>({})
const settingsNotice = ref<string | null>(null)
const settingsFailed = ref<string | null>(null)
const savingSettings = ref(false)

interface MemberForm { member: LaborMember; wage: string; exempt: boolean; error: string | null; notice: string | null; saving: boolean }
const members = ref<MemberForm[]>([])

function toForm(member: LaborMember): MemberForm {
  return { member, wage: member.hourly_wage === null ? '' : String(member.hourly_wage), exempt: member.overtime_exempt, error: null, notice: null, saving: false }
}

/** 空は null、半角・全角の数字だけを受け付ける。数字にならなければ NaN（サーバーで 422 にせず画面で止める） */
function toInt(input: string): number | null {
  const s = input.trim().replace(/[０-９]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xfee0)).replace(/,/g, '')
  if (s === '') return null
  return /^\d+$/.test(s) ? Number(s) : Number.NaN
}

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    const [s, people] = await Promise.all([fetchLaborSettings(), fetchLaborMembers()])
    settings.value = {
      weekly_hours_limit: s.weekly_hours_limit,
      week_start_day: s.week_start_day,
      legal_holiday_day: s.legal_holiday_day,
      minimum_wage: s.minimum_wage === null ? '' : String(s.minimum_wage),
    }
    warnings.value = s.warnings
    members.value = people.map(toForm)
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(load)

/** ホームの警告（GET /me の labor_warnings）も読み直す */
async function changed(): Promise<void> {
  emit('changed')
  await auth.refreshMe().catch(() => undefined)
}

async function saveSettings(): Promise<void> {
  if (savingSettings.value) return
  settingsErrors.value = {}
  settingsNotice.value = null
  settingsFailed.value = null
  const wage = toInt(settings.value.minimum_wage)
  if (Number.isNaN(wage)) {
    settingsErrors.value = { minimum_wage: t.numberInvalid }
    return
  }
  savingSettings.value = true
  try {
    const saved = await updateLaborSettings({
      weekly_hours_limit: settings.value.weekly_hours_limit,
      week_start_day: settings.value.week_start_day,
      legal_holiday_day: settings.value.legal_holiday_day,
      minimum_wage: wage,
    })
    warnings.value = saved.warnings
    settingsNotice.value = t.settingsSaved
    await changed()
  } catch (err) {
    if (errorStatus(err) === 422) settingsErrors.value = fieldErrors(err)
    else if (!isNetworkError(err)) settingsFailed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    savingSettings.value = false
  }
}

async function saveMember(row: MemberForm): Promise<void> {
  if (row.saving) return
  row.error = null
  row.notice = null
  const wage = toInt(row.wage)
  if (Number.isNaN(wage)) {
    row.error = t.numberInvalid
    return
  }
  row.saving = true
  try {
    const saved = await updateLaborMember(row.member.id, { hourly_wage: wage, overtime_exempt: row.exempt })
    row.member = saved
    row.notice = fmt(t.memberSaved, { name: saved.name })
    const s = await fetchLaborSettings().catch(() => null)
    if (s) warnings.value = s.warnings
    await changed()
  } catch (err) {
    if (errorStatus(err) === 422) {
      const e = fieldErrors(err)
      row.error = e.hourly_wage ?? e.overtime_exempt ?? ja.error.unexpected
    } else if (!isNetworkError(err)) {
      row.error = errorBody(err)?.message ?? ja.error.unexpected
    }
  } finally {
    row.saving = false
  }
}
</script>

<template>
  <div class="labor">
    <p
      v-if="loading"
      role="status"
    >
      {{ ja.common.loading }}
    </p>
    <section
      v-else-if="loadFailed"
      class="adm-panel"
    >
      <p
        class="adm-error"
        role="alert"
      >
        {{ loadFailed }}
      </p>
      <div class="adm-actions">
        <BigButton @click="load">
          {{ t.retry }}
        </BigButton>
      </div>
    </section>
    <template v-else>
      <LaborWarningBanner
        :warnings="warnings"
        :show-link="false"
      />
      <section
        class="adm-panel"
        aria-labelledby="labor-settings-heading"
      >
        <h2
          id="labor-settings-heading"
          class="adm-panel__title"
        >
          {{ t.settingsHeading }}
        </h2>
        <p class="adm-help">
          {{ t.settingsHelp }}
        </p>
        <form
          class="adm-form"
          novalidate
          @submit.prevent="saveSettings"
        >
          <div class="adm-field">
            <label
              for="labor-weekly"
              class="adm-field__label"
            >{{ t.weeklyLimit }}</label>
            <select
              id="labor-weekly"
              v-model="settings.weekly_hours_limit"
              class="adm-select"
              :aria-invalid="settingsErrors.weekly_hours_limit ? 'true' : undefined"
            >
              <option :value="null">
                {{ fmt(t.unsetDefault, { value: t.weeklyLimit40 }) }}
              </option>
              <option :value="40">
                {{ t.weeklyLimit40 }}
              </option>
              <option :value="44">
                {{ t.weeklyLimit44 }}
              </option>
            </select>
            <p
              v-if="settingsErrors.weekly_hours_limit"
              class="adm-error"
            >
              {{ settingsErrors.weekly_hours_limit }}
            </p>
          </div>
          <div class="adm-field">
            <label
              for="labor-week-start"
              class="adm-field__label"
            >{{ t.weekStart }}</label>
            <select
              id="labor-week-start"
              v-model="settings.week_start_day"
              class="adm-select"
              :aria-invalid="settingsErrors.week_start_day ? 'true' : undefined"
            >
              <option :value="null">
                {{ fmt(t.unsetDefault, { value: weekdayLabel(0) }) }}
              </option>
              <option
                v-for="d in WEEKDAYS"
                :key="d"
                :value="d"
              >
                {{ weekdayLabel(d) }}
              </option>
            </select>
            <p
              v-if="settingsErrors.week_start_day"
              class="adm-error"
            >
              {{ settingsErrors.week_start_day }}
            </p>
          </div>
          <div class="adm-field">
            <label
              for="labor-holiday"
              class="adm-field__label"
            >{{ t.legalHoliday }}</label>
            <select
              id="labor-holiday"
              v-model="settings.legal_holiday_day"
              class="adm-select"
              :aria-invalid="settingsErrors.legal_holiday_day ? 'true' : undefined"
            >
              <option :value="null">
                {{ fmt(t.unsetDefault, { value: t.noLegalHoliday }) }}
              </option>
              <option
                v-for="d in WEEKDAYS"
                :key="d"
                :value="d"
              >
                {{ weekdayLabel(d) }}
              </option>
            </select>
            <p
              v-if="settingsErrors.legal_holiday_day"
              class="adm-error"
            >
              {{ settingsErrors.legal_holiday_day }}
            </p>
          </div>
          <div class="adm-field">
            <label
              for="labor-min-wage"
              class="adm-field__label"
            >{{ t.minimumWage }}</label>
            <input
              id="labor-min-wage"
              v-model="settings.minimum_wage"
              class="adm-input labor__num"
              inputmode="numeric"
              maxlength="6"
              autocomplete="off"
              :aria-invalid="settingsErrors.minimum_wage ? 'true' : undefined"
            >
            <p
              v-if="settingsErrors.minimum_wage"
              class="adm-error"
            >
              {{ settingsErrors.minimum_wage }}
            </p>
          </div>
          <p
            v-if="settingsNotice"
            class="adm-ok"
            role="status"
          >
            {{ settingsNotice }}
          </p>
          <p
            v-if="settingsFailed"
            class="adm-error"
            role="alert"
          >
            {{ settingsFailed }}
          </p>
          <div class="adm-actions">
            <BigButton
              type="submit"
              :loading="savingSettings"
            >
              {{ t.saveSettings }}
            </BigButton>
          </div>
        </form>
      </section>

      <section
        class="adm-panel"
        aria-labelledby="labor-members-heading"
      >
        <h2
          id="labor-members-heading"
          class="adm-panel__title"
        >
          {{ t.membersHeading }}
        </h2>
        <p class="adm-help">
          {{ t.membersHelp }}
        </p>
        <ul class="adm-list">
          <li
            v-for="row in members"
            :key="row.member.id"
          >
            <form
              class="labor-member"
              :class="{ 'labor-member--off': !row.member.is_active }"
              novalidate
              :aria-label="row.member.name"
              @submit.prevent="saveMember(row)"
            >
              <p class="labor-member__name">
                {{ row.member.name }}
                <span class="adm-badge">{{ ja.role[row.member.role] }}</span>
                <span
                  v-if="!row.member.is_active"
                  class="adm-badge"
                >{{ t.inactive }}</span>
                <span
                  v-if="row.member.is_active && row.member.hourly_wage === null"
                  class="adm-badge labor-member__warn"
                >{{ t.summaryWarnings.wage_missing }}</span>
              </p>
              <div class="adm-field">
                <label
                  :for="`labor-wage-${row.member.id}`"
                  class="adm-field__label"
                >{{ t.hourlyWage }}</label>
                <input
                  :id="`labor-wage-${row.member.id}`"
                  v-model="row.wage"
                  class="adm-input labor__num"
                  inputmode="numeric"
                  maxlength="6"
                  autocomplete="off"
                  :aria-invalid="row.error ? 'true' : undefined"
                >
              </div>
              <label class="adm-check"><input
                v-model="row.exempt"
                type="checkbox"
              >{{ t.exempt }}</label>
              <div class="adm-actions">
                <BigButton
                  type="submit"
                  variant="secondary"
                  :loading="row.saving"
                >
                  {{ t.saveMember }}
                </BigButton>
              </div>
              <p
                v-if="row.error"
                class="adm-error"
                role="alert"
              >
                {{ row.error }}
              </p>
              <p
                v-if="row.notice"
                class="adm-ok"
                role="status"
              >
                {{ row.notice }}
              </p>
            </form>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
.labor { display: flex; flex-direction: column; gap: 16px; }
.labor__num { max-width: 200px; }

.labor-member {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
}

.labor-member--off { background: var(--c-surface-alt); }
.labor-member__name { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 18px; font-weight: 700; }
.labor-member__warn { border-color: var(--c-danger); color: var(--c-danger); }
</style>
