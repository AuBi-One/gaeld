<script setup>
import { computed } from 'vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardDescription from '@/Components/UI/CardDescription.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'
import { useTheme } from '@/lib/useTheme'
import { Line } from 'vue-chartjs'
import { intlLocale } from '@/lib/utils'

const props = defineProps({
  forecast: { type: Object, required: true },
})

const { t } = useTranslations()
const { formatCurrency, locale } = useFormatters()
const { isDark } = useTheme()

const worstCase = computed(() => props.forecast.scenarios?.withoutRevenue ?? {})
const withRevenue = computed(() => props.forecast.scenarios?.withRevenue ?? {})
const burn = computed(() => props.forecast.burn ?? {})

function asNumber(value) {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : 0
}

/**
 * The headline answers the worst case — no further income — because that is
 * the number a runway is for. The revenue scenario sits next to it as context.
 */
const headline = computed(() => {
  const months = worstCase.value.monthsRemaining

  if (months === null || months === undefined) {
    return {
      value: t('liquidity_beyond_horizon', { count: props.forecast.horizonMonths ?? 12 }),
      detail: null,
      tone: 'text-green-600 dark:text-green-400',
    }
  }

  return {
    value: t('liquidity_months_remaining', { count: months }),
    detail: worstCase.value.depletionDate,
    tone: months <= 3
      ? 'text-red-600 dark:text-red-400'
      : months <= 6
        ? 'text-amber-600 dark:text-amber-400'
        : 'text-green-600 dark:text-green-400',
  }
})

const sourceLabels = {
  master_data: 'liquidity_source_master_data',
  ledger: 'liquidity_source_ledger',
  none: 'liquidity_source_none',
}

const burnRows = computed(() => [
  { key: 'payroll', label: t('liquidity_burn_payroll'), ...burn.value.payroll },
  { key: 'recurring', label: t('liquidity_burn_recurring'), ...burn.value.recurring },
  { key: 'other', label: t('liquidity_burn_other'), ...burn.value.other },
])

/** True when any component is a run-rate guess rather than maintained data. */
const hasEstimates = computed(() => burnRows.value.some((row) => row.source === 'ledger'))

const chartData = computed(() => {
  const series = worstCase.value.series ?? []

  return {
    labels: series.map((point) => point.month),
    datasets: [
      {
        label: t('liquidity_scenario_without_revenue'),
        data: series.map((point) => asNumber(point.balance)),
        borderColor: '#dc2626',
        backgroundColor: 'rgba(220, 38, 38, 0.08)',
        fill: true,
        tension: 0.25,
        pointRadius: 2,
      },
      {
        label: t('liquidity_scenario_with_revenue'),
        data: (withRevenue.value.series ?? []).map((point) => asNumber(point.balance)),
        borderColor: '#16a34a',
        backgroundColor: 'rgba(22, 163, 74, 0.08)',
        fill: false,
        borderDash: [5, 4],
        tension: 0.25,
        pointRadius: 2,
      },
    ],
  }
})

const chartOptions = computed(() => {
  const tickColor = isDark.value ? '#9ca3af' : '#6b7280'
  const gridColor = isDark.value ? 'rgba(75, 85, 99, 0.3)' : 'rgba(229, 231, 235, 0.8)'

  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom', labels: { color: tickColor, boxWidth: 12 } },
      tooltip: {
        callbacks: {
          label: (item) => ` ${item.dataset.label}: ${formatCurrency(item.raw)}`,
        },
      },
    },
    scales: {
      x: { ticks: { color: tickColor }, grid: { color: gridColor } },
      y: {
        ticks: {
          color: tickColor,
          callback: (value) => 'CHF ' + new Intl.NumberFormat(intlLocale(locale.value), { notation: 'compact', maximumFractionDigits: 1 }).format(value),
        },
        grid: { color: gridColor },
      },
    },
  }
})
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ t('liquidity_runway_title') }}</CardTitle>
      <CardDescription>{{ t('liquidity_runway_desc') }}</CardDescription>
    </CardHeader>

    <CardContent>
      <div class="grid gap-6 lg:grid-cols-[minmax(0,320px)_1fr]">
        <!-- Figures -->
        <div class="space-y-5">
          <div>
            <p class="text-3xl font-bold" :class="headline.tone">{{ headline.value }}</p>
            <p v-if="headline.detail" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
              {{ t('liquidity_lasts_until', { date: headline.detail }) }}
            </p>
            <p v-if="withRevenue.sustainable" class="mt-1 text-sm text-green-600 dark:text-green-400">
              {{ t('liquidity_sustainable') }}
            </p>
          </div>

          <div class="space-y-1 border-t border-gray-200 pt-4 dark:border-gray-700">
            <div class="flex items-baseline justify-between text-sm">
              <span class="text-gray-600 dark:text-gray-300">{{ t('liquidity_liquid_assets') }}</span>
              <span class="font-medium">{{ formatCurrency(forecast.liquidAssets) }}</span>
            </div>
            <div class="flex items-baseline justify-between text-sm">
              <span class="text-gray-600 dark:text-gray-300">{{ t('liquidity_short_term_liabilities') }}</span>
              <span class="font-medium text-red-600 dark:text-red-400">−{{ formatCurrency(forecast.shortTermLiabilities) }}</span>
            </div>
            <div class="flex items-baseline justify-between border-t border-gray-200 pt-2 text-sm dark:border-gray-700">
              <span class="font-medium text-gray-700 dark:text-gray-200">{{ t('liquidity_available_funds') }}</span>
              <span class="text-base font-bold">{{ formatCurrency(forecast.availableFunds) }}</span>
            </div>
          </div>

          <div class="space-y-1 border-t border-gray-200 pt-4 dark:border-gray-700">
            <div v-for="row in burnRows" :key="row.key" class="flex items-baseline justify-between gap-2 text-sm">
              <span class="text-gray-600 dark:text-gray-300">
                {{ row.label }}
                <span class="ml-1 text-xs text-gray-400 dark:text-gray-500">{{ t(sourceLabels[row.source] ?? sourceLabels.none) }}</span>
              </span>
              <span class="whitespace-nowrap font-medium">{{ formatCurrency(row.amount) }}</span>
            </div>
            <div class="flex items-baseline justify-between border-t border-gray-200 pt-2 text-sm dark:border-gray-700">
              <span class="font-medium text-gray-700 dark:text-gray-200">{{ t('liquidity_monthly_burn') }}</span>
              <span class="text-base font-bold text-red-600 dark:text-red-400">{{ formatCurrency(burn.total) }}</span>
            </div>
            <div class="flex items-baseline justify-between text-sm">
              <span class="text-gray-600 dark:text-gray-300">{{ t('liquidity_monthly_revenue') }}</span>
              <span class="font-medium text-green-600 dark:text-green-400">{{ formatCurrency(forecast.monthlyRevenue?.amount) }}</span>
            </div>
          </div>

          <p v-if="hasEstimates" class="rounded-md bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
            {{ t('liquidity_estimate_hint', { months: forecast.window?.months ?? 6 }) }}
          </p>
        </div>

        <!-- Projection -->
        <div class="h-72">
          <Line :data="chartData" :options="chartOptions" />
        </div>
      </div>
    </CardContent>
  </Card>
</template>
