<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { Plus, Shapes } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import TextField from '@/components/common/TextField.vue'
import CategoryIcon from '@/components/common/CategoryIcon.vue'
import BottomSheet from '@/components/common/BottomSheet.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import { api } from '@/services/api'
import { useExpenseStore } from '@/stores/expenses'
import { CATEGORY_COLORS, CATEGORY_SWATCHES, ICON_GROUPS } from '@/data/categoryOptions'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import type { Category } from '@/types'

const expenses = useExpenseStore()
const ui = useUiStore()

const loading = ref(true)
const sheetOpen = ref(false)
const editing = ref<Category | null>(null)
const saving = ref(false)
const deleting = ref(false)
const confirmDelete = ref<Category | null>(null)
const errors = reactive<Record<string, string>>({})

/** Type to narrow the icon grid; matching is on the icon's name. */
const iconSearch = ref('')

const visibleGroups = computed(() => {
  const needle = iconSearch.value.trim().toLowerCase().replace(/\s+/g, '-')
  if (needle === '') return ICON_GROUPS

  return ICON_GROUPS.map((group) => ({
    label: group.label,
    icons: Object.fromEntries(
      Object.entries(group.icons).filter(([name]) => name.includes(needle)),
    ),
  })).filter((group) => Object.keys(group.icons).length > 0)
})

const COLOR_NAMES = Object.keys(CATEGORY_COLORS)

const form = reactive({
  name: '',
  icon: 'circle',
  color: 'slate',
  monthly_budget: '',
  is_allowance: false,
  warning_percentage: '80',
})

/** An allowance has to have an amount to reserve. */
const hasBudget = computed(() => Number.parseFloat(form.monthly_budget) > 0)

function open(category: Category | null): void {
  editing.value = category
  Object.keys(errors).forEach((key) => delete errors[key])

  form.name = category?.name ?? ''
  form.icon = category?.icon ?? 'circle'
  form.color = category?.color ?? 'slate'
  form.monthly_budget = category?.monthly_budget ? String(Number.parseFloat(category.monthly_budget)) : ''
  form.is_allowance = category?.is_allowance ?? false
  form.warning_percentage = String(category?.warning_percentage ?? 80)

  sheetOpen.value = true
}

async function submit(): Promise<void> {
  saving.value = true
  Object.keys(errors).forEach((key) => delete errors[key])

  const budget = Number.parseFloat(form.monthly_budget)

  const payload = {
    name: form.name.trim(),
    icon: form.icon,
    color: form.color,
    monthly_budget: Number.isFinite(budget) && budget > 0 ? budget.toFixed(2) : null,
    is_allowance: Number.isFinite(budget) && budget > 0 ? form.is_allowance : false,
    warning_percentage: Number(form.warning_percentage),
  }

  try {
    if (editing.value) {
      await api.put(`/categories/${editing.value.id}`, payload)
      ui.success('Category updated')
    } else {
      await api.post('/categories', payload)
      ui.success('Category added')
    }

    await expenses.loadReference(true)
    sheetOpen.value = false
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      Object.entries(error.errors).forEach(([field, messages]) => {
        errors[field] = messages[0] ?? ''
      })
    } else if (error instanceof ApiError) {
      ui.error('Could not save that category', error.message)
    }
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  if (!confirmDelete.value) return
  deleting.value = true

  try {
    const response = await api.delete<{ message: string }>(`/categories/${confirmDelete.value.id}`)
    ui.success(response.message)
    await expenses.loadReference(true)
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not remove that category', error.message)
  } finally {
    deleting.value = false
    confirmDelete.value = null
  }
}

onMounted(async () => {
  await expenses.loadReference(true)
  loading.value = false
})
</script>

