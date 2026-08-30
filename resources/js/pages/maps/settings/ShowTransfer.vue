<script setup lang="ts">
import MapImportController from '@/actions/App/Http/Controllers/MapImportController';
import MapTransferController from '@/actions/App/Http/Controllers/MapTransferController';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { TMapSummary } from '@/pages/maps';
import { router } from '@inertiajs/vue3';
import {
    Check,
    Download,
    FileJson,
    FilePlus2,
    Loader2,
    Orbit,
    Radar,
    Route,
    SlidersHorizontal,
    Upload,
    Users,
    Waypoints,
    type LucideIcon,
} from 'lucide-vue-next';
import { computed, ref, useTemplateRef } from 'vue';

const { map, counts } = defineProps<{
    map: TMapSummary;
    counts: Record<string, number>;
}>();

type TSectionKey = 'settings' | 'access' | 'solarsystems' | 'connections' | 'signatures' | 'routes';

const sections: { key: TSectionKey; label: string; description: string; icon: LucideIcon }[] = [
    { key: 'settings', label: 'Map settings', description: 'Name, layout, bookmark formats', icon: SlidersHorizontal },
    { key: 'access', label: 'Access', description: 'Characters, corporations, alliances', icon: Users },
    { key: 'solarsystems', label: 'Solar systems', description: 'Positions, notes, statuses', icon: Orbit },
    { key: 'connections', label: 'Connections', description: 'Wormholes between systems', icon: Waypoints },
    { key: 'signatures', label: 'Signatures', description: 'Scan results per system', icon: Radar },
    { key: 'routes', label: 'Routes', description: 'Route and ignored systems', icon: Route },
];

function toggleSection(selection: TSectionKey[], key: TSectionKey) {
    const index = selection.indexOf(key);
    if (index === -1) {
        selection.push(key);
    } else {
        selection.splice(index, 1);
    }
}

/* Export */

const export_sections = ref<TSectionKey[]>(sections.map((section) => section.key));

function downloadExport() {
    const params = new URLSearchParams();
    export_sections.value.forEach((section) => params.append('sections[]', section));
    window.location.href = `${MapTransferController.export(map.slug).url}?${params.toString()}`;
}

/* Import */

type TFilePreview = {
    map_name: string;
    exported_at: string | null;
    sections: Partial<Record<TSectionKey, number | null>>;
};

const steps = [
    { number: 1, label: 'File' },
    { number: 2, label: 'Destination' },
    { number: 3, label: 'Review' },
];

const step = ref(1);
const file_input = useTemplateRef<HTMLInputElement>('file-input');
const selected_file = ref<File | null>(null);
const preview = ref<TFilePreview | null>(null);
const parse_error = ref<string | null>(null);
const is_dragging = ref(false);
const destination = ref<'merge' | 'new'>('merge');
const new_map_name = ref('');
const import_sections = ref<TSectionKey[]>([]);
const server_error = ref<string | null>(null);
const is_importing = ref(false);

const available_sections = computed(() => sections.filter((section) => preview.value && section.key in preview.value.sections));

const exported_date = computed(() => {
    if (!preview.value?.exported_at) return null;
    const date = new Date(preview.value.exported_at);
    return Number.isNaN(date.getTime()) ? null : date.toLocaleDateString();
});

function onFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file) readFile(file);
}

function onFileDropped(event: DragEvent) {
    is_dragging.value = false;
    const file = event.dataTransfer?.files?.[0];
    if (file) readFile(file);
}

