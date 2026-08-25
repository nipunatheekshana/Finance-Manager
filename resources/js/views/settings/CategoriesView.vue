<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Plus, Shapes } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import TextField from '@/components/common/TextField.vue'
import SelectField from '@/components/common/SelectField.vue'
import CategoryIcon from '@/components/common/CategoryIcon.vue'
import BottomSheet from '@/components/common/BottomSheet.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import { api } from '@/services/api'
import { useExpenseStore } from '@/stores/expenses'
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

const ICON_OPTIONS = [
  'utensils', 'car', 'shopping-bag', 'clapperboard', 'receipt', 'cigarette',
  'user', 'dumbbell', 'heart-pulse', 'repeat', 'users', 'circle-ellipsis',
  'piggy-bank', 'shield', 'wallet', 'circle',
].map((icon) => ({ value: icon, label: icon.replace(/-/g, ' ') }))

const COLOR_OPTIONS = [
  'amber', 'sky', 'violet', 'pink', 'slate', 'stone',
  'teal', 'lime', 'rose', 'indigo', 'orange', 'zinc',
].map((color) => ({ value: color, label: color }))

const form = reactive({
  name: '',
  icon: 'circle',
  color: 'slate',
  monthly_budget: '',
  warning_percentage: '80',
})

function open(category: Category | null): void {
  editing.value = category
  Object.keys(errors).forEach((key) => delete errors[key])

  form.name = category?.name ?? ''
  form.icon = category?.icon ?? 'circle'
  form.color = category?.color ?? 'slate'
  form.monthly_budget = category?.monthly_budget ? String(Number.parseFloat(category.monthly_budget)) : ''
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
              warns at {{ category.warning_percentage }}%
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

        <div class="grid gap-4 sm:grid-cols-2">
          <SelectField v-model="form.icon" :options="ICON_OPTIONS" label="Icon" />
          <SelectField v-model="form.color" :options="COLOR_OPTIONS" label="Colour" />
        </div>

        <div class="flex items-center gap-3 rounded-[var(--radius-field)] bg-sunken p-3">
          <CategoryIcon :icon="form.icon" :color="form.color" />
          <span class="text-sm text-ink-muted">Preview</span>
        </div>

        <MoneyInput
          v-model="form.monthly_budget"
          label="Monthly budget"
          hint="Leave blank for no limit. You are never blocked from spending."
          :error="errors.monthly_budget"
        />

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
