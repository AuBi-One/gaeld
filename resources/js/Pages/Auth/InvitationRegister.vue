<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import PasswordStrength from '@/Components/UI/PasswordStrength.vue'
import Card from '@/Components/UI/Card.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import { useTranslations } from '@/lib/useTranslations'
import { computed } from 'vue'
import GuestBar from '@/Components/GuestBar.vue'

const props = defineProps({
  token: { type: String, required: true },
  email: { type: String, required: true },
  organizationName: { type: String, required: true },
})

const { t } = useTranslations()

const form = useForm({
  name: '',
  password: '',
  password_confirmation: '',
})

const passwordConfirmationError = computed(() => {
  if (form.password_confirmation && form.password !== form.password_confirmation) {
    return t('passwords_do_not_match')
  }
  return form.errors.password_confirmation
})

function submit() {
  form.post(`/invitations/${props.token}/register`, {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
  <Head :title="t('invitation_register_heading', { organization: organizationName })" />

  <GuestBar />
  <div class="flex min-h-screen items-center justify-center bg-[hsl(var(--muted))] p-6">
    <div class="w-full max-w-md">
      <div class="mb-8 text-center">
        <img src="/logo-wide.svg" alt="Gäld" class="mx-auto h-14 w-auto mb-4" />
        <h1 class="text-2xl font-bold">{{ t('invitation_register_heading', { organization: organizationName }) }}</h1>
        <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">{{ t('invitation_register_subheading') }}</p>
      </div>

      <Card>
        <CardContent class="pt-6">
          <form class="space-y-4" @submit.prevent="submit">
            <!-- The address is not submitted: the server reads it off the token. -->
            <FormInput
              id="email"
              :model-value="email"
              type="email"
              :label="t('email')"
              :hint="t('invitation_register_email_hint')"
              autocomplete="username"
              readonly
            />

            <FormInput
              id="name"
              v-model="form.name"
              :label="t('full_name')"
              placeholder="Max Muster"
              :error="form.errors.name"
              autocomplete="name"
              required
            />

            <FormInput
              id="password"
              v-model="form.password"
              type="password"
              :label="t('password')"
              :error="form.errors.password"
              autocomplete="new-password"
              required
            />

            <PasswordStrength :password="form.password" />

            <p class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('password_requirements_hint') }}</p>

            <FormInput
              id="password_confirmation"
              v-model="form.password_confirmation"
              type="password"
              :label="t('confirm_password')"
              :error="passwordConfirmationError"
              autocomplete="new-password"
              required
            />

            <Button type="submit" class="w-full" :disabled="form.processing" :loading="form.processing">
              {{ t('invitation_register_submit') }}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  </div>
</template>
