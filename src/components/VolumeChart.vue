<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<figure class="chart">
		<div class="chart__head">
			<ul class="chart__legend" :aria-label="t('ticketsystem', 'Legend')">
				<li><span class="chart__key chart__key--created" />{{ t('ticketsystem', 'Created') }}</li>
				<li><span class="chart__key chart__key--resolved" />{{ t('ticketsystem', 'Resolved') }}</li>
			</ul>
			<NcButton variant="tertiary" size="small" :pressed="showTable" @click="showTable = !showTable">
				{{ showTable ? t('ticketsystem', 'Show chart') : t('ticketsystem', 'Show as table') }}
			</NcButton>
		</div>

		<table v-if="showTable" class="chart__table">
			<thead>
				<tr>
					<th scope="col">{{ t('ticketsystem', 'Day') }}</th>
					<th scope="col">{{ t('ticketsystem', 'Created') }}</th>
					<th scope="col">{{ t('ticketsystem', 'Resolved') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="day in series" :key="day.date">
					<th scope="row">{{ longDate(day.date) }}</th>
					<td>{{ day.created }}</td>
					<td>{{ day.resolved }}</td>
				</tr>
			</tbody>
		</table>

		<div v-else ref="box" class="chart__plot" @mouseleave="hover = null">
			<svg :viewBox="`0 0 ${width} ${height}`"
				:width="width"
				:height="height"
				role="img"
				:aria-label="t('ticketsystem', 'Tickets created and resolved per day')">
				<g class="chart__grid">
					<g v-for="tick in ticks" :key="tick">
						<line :x1="left" :x2="width - right" :y1="y(tick)" :y2="y(tick)" />
						<text :x="left - 8" :y="y(tick) + 4" text-anchor="end">{{ tick }}</text>
					</g>
				</g>
				<g v-for="(day, i) in series" :key="day.date">
					<rect v-if="hover === i"
						class="chart__hover"
						:x="groupX(i)"
						:y="top"
						:width="groupWidth"
						:height="plotHeight" />
					<path class="chart__bar chart__bar--created" :d="bar(barX(i, 0), day.created)" />
					<path class="chart__bar chart__bar--resolved" :d="bar(barX(i, 1), day.resolved)" />
					<text v-if="showLabel(i)"
						class="chart__xlabel"
						:x="groupX(i) + groupWidth / 2"
						:y="height - 6"
						text-anchor="middle">{{ shortDate(day.date) }}</text>
					<!-- Hit area bigger than the bars. -->
					<rect class="chart__hit"
						:x="groupX(i)"
						:y="top"
						:width="groupWidth"
						:height="plotHeight"
						@mouseenter="hover = i" />
				</g>
				<line class="chart__baseline" :x1="left" :x2="width - right" :y1="y(0)" :y2="y(0)" />
			</svg>
			<div v-if="hover !== null"
				class="chart__tooltip"
				:style="{ left: tooltipLeft + 'px' }"
				role="status">
				<strong>{{ longDate(series[hover].date) }}</strong>
				<span><span class="chart__key chart__key--created" />{{ t('ticketsystem', 'Created') }}: {{ series[hover].created }}</span>
				<span><span class="chart__key chart__key--resolved" />{{ t('ticketsystem', 'Resolved') }}: {{ series[hover].resolved }}</span>
			</div>
		</div>
	</figure>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { getLanguage, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

const props = defineProps({
	/** [{date: 'Y-m-d', created, resolved}] */
	series: { type: Array, required: true },
})

const box = ref(null)
const width = ref(640)
const height = 240
const top = 12
const bottom = 26
const left = 36
const right = 8
const plotHeight = height - top - bottom
const hover = ref(null)
const showTable = ref(false)

const max = computed(() => Math.max(1, ...props.series.map(d => Math.max(d.created, d.resolved))))
/** Round tick steps (1, 2, 5, 10, 20, 50 …) giving about four grid lines. */
const ticks = computed(() => {
	const raw = max.value / 4
	const magnitude = 10 ** Math.floor(Math.log10(raw))
	const step = [1, 2, 5, 10].map(m => m * magnitude).find(s => s >= raw) ?? magnitude * 10
	const result = []
	for (let v = 0; v <= max.value + step * 0.01; v += Math.max(1, step)) {
		result.push(Math.round(v))
	}
	if (result[result.length - 1] < max.value) {
		result.push(result[result.length - 1] + Math.max(1, step))
	}
	return result
})
const yMax = computed(() => ticks.value[ticks.value.length - 1])
const y = (value) => top + plotHeight - (value / yMax.value) * plotHeight

const groupWidth = computed(() => (width.value - left - right) / Math.max(1, props.series.length))
const groupX = (i) => left + i * groupWidth.value
const barWidth = computed(() => Math.max(2, Math.min(18, (groupWidth.value - 6) / 2 - 1)))
// Two bars side by side with a 2px gap, centred in the day's slot.
const barX = (i, which) => groupX(i) + groupWidth.value / 2 - barWidth.value - 1 + which * (barWidth.value + 2)

/** A bar anchored to the baseline with rounded top corners. */
function bar(x, value) {
	if (!value) {
		return ''
	}
	const w = barWidth.value
	const base = y(0)
	const h = Math.max(2, base - y(value))
	const r = Math.min(4, w / 2, h)
	const t0 = base - h
	return `M${x},${base} V${t0 + r} Q${x},${t0} ${x + r},${t0} H${x + w - r} Q${x + w},${t0} ${x + w},${t0 + r} V${base} Z`
}

const labelEvery = computed(() => Math.max(1, Math.ceil(props.series.length / Math.max(1, Math.floor((width.value - left) / 56)))))
const showLabel = (i) => i % labelEvery.value === 0

const tooltipLeft = computed(() => {
	if (hover.value === null) {
		return 0
	}
	const x = groupX(hover.value) + groupWidth.value / 2
	return Math.min(Math.max(x - 80, 0), width.value - 170)
})

const short = new Intl.DateTimeFormat(getLanguage(), { day: 'numeric', month: 'short' })
const long = new Intl.DateTimeFormat(getLanguage(), { weekday: 'short', day: 'numeric', month: 'long' })
const asDate = (s) => new Date(s + 'T12:00:00')
const shortDate = (s) => short.format(asDate(s))
const longDate = (s) => long.format(asDate(s))

let observer
onMounted(() => {
	observer = new ResizeObserver(([entry]) => {
		width.value = Math.max(280, Math.floor(entry.contentRect.width))
	})
	if (box.value) {
		observer.observe(box.value)
	}
})
onBeforeUnmount(() => observer?.disconnect())
</script>

<style scoped lang="scss">
.chart {
	--series-created: #2a78d6;
	--series-resolved: #eb6834;
	margin: 0;

	&__head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		margin-bottom: 8px;
	}

	&__legend {
		display: flex;
		gap: 16px;
		color: var(--color-main-text);
		font-size: var(--font-size-small, 13px);

		li {
			display: flex;
			align-items: center;
			gap: 6px;
		}
	}

	&__key {
		display: inline-block;
		width: 10px;
		height: 10px;
		margin-inline-end: 4px;
		border-radius: 2px;

		&--created {
			background: var(--series-created);
		}

		&--resolved {
			background: var(--series-resolved);
		}
	}

	&__plot {
		position: relative;
		width: 100%;
	}

	svg {
		display: block;
		overflow: visible;
	}

	&__grid {
		line {
			stroke: var(--color-border);
			stroke-width: 1;
		}

		text {
			fill: var(--color-text-maxcontrast);
			font-size: 11px;
		}
	}

	&__baseline {
		stroke: var(--color-border-dark);
		stroke-width: 1;
	}

	&__bar {
		&--created {
			fill: var(--series-created);
		}

		&--resolved {
			fill: var(--series-resolved);
		}
	}

	&__xlabel {
		fill: var(--color-text-maxcontrast);
		font-size: 11px;
	}

	&__hover {
		fill: var(--color-background-hover);
	}

	&__hit {
		fill: transparent;
		cursor: default;
	}

	&__tooltip {
		position: absolute;
		top: 0;
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 160px;
		padding: 8px 10px;
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);
		box-shadow: 0 2px 10px var(--color-box-shadow);
		color: var(--color-main-text);
		font-size: var(--font-size-small, 13px);
		pointer-events: none;
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			padding: 4px 8px;
			border-bottom: 1px solid var(--color-border);
			text-align: start;
		}

		td {
			text-align: end;
			font-variant-numeric: tabular-nums;
		}
	}
}

@media (prefers-color-scheme: dark) {
	:global(body:not([data-themes*="light"])) .chart {
		--series-created: #3987e5;
		--series-resolved: #d95926;
	}
}

:global(body[data-themes*="dark"]) .chart {
	--series-created: #3987e5;
	--series-resolved: #d95926;
}
</style>