async function readFile(file: File) {
    parse_error.value = null;
    server_error.value = null;

    let data: unknown;
    try {
        data = JSON.parse(await file.text());
    } catch {
        parse_error.value = 'This file is not valid JSON.';
        return;
    }

    const payload = data as { format?: string; version?: number; map_name?: string; exported_at?: string; sections?: Record<string, unknown> };

    if (payload?.format !== 'wormholesystems-map-export') {
        parse_error.value = 'This file is not a wormholesystems map export.';
        return;
    }

    if (payload.version !== 1) {
        parse_error.value = 'This file was exported by an incompatible version of the application.';
        return;
    }

    const file_sections: TFilePreview['sections'] = {};
    for (const section of sections) {
        const value = payload.sections?.[section.key];
        if (value === undefined) continue;
        if (section.key === 'routes') {
            const routes = value as { route_solarsystems?: unknown[]; ignored_solarsystems?: unknown[] };
            file_sections.routes = (routes?.route_solarsystems?.length ?? 0) + (routes?.ignored_solarsystems?.length ?? 0);
        } else if (Array.isArray(value)) {
            file_sections[section.key] = value.length;
        } else {
            file_sections[section.key] = null;
        }
    }

    if (Object.keys(file_sections).length === 0) {
        parse_error.value = 'This file does not contain any importable sections.';
        return;
    }

    selected_file.value = file;
    preview.value = {
        map_name: payload.map_name ?? 'Unnamed map',
        exported_at: payload.exported_at ?? null,
        sections: file_sections,
    };
    import_sections.value = Object.keys(file_sections) as TSectionKey[];
    step.value = 2;
}

function resetImport() {
    step.value = 1;
    selected_file.value = null;
    preview.value = null;
    parse_error.value = null;
    server_error.value = null;
    destination.value = 'merge';
    new_map_name.value = '';
    import_sections.value = [];
}

function submitImport() {
    if (!selected_file.value || import_sections.value.length === 0) return;

    is_importing.value = true;
    server_error.value = null;

    const shared = {
        forceFormData: true,
        preserveScroll: true,
        onError: (errors: Record<string, string>) => {
            server_error.value = errors.file ?? errors.name ?? Object.values(errors)[0] ?? null;
        },
        onSuccess: () => resetImport(),
        onFinish: () => {
            is_importing.value = false;
        },
    };

    if (destination.value === 'merge') {
        router.post(MapTransferController.import(map.slug).url, { file: selected_file.value, sections: import_sections.value }, shared);
    } else {
        router.post(
            MapImportController.store().url,
            { file: selected_file.value, sections: import_sections.value, name: new_map_name.value || null },
            shared,
        );
    }
}
</script>

