<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Paperclip, Trash2, Eye, Download, X } from 'lucide-vue-next'

const props = defineProps({
  entry: { type: Object, required: true },
  source: { type: Object, default: null },
  justificatifUrl: { type: String, default: null },
  canEditJustificatif: { type: Boolean, default: false },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const showJustificatifPreview = ref(false)

function removeJustificatif() {
  router.delete(`/accounting/journal-entries/${props.entry.id}/justificatif`)
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
      <Card>
        <CardHeader>
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <CardTitle>{{ entry.description || entry.reference || t('journal_entry') }}</CardTitle>
              <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">{{ entry.reference || '—' }}</p>
            </div>
            <Badge :variant="entry.is_posted ? 'success' : 'warning'">
              {{ entry.is_posted ? t('posted') : t('draft') }}
            </Badge>
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

      <Card>
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

      <div>
        <Link href="/accounting/journal-entries">
          <Button variant="outline">{{ t('back') }}</Button>
        </Link>
      </div>
    </div>

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