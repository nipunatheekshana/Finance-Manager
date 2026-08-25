<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { AlertTriangle, Building2, Info, Laptop, Layers, Store, Wallet } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import TextField from '@/components/common/TextField.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import { api, ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/composables/useDates'
import type {
  FundingMethod,
  FundingMethodOption,
  IncomeMode,
  IncomeModeOption,
  IncomeModePreview,
} from '@/types'

const auth = useAuthStore()
const ui = useUiStore()

const loading = ref(true)
const saving = ref(false)
const modes = ref<IncomeModeOption[]>([])
const fundingByMode = ref<Record<IncomeMode, FundingMethodOption[]>>(
  {} as Record<IncomeMode, FundingMethodOption[]>,
)
const preview = ref<IncomeModePreview | null>(null)
const errors = ref<Record<string, string>>({})

const selectedMode = ref<IncomeMode>('salaried')
const selectedFunding = ref<FundingMethod | null>(null)
const baseSalary = ref('')
const targetDraw = ref('')
const cycleStartDay = ref('1')
const forecastFactor = ref('80')

const ICONS = {
  salaried: Building2,
  self_employed: Laptop,
  business: Store,
  hybrid: Layers,
} as const

const fundingOptions = computed(() => fundingByMode.value[selectedMode.value] ?? [])

const activeFunding = computed<FundingMethodOption | null>(
  () => fundingOptions.value.find((row) => row.value === selectedFunding.value) ?? null,
)

const needsSalary = computed(
  () => modes.value.find((row) => row.value === selectedMode.value)?.has_salary ?? false,
)

const needsDraw = computed(() => activeFunding.value?.uses_holding_pot ?? false)

const usesForecast = computed(() => selectedFunding.value === 'forecast')

const hasChanged = computed(() => {
  const profile = auth.profile
  if (!profile) return false
  return (
    selectedMode.value !== profile.income_mode ||
    selectedFunding.value !== profile.funding_method ||
    baseSalary.value !== String(Number.parseFloat(profile.base_salary)) ||
    targetDraw.value !== String(Number.parseFloat(profile.target_draw)) ||
    Number(cycleStartDay.value) !== profile.cycle_start_day ||
    Number(forecastFactor.value) !== profile.forecast_factor
  )
})

const canSave = computed(() => {
  if (saving.value || !hasChanged.value) return false
  if (needsSalary.value && !(Number.parseFloat(baseSalary.value) > 0)) return false
  if (needsDraw.value && !(Number.parseFloat(targetDraw.value) > 0)) return false
  return true
})

function hydrate(): void {
  const profile = auth.profile
  if (!profile) return

  selectedMode.value = profile.income_mode
  selectedFunding.value = profile.funding_method
  baseSalary.value = String(Number.parseFloat(profile.base_salary))
  targetDraw.value = String(Number.parseFloat(profile.target_draw))
  cycleStartDay.value = String(profile.cycle_start_day)
  forecastFactor.value = String(profile.forecast_factor)
}

/** Picking a mode resets the funding method to that mode's recommendation. */
watch(selectedMode, (mode) => {
  const options = fundingByMode.value[mode] ?? []
  const recommended = options.find((row) => row.recommended) ?? options[0]
  selectedFunding.value = recommended?.value ?? null
})

let previewTimer: ReturnType<typeof setTimeout> | undefined

/** Show what the switch would do, without doing it. */
watch([selectedMode, selectedFunding], () => {
  clearTimeout(previewTimer)

  if (!hasChanged.value) {
    preview.value = null
    return
  }

  previewTimer = setTimeout(() => {
    void api
      .post<{ data: IncomeModePreview }>('/income-modes/preview', {
        income_mode: selectedMode.value,
        funding_method: selectedFunding.value,
        base_salary: needsSalary.value ? Number.parseFloat(baseSalary.value || '0').toFixed(2) : undefined,
        target_draw: needsDraw.value ? Number.parseFloat(targetDraw.value || '0').toFixed(2) : undefined,
      })
      .then((response) => {
        preview.value = response.data
      })
      .catch(() => {
        preview.value = null
      })
  }, 300)
})

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}

  try {
    await api.put('/income-modes', {
      income_mode: selectedMode.value,
      funding_method: selectedFunding.value,
      base_salary: needsSalary.value ? Number.parseFloat(baseSalary.value || '0').toFixed(2) : undefined,
      target_draw: needsDraw.value ? Number.parseFloat(targetDraw.value || '0').toFixed(2) : undefined,
      cycle_start_day: Number(cycleStartDay.value),
      forecast_factor: Number(forecastFactor.value),
    })

    await auth.refreshProfile()
    hydrate()
    preview.value = null

    ui.success('Income setup saved', 'Your past months are unchanged.')
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? '']),
      )
    } else if (error instanceof ApiError) {
      ui.error('Could not save your income setup', error.message)
    }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  const [response] = await Promise.all([
    api.get<{
      data: {
        modes: IncomeModeOption[]
        funding_methods: Record<IncomeMode, FundingMethodOption[]>
      }
    }>('/income-modes'),
    auth.refreshProfile(),
  ])

  modes.value = response.data.modes
  fundingByMode.value = response.data.funding_methods
  hydrate()
  loading.value = false
})
</script>

