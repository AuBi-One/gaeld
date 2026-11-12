<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import FileUpload from '@/Components/UI/FileUpload.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import JournalCorrectionDialog from '@/Components/Accounting/JournalCorrectionDialog.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Paperclip, Trash2, Eye, Download, X, Replace, Plus } from 'lucide-vue-next'

const props = defineProps({
  entry: { type: Object, required: true },
  source: { type: Object, default: null },
  justificatifUrl: { type: String, default: null },
  canEditJustificatif: { type: Boolean, default: false },
  canAttachJustificatif: { type: Boolean, default: false },
  correction: { type: Object, default: null },
  canCorrect: { type: Boolean, default: false },
  correctionAccounts: { type: Array, default: () => [] },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const showJustificatifPreview = ref(false)

function removeJustificatif() {
  router.delete(`/accounting/journal-entries/${props.entry.id}/justificatif`)
}

// Also on a posted entry that has none yet (a voucher does not change the booking).
const attachForm = useForm({ justificatif: null })

function attachJustificatif() {
  attachForm.post(`/accounting/journal-entries/${props.entry.id}/justificatif`, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => attachForm.reset(),
  })
}

// Guided correction (the posted entry never changes: a reversal and a replacement are posted together)
const correctingEntry = ref(null)
const confirmingPostCorrection = ref(false)
const confirmingCancelCorrection = ref(false)

const accountOptions = computed(() => [
  { value: '', label: t('select_placeholder') },
  ...props.correctionAccounts.map(a => ({ value: String(a.id), label: `${a.code} — ${a.name}` })),
])

const replacementForm = useForm({
  date: props.entry.date ? String(props.entry.date).slice(0, 10) : '',
  reference: props.entry.reference || '',
  description: props.entry.description || '',
  lines: (props.entry.lines || []).map(line => ({
    account_id: String(line.account_id),
    debit: String(line.debit || '0.00'),
    credit: String(line.credit || '0.00'),
    description: line.description || '',
  })),
})

function addReplacementLine() {
  replacementForm.lines.push({ account_id: '', debit: '0.00', credit: '0.00', description: '' })
}

function removeReplacementLine(index) {
  if (replacementForm.lines.length > 2) replacementForm.lines.splice(index, 1)
}

const replacementDebit = computed(() => replacementForm.lines.reduce((sum, l) => sum + (parseFloat(l.debit) || 0), 0))
const replacementCredit = computed(() => replacementForm.lines.reduce((sum, l) => sum + (parseFloat(l.credit) || 0), 0))
const replacementBalanced = computed(() => +(replacementDebit.value - replacementCredit.value).toFixed(2) === 0 && replacementDebit.value > 0)

function saveReplacement() {
  replacementForm.put(`/accounting/journal-corrections/${props.correction.id}/replacement`, { preserveScroll: true })
}

function postCorrection() {
  router.post(`/accounting/journal-corrections/${props.correction.id}/post`, {}, {
    preserveScroll: true,
    onFinish: () => { confirmingPostCorrection.value = false },
  })
}

function cancelCorrection() {
  router.delete(`/accounting/journal-corrections/${props.correction.id}`, {
    onFinish: () => { confirmingCancelCorrection.value = false },
  })
}

const totalDebit = computed(() => props.entry.lines?.reduce((total, line) => total + Number(line.debit || 0), 0) || 0)
const totalCredit = computed(() => props.entry.lines?.reduce((total, line) => total + Number(line.credit || 0), 0) || 0)
</script>

<template>
  <AppLayout :title="t('journal_entry')" help-page="accounting-basics">
    <Breadcrumb
      :items="[{ label: t('journal_entries'), href: '/accounting/journal-entries' }, { label: entry.reference || t('journal_entry') }]"
      class="mb-4"
    />

    <div class="max-w-4xl space-y-6">
      <div v-if="correction" class="rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] p-4 text-sm" data-testid="journal-correction">
        <div class="mb-2 flex flex-wrap items-center gap-2 font-medium">
          <Badge v-if="correction.role === 'original'" variant="warning">{{ t('journal_correction_corrected_badge') }}</Badge>
          <Badge v-else-if="correction.role === 'reversal'" variant="secondary">{{ t('journal_correction_reversal_badge') }}</Badge>
          <Badge v-else variant="info">{{ t('journal_correction_replacement_badge') }}</Badge>
          <span>{{ correction.status === 'draft' ? t('journal_correction_open_notice') : t('journal_correction_posted_notice') }}</span>
        </div>
        <p class="mb-2 text-[hsl(var(--muted-foreground))]">{{ t('correction_reason') }}: {{ correction.reason }}</p>
        <div class="flex flex-wrap gap-3">
          <Link v-if="correction.role !== 'original'" :href="`/accounting/journal-entries/${correction.original_id}`" class="text-[hsl(var(--primary))] hover:underline">{{ t('view_original') }}</Link>
          <Link v-if="correction.role !== 'reversal'" :href="`/accounting/journal-entries/${correction.reversal_id}`" class="text-[hsl(var(--primary))] hover:underline">{{ t('view_reversal') }}</Link>
          <Link v-if="correction.role !== 'replacement'" :href="`/accounting/journal-entries/${correction.replacement_id}`" class="text-[hsl(var(--primary))] hover:underline">{{ t('view_replacement') }}</Link>
        </div>
      </div>

      <Card>
        <CardHeader>
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <CardTitle>{{ entry.description || entry.reference || t('journal_entry') }}</CardTitle>
              <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">{{ entry.reference || '—' }}</p>
            </div>
            <div class="flex items-center gap-2">
              <Badge :variant="entry.is_posted ? 'success' : 'warning'">
                {{ entry.is_posted ? t('posted') : t('draft') }}
              </Badge>
              <Button v-if="canCorrect" size="sm" variant="outline" data-testid="journal-correct" @click="correctingEntry = entry">
                <Replace class="mr-1 h-4 w-4" />
                {{ t('correct') }}
              </Button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
            <div>
              <dt class="text-[hsl(var(--muted-foreground))]">{{ t('date') }}</dt>
              <dd class="font-medium">{{ formatDate(entry.date) }}</dd>
            </div>
            <div>
              <dt class="text-[hsl(var(--muted-foreground))]">{{ t('reference') }}</dt>
              <dd class="font-medium">{{ entry.reference || '—' }}</dd>
            </div>
            <div>
              <dt class="text-[hsl(var(--muted-foreground))]">{{ t('description') }}</dt>
              <dd class="font-medium">{{ entry.description || '—' }}</dd>
            </div>
            <div v-if="source" class="sm:col-span-3" data-testid="journal-source">
              <dt class="text-[hsl(var(--muted-foreground))]">{{ t('journal_entry_source') }}</dt>
              <dd class="font-medium">
                <Link v-if="source.url" :href="source.url" class="underline">{{ source.label }}</Link>
                <span v-else>{{ source.label }}</span>
              </dd>
            </div>
          </dl>
        </CardContent>
      </Card>

      <!-- The replacement of an open correction: edited here, posted or discarded with its reversal -->
      <Card v-if="correction?.editable">
        <CardHeader><CardTitle>{{ t('view_replacement') }}</CardTitle></CardHeader>
        <CardContent>
          <form class="space-y-4" @submit.prevent="saveReplacement">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <FormInput id="replacement_date" v-model="replacementForm.date" type="date" :label="t('date')" :error="replacementForm.errors.date" required />
              <FormInput id="replacement_reference" v-model="replacementForm.reference" maxlength="100" :label="t('reference')" :error="replacementForm.errors.reference" />
              <FormInput id="replacement_description" v-model="replacementForm.description" maxlength="1000" :label="t('description')" :error="replacementForm.errors.description" />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm font-medium">{{ t('entry_lines') }}</span>
              <Button type="button" size="sm" variant="outline" @click="addReplacementLine">
                <Plus class="mr-1 h-3 w-3" /> {{ t('add_line') }}
              </Button>
            </div>
            <div v-for="(line, index) in replacementForm.lines" :key="index" class="grid grid-cols-12 items-start gap-2">
              <div class="col-span-12 sm:col-span-5">
                <FormSelect :id="`replacement_account_${index}`" v-model="line.account_id" :label="index === 0 ? t('account') : ''" :options="accountOptions" :error="replacementForm.errors[`lines.${index}.account_id`]" required />
              </div>
              <div class="col-span-5 sm:col-span-2">
                <FormInput :id="`replacement_debit_${index}`" v-model="line.debit" type="number" step="0.01" min="0" :label="index === 0 ? t('debit') : ''" :error="replacementForm.errors[`lines.${index}.debit`]" />
              </div>
              <div class="col-span-5 sm:col-span-2">
                <FormInput :id="`replacement_credit_${index}`" v-model="line.credit" type="number" step="0.01" min="0" :label="index === 0 ? t('credit') : ''" :error="replacementForm.errors[`lines.${index}.credit`]" />
              </div>
              <div class="col-span-10 sm:col-span-2">
                <FormInput :id="`replacement_line_${index}`" v-model="line.description" maxlength="255" :label="index === 0 ? t('description') : ''" :error="replacementForm.errors[`lines.${index}.description`]" />
              </div>
              <div class="col-span-2 flex items-end pb-2 sm:col-span-1">
                <Button type="button" variant="ghost" size="icon" :disabled="replacementForm.lines.length <= 2" :aria-label="t('remove')" @click="removeReplacementLine(index)">
                  <Trash2 class="h-4 w-4" />
                </Button>
              </div>
            </div>
            <div class="grid grid-cols-3 gap-2 rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] p-4 text-sm">
              <div><div class="text-[hsl(var(--muted-foreground))]">{{ t('total_debit') }}</div><div class="font-semibold">{{ formatCurrency(replacementDebit) }}</div></div>
              <div><div class="text-[hsl(var(--muted-foreground))]">{{ t('total_credit') }}</div><div class="font-semibold">{{ formatCurrency(replacementCredit) }}</div></div>
              <div>
                <div class="text-[hsl(var(--muted-foreground))]">{{ t('difference') }}</div>
                <div class="font-semibold" :class="replacementBalanced ? 'text-[hsl(var(--success))]' : 'text-[hsl(var(--destructive))]'">{{ formatCurrency(+(replacementDebit - replacementCredit).toFixed(2)) }}</div>
              </div>
            </div>
            <p v-if="replacementForm.errors.lines" class="text-xs text-[hsl(var(--destructive))]">{{ replacementForm.errors.lines }}</p>
            <div class="flex flex-wrap justify-end gap-2">
              <Button type="button" variant="destructive" :disabled="replacementForm.processing" @click="confirmingCancelCorrection = true">{{ t('cancel_correction') }}</Button>
              <Button type="submit" variant="outline" :disabled="replacementForm.processing || !replacementBalanced" :loading="replacementForm.processing">{{ t('save_changes') }}</Button>
              <Button type="button" :disabled="replacementForm.processing || replacementForm.isDirty" @click="confirmingPostCorrection = true">{{ t('post_correction') }}</Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <Card v-else>
        <CardHeader><CardTitle>{{ t('entry_lines') }}</CardTitle></CardHeader>
        <CardContent>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-[hsl(var(--border))] text-left text-[hsl(var(--muted-foreground))]">
                  <th class="pb-2 font-medium">{{ t('account') }}</th>
                  <th class="pb-2 font-medium">{{ t('description') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('debit') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('credit') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <tr v-for="line in entry.lines" :key="line.id">
                  <td class="py-2">{{ line.account?.code }} — {{ line.account?.name }}</td>
                  <td class="py-2">{{ line.description || '—' }}</td>
                  <td class="py-2 text-right font-mono">{{ formatCurrency(line.debit) }}</td>
                  <td class="py-2 text-right font-mono">{{ formatCurrency(line.credit) }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t-2 border-[hsl(var(--border))] font-bold">
                  <td class="pt-3" colspan="2">{{ t('total') }}</td>
                  <td class="pt-3 text-right font-mono">{{ formatCurrency(totalDebit) }}</td>
                  <td class="pt-3 text-right font-mono">{{ formatCurrency(totalCredit) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </CardContent>
      </Card>

      <!-- Justificatif -->
      <Card v-if="justificatifUrl">
        <CardHeader>
          <div class="flex items-center justify-between">
            <CardTitle class="flex items-center gap-2">
              <Paperclip class="h-4 w-4" />
              {{ t('justificatif') }}
            </CardTitle>
            <Button
              v-if="canEditJustificatif"
              variant="ghost"
              size="sm"
              @click="removeJustificatif"
            >
              <Trash2 class="mr-1 h-4 w-4" />
              {{ t('remove') }}
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          <div class="flex flex-wrap items-center gap-3">
            <Button variant="outline" size="sm" @click="showJustificatifPreview = true">
              <Eye class="mr-1.5 h-4 w-4" />
              {{ t('view_justificatif') }}
            </Button>
            <a
              :href="justificatifUrl"
              class="inline-flex items-center gap-1.5 text-sm text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--foreground))] transition-colors"
            >
              <Download class="h-4 w-4" />
              {{ t('download') }}
            </a>
          </div>
        </CardContent>
      </Card>

      <Card v-else-if="canAttachJustificatif">
        <CardHeader>
          <CardTitle class="flex items-center gap-2">
            <Paperclip class="h-4 w-4" />
            {{ t('justificatif') }}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <form class="space-y-4" @submit.prevent="attachJustificatif">
            <FileUpload
              v-model="attachForm.justificatif"
              size="compact"
              :help-text="t('justificatif_attach_hint')"
              :error="attachForm.errors.justificatif"
            />
            <div class="flex justify-end">
              <Button type="submit" :disabled="!attachForm.justificatif || attachForm.processing" :loading="attachForm.processing">
                {{ t('attach_justificatif') }}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <div>
        <Link href="/accounting/journal-entries">
          <Button variant="outline">{{ t('back') }}</Button>
        </Link>
      </div>
    </div>

    <JournalCorrectionDialog :entry="correctingEntry" @close="correctingEntry = null" />
    <ConfirmDialog
      :open="confirmingPostCorrection"
      :title="t('post_correction')"
      :message="t('confirm_post_correction')"
      confirm-variant="default"
      @confirm="postCorrection"
      @cancel="confirmingPostCorrection = false"
    />
    <ConfirmDialog
      :open="confirmingCancelCorrection"
      :title="t('cancel_correction')"
      :message="t('confirm_cancel_correction')"
      @confirm="cancelCorrection"
      @cancel="confirmingCancelCorrection = false"
    />

    <!-- Justificatif Preview -->
    <Transition name="modal">
      <div v-if="showJustificatifPreview" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-black/60" @click="showJustificatifPreview = false" />
        <div class="relative z-50 flex w-full max-w-4xl flex-col rounded-xl border border-[hsl(var(--border))] bg-[hsl(var(--background))] shadow-xl" style="max-height: 90dvh;">
          <div class="flex shrink-0 items-center justify-between border-b border-[hsl(var(--border))] px-4 py-3">
            <h2 class="text-base font-semibold">{{ t('justificatif') }}</h2>
            <div class="flex items-center gap-2">
              <a
                :href="justificatifUrl"
                class="inline-flex items-center gap-1.5 text-sm text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--foreground))] transition-colors"
              >
                <Download class="h-4 w-4" />
                {{ t('download') }}
              </a>
              <Button variant="ghost" size="icon" :aria-label="t('close')" @click="showJustificatifPreview = false">
                <X class="h-4 w-4" />
              </Button>
            </div>
          </div>
          <div class="flex-1 overflow-hidden p-2">
            <iframe
              :src="`${justificatifUrl}?inline=1`"
              class="h-full w-full rounded border-0"
              style="min-height: 70dvh;"
              :title="t('justificatif')"
            />
          </div>
        </div>
      </div>
    </Transition>
  </AppLayout>
</template>