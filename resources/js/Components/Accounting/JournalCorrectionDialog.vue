<script setup>
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/UI/Modal.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import { useTranslations } from '@/lib/useTranslations'
import { todayLocal } from '@/lib/dates'

const props = defineProps({
  entry: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const { t } = useTranslations()

const form = useForm({
  reason: '',
  correction_date: todayLocal(),
})

function submit() {
  form.post(`/accounting/journal-entries/${props.entry.id}/corrections`, {
    preserveScroll: true,
    onSuccess: () => close(),
  })
}

function close() {
  form.reset()
  form.clearErrors()
  emit('close')
}
</script>

<template>
  <Modal :open="!!entry" :title="t('correct_journal_entry_title')" size="md" @close="close">
    <form @submit.prevent="submit">
      <div class="space-y-4">
        <p class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('correct_journal_entry_intro') }}</p>

        <FormTextarea
          id="correction_reason"
          v-model="form.reason"
          :label="t('correction_reason')"
          :placeholder="t('correction_reason_placeholder')"
          :error="form.errors.reason"
          required
        />

        <FormInput
          id="correction_date"
          v-model="form.correction_date"
          type="date"
          :label="t('correction_date')"
          :error="form.errors.correction_date"
          required
        />

        <div class="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" @click="close">{{ t('cancel') }}</Button>
          <Button type="submit" :disabled="form.processing" :loading="form.processing">
            {{ t('correct') }}
          </Button>
        </div>
      </div>
    </form>
  </Modal>
</template>
