<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardDescription from '@/Components/UI/CardDescription.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import { useTranslations } from '@/lib/useTranslations'

const props = defineProps({
  code: { type: String, required: true },
  accounts: { type: Array, default: () => [] },
})

const { t } = useTranslations()

const form = useForm({
  default_revenue_account_code: props.code,
})

// The current account stays listed even if it is missing or inactive, so the select shows it.
const options = computed(() => {
  const list = props.accounts.map(a => ({ value: a.code, label: `${a.code} — ${a.name}` }))
  if (!props.accounts.some(a => a.code === props.code)) {
    list.unshift({ value: props.code, label: props.code })
  }
  return list
})

function submit() {
  form.put('/settings/revenue-account', { preserveScroll: true })
}
</script>

<template>
  <Card class="mb-6">
    <CardHeader>
      <CardTitle>{{ t('settings_revenue_account_title') }}</CardTitle>
      <CardDescription>{{ t('settings_revenue_account_desc') }}</CardDescription>
    </CardHeader>
    <CardContent>
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          id="default_revenue_account_code"
          v-model="form.default_revenue_account_code"
          :label="t('settings_revenue_account_label')"
          :options="options"
          :error="form.errors.default_revenue_account_code"
          required
        />
        <div class="flex justify-end">
          <Button type="submit" :disabled="form.processing" :loading="form.processing">
            {{ t('save_changes') }}
          </Button>
        </div>
      </form>
    </CardContent>
  </Card>
</template>
