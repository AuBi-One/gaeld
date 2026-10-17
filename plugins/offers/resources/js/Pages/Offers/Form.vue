<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import OfferLines from '../../Components/OfferLines.vue'
import { useTranslations } from '@/lib/useTranslations'
import { Send } from 'lucide-vue-next'

const { t } = useTranslations()

const props = defineProps({
  offer: { type: Object, default: null },
  contacts: { type: Array, default: () => [] },
  vatRates: { type: Array, default: () => [] },
  templates: { type: Array, default: () => [] },
  defaults: { type: Object, default: () => ({}) },
  initialTemplateId: { type: String, default: null },
  initialContactId: { type: Number, default: null },
})

const today = new Date().toISOString().slice(0, 10)
// Same as Carbon addMonthsNoOverflow: 31.1. + 1 month = 28/29.2.
const addMonths = (date, months) => {
  const d = new Date(`${date}T00:00:00Z`)
  const day = d.getUTCDate()
  d.setUTCDate(1)
  d.setUTCMonth(d.getUTCMonth() + Number(months || 0))
  const last = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate()
  d.setUTCDate(Math.min(day, last))
  return d.toISOString().slice(0, 10)
}
const defaultVat = props.vatRates.find(v => v.is_default) ?? null
const toLine = l => ({ type: l.type, label: l.label ?? '', description: l.description ?? '', quantity: l.quantity ?? '', unit: l.unit ?? '', unit_price: l.unit_price ?? '' })

const form = useForm({
  template_id: props.offer?.template_id ?? '',
  contact_id: props.offer?.contact_id ?? props.initialContactId ?? '',
  contact_person_id: props.offer?.contact_person_id ?? '',
  title: props.offer?.title ?? '',
  intro: props.offer?.intro ?? '',
  closing: props.offer ? (props.offer.closing ?? '') : (props.defaults.closing ?? ''),
  notes: props.offer?.notes ?? '',
  offer_date: props.offer?.offer_date ?? today,
  valid_until: props.offer ? (props.offer.valid_until ?? '') : addMonths(today, props.defaults.validity_months ?? 2),
  request_date: props.offer?.request_date ?? '',
  language: props.offer?.language ?? props.defaults.language ?? 'fr',
  currency: props.offer?.currency ?? props.defaults.currency ?? 'CHF',
  vat_rate_id: props.offer ? (props.offer.vat_rate_id ?? '') : (defaultVat?.id ?? ''),
  lines: props.offer?.lines?.length ? props.offer.lines.map(toLine) : [toLine({ type: 'item', quantity: '1', unit_price: '0' })],
})

function applyTemplate(id) {
  const template = props.templates.find(tpl => tpl.id === id)
  if (!template) return
  form.title = template.title ?? form.title
  form.intro = template.intro ?? ''
  form.closing = template.closing ?? ''
  if (template.lines?.length) form.lines = template.lines.map(toLine)
}

if (!props.offer && props.initialTemplateId) {
  form.template_id = props.initialTemplateId
  applyTemplate(props.initialTemplateId)
}

const contact = computed(() => props.contacts.find(c => String(c.id) === String(form.contact_id)))
watch(() => form.contact_id, () => {
  const persons = contact.value?.persons ?? []
  if (!persons.some(p => p.id === form.contact_person_id)) {
    form.contact_person_id = persons.find(p => p.is_primary)?.id ?? (persons.length === 1 ? persons[0].id : '')
  }
}, { immediate: true })

const contactOptions = computed(() => props.contacts.map(c => ({ value: c.id, label: c.city ? `${c.name} (${c.city})` : c.name })))
const personOptions = computed(() => [
  { value: '', label: t('of_no_contact_person') },
  ...(contact.value?.persons ?? []).map(p => ({ value: p.id, label: p.email ? `${p.name} — ${p.email}` : p.name })),
])
const templateOptions = computed(() => [{ value: '', label: t('of_no_template') }, ...props.templates.map(tpl => ({ value: tpl.id, label: tpl.name }))])
const vatOptions = computed(() => [{ value: '', label: t('of_no_vat') }, ...props.vatRates.map(v => ({ value: v.id, label: `${v.name} (${Number(v.rate)} %)` }))])
const languageOptions = [
  { value: 'fr', label: 'Français' },
  { value: 'de', label: 'Deutsch' },
  { value: 'it', label: 'Italiano' },
  { value: 'en', label: 'English' },
]
const vatRate = computed(() => {
  const rate = props.vatRates.find(v => String(v.id) === String(form.vat_rate_id))
  return rate ? Number(rate.rate) : null
})

