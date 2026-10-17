<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import QuickCreateContactModal from '@/Components/QuickCreateContactModal.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { currencyOptions } from '@/lib/contactOptions'
import { useClosedFiscalYear } from '@/lib/useClosedFiscalYear'
import ClosedYearBanner from '@/Components/UI/ClosedYearBanner.vue'
import { useUnsavedChanges } from '@/lib/useUnsavedChanges'
import UnsavedChangesDialog from '@/Components/UI/UnsavedChangesDialog.vue'
import { useFormValidation, z } from '@/lib/useFormValidation'
import FileUpload from '@/Components/UI/FileUpload.vue'
import InvoiceLineItems from '@/Components/Invoices/InvoiceLineItems.vue'
import { Plus } from 'lucide-vue-next'

const props = defineProps({
  invoice: Object,
  customers: { type: Array, default: () => [] },
  contactPersons: { type: Object, default: () => ({}) },
  vatRates: { type: Array, default: () => [] },
  catalogItems: { type: Array, default: () => [] },
  justificatifUrl: { type: String, default: null },
  defaultVatRateId: { type: [String, Number], default: null },
  taxTreatments: { type: Array, default: () => [] },
  lineSources: { type: Array, default: () => [] },
  lineSourceRefs: { type: Object, default: () => ({}) },
})

const { t } = useTranslations()
const { formatCurrency } = useFormatters()

const { isClosed: isIssueDateClosed, closedYear } = useClosedFiscalYear(() => form.issue_date)

const form = useForm({
  customer_id: props.invoice.customer_id != null ? String(props.invoice.customer_id) : '',
  contact_person_id: props.invoice.contact_person_id ?? '',
  number: props.invoice.number ?? '',
  issue_date: props.invoice.issue_date?.slice(0, 10) ?? '',
  due_date: props.invoice.due_date?.slice(0, 10) ?? '',
  currency: props.invoice.currency ?? 'CHF',
  introduction: props.invoice.introduction ?? '',
  notes: props.invoice.notes ?? '',
  payment_terms: props.invoice.payment_terms ?? '',
  tax_treatment: props.invoice.tax_treatment ?? 'standard',
  lines: (props.invoice.lines ?? []).map(l => ({
    type: l.type ?? 'item',
    discount_type: l.discount_type ?? 'flat',
    description: l.description,
    quantity: l.quantity,
    unit_price: l.unit_price,
    vat_rate_id: l.vat_rate_id ?? '',
    source_type: l.source_type ?? null,
    source_id: l.source_id ?? null,
    // a reference whose record is no longer offered keeps a neutral caption
    source_label: props.lineSourceRefs?.[l.id]?.label ?? (l.source_id ? `#${l.source_id}` : null),
  })),
  justificatif: null,
})

if (form.lines.length === 0) {
  form.lines.push({ type: 'item', discount_type: 'flat', description: '', quantity: 1, unit_price: 0, vat_rate_id: props.defaultVatRateId ? String(props.defaultVatRateId) : '' })
}

function saveDraft() {
  return new Promise((resolve) => {
    form.post(`/invoices/${props.invoice.id}`, {
      forceFormData: true,
      headers: { 'X-HTTP-Method-Override': 'PUT' },
      preserveState: false,
      onSuccess: () => resolve(),
      onError: () => resolve(),
    })
  })
}

const { showDialog, handleSave, handleDiscard, handleStay, forceClear } = useUnsavedChanges(
  computed(() => form.isDirty),
  { onSave: saveDraft, fallbackUrl: `/invoices/${props.invoice.id}` },
)

const { errors: clientErrors, validate, validateField } = useFormValidation(z.object({
  issue_date: z.string().min(1, 'This field is required.'),
}))

function submit() {
  if (!validate(form.data())) return
  forceClear.value = true
  form.post(`/invoices/${props.invoice.id}`, {
    forceFormData: true,
    headers: { 'X-HTTP-Method-Override': 'PUT' },
    onError: () => { forceClear.value = false },
  })
}

function onJustificatifChange(file) {
  form.justificatif = file ?? null
}

const customerList = reactive([...props.customers])
const clientOptions = ref(customerList.map(c => ({ value: String(c.id), label: c.name })))

const showCreateCustomer = ref(false)

function onCustomerCreated(customer) {
  customerList.push(customer)
  clientOptions.value = customerList.map(c => ({ value: String(c.id), label: c.name }))
  form.customer_id = String(customer.id)
}

// The contact person offered depends on the chosen customer; keep a valid
// selection (or the customer's primary/only person) as that choice changes.
const personOptions = computed(() => [
  { value: '', label: t('no_contact_person') },
  ...(props.contactPersons[form.customer_id] ?? []).map(p => ({ value: p.id, label: p.email ? `${p.name} — ${p.email}` : p.name })),
])
watch(() => form.customer_id, () => {
  const persons = props.contactPersons[form.customer_id] ?? []
  if (!persons.some(p => p.id === form.contact_person_id)) {
    form.contact_person_id = persons.find(p => p.is_primary)?.id ?? (persons.length === 1 ? persons[0].id : '')
  }
})
</script>

