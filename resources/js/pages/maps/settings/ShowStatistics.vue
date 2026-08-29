<script setup lang="ts">
import MapStatisticsPageController from '@/actions/App/Http/Controllers/MapStatisticsPageController';
import MaintainerLeaderboard from '@/components/maps/statistics/MaintainerLeaderboard.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { TMapSummary } from '@/pages/maps';
import type { TMaintainerEntry, TMaintainerPeriodOption } from '@/types/models';
import { router } from '@inertiajs/vue3';
import { Trophy } from 'lucide-vue-next';

const { map, period, available_periods, entries } = defineProps<{
    map: TMapSummary;
    period: string;
    available_periods: TMaintainerPeriodOption[];
    entries: TMaintainerEntry[];
}>();

function changePeriod(value: unknown): void {
    if (typeof value !== 'string' || value === period) return;

    router.visit(MapStatisticsPageController.show(map.slug, { query: { period: value } }), {
        only: ['period', 'entries'],
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <SettingsLayout :map="map" title="Leaderboard" description="Monthly maintainer points, ranked by scanning activity">
        <Card class="gap-0 py-0">
            <CardHeader class="flex flex-col items-start justify-between gap-4 border-b py-4 sm:flex-row sm:items-center">
                <div class="space-y-1">
                    <CardTitle class="flex items-center gap-2 text-lg">
                        <Trophy class="size-5 text-indigo-400" />
                        Maintainer leaderboard
                    </CardTitle>
                    <CardDescription>Points for signatures added, updated, and removed. Anomalies don't count — only scanning does.</CardDescription>
                </div>
                <Select :model-value="period" @update:model-value="changePeriod">
                    <SelectTrigger class="w-full sm:w-56"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in available_periods" :key="option.value" :value="option.value">
                            {{ option.label }}{{ option.is_current ? ' (in progress)' : '' }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </CardHeader>
            <CardContent class="p-4">
                <MaintainerLeaderboard :entries="entries" />
            </CardContent>
        </Card>
    </SettingsLayout>
</template>