<template>
    <SettingsLayout :map="map" title="Import / Export" description="Move map data between maps and installations">
        <Card>
            <CardContent class="pt-6">
                <Tabs default-value="export">
                    <TabsList class="grid w-full max-w-xs grid-cols-2">
                        <TabsTrigger value="export">
                            <Download class="mr-2 size-4" />
                            Export
                        </TabsTrigger>
                        <TabsTrigger value="import">
                            <Upload class="mr-2 size-4" />
                            Import
                        </TabsTrigger>
                    </TabsList>

                    <!-- Export -->
                    <TabsContent value="export" class="mt-6">
                        <div class="space-y-6">
                            <div>
                                <h3 class="text-sm font-medium">Choose what travels</h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Share links, webhook URLs, and personal preferences are never included.
                                </p>
                            </div>

                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                <button
                                    v-for="section in sections"
                                    :key="`export-${section.key}`"
                                    type="button"
                                    class="group relative flex items-start gap-3 rounded-lg border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :class="
                                        export_sections.includes(section.key)
                                            ? 'border-primary/50 bg-primary/5'
                                            : 'border-border/60 hover:bg-muted/40'
                                    "
                                    @click="toggleSection(export_sections, section.key)"
                                >
                                    <component
                                        :is="section.icon"
                                        class="mt-0.5 size-4 shrink-0"
                                        :class="export_sections.includes(section.key) ? 'text-primary' : 'text-muted-foreground'"
                                    />
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium">{{ section.label }}</span>
                                        <span class="block truncate text-xs text-muted-foreground">{{ section.description }}</span>
                                    </span>
                                    <span v-if="counts[section.key] !== undefined" class="ml-auto text-xs text-muted-foreground tabular-nums">
                                        {{ counts[section.key] }}
                                    </span>
                                    <span
                                        class="absolute -top-1.5 -right-1.5 flex size-4 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity"
                                        :class="export_sections.includes(section.key) ? 'opacity-100' : 'opacity-0'"
                                    >
                                        <Check class="size-3" />
                                    </span>
                                </button>
                            </div>

                            <div class="flex items-center justify-between border-t border-border/50 pt-4">
                                <p class="text-xs text-muted-foreground">{{ export_sections.length }} of {{ sections.length }} sections selected</p>
                                <Button :disabled="export_sections.length === 0" @click="downloadExport">
                                    <Download class="mr-2 size-4" />
                                    Download export
                                </Button>
                            </div>
                        </div>
                    </TabsContent>

                    <!-- Import -->
                    <TabsContent value="import" class="mt-6">
                        <div class="space-y-6">
                            <!-- Step rail -->
                            <ol class="flex items-center gap-2">
                                <template v-for="(item, index) in steps" :key="item.number">
                                    <li class="flex items-center gap-2">
                                        <span
                                            class="flex size-6 items-center justify-center rounded-full text-xs font-medium transition-colors"
                                            :class="{
                                                'bg-primary text-primary-foreground': step === item.number,
                                                'bg-primary/15 text-primary': step > item.number,
                                                'bg-muted text-muted-foreground': step < item.number,
                                            }"
                                        >
                                            <Check v-if="step > item.number" class="size-3.5" />
                                            <template v-else>{{ item.number }}</template>
                                        </span>
                                        <span class="text-sm" :class="step === item.number ? 'font-medium' : 'text-muted-foreground'">
                                            {{ item.label }}
                                        </span>
                                    </li>
                                    <li v-if="index < steps.length - 1" class="h-px flex-1 bg-border" aria-hidden="true" />
                                </template>
                            </ol>

                            <!-- Step 1: File -->
                            <div v-if="step === 1" class="space-y-3">
                                <input ref="file-input" type="file" accept="application/json,.json" class="hidden" @change="onFileSelected" />
                                <button
                                    type="button"
                                    class="flex w-full flex-col items-center justify-center gap-3 rounded-lg border border-dashed px-6 py-12 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :class="is_dragging ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted/30'"
                                    @click="file_input?.click()"
                                    @dragover.prevent="is_dragging = true"
                                    @dragleave="is_dragging = false"
                                    @drop.prevent="onFileDropped"
                                >
                                    <FileJson class="size-8 text-muted-foreground" />
                                    <span class="text-sm font-medium">Drop an export file here, or click to browse</span>
                                    <span class="text-xs text-muted-foreground">JSON exports from any wormholesystems map, up to 5 MB</span>
                                </button>
                                <p v-if="parse_error" class="text-sm text-red-500">{{ parse_error }}</p>
                            </div>

                            <!-- Step 2: Destination -->
                            <div v-else-if="step === 2 && preview" class="space-y-4">
                                <div class="flex items-center gap-3 rounded-lg border border-border/60 bg-muted/20 px-4 py-3">
                                    <FileJson class="size-5 shrink-0 text-muted-foreground" />
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">{{ preview.map_name }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ selected_file?.name }}<template v-if="exported_date"> · exported {{ exported_date }}</template>
                                        </p>
                                    </div>
                                </div>

                                <div class="grid gap-2 sm:grid-cols-2">
                                    <button
                                        type="button"
                                        class="flex items-start gap-3 rounded-lg border p-4 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        :class="destination === 'merge' ? 'border-primary/50 bg-primary/5' : 'border-border/60 hover:bg-muted/40'"
                                        @click="destination = 'merge'"
                                    >
                                        <Upload
                                            class="mt-0.5 size-5 shrink-0"
                                            :class="destination === 'merge' ? 'text-primary' : 'text-muted-foreground'"
                                        />
                                        <span>
                                            <span class="block text-sm font-medium">Merge into "{{ map.name }}"</span>
                                            <span class="block text-xs text-muted-foreground">
                                                Matching systems, signatures, and settings are overwritten
                                            </span>
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        class="flex items-start gap-3 rounded-lg border p-4 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        :class="destination === 'new' ? 'border-primary/50 bg-primary/5' : 'border-border/60 hover:bg-muted/40'"
                                        @click="destination = 'new'"
                                    >
                                        <FilePlus2
                                            class="mt-0.5 size-5 shrink-0"
                                            :class="destination === 'new' ? 'text-primary' : 'text-muted-foreground'"
                                        />
                                        <span>
                                            <span class="block text-sm font-medium">Create a new map</span>
                                            <span class="block text-xs text-muted-foreground">A fresh map owned by you, nothing here is touched</span>
                                        </span>
                                    </button>
                                </div>

                                <div v-if="destination === 'new'">
                                    <Label for="new-map-name" class="text-sm font-medium">Map name</Label>
                                    <Input id="new-map-name" v-model="new_map_name" :placeholder="preview.map_name" class="mt-2 max-w-sm" />
                                    <p class="mt-1 text-xs text-muted-foreground">Leave empty to keep the name from the file</p>
                                </div>

                                <div class="flex items-center justify-between border-t border-border/50 pt-4">
                                    <Button variant="ghost" @click="resetImport">Back</Button>
                                    <Button @click="step = 3">Continue</Button>
                                </div>
                            </div>

                            <!-- Step 3: Review -->
                            <div v-else-if="step === 3 && preview" class="space-y-4">
                                <div>
                                    <h3 class="text-sm font-medium">Choose what to import</h3>
                                    <p class="mt-1 text-xs text-muted-foreground">Only sections present in the file are shown</p>
                                </div>

                                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    <button
                                        v-for="section in available_sections"
                                        :key="`import-${section.key}`"
                                        type="button"
                                        class="group relative flex items-start gap-3 rounded-lg border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        :class="
                                            import_sections.includes(section.key)
                                                ? 'border-primary/50 bg-primary/5'
                                                : 'border-border/60 hover:bg-muted/40'
                                        "
                                        @click="toggleSection(import_sections, section.key)"
                                    >
                                        <component
                                            :is="section.icon"
                                            class="mt-0.5 size-4 shrink-0"
                                            :class="import_sections.includes(section.key) ? 'text-primary' : 'text-muted-foreground'"
                                        />
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium">{{ section.label }}</span>
                                            <span class="block truncate text-xs text-muted-foreground">{{ section.description }}</span>
                                        </span>
                                        <span
                                            v-if="preview.sections[section.key] !== null"
                                            class="ml-auto text-xs text-muted-foreground tabular-nums"
                                        >
                                            {{ preview.sections[section.key] }}
                                        </span>
                                        <span
                                            class="absolute -top-1.5 -right-1.5 flex size-4 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity"
                                            :class="import_sections.includes(section.key) ? 'opacity-100' : 'opacity-0'"
                                        >
                                            <Check class="size-3" />
                                        </span>
                                    </button>
                                </div>

                                <p v-if="destination === 'merge'" class="text-xs text-muted-foreground">
                                    Matching entries on "{{ map.name }}" will be overwritten. Existing connections are kept.
                                </p>
                                <p v-else class="text-xs text-muted-foreground">
                                    A new map named "{{ new_map_name || preview.map_name }}" will be created, owned by you.
                                </p>

                                <p v-if="server_error" class="text-sm text-red-500">{{ server_error }}</p>

                                <div class="flex items-center justify-between border-t border-border/50 pt-4">
                                    <Button variant="ghost" :disabled="is_importing" @click="step = 2">Back</Button>
                                    <Button
                                        :variant="destination === 'merge' ? 'destructive' : 'default'"
                                        :disabled="import_sections.length === 0 || is_importing"
                                        @click="submitImport"
                                    >
                                        <Loader2 v-if="is_importing" class="mr-2 size-4 animate-spin" />
                                        {{ destination === 'merge' ? `Import into "${map.name}"` : 'Create map' }}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>
            </CardContent>
        </Card>
    </SettingsLayout>
</template>