<template>
  <AppLayout :title="t('edit_invoice')" help-page="invoices">
    <Breadcrumb :items="[{ label: t('invoices'), href: '/invoices' }, { label: invoice.number, href: `/invoices/${invoice.id}` }, { label: t('edit') }]" class="mb-4" />

    <ClosedYearBanner v-if="isIssueDateClosed" :year="closedYear" />

    <Card class="max-w-5xl">
      <CardHeader>
        <CardTitle>{{ t('edit_invoice') }} {{ invoice.number }}</CardTitle>
      </CardHeader>
      <CardContent>
        <form class="space-y-6" @submit.prevent="submit">
          <!-- Invoice Details -->
          <h3 class="text-sm font-medium text-[hsl(var(--foreground))]">{{ t('invoice_details') }}</h3>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="space-y-1">
              <div class="flex items-end gap-2">
                <SearchableSelect
                  id="customer_id"
                  v-model="form.customer_id"
                  :label="t('client')"
                  :options="clientOptions"
                  :placeholder="t('select_client')"
                  :error="form.errors.customer_id || clientErrors.customer_id"
                  class="flex-1"
                />
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  class="mb-[2px] shrink-0"
                  :title="t('new_customer')"
                  @click="showCreateCustomer = true"
                >
                  <Plus class="h-4 w-4" />
                </Button>
              </div>
              <FormSelect
                id="contact_person_id"
                v-model="form.contact_person_id"
                :label="t('contact_person')"
                :options="personOptions"
                :error="form.errors.contact_person_id"
              />
              <p class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('required_to_finalize') }}</p>
            </div>
            <FormInput
              id="number"
              v-model="form.number"
              :label="t('invoice_number')"
              placeholder="INV-001"
              :error="form.errors.number || clientErrors.number"
              readonly
            />
            <FormInput
              id="issue_date"
              v-model="form.issue_date"
              type="date"
              :label="t('issue_date')"
              :error="form.errors.issue_date || clientErrors.issue_date"
              required
            />
            <FormInput
              id="due_date"
              v-model="form.due_date"
              type="date"
              :label="t('due_date')"
              :error="form.errors.due_date || clientErrors.due_date"
              :hint="t('required_to_finalize')"
            />
            <FormSelect
              id="currency"
              v-model="form.currency"
              :label="t('currency')"
              :options="currencyOptions(t)"
              :error="form.errors.currency"
            />
            <div class="sm:col-span-2">
              <FormSelect
                id="tax_treatment"
                v-model="form.tax_treatment"
                :label="t('invoice_tax_treatment')"
                :options="taxTreatments"
                :error="form.errors.tax_treatment"
              />
              <p v-if="form.tax_treatment === 'reverse_charge'" class="mt-1 text-xs text-[hsl(var(--muted-foreground))]">
                {{ t('invoice_reverse_charge_hint') }}
              </p>
            </div>
          </div>

          <!-- Line items -->
          <hr class="border-[hsl(var(--border))]" />
          <InvoiceLineItems
            v-model="form.lines"
            :vat-rates="vatRates"
            :catalog-items="catalogItems"
            :errors="form.errors"
            :currency="form.currency"
            :default-vat-rate-id="defaultVatRateId"
            :tax-treatment="form.tax_treatment"
            :line-sources="lineSources"
            :customer-id="form.customer_id"
            @source-picked="option => { if (!form.introduction && option.introduction) form.introduction = option.introduction }"
          />

          <!-- Notes & Terms -->
          <hr class="border-[hsl(var(--border))]" />
          <h3 class="text-sm font-medium text-[hsl(var(--foreground))]">{{ t('notes_and_terms') }}</h3>
          <FormTextarea
            id="introduction"
            v-model="form.introduction"
            :label="t('introduction')"
            :placeholder="t('introduction_hint')"
            :error="form.errors.introduction"
          />
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormTextarea
              id="notes"
              v-model="form.notes"
              :label="t('notes')"
            />
            <p class="mt-1 text-xs text-[hsl(var(--muted-foreground))]">{{ t('notes_printed_hint') }}</p>
            <FormInput
              id="payment_terms"
              v-model="form.payment_terms"
              :label="t('payment_terms')"
              :placeholder="t('payment_terms_example')"
            />
          </div>

          <FileUpload
            size="compact"
            :label="t('justificatif')"
            :error="form.errors.justificatif"
            @change="onJustificatifChange"
          >
            <p v-if="justificatifUrl && !form.justificatif" class="text-xs text-[hsl(var(--muted-foreground))]">
              {{ t('justificatif_attached') }}
            </p>
          </FileUpload>

          <div class="flex flex-wrap justify-end gap-3">
            <Button as="a" :href="`/invoices/${invoice.id}`" variant="outline">{{ t('cancel') }}</Button>
            <Button type="submit" :disabled="form.processing" :loading="form.processing">{{ t('save_changes') }}</Button>
          </div>
        </form>
      </CardContent>
    </Card>

    <QuickCreateContactModal
      :open="showCreateCustomer"
      contact-type="customer"
      @close="showCreateCustomer = false"
      @created="onCustomerCreated"
    />

    <UnsavedChangesDialog
      :open="showDialog"
      :saving="form.processing"
      @save="handleSave"
      @discard="handleDiscard"
      @stay="handleStay"
    />
  </AppLayout>
</template>
