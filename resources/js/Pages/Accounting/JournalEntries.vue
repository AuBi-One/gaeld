<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import ExportDropdown from '@/Components/UI/ExportDropdown.vue'
import Tooltip from '@/Components/UI/Tooltip.vue'
import Modal from '@/Components/UI/Modal.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FileUpload from '@/Components/UI/FileUpload.vue'
import MultiSelect from '@/Components/UI/MultiSelect.vue'
import HelpText from '@/Components/HelpText.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'
import { buildAccountOptions } from '@/lib/accountOptions'
import { BookText, Plus, Check, RotateCcw, Trash2, Pencil, HelpCircle, ChevronsUpDown, ChevronsDownUp, X, Paperclip, Eye } from 'lucide-vue-next'

const props = defineProps({
  entries: Object,
  accounts: { type: Array, default: () => [] },
  can: { type: Object, default: () => ({ create: false, edit: false, delete: false }) },
  filterAccounts: { type: Array, default: () => [] },
  filters: { type: Object, default: () => ({}) },
  perPageOptions: { type: Array, default: () => [20, 50, 100, 200] },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

// Column key -> sort key understood by JournalEntryQuery
const sortKeys = { date: 'date', reference: 'reference', description: 'description', is_posted: 'status', amount: 'amount' }

const columns = computed(() => [
  { key: 'date', label: t('date'), format: v => formatDate(v), sortable: true },
  { key: 'reference', label: t('reference'), sortable: true },
  { key: 'description', label: t('description'), sortable: true },
  { key: 'amount', label: t('amount'), format: v => formatCurrency(v ?? 0), class: 'text-right', sortable: true },
  { key: 'is_posted', label: t('status'), sortable: true },
  { key: 'actions', label: '', align: 'right' },
])

// Filters, sorting and page size live in the URL (server-side, see JournalEntryQuery)
const filterForm = ref({
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
  accounts: (props.filters.accounts ?? []).map(String),
  reference: props.filters.reference ?? '',
  description: props.filters.description ?? '',
  status: props.filters.status ?? '',
})

const activeSortColumn = computed(() =>
  Object.keys(sortKeys).find(k => sortKeys[k] === (props.filters.sort ?? 'date')) ?? 'date',
)

const hasActiveFilters = computed(() => Object.values(filterForm.value).some(v => (Array.isArray(v) ? v.length > 0 : v !== '' && v !== null)))

// Filters as query parameters; accounts as "12,63"
function filterParams(filters) {
  return { ...filters, accounts: (filters.accounts ?? []).join(',') }
}

// Export uses the applied filters (without them: posted entries of the current year)
const exportParams = computed(() => {
  const { from, to, reference, description, status } = props.filters
  return filterParams({ from, to, accounts: props.filters.accounts, reference, description, status })
})

function loadList(overrides = {}) {
  const params = {
    ...filterParams(filterForm.value),
    sort: props.filters.sort ?? 'date',
    direction: props.filters.direction ?? 'desc',
    per_page: props.filters.per_page ?? props.perPageOptions[0],
    ...overrides,
  }
  // Keep the URL short: drop empty values and the defaults
  const query = Object.fromEntries(Object.entries(params).filter(([k, v]) =>
    v !== '' && v !== null && v !== undefined
    && !(k === 'sort' && v === 'date' && params.direction === 'desc')
    && !(k === 'direction' && v === 'desc' && params.sort === 'date')
    && !(k === 'per_page' && Number(v) === props.perPageOptions[0]),
  ))
  router.get('/accounting/journal-entries', query, { only: ['entries', 'filters'], preserveState: true, preserveScroll: true, replace: true })
}

let filterTimer = null
watch(filterForm, () => {
  clearTimeout(filterTimer)
  filterTimer = setTimeout(() => loadList(), 350)
}, { deep: true })
onBeforeUnmount(() => clearTimeout(filterTimer))

function clearFilters() {
  filterForm.value = { from: '', to: '', accounts: [], reference: '', description: '', status: '' }
}

function handleSort({ sort, direction }) {
  clearTimeout(filterTimer)
  loadList({ sort: sortKeys[sort] ?? 'date', direction })
}

function changePerPage(value) {
  clearTimeout(filterTimer)
  loadList({ per_page: Number(value) })
}

const filterAccountOptions = computed(() =>
  buildAccountOptions(props.filterAccounts).map(o => ({ ...o, value: String(o.value) })),
)

const statusOptions = computed(() => [
  { value: '', label: t('all_statuses') },
  { value: 'draft', label: t('draft') },
  { value: 'posted', label: t('posted') },
])

// Expand all / collapse all; the choice is remembered in this browser
const EXPAND_KEY = 'gaeld.journal.expandAll'
const table = ref(null)
const allExpanded = ref(false)
try { allExpanded.value = localStorage.getItem(EXPAND_KEY) === '1' } catch { /* storage unavailable */ }

function setAllExpanded(value) {
  allExpanded.value = value
  value ? table.value?.expandAll() : table.value?.collapseAll()
  try { localStorage.setItem(EXPAND_KEY, value ? '1' : '0') } catch { /* storage unavailable */ }
}

const accountOptions = computed(() => [
  { value: '', label: t('select_placeholder') },
  ...props.accounts.map(a => ({ value: String(a.id), label: `${a.code} — ${a.name}` })),
])

// Form modal state
const showForm = ref(false)
const editingEntry = ref(null)

const form = useForm({
  date: new Date().toISOString().split('T')[0],
  reference: '',
  description: '',
  is_posted: true,
  lines: [
    { account_id: '', debit: '0.00', credit: '0.00', description: '' },
    { account_id: '', debit: '0.00', credit: '0.00', description: '' },
  ],
  justificatif: null,
})

function onJustificatifChange(file) {
  form.justificatif = file ?? null
}

function removeExistingJustificatif() {
  if (!editingEntry.value) return
  router.delete(`/accounting/journal-entries/${editingEntry.value.id}/justificatif`, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { editingEntry.value.justificatif_path = null },
  })
}

function openCreate() {
  editingEntry.value = null
  form.reset()
  form.date = new Date().toISOString().split('T')[0]
  form.is_posted = true
  form.lines = [
    { account_id: '', debit: '0.00', credit: '0.00', description: '' },
    { account_id: '', debit: '0.00', credit: '0.00', description: '' },
  ]
  form.justificatif = null
  showForm.value = true
}

function openEdit(entry) {
  editingEntry.value = entry

  // Format date to YYYY-MM-DD for date input (handle both string and object dates)
  const dateStr = typeof entry.date === 'string' ? entry.date : entry.date?.toString()
  form.date = dateStr ? dateStr.split('T')[0].split(' ')[0] : new Date().toISOString().split('T')[0]

  form.reference = entry.reference || ''
  form.description = entry.description || ''
  form.is_posted = entry.is_posted
  form.lines = entry.lines.map(line => ({
    account_id: String(line.account_id),
    debit: String(line.debit || '0.00'),
    credit: String(line.credit || '0.00'),
    description: line.description || '',
  }))
  form.justificatif = null
  showForm.value = true
}

function submitForm(post = true) {
  form.is_posted = post

  if (editingEntry.value) {
    form.put(`/accounting/journal-entries/${editingEntry.value.id}`, {
      forceFormData: true,
      onSuccess: () => { showForm.value = false },
      preserveScroll: true,
    })
  } else {
    form.post('/accounting/journal-entries', {
      forceFormData: true,
      onSuccess: () => { showForm.value = false },
      preserveScroll: true,
    })
  }
}

function addLine() {
  form.lines.push({ account_id: '', debit: '0.00', credit: '0.00', description: '' })
}

function removeLine(index) {
  if (form.lines.length <= 2) return
  form.lines.splice(index, 1)
}

const totalDebit = computed(() =>
  form.lines.reduce((sum, l) => sum + (parseFloat(l.debit) || 0), 0)
)
const totalCredit = computed(() =>
  form.lines.reduce((sum, l) => sum + (parseFloat(l.credit) || 0), 0)
)
const difference = computed(() => +(totalDebit.value - totalCredit.value).toFixed(2))
const isBalanced = computed(() => difference.value === 0 && totalDebit.value > 0)

function lineError(index, field) {
  return form.errors[`lines.${index}.${field}`]
}

// Post action
const postingEntry = ref(null)

function confirmPost(entry) {
  postingEntry.value = entry
}

function doPost() {
  router.post(`/accounting/journal-entries/${postingEntry.value.id}/post`, {}, {
    preserveScroll: true,
    onFinish: () => { postingEntry.value = null },
  })
}

// Reverse action
const reversingEntry = ref(null)

function confirmReverse(entry) {
  reversingEntry.value = entry
}

function doReverse() {
  router.post(`/accounting/journal-entries/${reversingEntry.value.id}/reverse`, {}, {
    preserveScroll: true,
    onFinish: () => { reversingEntry.value = null },
  })
}

// Delete action
const deletingEntry = ref(null)

function confirmDelete(entry) {
  deletingEntry.value = entry
}

function doDelete() {
  router.delete(`/accounting/journal-entries/${deletingEntry.value.id}`, {
    preserveScroll: true,
    onFinish: () => { deletingEntry.value = null },
  })
}
</script>

<template>
  <AppLayout :title="t('journal_entries')" help-page="accounting-basics">
    <HelpText :title="t('help_journal_title')" class="mb-6">
      <p>{{ t('help_journal_text') }}</p>
    </HelpText>

    <div class="mb-4 flex justify-end gap-2">
      <Button v-if="can.create" size="sm" @click="openCreate">
        <Plus class="mr-1 h-4 w-4" />
        {{ t('new_journal_entry') }}
      </Button>
      <Tooltip :content="t('journal_export_hint')" side="left">
        <ExportDropdown base-url="/accounting/journal-entries/export" :params="exportParams" />
      </Tooltip>
    </div>

    <!-- Filters -->
    <Card class="mb-4" data-testid="journal-filters">
      <CardContent class="pt-6">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-6">
          <FormInput id="filter_from" v-model="filterForm.from" type="date" :label="t('from')" />
          <FormInput id="filter_to" v-model="filterForm.to" type="date" :label="t('to')" />
          <div class="col-span-2">
            <MultiSelect
              id="filter_accounts"
              v-model="filterForm.accounts"
              :label="t('accounts')"
              :options="filterAccountOptions"
              group-key="group"
              :placeholder="t('all_accounts')"
              :search-placeholder="t('filter_contains')"
            />
          </div>
          <FormSelect id="filter_status" v-model="filterForm.status" :label="t('status')" :options="statusOptions" />
          <div class="flex items-end">
            <Button v-if="hasActiveFilters" type="button" variant="outline" size="sm" class="w-full" @click="clearFilters">
              <X class="mr-1 h-4 w-4" /> {{ t('clear_filters') }}
            </Button>
          </div>
          <div class="col-span-2 sm:col-span-1 lg:col-span-3">
            <FormInput id="filter_reference" v-model="filterForm.reference" :label="t('reference')" :placeholder="t('filter_contains')" />
          </div>
          <div class="col-span-2 sm:col-span-1 lg:col-span-3">
            <FormInput id="filter_description" v-model="filterForm.description" :label="t('description')" :placeholder="t('filter_contains')" />
          </div>
        </div>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <CardTitle>
            {{ t('journal_entries') }}
            <span class="ml-2 text-sm font-normal text-[hsl(var(--muted-foreground))]" data-testid="journal-count">{{ t('journal_entries_count', { count: entries?.total ?? 0 }) }}</span>
          </CardTitle>
          <div class="flex flex-wrap items-center gap-2">
            <Button type="button" variant="outline" size="sm" data-testid="expand-all" @click="setAllExpanded(true)">
              <ChevronsUpDown class="mr-1 h-4 w-4" /> {{ t('expand_all') }}
            </Button>
            <Button type="button" variant="outline" size="sm" data-testid="collapse-all" @click="setAllExpanded(false)">
              <ChevronsDownUp class="mr-1 h-4 w-4" /> {{ t('collapse_all') }}
            </Button>
            <label class="flex items-center gap-2 text-sm text-[hsl(var(--muted-foreground))]" for="per_page">
              {{ t('per_page') }}
              <select
                id="per_page"
                :value="filters.per_page ?? perPageOptions[0]"
                class="h-9 rounded-md border border-[hsl(var(--input))] bg-transparent px-2 text-sm text-[hsl(var(--foreground))]"
                @change="changePerPage($event.target.value)"
              >
                <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
              </select>
            </label>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <DataTable
          ref="table"
          :columns="columns"
          :rows="entries?.data ?? []"
          :pagination="entries"
          :sort="activeSortColumn"
          :direction="filters.direction ?? 'desc'"
          expandable
          :expanded-by-default="allExpanded"
          @sort="handleSort"
        >
          <template #empty>
            <EmptyState
              :icon="BookText"
              :title="t('empty_journal_entries_title')"
              :description="t('empty_journal_entries_desc')"
              :action-label="can.create ? t('new_journal_entry') : null"
              @action="openCreate"
            />
          </template>
          <template #cell-is_posted="{ value }">
            <Badge :variant="value ? 'success' : 'warning'">{{ value ? t('posted') : t('draft') }}</Badge>
          </template>
          <template #cell-reference="{ value, row }">
            <span class="inline-flex items-center gap-2">
              {{ value }}
              <Badge v-if="row.type === 'historical_summary'" variant="secondary">{{ t('historical_summary_badge') }}</Badge>
            </span>
            <!-- Owned by another feature (e.g. a salary slip): edited or deleted there -->
            <span v-if="row.source" class="block text-xs text-[hsl(var(--muted-foreground))]" data-testid="journal-source">
              <Link v-if="row.source.url" :href="row.source.url" class="underline">{{ row.source.label }}</Link>
              <template v-else>{{ row.source.label }}</template>
            </span>
          </template>
          <template #cell-actions="{ row }">
            <div class="flex justify-end gap-1">
              <!-- Draft entry actions -->
              <template v-if="!row.is_posted">
                <Tooltip v-if="can.edit && !row.source" :content="t('edit')" side="left">
                  <Button variant="ghost" size="icon" @click="openEdit(row)">
                    <Pencil class="h-4 w-4" />
                  </Button>
                </Tooltip>
                <Tooltip v-if="can.edit" :content="t('post')" side="left">
                  <Button variant="ghost" size="icon" @click="confirmPost(row)">
                    <Check class="h-4 w-4 text-[hsl(var(--success))]" />
                  </Button>
                </Tooltip>
                <Tooltip v-if="can.delete && !row.source" :content="t('delete')" side="left">
                  <Button variant="ghost" size="icon" @click="confirmDelete(row)">
                    <Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" />
                  </Button>
                </Tooltip>
              </template>
              <!-- Posted entry actions (immutable - can only reverse) -->
              <template v-else>
                <Tooltip v-if="can.edit && !row.source" :content="t('tooltip_reverse_journal_entry')" side="left">
                  <Button variant="ghost" size="icon" @click="confirmReverse(row)">
                    <RotateCcw class="h-4 w-4" />
                  </Button>
                </Tooltip>
              </template>
            </div>
          </template>
          <template #expand-row="{ row }">
            <div v-if="row.lines?.length" class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                    <th class="pb-1">{{ t('account') }}</th>
                    <th class="pb-1 text-right">
                      <span class="inline-flex items-center gap-1">
                        {{ t('debit') }}
                        <Tooltip :content="t('tooltip_journal_balance')" side="top">
                          <HelpCircle class="h-3 w-3 text-[hsl(var(--muted-foreground))]" />
                        </Tooltip>
                      </span>
                    </th>
                    <th class="pb-1 text-right">{{ t('credit') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="line in row.lines" :key="line.id">
                    <td>{{ line.account?.code }} — {{ line.account?.name }}</td>
                    <td class="text-right">{{ formatCurrency(line.debit) }}</td>
                    <td class="text-right">{{ formatCurrency(line.credit) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('no_journal_lines') }}</p>
          </template>
        </DataTable>
      </CardContent>
    </Card>

    <!-- Create/Edit Modal -->
    <Modal
      :open="showForm"
      :title="editingEntry ? t('edit_journal_entry') : t('new_journal_entry')"
      size="xl"
      @close="showForm = false"
    >
      <form @submit.prevent="submitForm(true)">
        <div class="space-y-6">
          <!-- Header section -->
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <FormInput
              id="date"
              v-model="form.date"
              type="date"
              :label="t('date')"
              :error="form.errors.date"
              required
            />
            <FormInput
              id="reference"
              v-model="form.reference"
              :label="t('reference')"
              :error="form.errors.reference"
              :placeholder="t('reference_placeholder')"
            />
            <FormInput
              id="description"
              v-model="form.description"
              :label="t('description')"
              :error="form.errors.description"
            />
          </div>

          <!-- Lines section -->
          <div>
            <div class="mb-2 flex items-center justify-between">
              <label class="text-sm font-medium">{{ t('entry_lines') }}</label>
              <Button type="button" size="sm" variant="outline" @click="addLine">
                <Plus class="mr-1 h-3 w-3" /> {{ t('add_line') }}
              </Button>
            </div>

            <div class="space-y-2">
              <div
                v-for="(line, index) in form.lines"
                :key="index"
                class="grid grid-cols-12 gap-2 items-start"
              >
                <div class="col-span-12 sm:col-span-5">
                  <FormSelect
                    :id="`account_${index}`"
                    v-model="line.account_id"
                    :label="index === 0 ? t('account') : ''"
                    :options="accountOptions"
                    :error="lineError(index, 'account_id')"
                    required
                  />
                </div>
                <div class="col-span-5 sm:col-span-2">
                  <FormInput
                    :id="`debit_${index}`"
                    v-model="line.debit"
                    type="number"
                    step="0.01"
                    min="0"
                    :label="index === 0 ? t('debit') : ''"
                    :error="lineError(index, 'debit')"
                  />
                </div>
                <div class="col-span-5 sm:col-span-2">
                  <FormInput
                    :id="`credit_${index}`"
                    v-model="line.credit"
                    type="number"
                    step="0.01"
                    min="0"
                    :label="index === 0 ? t('credit') : ''"
                    :error="lineError(index, 'credit')"
                  />
                </div>
                <div class="col-span-10 sm:col-span-2">
                  <FormInput
                    :id="`line_desc_${index}`"
                    v-model="line.description"
                    :label="index === 0 ? t('description') : ''"
                    :error="lineError(index, 'description')"
                  />
                </div>
                <div class="col-span-2 sm:col-span-1 flex items-end pb-2">
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :disabled="form.lines.length <= 2"
                    @click="removeLine(index)"
                  >
                    <Trash2 class="h-4 w-4" />
                  </Button>
                </div>
              </div>
            </div>
          </div>

          <!-- Balance summary -->
          <div class="rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] p-4">
            <div class="grid grid-cols-3 gap-2 text-sm">
              <div>
                <div class="text-[hsl(var(--muted-foreground))]">{{ t('total_debit') }}</div>
                <div class="text-base font-semibold">{{ formatCurrency(totalDebit) }}</div>
              </div>
              <div>
                <div class="text-[hsl(var(--muted-foreground))]">{{ t('total_credit') }}</div>
                <div class="text-base font-semibold">{{ formatCurrency(totalCredit) }}</div>
              </div>
              <div>
                <div class="text-[hsl(var(--muted-foreground))]">{{ t('difference') }}</div>
                <div
                  class="text-base font-semibold"
                  :class="isBalanced ? 'text-[hsl(var(--success))]' : 'text-[hsl(var(--destructive))]'"
                >
                  {{ formatCurrency(difference) }}
                  <span class="ml-1 text-xs font-normal">
                    {{ isBalanced ? t('entry_balanced') : t('entry_unbalanced') }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <div v-if="form.errors.lines" class="text-xs text-[hsl(var(--destructive))]">{{ form.errors.lines }}</div>

          <!-- Justificatif -->
          <div>
            <FileUpload
              size="compact"
              :label="t('justificatif')"
              :error="form.errors.justificatif"
              @change="onJustificatifChange"
            >
              <p v-if="editingEntry?.justificatif_path && !form.justificatif" class="text-xs text-[hsl(var(--muted-foreground))]">
                {{ t('justificatif_attached') }}
              </p>
            </FileUpload>
            <ul v-if="editingEntry?.justificatif_path" class="mt-2 space-y-1">
              <li class="flex items-center justify-between rounded-md border border-[hsl(var(--border))] px-3 py-2 text-sm">
                <span class="flex items-center gap-1.5 text-[hsl(var(--muted-foreground))]">
                  <Paperclip class="h-4 w-4" />
                  {{ t('justificatif') }}
                </span>
                <span class="flex items-center gap-3">
                  <a
                    :href="`/accounting/journal-entries/${editingEntry.id}/justificatif?inline=1`"
                    target="_blank"
                    class="inline-flex items-center gap-1 text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--foreground))]"
                  >
                    <Eye class="h-4 w-4" />
                    {{ t('view_justificatif') }}
                  </a>
                  <button
                    v-if="!editingEntry.is_posted"
                    type="button"
                    class="inline-flex items-center gap-1 text-[hsl(var(--destructive))] hover:underline"
                    @click="removeExistingJustificatif"
                  >
                    <Trash2 class="h-4 w-4" />
                    {{ t('remove') }}
                  </button>
                </span>
              </li>
            </ul>
          </div>

          <!-- Actions -->
          <div class="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" @click="showForm = false">{{ t('cancel') }}</Button>
            <Button
              v-if="!editingEntry || !editingEntry.is_posted"
              type="button"
              variant="outline"
              :disabled="form.processing"
              @click="submitForm(false)"
            >
              {{ t('save_as_draft') }}
            </Button>
            <Button
              type="submit"
              :disabled="form.processing || !isBalanced"
              :loading="form.processing"
            >
              {{ editingEntry ? t('save_changes') : t('post_entry') }}
            </Button>
          </div>
        </div>
      </form>
    </Modal>

    <!-- Post confirm -->
    <ConfirmDialog
      :open="!!postingEntry"
      :title="t('post')"
      :message="t('confirm_post_journal_entry')"
      @confirm="doPost"
      @cancel="postingEntry = null"
    />

    <!-- Reverse confirm -->
    <ConfirmDialog
      :open="!!reversingEntry"
      :title="t('reverse')"
      :message="t('confirm_reverse_journal_entry')"
      @confirm="doReverse"
      @cancel="reversingEntry = null"
    />

    <!-- Delete confirm -->
    <ConfirmDialog
      :open="!!deletingEntry"
      :title="t('delete_journal_entry')"
      :message="t('confirm_delete_journal_entry')"
      variant="destructive"
      @confirm="doDelete"
      @cancel="deletingEntry = null"
    />
  </AppLayout>
</template>
