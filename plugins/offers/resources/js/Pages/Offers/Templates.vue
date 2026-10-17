<script setup>
import { ref } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useTranslations } from '@/lib/useTranslations'
import { LayoutTemplate, Pencil, Plus, Trash2 } from 'lucide-vue-next'

const { t } = useTranslations()

const props = defineProps({
  templates: { type: Array, default: () => [] },
  settings: { type: Object, default: () => ({}) },
  canManage: { type: Boolean, default: false },
})

const settingsForm = useForm({
  validity_months: props.settings.validity_months ?? 2,
})

function saveSettings() {
  settingsForm.put('/offer-templates/settings', { preserveScroll: true })
}

const toDelete = ref(null)

function destroy() {
  router.delete(`/offer-templates/${toDelete.value}`, { onFinish: () => { toDelete.value = null } })
}
</script>

<template>
  <AppLayout :title="t('of_templates_title')" help-page="invoices">
    <Breadcrumb :items="[{ label: t('of_title_offers'), href: '/offers' }, { label: t('of_templates_title') }]" class="mb-4" />

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="max-w-2xl text-sm text-[hsl(var(--muted-foreground))]">{{ t('of_templates_intro') }}</p>
      <Button v-if="canManage" as="a" href="/offer-templates/create"><Plus class="mr-2 h-4 w-4" />{{ t('of_new_template') }}</Button>
    </div>

    <Card class="mb-6 max-w-4xl">
      <CardHeader>
        <CardTitle>{{ t('of_settings_title') }}</CardTitle>
        <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">{{ t('of_settings_intro') }}</p>
      </CardHeader>
      <CardContent>
        <form class="grid gap-4 sm:grid-cols-[10rem_auto] sm:items-end" @submit.prevent="saveSettings">
          <FormInput v-model="settingsForm.validity_months" id="of-set-validity" type="number" min="1" max="24" :label="t('of_validity_months')" :error="settingsForm.errors.validity_months" :disabled="!canManage" required />
          <Button v-if="canManage" type="submit" :loading="settingsForm.processing">{{ t('save') }}</Button>
        </form>
      </CardContent>
    </Card>

    <Card v-if="templates.length" class="max-w-4xl">
      <CardContent class="p-0">
        <table class="w-full text-sm">
          <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
            <tr>
              <th class="px-4 py-2">{{ t('of_template_name') }}</th>
              <th class="px-4 py-2">{{ t('of_subject') }}</th>
              <th class="px-4 py-2 text-right">{{ t('of_lines') }}</th>
              <th class="px-4 py-2 text-right">{{ t('of_used') }}</th>
              <th class="px-4 py-2" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="tpl in templates" :key="tpl.id" class="border-t border-[hsl(var(--border))]">
              <td class="px-4 py-2 font-medium">
                {{ tpl.name }}
                <Badge v-if="tpl.is_default" variant="info" class="ml-2">{{ t('of_default') }}</Badge>
              </td>
              <td class="px-4 py-2">{{ tpl.title }}</td>
              <td class="px-4 py-2 text-right font-mono">{{ tpl.lines }}</td>
              <td class="px-4 py-2 text-right font-mono">{{ tpl.used }}</td>
              <td class="whitespace-nowrap px-4 py-2 text-right">
                <template v-if="canManage">
                  <Button as="a" :href="`/offers/create?template=${tpl.id}`" variant="outline" size="sm" class="mr-1"><Plus class="mr-1 h-4 w-4" />{{ t('of_new_offer') }}</Button>
                  <Link :href="`/offer-templates/${tpl.id}/edit`"><Button variant="ghost" size="icon" :title="t('edit')"><Pencil class="h-4 w-4" /></Button></Link>
                  <Button variant="ghost" size="icon" :title="t('delete')" @click="toDelete = tpl.id"><Trash2 class="h-4 w-4" /></Button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </CardContent>
    </Card>
    <EmptyState
      v-else
      :icon="LayoutTemplate"
      :title="t('of_no_templates')"
      :description="t('of_no_templates_desc')"
      :action-label="canManage ? t('of_new_template') : ''"
      :action-href="canManage ? '/offer-templates/create' : ''"
    />

    <ConfirmDialog
      :open="toDelete !== null"
      :title="t('of_delete_template')"
      :message="t('of_delete_template_confirm')"
      @confirm="destroy"
      @cancel="toDelete = null"
    />
  </AppLayout>
</template>