function submit(send = false) {
  form.transform(data => ({
    ...data,
    contact_person_id: data.contact_person_id || null,
    template_id: data.template_id || null,
    vat_rate_id: data.vat_rate_id || null,
    valid_until: data.valid_until || null,
    request_date: data.request_date || null,
    lines: data.lines.map(l => l.type === 'item'
      ? { type: 'item', label: l.label || null, description: l.description, quantity: String(l.quantity), unit: l.unit || null, unit_price: String(l.unit_price) }
      : { type: 'text', label: l.label || null, description: l.description }),
    send,
  }))
  if (props.offer) form.put(`/offers/${props.offer.id}`)
  else form.post('/offers')
}
</script>

<template>
  <AppLayout :title="offer ? `${t('of_edit_offer')} ${offer.number}` : t('of_new_offer')" help-page="invoices">
    <Breadcrumb
      :items="[
        { label: t('of_title_offers'), href: '/offers' },
        { label: offer ? offer.number : t('of_new_offer') },
      ]"
      class="mb-4"
    />

    <form class="max-w-5xl space-y-6" @submit.prevent="submit(false)">
      <Card>
        <CardHeader><CardTitle>{{ offer ? `${t('of_edit_offer')} ${offer.number}` : t('of_new_offer') }}</CardTitle></CardHeader>
        <CardContent class="grid gap-4 sm:grid-cols-2">
          <FormSelect v-if="!offer" v-model="form.template_id" id="of-form-template" :label="t('of_template')" :options="templateOptions" class="sm:col-span-2" @update:model-value="applyTemplate" />
          <SearchableSelect v-model="form.contact_id" id="of-form-contact" :label="t('of_contact')" :options="contactOptions" :placeholder="t('of_contact')" :error="form.errors.contact_id" required />
          <div>
            <FormSelect v-model="form.contact_person_id" id="of-form-person" :label="t('of_contact_person')" :options="personOptions" :error="form.errors.contact_person_id" />
            <p class="mt-1 text-xs text-[hsl(var(--muted-foreground))]">{{ t('of_add_person_hint') }}</p>
          </div>
          <FormInput v-model="form.title" id="of-form-title" :label="t('of_subject')" :error="form.errors.title" class="sm:col-span-2" required />
          <FormInput v-model="form.offer_date" id="of-form-offer-date" type="date" :label="t('of_offer_date')" :error="form.errors.offer_date" required />
          <FormInput v-model="form.valid_until" id="of-form-valid-until" type="date" :label="t('of_valid_until')" :error="form.errors.valid_until" />
          <FormInput v-model="form.request_date" id="of-form-request-date" type="date" :label="t('of_request_date')" :error="form.errors.request_date" />
          <FormSelect v-model="form.language" id="of-form-language" :label="t('of_language')" :options="languageOptions" :error="form.errors.language" />
          <FormSelect v-model="form.vat_rate_id" id="of-form-vat" :label="t('of_vat_rate')" :options="vatOptions" :error="form.errors.vat_rate_id" />
          <FormInput v-model="form.currency" id="of-form-currency" :label="t('of_currency')" :error="form.errors.currency" maxlength="3" required />
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{{ t('of_intro') }}</CardTitle></CardHeader>
        <CardContent class="space-y-2">
          <FormTextarea v-model="form.intro" id="of-form-intro" :label="t('of_intro')" label-class="sr-only" :error="form.errors.intro" :rows="6" />
          <p class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('of_texts_hint') }}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{{ t('of_lines') }}</CardTitle></CardHeader>
        <CardContent>
          <OfferLines v-model="form.lines" :errors="form.errors" :currency="form.currency" :vat-rate="vatRate" />
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{{ t('of_closing') }}</CardTitle></CardHeader>
        <CardContent class="space-y-4">
          <FormTextarea v-model="form.closing" id="of-form-closing" :label="t('of_closing')" label-class="sr-only" :error="form.errors.closing" :rows="4" />
          <FormTextarea v-model="form.notes" id="of-form-notes" :label="t('of_notes')" :error="form.errors.notes" :rows="2" />
        </CardContent>
      </Card>

      <div class="flex justify-end gap-2">
        <Button as="a" :href="offer ? `/offers/${offer.id}` : '/offers'" variant="outline">{{ t('cancel') }}</Button>
        <Button type="submit" variant="secondary" :loading="form.processing">{{ t('of_save_draft') }}</Button>
        <Button
          v-if="!offer"
          type="button"
          class="bg-green-600 text-white hover:bg-green-700"
          :loading="form.processing"
          @click="submit(true)"
        >
          <Send class="mr-2 h-4 w-4" />{{ t('of_action_send') }}
        </Button>
      </div>
    </form>
  </AppLayout>
</template>
