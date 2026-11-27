<script setup>
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import { Banknote, Download } from 'lucide-vue-next'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { postDownload } from '@/lib/postDownload'

const props = defineProps({
  expenses: { type: Array, default: () => [] },
  bankAccounts: { type: Array, default: () => [] },
  canPaySalaries: { type: Boolean, default: false },
  // Posted slips whose net pay is not yet in a payment file, and those put into one recently.
  salarySlips: { type: Array, default: () => [] },
  paidSalarySlips: { type: Array, default: () => [] },
  salaryBankAccountIds: { type: Array, default: () => [] },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

// Local dates (not UTC: after midnight Swiss time the UTC date is still yesterday).
const localDate = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const today = localDate(new Date())
const tomorrow = localDate(new Date(Date.now() + 86400000))

const form = useForm({
  bank_account_id: props.bankAccounts[0]?.id ?? '',
  expense_ids: [],
  execution_date: tomorrow,
})

const bankAccountOptions = computed(() =>
  props.bankAccounts.map(ba => ({
    value: ba.id,
    label: `${ba.name} — ${ba.iban} (${ba.currency})`,
  })),
)

const selectedExpenses = computed(() =>
  props.expenses.filter(e => form.expense_ids.includes(e.id)),
)

const totalsByCurrency = computed(() => {
  const totals = {}
  for (const e of selectedExpenses.value) {
    totals[e.currency] = (totals[e.currency] ?? 0) + Number(e.amount)
  }
  return totals
})

const allSelected = computed(
  () => props.expenses.length > 0 && form.expense_ids.length === props.expenses.length,
)

function toggleAll() {
  form.expense_ids = allSelected.value ? [] : props.expenses.map(e => e.id)
}

function toggle(id) {
  form.expense_ids = form.expense_ids.includes(id)
    ? form.expense_ids.filter(x => x !== id)
    : [...form.expense_ids, id]
}

const hasMultipleCurrencies = computed(() => Object.keys(totalsByCurrency.value).length > 1)

const submitting = ref(false)

// ── Salaries: a separate file (category purpose SALA) ──
const salaryIds = ref([])
const salaryBankAccountOptions = computed(() => bankAccountOptions.value.filter(o => props.salaryBankAccountIds.includes(o.value)))
const salaryBankAccountId = ref(props.salaryBankAccountIds[0] ?? '')
const latestPayday = props.salarySlips.map(s => s.posting_date).filter(Boolean).sort().pop()
const salaryExecutionDate = ref(latestPayday && latestPayday > tomorrow ? latestPayday : tomorrow)
const salaryTotal = computed(() => props.salarySlips
  .filter(s => salaryIds.value.includes(s.id))
  .reduce((sum, s) => sum + Number(s.amount), 0))
const allSalariesSelected = computed(() => props.salarySlips.length > 0 && salaryIds.value.length === props.salarySlips.length)
function toggleAllSalaries() {
  salaryIds.value = allSalariesSelected.value ? [] : props.salarySlips.map(s => s.id)
}
function toggleSalary(id) {
  salaryIds.value = salaryIds.value.includes(id) ? salaryIds.value.filter(x => x !== id) : [...salaryIds.value, id]
}
function downloadSalaries() {
  if (!salaryIds.value.length || submitting.value) return
  submitting.value = true
  postDownload('/payments/outgoing/salaries/download', {
    bank_account_id: salaryBankAccountId.value,
    execution_date: salaryExecutionDate.value,
    salary_slip_ids: salaryIds.value,
  })
  // The slips leave the list once the file is built: reload after the download started.
  setTimeout(() => { submitting.value = false; salaryIds.value = []; router.reload() }, 1500)
}
function reopenSalary(id) {
  if (!window.confirm(t('payments_salary_reopen_confirm'))) return
  router.post(`/payments/outgoing/salaries/${id}/reopen`, {}, { preserveScroll: true })
}

function submit() {
  if (hasMultipleCurrencies.value || submitting.value) return
  submitting.value = true

  postDownload('/payments/outgoing/download', {
    bank_account_id: form.bank_account_id,
    execution_date: form.execution_date ?? '',
    expense_ids: form.expense_ids,
  })

  // Re-enable the button shortly after — the download happens out-of-band.
  setTimeout(() => { submitting.value = false }, 1500)
}
</script>

<template>
  <AppLayout :title="t('banking')">
    <PageHeader :title="t('payments_outgoing')">
      <template #description>
        <p class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('payments_outgoing_help') }}</p>
      </template>
    </PageHeader>

    <Card v-if="!bankAccounts.length">
      <CardContent class="py-10">
        <EmptyState
          :icon="Banknote"
          :title="t('payments_outgoing_no_bank_title')"
          :description="t('payments_outgoing_no_bank_description')"
        />
      </CardContent>
    </Card>

    <template v-else>
      <Card v-if="!expenses.length && !salarySlips.length && !paidSalarySlips.length">
        <CardContent class="py-10">
          <EmptyState
            :icon="Banknote"
            :title="t('payments_outgoing_empty_title')"
            :description="t('payments_outgoing_empty_description')"
          />
        </CardContent>
      </Card>

      <div v-if="expenses.length" class="grid gap-6 lg:grid-cols-[1fr_320px]">
        <Card>
          <CardContent class="pt-6">
            <table class="w-full text-sm">
              <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
                <tr>
                  <th class="w-8 pb-2">
                    <input type="checkbox" :checked="allSelected" @change="toggleAll" />
                  </th>
                  <th class="pb-2">{{ t('date') }}</th>
                  <th class="pb-2">{{ t('supplier') }}</th>
                  <th class="pb-2">{{ t('iban') }}</th>
                  <th class="pb-2">{{ t('description') }}</th>
                  <th class="pb-2 text-right">{{ t('amount') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <tr
                  v-for="e in expenses"
                  :key="e.id"
                  class="cursor-pointer transition-colors hover:bg-[hsl(var(--muted))]"
                  @click="toggle(e.id)"
                >
                  <td class="py-2">
                    <input type="checkbox" :checked="form.expense_ids.includes(e.id)" @click.stop @change="toggle(e.id)" />
                  </td>
                  <td class="py-2">{{ formatDate(e.date) }}</td>
                  <td class="py-2">
                    {{ e.supplier?.name }}
                    <span v-if="e.supplier?.account_holder && e.supplier.account_holder !== e.supplier.name" class="block text-xs text-[hsl(var(--muted-foreground))]">
                      {{ t('account_holder') }}: {{ e.supplier.account_holder }}
                    </span>
                    <Badge v-if="!e.supplier?.iban" variant="outline" class="ml-1 text-[10px]">
                      {{ t('no_iban') }}
                    </Badge>
                    <Badge v-if="e.supplier && !e.supplier.has_address" variant="outline" class="ml-1 text-[10px]">
                      {{ t('no_supplier_address') }}
                    </Badge>
                  </td>
                  <td class="py-2 font-mono text-xs">{{ e.supplier?.iban || '—' }}</td>
                  <td class="py-2">{{ e.description }}</td>
                  <td class="py-2 text-right font-medium">
                    {{ formatCurrency(Number(e.amount), e.currency) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="space-y-4 pt-6">
            <h3 class="text-sm font-semibold">{{ t('payments_outgoing_summary') }}</h3>

            <FormSelect
              id="bank_account_id"
              v-model="form.bank_account_id"
              :label="t('debtor_account')"
              :options="bankAccountOptions"
              :error="form.errors.bank_account_id"
            />

            <FormInput
              id="execution_date"
              v-model="form.execution_date"
              type="date"
              :label="t('execution_date')"
              :error="form.errors.execution_date"
            />

            <div class="space-y-1 border-t border-[hsl(var(--border))] pt-3 text-sm">
              <div class="flex justify-between">
                <span>{{ t('selected_count') }}</span>
                <span class="font-medium">{{ form.expense_ids.length }}</span>
              </div>
              <div
                v-for="(total, currency) in totalsByCurrency"
                :key="currency"
                class="flex justify-between"
              >
                <span>{{ t('total') }} ({{ currency }})</span>
                <span class="font-semibold">{{ formatCurrency(total, currency) }}</span>
              </div>
            </div>

            <p v-if="hasMultipleCurrencies" class="rounded bg-amber-50 p-2 text-xs text-amber-800">
              {{ t('payments_outgoing_single_currency') }}
            </p>

            <Button
              class="w-full"
              :disabled="!form.expense_ids.length || submitting || hasMultipleCurrencies"
              :loading="submitting"
              @click="submit"
            >
              <Download class="mr-2 h-4 w-4" />
              {{ t('download_pain001') }}
            </Button>
          </CardContent>
        </Card>
      </div>
      <template v-if="salarySlips.length || paidSalarySlips.length">
        <h2 class="mb-1 mt-8 text-lg font-semibold">{{ t('payments_salaries_title') }}</h2>
        <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('payments_salaries_help') }}</p>
        <div v-if="salarySlips.length" class="grid gap-6 lg:grid-cols-[1fr_320px]">
          <Card>
            <CardContent class="pt-6">
              <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
                  <tr>
                    <th class="w-8 pb-2"><input type="checkbox" :checked="allSalariesSelected" @change="toggleAllSalaries" /></th>
                    <th class="pb-2">{{ t('period') }}</th>
                    <th class="pb-2">{{ t('employee') }}</th>
                    <th class="pb-2">{{ t('iban') }}</th>
                    <th class="pb-2 text-right">{{ t('amount') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[hsl(var(--border))]">
                  <tr v-for="s in salarySlips" :key="s.id" class="cursor-pointer transition-colors hover:bg-[hsl(var(--muted))]" @click="toggleSalary(s.id)">
                    <td class="py-2"><input type="checkbox" :checked="salaryIds.includes(s.id)" @click.stop @change="toggleSalary(s.id)" /></td>
                    <td class="py-2">{{ s.period }}</td>
                    <td class="py-2">
                      {{ s.employee }}
                      <Badge v-if="!s.iban" variant="outline" class="ml-1 text-[10px]">{{ t('no_iban') }}</Badge>
                      <Badge v-if="!s.has_address" variant="outline" class="ml-1 text-[10px]">{{ t('no_supplier_address') }}</Badge>
                    </td>
                    <td class="py-2 font-mono text-xs">{{ s.iban || '—' }}</td>
                    <td class="py-2 text-right font-medium">{{ formatCurrency(Number(s.amount), 'CHF') }}</td>
                  </tr>
                </tbody>
              </table>
            </CardContent>
          </Card>
          <Card>
            <CardContent class="space-y-4 pt-6">
              <FormSelect id="salary_bank_account_id" v-model="salaryBankAccountId" :label="t('debtor_account')" :options="salaryBankAccountOptions" />
              <FormInput id="salary_execution_date" v-model="salaryExecutionDate" type="date" :min="today" :label="t('execution_date')" />
              <div class="space-y-1 border-t border-[hsl(var(--border))] pt-3 text-sm">
                <div class="flex justify-between"><span>{{ t('selected_count') }}</span><span class="font-medium">{{ salaryIds.length }}</span></div>
                <div class="flex justify-between"><span>{{ t('total') }} (CHF)</span><span class="font-semibold">{{ formatCurrency(salaryTotal, 'CHF') }}</span></div>
              </div>
              <Button v-if="canPaySalaries" class="w-full" :disabled="!salaryIds.length || !salaryBankAccountId || submitting" :loading="submitting" @click="downloadSalaries">
                <Download class="mr-2 h-4 w-4" />
                {{ t('download_salary_file') }}
              </Button>
            </CardContent>
          </Card>
        </div>
        <Card v-if="paidSalarySlips.length" class="mt-6">
          <CardContent class="pt-6">
            <h3 class="mb-2 text-sm font-semibold">{{ t('payments_salaries_paid_title') }}</h3>
            <table class="w-full text-sm">
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <tr v-for="s in paidSalarySlips" :key="s.id">
                  <td class="py-2">{{ s.period }}</td>
                  <td class="py-2">{{ s.employee }}</td>
                  <td class="py-2 text-right">{{ formatCurrency(Number(s.amount), 'CHF') }}</td>
                  <td class="py-2 text-right text-[hsl(var(--muted-foreground))]">{{ formatDate(s.payment_file_at) }}</td>
                  <td class="py-2 text-right"><Button v-if="canPaySalaries" size="sm" variant="outline" @click="reopenSalary(s.id)">{{ t('payments_salary_reopen') }}</Button></td>
                </tr>
              </tbody>
            </table>
          </CardContent>
        </Card>
      </template>
    </template>
  </AppLayout>
</template>
