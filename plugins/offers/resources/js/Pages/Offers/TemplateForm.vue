<script setup>
import { ref } from 'vue'
import axios from 'axios'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import Modal from '@/Components/UI/Modal.vue'
import OfferLines from '../../Components/OfferLines.vue'
import { useTranslations } from '@/lib/useTranslations'
import { Eye } from 'lucide-vue-next'

const { t } = useTranslations()

const props = defineProps({
  template: { type: Object, default: null },
  defaultLayout: { type: Object, required: true },
  defaultClosing: { type: String, default: '' },
})

const clone = value => JSON.parse(JSON.stringify(value))
const boxes = { from: ['logo', 'address', 'email', 'phone'], to: ['address', 'email', 'phone'] }

const form = useForm({
  name: props.template?.name ?? '',
  title: props.template?.title ?? '',
  intro: props.template?.intro ?? '',
  closing: props.template ? (props.template.closing ?? '') : props.defaultClosing,
  layout: clone(props.template?.layout ?? props.defaultLayout),
  is_default: props.template?.is_default ?? false,
  lines: (props.template?.lines ?? []).map(l => ({ type: l.type, label: l.label ?? '', description: l.description ?? '', quantity: l.quantity ?? '', unit: l.unit ?? '', unit_price: l.unit_price ?? '' })),
})

const payload = data => ({
  ...data,
  lines: data.lines.map(l => l.type === 'item'
    ? { type: 'item', label: l.label || null, description: l.description, quantity: String(l.quantity), unit: l.unit || null, unit_price: String(l.unit_price) }
    : { type: 'text', label: l.label || null, description: l.description }),
})

// PDF preview of the unsaved form: the server validates it, keeps it a few minutes and draws it
// for a sample recipient at the returned URL; no template or offer is stored.
const previewUrl = ref(null)
const previewing = ref(false)
const previewError = ref('')
let previewRequest = 0
const closePreview = () => {
  previewRequest++
  previewUrl.value = null
}

async function preview() {
  const request = ++previewRequest
  previewing.value = true
  previewError.value = ''
  form.clearErrors()
  try {
    const response = await axios.post('/offer-templates/preview', payload(form.data()))
    if (request === previewRequest) previewUrl.value = response.data.url
  } catch (error) {
    if (request !== previewRequest) return
    if (error.response?.status === 422) {
      form.setError(Object.fromEntries(Object.entries(error.response.data.errors ?? {}).map(([key, messages]) => [key, messages[0]])))
    }
    previewError.value = t('of_preview_failed')
  } finally {
    previewing.value = false
  }
}

function submit() {
  form.transform(payload)
  if (props.template) form.put(`/offer-templates/${props.template.id}`)
  else form.post('/offer-templates')
}
</script>

<template>
  <AppLayout :title="template ? t('of_edit_template') : t('of_new_template')" help-page="invoices">
    <Breadcrumb :items="[{ label: t('of_title_offers'), href: '/offers' }, { label: t('of_templates_title'), href: '/offer-templates' }, { label: template ? template.name : t('of_new_template') }]" class="mb-4" />

    <form class="max-w-5xl space-y-6" @submit.prevent="submit">
      <Card>
        <CardHeader><CardTitle>{{ template ? t('of_edit_template') : t('of_new_template') }}</CardTitle></CardHeader>
        <CardContent class="grid gap-4 sm:grid-cols-2">
          <FormInput v-model="form.name" id="of-tpl-name" :label="t('of_template_name')" :error="form.errors.name" required />
          <FormInput v-model="form.title" id="of-tpl-title" :label="t('of_subject')" :error="form.errors.title" class="sm:col-span-2" />
          <label class="flex items-center gap-2 text-sm sm:col-span-2">
            <input v-model="form.is_default" type="checkbox" class="h-4 w-4 accent-[hsl(var(--primary))]" />
            {{ t('of_is_default') }}
          </label>
          <FormTextarea v-model="form.intro" id="of-tpl-intro" :label="t('of_intro')" :error="form.errors.intro" :rows="6" class="sm:col-span-2" />
          <p class="text-xs text-[hsl(var(--muted-foreground))] sm:col-span-2">{{ t('of_texts_hint') }}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{{ t('of_layout') }}</CardTitle>
          <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">{{ t('of_layout_hint') }}</p>
        </CardHeader>
        <CardContent class="grid gap-4 sm:grid-cols-2">
          <fieldset v-for="(elements, box) in boxes" :key="box" class="space-y-2">
            <legend class="mb-1 text-sm font-medium">{{ t(`of_layout_${box}`) }}</legend>
            <label v-for="element in elements" :key="element" class="flex items-center gap-2 text-sm">
              <input :id="`of-tpl-layout-${box}-${element}`" v-model="form.layout[box][element]" type="checkbox" class="h-4 w-4 accent-[hsl(var(--primary))]" />
              {{ t(`of_layout_${element}`) }}
            </label>
          </fieldset>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{{ t('of_lines') }}</CardTitle></CardHeader>
        <CardContent>
          <OfferLines v-model="form.lines" :errors="form.errors" :show-totals="false" id-prefix="of-tpl-line" />
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{{ t('of_closing') }}</CardTitle></CardHeader>
        <CardContent>
          <FormTextarea v-model="form.closing" id="of-tpl-closing" :label="t('of_closing')" label-class="sr-only" :error="form.errors.closing" :rows="4" />
        </CardContent>
      </Card>

      <div class="flex flex-wrap items-center justify-end gap-2">
        <p v-if="previewError" class="mr-auto text-sm text-[hsl(var(--destructive))]" role="alert">{{ previewError }}</p>
        <Button type="button" variant="outline" :loading="previewing" :title="t('of_preview_hint')" @click="preview"><Eye class="mr-2 h-4 w-4" />{{ t('of_preview_pdf') }}</Button>
        <Button as="a" href="/offer-templates" variant="outline">{{ t('cancel') }}</Button>
        <Button type="submit" :loading="form.processing">{{ t('save') }}</Button>
      </div>
    </form>

    <Modal :open="previewUrl !== null" :title="t('of_preview_pdf')" size="2xl" @close="closePreview">
      <p class="mb-2 flex flex-wrap justify-between gap-2 text-xs text-[hsl(var(--muted-foreground))]">
        <span>{{ t('of_preview_hint') }}</span>
        <a :href="previewUrl" target="_blank" rel="noopener" class="text-[hsl(var(--primary))] underline">{{ t('of_preview_open') }}</a>
      </p>
      <iframe v-if="previewUrl" :src="previewUrl" class="h-[75dvh] w-full rounded border border-[hsl(var(--border))]" :title="t('of_preview_pdf')" />
    </Modal>
  </AppLayout>
</template>