<template>
  <div>
    <PageHeader title="Categories" subtitle="Set a monthly limit to get warnings before you overspend." back-to="/settings">
      <template #actions>
        <button type="button" class="btn btn-primary !px-3" @click="open(null)">
          <Plus class="h-4 w-4" aria-hidden="true" />
          <span class="sr-only">Add category</span>
        </button>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" variant="list" :rows="6" />

    <EmptyState
      v-else-if="expenses.categories.length === 0"
      :icon="Shapes"
      title="No categories"
      description="Add a category to start organising your spending."
      action-label="Add category"
      @action="open(null)"
    />

    <ul v-else class="card divide-y divide-line px-4">
      <li v-for="category in expenses.categories" :key="category.id" class="flex items-center gap-3 py-3">
        <CategoryIcon :icon="category.icon" :color="category.color" size="sm" />

        <button type="button" class="min-w-0 flex-1 text-left" @click="open(category)">
          <p class="truncate text-sm font-medium text-ink">
            {{ category.name }}
            <span v-if="!category.active" class="badge ml-1 bg-sunken text-ink-subtle">Hidden</span>
          </p>
          <p class="text-xs text-ink-subtle">
            <template v-if="category.monthly_budget">
              <MoneyText :amount="category.monthly_budget" size="xs" class="font-semibold" /> a month ·
              <template v-if="category.is_allowance">set aside in your plan</template>
              <template v-else>warns at {{ category.warning_percentage }}%</template>
            </template>
            <template v-else>No budget set</template>
          </p>
        </button>

        <button
          type="button"
          class="btn btn-ghost !min-h-11 !px-3 !text-xs text-over"
          @click="confirmDelete = category"
        >
          Remove
        </button>
      </li>
    </ul>

    <BottomSheet
      :open="sheetOpen"
      :title="editing ? 'Edit category' : 'New category'"
      :busy="saving"
      @close="sheetOpen = false"
    >
      <div class="space-y-4 pb-2">
        <TextField v-model="form.name" label="Name" required :error="errors.name" data-autofocus />

        <!-- What it will look like, above the choices that change it. -->
        <div class="flex items-center gap-3 rounded-[var(--radius-field)] bg-sunken p-3">
          <CategoryIcon :icon="form.icon" :color="form.color" size="lg" />
          <span class="min-w-0">
            <span class="block truncate text-sm font-semibold text-ink">{{ form.name || 'New category' }}</span>
            <span class="block text-xs text-ink-muted">{{ form.icon.replace(/-/g, ' ') }} · {{ form.color }}</span>
          </span>
        </div>

        <div>
          <span class="label">Colour</span>
          <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Colour">
            <button
              v-for="color in COLOR_NAMES"
              :key="color"
              type="button"
              role="radio"
              :aria-checked="form.color === color"
              :aria-label="color"
              class="flex h-9 w-9 items-center justify-center rounded-full transition"
              :class="form.color === color ? 'ring-2 ring-brand ring-offset-2 ring-offset-raised' : 'hover:scale-105'"
              @click="form.color = color"
            >
              <span class="h-6 w-6 rounded-full" :class="CATEGORY_SWATCHES[color]" aria-hidden="true" />
            </button>
          </div>
        </div>

        <div>
          <TextField v-model="iconSearch" label="Icon" placeholder="Search icons…" />

          <div class="mt-2 max-h-64 space-y-3 overflow-y-auto rounded-[var(--radius-field)] border border-line p-3">
            <p v-if="!visibleGroups.length" class="py-4 text-center text-sm text-ink-muted">
              No icon matches that.
            </p>

            <section v-for="group in visibleGroups" :key="group.label">
              <p class="eyebrow mb-1.5">{{ group.label }}</p>
              <div class="grid grid-cols-6 gap-1.5 sm:grid-cols-8" role="radiogroup" :aria-label="group.label">
                <button
                  v-for="(_, name) in group.icons"
                  :key="name"
                  type="button"
                  role="radio"
                  :aria-checked="form.icon === name"
                  :aria-label="String(name).replace(/-/g, ' ')"
                  :title="String(name).replace(/-/g, ' ')"
                  class="flex h-11 w-full items-center justify-center rounded-[var(--radius-field)] transition"
                  :class="form.icon === name ? 'bg-brand-soft ring-2 ring-brand' : 'hover:bg-sunken'"
                  @click="form.icon = String(name)"
                >
                  <CategoryIcon :icon="String(name)" :color="form.color" size="sm" />
                </button>
              </div>
            </section>
          </div>
        </div>

        <MoneyInput
          v-model="form.monthly_budget"
          label="Monthly budget"
          hint="Leave blank for no limit. You are never blocked from spending."
          :error="errors.monthly_budget"
        />

        <!-- The difference between a warning and money actually put aside.
             This used to appear only once an amount had been typed, so the
             feature was invisible to anyone who did not already know it
             existed. -->
        <label
          class="flex items-start justify-between gap-3 rounded-[var(--radius-field)] bg-sunken p-3"
          :class="hasBudget ? 'cursor-pointer' : 'opacity-60'"
        >
          <span class="min-w-0">
            <span class="block text-sm font-medium text-ink">Set this money aside</span>
            <span class="block text-xs text-ink-muted">
              Reserve it in your monthly plan instead of only warning you. Use this
              for spending that adds up through the month, like fuel or groceries.
              It comes out of your income and stops competing with your daily budget.
            </span>
            <span v-if="!hasBudget" class="mt-1 block text-xs font-medium text-ink-subtle">
              Enter a monthly amount above to set money aside.
            </span>
          </span>
          <input
            v-model="form.is_allowance"
            type="checkbox"
            :disabled="!hasBudget"
            class="mt-0.5 h-5 w-5 shrink-0 rounded border-line accent-[rgb(var(--color-brand))]"
          />
        </label>

        <TextField
          v-model="form.warning_percentage"
          label="Warn me at (%)"
          type="number"
          inputmode="numeric"
          min="1"
          max="100"
          :error="errors.warning_percentage"
        />
      </div>

      <template #footer>
        <button
          type="button"
          class="btn btn-primary w-full !text-base"
          :disabled="saving || form.name.trim() === ''"
          @click="submit"
        >
          {{ saving ? 'Saving…' : editing ? 'Save changes' : 'Add category' }}
        </button>
      </template>
    </BottomSheet>

    <ConfirmDialog
      :open="confirmDelete !== null"
      title="Remove this category?"
      message="Categories with expenses are hidden instead of deleted, so your history stays intact."
      confirm-label="Remove"
      destructive
      :busy="deleting"
      @confirm="remove"
      @cancel="confirmDelete = null"
    />
  </div>
</template>