<template>
  <div>
    <PageHeader
      title="Income setup"
      subtitle="How you earn shapes how your plan works. Change it whenever your work changes."
      back-to="/settings"
    />

    <LoadingState v-if="loading" :rows="3" />

    <div v-else class="space-y-6">
      <!-- Mode -->
      <section>
        <h2 class="mb-3 text-base font-semibold text-ink">How do you earn?</h2>

        <div class="space-y-2.5" role="radiogroup" aria-label="How do you earn?">
          <button
            v-for="mode in modes"
            :key="mode.value"
            type="button"
            role="radio"
            :aria-checked="selectedMode === mode.value"
            class="w-full rounded-[var(--radius-card)] border p-3.5 text-left transition"
            :class="
              selectedMode === mode.value
                ? 'border-brand bg-brand-soft'
                : 'border-line bg-raised hover:border-ink-subtle'
            "
            @click="selectedMode = mode.value"
          >
            <div class="flex items-start gap-3">
              <component
                :is="ICONS[mode.value]"
                class="mt-0.5 h-5 w-5 shrink-0"
                :class="selectedMode === mode.value ? 'text-brand' : 'text-ink-subtle'"
                aria-hidden="true"
              />
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-ink">{{ mode.label }}</p>
                <p class="mt-0.5 text-sm text-ink-muted">{{ mode.description }}</p>
              </div>
            </div>
          </button>
        </div>
      </section>

      <!-- Funding method -->
      <section v-if="fundingOptions.length > 1">
        <h2 class="mb-1 text-base font-semibold text-ink">What should fund your plan?</h2>
        <p class="mb-3 text-sm text-ink-muted">
          The figure your budget is built on each cycle.
        </p>

        <div class="space-y-2.5" role="radiogroup" aria-label="What funds your plan?">
          <button
            v-for="option in fundingOptions"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="selectedFunding === option.value"
            class="w-full rounded-[var(--radius-card)] border p-3.5 text-left transition"
            :class="
              selectedFunding === option.value
                ? 'border-brand bg-brand-soft'
                : 'border-line bg-raised hover:border-ink-subtle'
            "
            @click="selectedFunding = option.value"
          >
            <p class="text-sm font-semibold text-ink">
              {{ option.label }}
              <span v-if="option.recommended" class="badge ml-1.5 bg-safe-soft text-safe">
                Recommended
              </span>
            </p>
            <p class="mt-0.5 text-sm text-ink-muted">{{ option.description }}</p>
          </button>
        </div>
      </section>

      <!-- Figures -->
      <section class="card space-y-4 p-4">
        <h2 class="text-base font-semibold text-ink">Your figures</h2>

        <MoneyInput
          v-if="needsSalary"
          v-model="baseSalary"
          label="Monthly salary"
          :error="errors.base_salary"
        />

        <MoneyInput
          v-if="needsDraw"
          v-model="targetDraw"
          label="What you pay yourself each cycle"
          hint="Income collects in a pot and you draw this steadily from it."
          :error="errors.target_draw"
        />

        <TextField
          v-if="usesForecast"
          v-model="forecastFactor"
          label="Plan at this much of your average (%)"
          type="number"
          inputmode="numeric"
          min="10"
          max="100"
          hint="Below 100% so one good month does not set an unaffordable budget."
          :error="errors.forecast_factor"
        />

        <TextField
          v-model="cycleStartDay"
          :label="needsSalary ? 'Pay day' : 'Day your month starts'"
          type="number"
          inputmode="numeric"
          min="1"
          max="31"
          :error="errors.cycle_start_day"
        />
      </section>

      <!-- What the change will do -->
      <section
        v-if="preview"
        class="rounded-[var(--radius-card)] p-4"
        :class="preview.deferred ? 'bg-warn-soft' : 'bg-info-soft'"
      >
        <div class="flex items-start gap-3">
          <component
            :is="preview.deferred ? AlertTriangle : Info"
            class="mt-0.5 h-5 w-5 shrink-0"
            :class="preview.deferred ? 'text-warn' : 'text-info'"
            aria-hidden="true"
          />
          <div class="min-w-0 flex-1 text-sm">
            <p class="font-semibold text-ink">What this changes</p>

            <ul class="mt-1.5 space-y-1 text-ink-muted">
              <li>Plans will be funded by: {{ preview.to.funding_method_label }}</li>
              <li v-if="preview.cycle_anchor_changes">
                Your cycle becomes: {{ preview.to.cycle_anchor_label }}
              </li>
              <li v-if="preview.suggested_draw?.has_history">
                Suggested draw:
                <MoneyText :amount="preview.suggested_draw.suggested" size="sm" class="font-semibold text-ink" />
                — {{ preview.suggested_draw.explanation }}
              </li>
            </ul>

            <p v-if="preview.deferred" class="mt-2 font-medium text-warn">
              Your cycle dates change on {{ formatDate(preview.takes_effect_on, true) }}, once
              {{ preview.active_plan_label }} has finished. Nothing moves under a plan you are
              already spending against.
            </p>

            <p class="mt-2 text-xs text-ink-subtle">
              Past months keep the settings they were built with and are not rewritten.
            </p>
          </div>
        </div>
      </section>

      <button
        type="button"
        class="btn btn-primary w-full !text-base"
        :disabled="!canSave"
        @click="save"
      >
        <Wallet class="h-4 w-4" aria-hidden="true" />
        {{ saving ? 'Saving…' : hasChanged ? 'Save income setup' : 'No changes to save' }}
      </button>
    </div>
  </div>
</template>
