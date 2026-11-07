<script setup lang="ts">
import { Deferred, Head, Link, router, usePage } from "@inertiajs/vue3";
import { AlertTriangle, ArrowRight, CalendarClock } from "@lucide/vue";
import { computed, ref } from "vue";
import { index as reportsIndex } from "@/actions/App/Http/Controllers/Projects/ReportController";
import ProgressRing from "@/components/projects/charts/ProgressRing.vue";
import ChartTooltip from "@/components/projects/charts/ChartTooltip.vue";
import Sparkline from "@/components/projects/charts/Sparkline.vue";
import VelocityBars from "@/components/projects/charts/VelocityBars.vue";
import AvatarStack from "@/components/projects/shared/AvatarStack.vue";
import DueDateBadge from "@/components/projects/shared/DueDateBadge.vue";
import IssueIdentifier from "@/components/projects/shared/IssueIdentifier.vue";
import PriorityIcon from "@/components/projects/shared/PriorityIcon.vue";
import StatusIcon from "@/components/projects/shared/StatusIcon.vue";
import Breadcrumbs from "@/components/projects/workspace/Breadcrumbs.vue";
import OverviewSection from "@/components/projects/workspace/OverviewSection.vue";
import {
    NativeSelect,
    NativeSelectOption,
} from "@/components/ui/native-select";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { useKeyboard } from "@/composables/useKeyboard";
import { PRIORITY } from "@/lib/keyboard";
import { linearScale, niceTicks } from "@/lib/projects/chartScales";
import {
    CATEGORY_META,
    PROJECT_HEALTH_META,
    PROJECT_STATUS_META,
} from "@/lib/projects/meta";
import {
    categoryBreakdown,
    formatHours,
    padWeeks,
    presetForDays,
    RANGE_PRESETS,
    throughputPerWeek,
    weekLabel,
    type ThroughputWeek,
} from "@/lib/projects/reportShapes";
import { cn } from "@/lib/utils";
import type {
    IssueListItem,
    ProductSummary,
    ProjectHealth,
    ProjectStatus,
    ProjectSummary,
    StatusCategory,
    TeamSummary,
} from "@/types";

type ScopeType = "organization" | "product" | "project" | "team";

type Scope = {
    type: ScopeType;
    id: string | null;
    name: string;
    days: number;
};

type Figures = {
    backlog: number;
    unstarted: number;
    started: number;
    completed: number;
    canceled: number;
    open: number;
    completed_since: number;
    created_since: number;
};

type CrossTeam = {
    multi_team_issues: number;
    multi_team_open: number;
    blocked_by_another_team: number;
    waiting_workstreams: number;
    avg_handoff_hours: number | null;
    handoffs: number;
    ready_to_complete: number;
    per_team: {
        id: number;
        name: string;
        color: string;
        workstreams: number;
        completed: number;
        blocked: number;
    }[];
};

type TeamRow = {
    id: number;
    slug: string;
    name: string;
    color: string;
    open_workstreams: number;
    started_workstreams: number;
    blocked_workstreams: number;
};

type ProjectRow = {
    uuid: string;
    slug: string;
    name: string;
    color: string;
    status: ProjectStatus;
    health: ProjectHealth;
    target_date: string | null;
    percent: number;
    issues: number;
    product: { key: string; name: string } | null;
};

type Velocity = {
    cycle: { id: number; uuid: string; number: number; name: string };
    committed: number;
    completed: number;
    points: number;
};

type Report = {
    since: string;
    issues?: Figures;
    throughput?: ThroughputWeek[];
    teams?: TeamRow[];
    cross_team?: CrossTeam;
    projects?: {
        active: number;
        at_risk: number;
        overdue: number;
        rows: ProjectRow[];
    };
    overdue?: { count: number; issues: IssueListItem[] };
    by_module?: {
        id: number;
        name: string;
        color: string | null;
        open: number;
        completed: number;
    }[];
    releases?: {
        uuid: string;
        name: string;
        status: string;
        planned_at: string | null;
        issues_count: number;
        completed_issues_count: number;
    }[];
    progress?: {
        percent: number;
        total: number;
        completed: number;
        started: number;
        canceled: number;
    };
    milestones?: {
        uuid: string;
        name: string;
        target_date: string | null;
        status: string;
        issues_count: number;
        completed_issues_count: number;
    }[];
    blockers?: number;
    target?: {
        date: string | null;
        days_left: number | null;
        on_track: boolean | null;
    };
    workstreams?: {
        open: number;
        started: number;
        blocked: number;
        completed_since: number;
    };
    lead_time_hours?: number | null;
    cycle_time_hours?: number | null;
    velocity?: Velocity[];
    workload?: { id: number; name: string; open: number; points: number }[];
    completed_per_week?: { week: string; completed: number }[];
    waiting_on_others?: number;
};

const props = defineProps<{
    scope: Scope;
    report?: Report;
    options: {
        products: ProductSummary[];
        projects: ProjectSummary[];
        teams: TeamSummary[];
    };
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? "");
const today = new Date();

/* ---- scope and range controls ------------------------------------------------------- */

const scopeType = ref<ScopeType>(props.scope.type);
const scopeId = ref<string>(props.scope.id ?? "");

const entities = computed(() => {
    switch (scopeType.value) {
        case "product":
            return props.options.products.map((p) => ({
                id: p.slug,
                label: `${p.key} · ${p.name}`,
            }));
        case "project":
            return props.options.projects.map((p) => ({
                id: p.slug,
                label: p.product ? `${p.product.key} · ${p.name}` : p.name,
            }));
        case "team":
            return props.options.teams.map((t) => ({
                id: t.slug,
                label: t.name,
            }));
        default:
            return [];
    }
});

/** The controller sends Carbon's fractional diff; the API itself takes whole days. */
const days = computed(() => Math.round(props.scope.days));
const activePreset = computed(() => presetForDays(days.value, today));

const visit = (
    next: Partial<{ type: ScopeType; id: string; days: number }>,
) => {
    const type = next.type ?? scopeType.value;
    const id = next.id ?? scopeId.value;

    if (type !== "organization" && !id) {
        return;
    }

    router.get(
        reportsIndex.url(
            { current_organization: slug.value },
            {
                query: {
                    scope: type,
                    ...(type !== "organization" ? { id } : {}),
                    days: next.days ?? days.value,
                },
            },
        ),
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
};

const selected = (event: Event) => (event.target as HTMLSelectElement).value;

const changeType = (event: Event) => {
    const type = selected(event) as ScopeType;
    scopeType.value = type;
    scopeId.value =
        type === "organization" ? "" : (entities.value[0]?.id ?? "");
    visit({ type, id: scopeId.value });
};

const changeEntity = (event: Event) => {
    scopeId.value = selected(event);
    visit({ id: scopeId.value });
};

const changeRange = (key: string) => {
    const preset = RANGE_PRESETS.find((p) => p.key === key);

    if (preset) {
        visit({ days: preset.days(today) });
    }
};

useKeyboard(
    () =>
        RANGE_PRESETS.map((preset, i) => ({
            keys: String(i + 1),
            description: preset.label,
            group: "Reports",
            handler: () => changeRange(preset.key),
        })),
    { scope: "Reports", priority: PRIORITY.page },
);

/* ---- derived figures ---------------------------------------------------------------- */

const report = computed(() => props.report);
const isTeam = computed(() => props.scope.type === "team");

const figures = computed(() => report.value?.issues ?? null);
const perWeek = computed(() =>
    throughputPerWeek(report.value?.throughput ?? []),
);
const breakdown = computed(() =>
    figures.value
        ? categoryBreakdown({
              backlog: figures.value.backlog,
              unstarted: figures.value.unstarted,
              started: figures.value.started,
              completed: figures.value.completed,
              canceled: figures.value.canceled,
          })
        : [],
);
const breakdownTotal = computed(() =>
    breakdown.value.reduce((sum, s) => sum + s.count, 0),
);

const teamWeeks = computed(() =>
    padWeeks(report.value?.completed_per_week ?? [], 8, today),
);
const teamPerWeek = computed(() =>
    teamWeeks.value.length
        ? teamWeeks.value.reduce((s, w) => s + w.completed, 0) /
          teamWeeks.value.length
        : 0,
);

const rangeLabel = computed(() => {
    const preset = RANGE_PRESETS.find((p) => p.key === activePreset.value);

    return preset ? preset.label.toLowerCase() : `last ${days.value} days`;
});

const formatNumber = (value: number, digits = 1) =>
    Number.isInteger(value)
        ? String(value)
        : value.toLocaleString(undefined, { maximumFractionDigits: digits });

type Tile = {
    key: string;
    label: string;
    value: string;
    hint: string;
    tone?: "warning" | "destructive" | "success";
};

const tiles = computed<Tile[]>(() => {
    const r = report.value;

    if (!r) {
        return [];
    }

    if (isTeam.value && r.workstreams) {
        return [
            {
                key: "open",
                label: "Open workstreams",
                value: String(r.workstreams.open),
                hint: `${r.workstreams.started} in progress`,
            },
            {
                key: "completed",
                label: "Completed",
                value: String(r.workstreams.completed_since),
                hint: `workstreams, ${rangeLabel.value}`,
            },
            {
                key: "throughput",
                label: "Throughput",
                value: formatNumber(teamPerWeek.value),
                hint: "workstreams per week, 8 weeks",
            },
            {
                key: "cycle",
                label: "Cycle time",
                value: formatHours(r.cycle_time_hours) ?? "—",
                hint: r.lead_time_hours
                    ? `lead time ${formatHours(r.lead_time_hours)}`
                    : "started to completed",
            },
            {
                key: "blocked",
                label: "Blocked",
                value: String(r.workstreams.blocked),
                hint: "waiting on another team",
                tone: r.workstreams.blocked > 0 ? "warning" : undefined,
            },
        ];
    }

    const f = r.issues;

    if (!f) {
        return [];
    }

    const overdue = r.overdue?.count ?? null;

    return [
        {
            key: "open",
            label: "Open issues",
            value: String(f.open),
            hint: `${f.started} in progress`,
        },
        {
            key: "completed",
            label: "Completed",
            value: String(f.completed_since),
            hint: `issues, ${rangeLabel.value}`,
        },
        {
            key: "throughput",
            label: "Throughput",
            value: formatNumber(perWeek.value),
            hint: "issues per week, 8 weeks",
        },
        {
            key: "created",
            label: "Created",
            value: String(f.created_since),
            hint: `issues, ${rangeLabel.value}`,
        },
        overdue !== null
            ? {
                  key: "overdue",
                  label: "Overdue",
                  value: String(overdue),
                  hint: "past their due date",
                  tone: overdue > 0 ? "destructive" : undefined,
              }
            : r.blockers !== undefined
              ? {
                    key: "blocked",
                    label: "Blocked",
                    value: String(r.blockers),
                    hint: "issues waiting on a blocker",
                    tone: r.blockers > 0 ? "warning" : undefined,
                }
              : {
                    key: "multi",
                    label: "Cross-team",
                    value: String(r.cross_team?.multi_team_open ?? 0),
                    hint: "open issues with 2+ teams",
                },
    ];
});

/* ---- throughput chart (inline SVG) ---------------------------------------------------- */

const W = 420;
const H = 176;
const PAD = { top: 10, right: 8, bottom: 22, left: 26 };

const throughput = computed(() => report.value?.throughput ?? []);
const maxY = computed(() =>
    Math.max(
        ...throughput.value.map((w) => Math.max(w.created, w.completed)),
        1,
    ),
);
const ticksY = computed(() => niceTicks(0, maxY.value, 3));
const y = computed(() =>
    linearScale(
        [0, ticksY.value.at(-1) ?? maxY.value],
        [H - PAD.bottom, PAD.top],
    ),
);
const slot = computed(
    () => (W - PAD.left - PAD.right) / Math.max(throughput.value.length, 1),
);
const barW = computed(() => Math.min(18, slot.value * 0.3));

const wrapper = ref<HTMLElement | null>(null);
const hover = ref<{ i: number; px: number; py: number } | null>(null);

const onMove = (event: MouseEvent) => {
    const el = wrapper.value;

    if (!el || throughput.value.length === 0) {
        return;
    }

    const rect = el.getBoundingClientRect();
    const px = event.clientX - rect.left;
    const chartX = (px / rect.width) * W;
    const i = Math.min(
        Math.max(Math.floor((chartX - PAD.left) / slot.value), 0),
        throughput.value.length - 1,
    );

    hover.value = { i, px, py: event.clientY - rect.top };
};

const issueUrl = (identifier: string) => `/${slug.value}/issues/${identifier}`;
const categoryLabel = (category: StatusCategory) =>
    CATEGORY_META[category].label;
</script>

<template>
    <Head title="Reports" />

    <div class="flex h-full min-h-0 flex-col">
        <header
            class="shrink-0 border-b bg-background/95 px-4 pt-3 pb-3 backdrop-blur sm:px-6"
        >
            <Breadcrumbs
                :items="[
                    { label: 'Reports', href: `/${slug}/reports` },
                    { label: scope.name },
                ]"
                class="pl-9 md:pl-0"
            />
            <div
                class="mt-2 flex flex-wrap items-end justify-between gap-x-4 gap-y-3"
            >
                <div class="min-w-0">
                    <h1
                        class="truncate font-display text-xl leading-tight font-semibold tracking-[-0.01em]"
                    >
                        {{ scope.name }}
                    </h1>
                    <p class="text-[12px] text-muted-foreground">
                        Issues are counted once however many teams work on them.
                        Team figures count workstreams.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <NativeSelect
                        :model-value="scopeType"
                        class="h-7 py-0 text-[12px]"
                        aria-label="Report scope"
                        @change="changeType"
                    >
                        <NativeSelectOption value="organization"
                            >Organization</NativeSelectOption
                        >
                        <NativeSelectOption
                            value="product"
                            :disabled="options.products.length === 0"
                            >Product</NativeSelectOption
                        >
                        <NativeSelectOption
                            value="project"
                            :disabled="options.projects.length === 0"
                            >Project</NativeSelectOption
                        >
                        <NativeSelectOption
                            value="team"
                            :disabled="options.teams.length === 0"
                            >Team</NativeSelectOption
                        >
                    </NativeSelect>
                    <NativeSelect
                        v-if="scopeType !== 'organization'"
                        :model-value="scopeId"
                        class="h-7 max-w-56 py-0 text-[12px]"
                        :aria-label="`Which ${scopeType}`"
                        @change="changeEntity"
                    >
                        <NativeSelectOption
                            v-for="entity in entities"
                            :key="entity.id"
                            :value="entity.id"
                            >{{ entity.label }}</NativeSelectOption
                        >
                    </NativeSelect>
                    <ToggleGroup
                        type="single"
                        :model-value="activePreset ?? ''"
                        size="sm"
                        variant="outline"
                        aria-label="Date range"
                        @update:model-value="(v) => v && changeRange(String(v))"
                    >
                        <ToggleGroupItem
                            v-for="preset in RANGE_PRESETS"
                            :key="preset.key"
                            :value="preset.key"
                            class="h-7 px-2 text-[12px]"
                            >{{
                                preset.label.replace("Last ", "")
                            }}</ToggleGroupItem
                        >
                    </ToggleGroup>
                </div>
            </div>
        </header>

        <div
            class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6"
        >
            <Deferred data="report">
                <template #fallback>
                    <div class="mx-auto max-w-6xl space-y-6" aria-busy="true">
                        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                            <div
                                v-for="n in 5"
                                :key="n"
                                class="h-20 shimmer rounded-xl"
                            />
                        </div>
                        <div class="grid gap-4 lg:grid-cols-5">
                            <div
                                class="h-56 shimmer rounded-xl lg:col-span-3"
                            />
                            <div
                                class="h-56 shimmer rounded-xl lg:col-span-2"
                            />
                        </div>
                        <div class="h-64 shimmer rounded-xl" />
                        <p class="sr-only">Loading the report</p>
                    </div>
                </template>

                <div v-if="report" class="mx-auto max-w-6xl space-y-8">
                    <!-- Summary tiles -->
                    <div
                        class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5"
                        role="list"
                        aria-label="Summary"
                    >
                        <div
                            v-for="tile in tiles"
                            :key="tile.key"
                            role="listitem"
                            class="rounded-xl border bg-card px-4 py-3"
                        >
                            <p
                                class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ tile.label }}
                            </p>
                            <p
                                :class="
                                    cn(
                                        'mt-1 font-display text-2xl leading-none font-semibold tabular-nums',
                                        tile.tone === 'destructive' &&
                                            'text-destructive',
                                        tile.tone === 'warning' &&
                                            'text-warning',
                                        tile.tone === 'success' &&
                                            'text-success',
                                    )
                                "
                            >
                                {{ tile.value }}
                            </p>
                            <p class="mt-1 text-[11.5px] text-muted-foreground">
                                {{ tile.hint }}
                            </p>
                        </div>
                    </div>

                    <!-- Team scope: velocity + completed per week -->
                    <div v-if="isTeam" class="grid gap-6 lg:grid-cols-5">
                        <OverviewSection
                            label="Completed per week"
                            hint="workstreams, last 8 weeks"
                            class="lg:col-span-3"
                        >
                            <div class="rounded-xl border bg-card p-4">
                                <div
                                    class="flex items-end justify-between gap-4"
                                >
                                    <p
                                        class="font-display text-2xl leading-none font-semibold tabular-nums"
                                    >
                                        {{ formatNumber(teamPerWeek) }}
                                        <span
                                            class="text-[12px] font-normal text-muted-foreground"
                                            >/ week</span
                                        >
                                    </p>
                                    <Sparkline
                                        :values="
                                            teamWeeks.map((w) => w.completed)
                                        "
                                        :width="160"
                                        :height="36"
                                    />
                                </div>
                                <div class="mt-3 grid grid-cols-8 gap-1">
                                    <div
                                        v-for="w in teamWeeks"
                                        :key="w.week"
                                        class="flex flex-col items-center gap-1"
                                    >
                                        <div
                                            class="flex h-16 w-full items-end rounded-sm bg-muted/60"
                                        >
                                            <div
                                                class="w-full rounded-sm bg-brand transition-[height]"
                                                :style="{
                                                    height: `${Math.max(
                                                        (w.completed /
                                                            Math.max(
                                                                ...teamWeeks.map(
                                                                    (x) =>
                                                                        x.completed,
                                                                ),
                                                                1,
                                                            )) *
                                                            100,
                                                        w.completed > 0 ? 6 : 0,
                                                    )}%`,
                                                }"
                                                :title="`${w.label}: ${w.completed}`"
                                            />
                                        </div>
                                        <span
                                            class="text-[10px] text-muted-foreground tabular-nums"
                                            >{{ w.label }}</span
                                        >
                                    </div>
                                </div>
                                <table class="sr-only">
                                    <caption>
                                        Workstreams completed per week
                                    </caption>
                                    <thead>
                                        <tr>
                                            <th>Week</th>
                                            <th>Completed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="w in teamWeeks"
                                            :key="w.week"
                                        >
                                            <td>{{ w.label }}</td>
                                            <td>{{ w.completed }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </OverviewSection>
                        <OverviewSection
                            label="Velocity"
                            hint="completed cycles"
                            class="lg:col-span-2"
                        >
                            <div class="rounded-xl border bg-card p-4">
                                <VelocityBars
                                    v-if="(report.velocity ?? []).length > 0"
                                    :cycles="report.velocity ?? []"
                                    :height="150"
                                />
                                <p
                                    v-else
                                    class="py-10 text-center text-[12.5px] text-muted-foreground"
                                >
                                    No completed cycles yet.
                                </p>
                            </div>
                        </OverviewSection>
                    </div>

                    <!-- Issue scopes: throughput + breakdown -->
                    <div v-else class="grid gap-6 lg:grid-cols-5">
                        <OverviewSection
                            label="Created vs completed"
                            hint="issues per week, last 8 weeks"
                            class="lg:col-span-3"
                        >
                            <div
                                ref="wrapper"
                                class="relative rounded-xl border bg-card p-3"
                                @mousemove="onMove"
                                @mouseleave="hover = null"
                            >
                                <svg
                                    :viewBox="`0 0 ${W} ${H}`"
                                    preserveAspectRatio="none"
                                    class="h-44 w-full"
                                    role="img"
                                    aria-labelledby="throughput-title"
                                >
                                    <title id="throughput-title">
                                        Issues created and completed per week
                                    </title>
                                    <g class="text-border">
                                        <line
                                            v-for="t in ticksY"
                                            :key="t"
                                            :x1="PAD.left"
                                            :x2="W - PAD.right"
                                            :y1="y(t)"
                                            :y2="y(t)"
                                            stroke="currentColor"
                                            :opacity="t === 0 ? 1 : 0.6"
                                        />
                                    </g>
                                    <g
                                        class="fill-muted-foreground"
                                        font-size="10"
                                    >
                                        <text
                                            v-for="t in ticksY"
                                            :key="`l-${t}`"
                                            :x="PAD.left - 6"
                                            :y="y(t) + 3"
                                            text-anchor="end"
                                        >
                                            {{ t }}
                                        </text>
                                    </g>
                                    <g
                                        v-for="(w, i) in throughput"
                                        :key="w.week"
                                    >
                                        <rect
                                            :x="
                                                PAD.left +
                                                i * slot +
                                                slot / 2 -
                                                barW -
                                                1
                                            "
                                            :y="y(w.created)"
                                            :width="barW"
                                            :height="
                                                Math.max(y(0) - y(w.created), 0)
                                            "
                                            rx="2"
                                            fill="var(--muted-foreground)"
                                            opacity="0.35"
                                        />
                                        <rect
                                            :x="
                                                PAD.left +
                                                i * slot +
                                                slot / 2 +
                                                1
                                            "
                                            :y="y(w.completed)"
                                            :width="barW"
                                            :height="
                                                Math.max(
                                                    y(0) - y(w.completed),
                                                    0,
                                                )
                                            "
                                            rx="2"
                                            fill="var(--brand)"
                                            :opacity="
                                                hover && hover.i !== i ? 0.6 : 1
                                            "
                                        />
                                        <text
                                            :x="PAD.left + i * slot + slot / 2"
                                            :y="H - 6"
                                            text-anchor="middle"
                                            font-size="10"
                                            class="fill-muted-foreground"
                                        >
                                            {{ weekLabel(w.week) }}
                                        </text>
                                    </g>
                                </svg>

                                <ChartTooltip
                                    v-if="hover && throughput[hover.i]"
                                    :x="hover.px"
                                    :y="hover.py"
                                    :width="wrapper?.clientWidth ?? W"
                                    :title="`Week of ${weekLabel(throughput[hover.i].week)}`"
                                    :rows="[
                                        {
                                            label: 'Created',
                                            value: throughput[hover.i].created,
                                            tone: 'var(--muted-foreground)',
                                        },
                                        {
                                            label: 'Completed',
                                            value: throughput[hover.i]
                                                .completed,
                                            tone: 'var(--brand)',
                                        },
                                    ]"
                                />

                                <div
                                    class="mt-1 flex items-center gap-4 px-1 text-[11px] text-muted-foreground"
                                >
                                    <span class="flex items-center gap-1.5"
                                        ><span
                                            class="size-2 rounded-sm bg-muted-foreground/40"
                                        />Created</span
                                    >
                                    <span class="flex items-center gap-1.5"
                                        ><span
                                            class="size-2 rounded-sm bg-brand"
                                        />Completed</span
                                    >
                                </div>

                                <table class="sr-only">
                                    <caption>
                                        Issues created and completed per week
                                    </caption>
                                    <thead>
                                        <tr>
                                            <th>Week</th>
                                            <th>Created</th>
                                            <th>Completed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="w in throughput"
                                            :key="w.week"
                                        >
                                            <td>{{ weekLabel(w.week) }}</td>
                                            <td>{{ w.created }}</td>
                                            <td>{{ w.completed }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </OverviewSection>

                        <OverviewSection
                            label="By status"
                            :hint="`${breakdownTotal} issues`"
                            class="lg:col-span-2"
                        >
                            <div class="rounded-xl border bg-card p-4">
                                <div
                                    v-if="breakdown.length > 0"
                                    class="flex h-3 w-full overflow-hidden rounded-full bg-muted"
                                    role="img"
                                    aria-label="Share of issues per status category"
                                >
                                    <div
                                        v-for="segment in breakdown"
                                        :key="segment.category"
                                        :style="{
                                            width: `${segment.share * 100}%`,
                                            background: `var(--${CATEGORY_META[segment.category].token})`,
                                        }"
                                        :title="`${categoryLabel(segment.category)}: ${segment.count}`"
                                    />
                                </div>
                                <p
                                    v-else
                                    class="py-6 text-center text-[12.5px] text-muted-foreground"
                                >
                                    No issues in this scope yet.
                                </p>
                                <ul class="mt-3 space-y-1.5">
                                    <li
                                        v-for="segment in breakdown"
                                        :key="segment.category"
                                        class="flex items-center justify-between gap-3 text-[12.5px]"
                                    >
                                        <span class="flex items-center gap-2">
                                            <StatusIcon
                                                :category="segment.category"
                                                :size="13"
                                            />
                                            {{
                                                categoryLabel(segment.category)
                                            }}
                                        </span>
                                        <span
                                            class="text-muted-foreground tabular-nums"
                                            >{{ segment.count }}
                                            <span class="text-[11px]"
                                                >·
                                                {{
                                                    Math.round(
                                                        segment.share * 100,
                                                    )
                                                }}%</span
                                            ></span
                                        >
                                    </li>
                                </ul>
                                <table class="sr-only">
                                    <caption>
                                        Issues per status category
                                    </caption>
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Issues</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="segment in breakdown"
                                            :key="segment.category"
                                        >
                                            <td>
                                                {{
                                                    categoryLabel(
                                                        segment.category,
                                                    )
                                                }}
                                            </td>
                                            <td>{{ segment.count }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </OverviewSection>
                    </div>

                    <!-- Project scope: progress, target, milestones -->
                    <div
                        v-if="report.progress"
                        class="grid gap-6 lg:grid-cols-5"
                    >
                        <OverviewSection
                            label="Progress"
                            hint="issues"
                            class="lg:col-span-2"
                        >
                            <div
                                class="flex items-center gap-5 rounded-xl border bg-card p-4"
                            >
                                <ProgressRing
                                    :value="report.progress.percent / 100"
                                    :size="72"
                                    :stroke="7"
                                    :tone="
                                        report.target?.on_track === false
                                            ? 'warning'
                                            : 'brand'
                                    "
                                />
                                <div class="space-y-1 text-[12.5px]">
                                    <p
                                        class="font-display text-2xl leading-none font-semibold tabular-nums"
                                    >
                                        {{
                                            Math.round(report.progress.percent)
                                        }}%
                                    </p>
                                    <p class="text-muted-foreground">
                                        {{ report.progress.completed }} of
                                        {{ report.progress.total }} issues done,
                                        {{ report.progress.started }} in
                                        progress
                                    </p>
                                    <p
                                        v-if="report.target?.date"
                                        :class="
                                            cn(
                                                'flex items-center gap-1.5',
                                                report.target.on_track === false
                                                    ? 'text-warning'
                                                    : 'text-success',
                                            )
                                        "
                                    >
                                        <CalendarClock class="size-3.5" />
                                        <template
                                            v-if="
                                                (report.target.days_left ?? 0) <
                                                0
                                            "
                                            >{{
                                                Math.abs(
                                                    report.target.days_left ??
                                                        0,
                                                )
                                            }}
                                            days past target</template
                                        >
                                        <template v-else
                                            >{{ report.target.days_left }} days
                                            to target ·
                                            {{
                                                report.target.on_track
                                                    ? "on track"
                                                    : "behind"
                                            }}</template
                                        >
                                    </p>
                                </div>
                            </div>
                        </OverviewSection>
                        <OverviewSection
                            label="Milestones"
                            :hint="`${(report.milestones ?? []).length}`"
                            class="lg:col-span-3"
                        >
                            <div
                                class="divide-y rounded-xl border bg-card text-[12.5px]"
                            >
                                <div
                                    v-for="m in report.milestones"
                                    :key="m.uuid"
                                    class="flex items-center gap-3 px-4 py-2.5"
                                >
                                    <ProgressRing
                                        :value="
                                            m.issues_count
                                                ? m.completed_issues_count /
                                                  m.issues_count
                                                : 0
                                        "
                                        :tone="
                                            m.status === 'missed'
                                                ? 'warning'
                                                : 'brand'
                                        "
                                    />
                                    <span class="min-w-0 flex-1 truncate">{{
                                        m.name
                                    }}</span>
                                    <span
                                        class="text-muted-foreground tabular-nums"
                                        >{{ m.completed_issues_count }}/{{
                                            m.issues_count
                                        }}</span
                                    >
                                    <DueDateBadge
                                        :date="m.target_date"
                                        :category="
                                            m.status === 'completed'
                                                ? 'completed'
                                                : 'started'
                                        "
                                        compact
                                    />
                                </div>
                                <p
                                    v-if="
                                        (report.milestones ?? []).length === 0
                                    "
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No milestones on this project.
                                </p>
                            </div>
                        </OverviewSection>
                    </div>

                    <!-- Organization scope: teams -->
                    <OverviewSection
                        v-if="report.teams"
                        label="Teams"
                        hint="workstreams, not issues"
                    >
                        <div class="overflow-hidden rounded-xl border bg-card">
                            <table class="w-full text-[12.5px]">
                                <thead
                                    class="bg-muted/40 text-[11px] text-muted-foreground uppercase"
                                >
                                    <tr>
                                        <th
                                            class="px-4 py-2 text-left font-medium"
                                        >
                                            Team
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Open workstreams
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            In progress
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Blocked
                                        </th>
                                        <th
                                            class="w-40 px-4 py-2 text-left font-medium"
                                        >
                                            Load
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="team in report.teams"
                                        :key="team.id"
                                    >
                                        <td class="px-4 py-2">
                                            <Link
                                                :href="`/${slug}/teams/${team.slug}`"
                                                class="flex items-center gap-2 hover:underline"
                                            >
                                                <span
                                                    class="size-2 rounded-full"
                                                    :style="{
                                                        background: team.color,
                                                    }"
                                                />
                                                {{ team.name }}
                                            </Link>
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{ team.open_workstreams }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{ team.started_workstreams }}
                                        </td>
                                        <td
                                            :class="
                                                cn(
                                                    'px-4 py-2 text-right tabular-nums',
                                                    team.blocked_workstreams >
                                                        0 && 'text-warning',
                                                )
                                            "
                                        >
                                            {{ team.blocked_workstreams }}
                                        </td>
                                        <td class="px-4 py-2">
                                            <div
                                                class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
                                            >
                                                <div
                                                    class="h-full rounded-full bg-brand"
                                                    :style="{
                                                        width: `${
                                                            (team.open_workstreams /
                                                                Math.max(
                                                                    ...report.teams.map(
                                                                        (t) =>
                                                                            t.open_workstreams,
                                                                    ),
                                                                    1,
                                                                )) *
                                                            100
                                                        }%`,
                                                    }"
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </OverviewSection>

                    <!-- Team scope: workload -->
                    <OverviewSection
                        v-if="report.workload"
                        label="Workload"
                        hint="open workstreams per member"
                    >
                        <div class="overflow-hidden rounded-xl border bg-card">
                            <table class="w-full text-[12.5px]">
                                <thead
                                    class="bg-muted/40 text-[11px] text-muted-foreground uppercase"
                                >
                                    <tr>
                                        <th
                                            class="px-4 py-2 text-left font-medium"
                                        >
                                            Member
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Open
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Points
                                        </th>
                                        <th
                                            class="w-40 px-4 py-2 text-left font-medium"
                                        >
                                            Load
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="row in report.workload"
                                        :key="row.id"
                                    >
                                        <td class="px-4 py-2">
                                            {{ row.name }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{ row.open }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{ formatNumber(row.points) }}
                                        </td>
                                        <td class="px-4 py-2">
                                            <div
                                                class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
                                            >
                                                <div
                                                    class="h-full rounded-full bg-brand"
                                                    :style="{
                                                        width: `${
                                                            (row.open /
                                                                Math.max(
                                                                    ...report.workload.map(
                                                                        (r) =>
                                                                            r.open,
                                                                    ),
                                                                    1,
                                                                )) *
                                                            100
                                                        }%`,
                                                    }"
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="report.workload.length === 0">
                                        <td
                                            colspan="4"
                                            class="px-4 py-6 text-center text-muted-foreground"
                                        >
                                            Nothing assigned right now.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </OverviewSection>

                    <!-- Product scope: modules and releases -->
                    <div
                        v-if="report.by_module || report.releases"
                        class="grid gap-6 lg:grid-cols-2"
                    >
                        <OverviewSection
                            v-if="report.by_module"
                            label="Modules"
                            hint="issues"
                        >
                            <div
                                class="divide-y rounded-xl border bg-card text-[12.5px]"
                            >
                                <div
                                    v-for="m in report.by_module"
                                    :key="m.id"
                                    class="flex items-center gap-3 px-4 py-2.5"
                                >
                                    <span
                                        class="size-2 rounded-full"
                                        :style="{
                                            background:
                                                m.color ??
                                                'var(--muted-foreground)',
                                        }"
                                    />
                                    <span class="min-w-0 flex-1 truncate">{{
                                        m.name
                                    }}</span>
                                    <span
                                        class="text-muted-foreground tabular-nums"
                                        >{{ m.open }} open ·
                                        {{ m.completed }} done</span
                                    >
                                </div>
                                <p
                                    v-if="report.by_module.length === 0"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No issues are filed under a module yet.
                                </p>
                            </div>
                        </OverviewSection>
                        <OverviewSection
                            v-if="report.releases"
                            label="Releases"
                            hint="issues"
                        >
                            <div
                                class="divide-y rounded-xl border bg-card text-[12.5px]"
                            >
                                <div
                                    v-for="r in report.releases"
                                    :key="r.uuid"
                                    class="flex items-center gap-3 px-4 py-2.5"
                                >
                                    <ProgressRing
                                        :value="
                                            r.issues_count
                                                ? r.completed_issues_count /
                                                  r.issues_count
                                                : 0
                                        "
                                        :tone="
                                            r.status === 'released'
                                                ? 'success'
                                                : 'brand'
                                        "
                                    />
                                    <span class="min-w-0 flex-1 truncate">{{
                                        r.name
                                    }}</span>
                                    <span
                                        class="text-muted-foreground tabular-nums"
                                        >{{ r.completed_issues_count }}/{{
                                            r.issues_count
                                        }}</span
                                    >
                                    <span
                                        class="text-[11px] text-muted-foreground"
                                        >{{ r.planned_at ?? "" }}</span
                                    >
                                </div>
                                <p
                                    v-if="report.releases.length === 0"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No releases planned.
                                </p>
                            </div>
                        </OverviewSection>
                    </div>

                    <!-- Projects table (organization and product) -->
                    <OverviewSection
                        v-if="report.projects"
                        label="Projects"
                        :hint="`${report.projects.active} active · ${report.projects.at_risk} at risk · ${report.projects.overdue} overdue`"
                    >
                        <div class="overflow-hidden rounded-xl border bg-card">
                            <table class="w-full text-[12.5px]">
                                <thead
                                    class="bg-muted/40 text-[11px] text-muted-foreground uppercase"
                                >
                                    <tr>
                                        <th
                                            class="px-4 py-2 text-left font-medium"
                                        >
                                            Project
                                        </th>
                                        <th
                                            class="px-4 py-2 text-left font-medium"
                                        >
                                            Status
                                        </th>
                                        <th
                                            class="px-4 py-2 text-left font-medium"
                                        >
                                            Health
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Issues
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Done
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Target
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="p in report.projects.rows"
                                        :key="p.uuid"
                                    >
                                        <td class="px-4 py-2">
                                            <Link
                                                :href="`/${slug}/projects/${p.slug}`"
                                                class="flex items-center gap-2 hover:underline"
                                            >
                                                <span
                                                    class="size-2 rounded-full"
                                                    :style="{
                                                        background: p.color,
                                                    }"
                                                />
                                                <span class="truncate">{{
                                                    p.name
                                                }}</span>
                                                <span
                                                    v-if="p.product"
                                                    class="font-mono text-[10.5px] text-muted-foreground"
                                                    >{{ p.product.key }}</span
                                                >
                                            </Link>
                                        </td>
                                        <td class="px-4 py-2">
                                            <span
                                                class="flex items-center gap-1.5"
                                            >
                                                <span
                                                    :class="
                                                        cn(
                                                            'size-1.5 rounded-full',
                                                            PROJECT_STATUS_META[
                                                                p.status
                                                            ].dot,
                                                        )
                                                    "
                                                />
                                                {{
                                                    PROJECT_STATUS_META[
                                                        p.status
                                                    ].label
                                                }}
                                            </span>
                                        </td>
                                        <td
                                            :class="
                                                cn(
                                                    'px-4 py-2',
                                                    PROJECT_HEALTH_META[
                                                        p.health
                                                    ].className,
                                                )
                                            "
                                        >
                                            {{
                                                PROJECT_HEALTH_META[p.health]
                                                    .label
                                            }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{ p.issues }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{ Math.round(p.percent) }}%
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            <DueDateBadge
                                                :date="p.target_date"
                                                :category="
                                                    p.status === 'completed' ||
                                                    p.status === 'canceled'
                                                        ? 'completed'
                                                        : 'started'
                                                "
                                                compact
                                            />
                                        </td>
                                    </tr>
                                    <tr
                                        v-if="report.projects.rows.length === 0"
                                    >
                                        <td
                                            colspan="6"
                                            class="px-4 py-6 text-center text-muted-foreground"
                                        >
                                            No projects in this scope.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </OverviewSection>

                    <!-- Cross-team -->
                    <OverviewSection
                        v-if="report.cross_team"
                        label="Across teams"
                        hint="issues are counted once; team rows count workstreams"
                    >
                        <div class="grid gap-4 lg:grid-cols-5">
                            <div
                                class="grid gap-3 sm:grid-cols-2 lg:col-span-2"
                            >
                                <div
                                    class="rounded-xl border bg-card px-4 py-3"
                                >
                                    <p
                                        class="text-[11px] text-muted-foreground uppercase"
                                    >
                                        Multi-team issues
                                    </p>
                                    <p
                                        class="mt-1 font-display text-xl font-semibold tabular-nums"
                                    >
                                        {{ report.cross_team.multi_team_open }}
                                        <span
                                            class="text-[11.5px] font-normal text-muted-foreground"
                                            >open of
                                            {{
                                                report.cross_team
                                                    .multi_team_issues
                                            }}</span
                                        >
                                    </p>
                                </div>
                                <div
                                    class="rounded-xl border bg-card px-4 py-3"
                                >
                                    <p
                                        class="text-[11px] text-muted-foreground uppercase"
                                    >
                                        Blocked by another team
                                    </p>
                                    <p
                                        :class="
                                            cn(
                                                'mt-1 font-display text-xl font-semibold tabular-nums',
                                                report.cross_team
                                                    .blocked_by_another_team >
                                                    0 && 'text-warning',
                                            )
                                        "
                                    >
                                        {{
                                            report.cross_team
                                                .blocked_by_another_team
                                        }}
                                        <span
                                            class="text-[11.5px] font-normal text-muted-foreground"
                                            >issues ·
                                            {{
                                                report.cross_team
                                                    .waiting_workstreams
                                            }}
                                            workstreams</span
                                        >
                                    </p>
                                </div>
                                <div
                                    class="rounded-xl border bg-card px-4 py-3"
                                >
                                    <p
                                        class="text-[11px] text-muted-foreground uppercase"
                                    >
                                        Handoff time
                                    </p>
                                    <p
                                        class="mt-1 font-display text-xl font-semibold tabular-nums"
                                    >
                                        {{
                                            formatHours(
                                                report.cross_team
                                                    .avg_handoff_hours,
                                            ) ?? "—"
                                        }}
                                        <span
                                            class="text-[11.5px] font-normal text-muted-foreground"
                                            >avg over
                                            {{ report.cross_team.handoffs }}
                                            handoffs</span
                                        >
                                    </p>
                                </div>
                                <div
                                    class="rounded-xl border bg-card px-4 py-3"
                                >
                                    <p
                                        class="text-[11px] text-muted-foreground uppercase"
                                    >
                                        Ready to complete
                                    </p>
                                    <p
                                        :class="
                                            cn(
                                                'mt-1 font-display text-xl font-semibold tabular-nums',
                                                report.cross_team
                                                    .ready_to_complete > 0 &&
                                                    'text-success',
                                            )
                                        "
                                    >
                                        {{
                                            report.cross_team.ready_to_complete
                                        }}
                                        <span
                                            class="text-[11.5px] font-normal text-muted-foreground"
                                            >issues with all teams done</span
                                        >
                                    </p>
                                </div>
                            </div>
                            <div
                                class="overflow-hidden rounded-xl border bg-card lg:col-span-3"
                            >
                                <table class="w-full text-[12.5px]">
                                    <caption class="sr-only">
                                        Workstreams per team on multi-team
                                        issues
                                    </caption>
                                    <thead
                                        class="bg-muted/40 text-[11px] text-muted-foreground uppercase"
                                    >
                                        <tr>
                                            <th
                                                class="px-4 py-2 text-left font-medium"
                                            >
                                                Team
                                            </th>
                                            <th
                                                class="px-4 py-2 text-right font-medium"
                                            >
                                                Workstreams
                                            </th>
                                            <th
                                                class="px-4 py-2 text-right font-medium"
                                            >
                                                Done
                                            </th>
                                            <th
                                                class="px-4 py-2 text-right font-medium"
                                            >
                                                Blocked
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <tr
                                            v-for="t in report.cross_team
                                                .per_team"
                                            :key="t.id"
                                        >
                                            <td class="px-4 py-2">
                                                <span
                                                    class="flex items-center gap-2"
                                                >
                                                    <span
                                                        class="size-2 rounded-full"
                                                        :style="{
                                                            background: t.color,
                                                        }"
                                                    />
                                                    {{ t.name }}
                                                </span>
                                            </td>
                                            <td
                                                class="px-4 py-2 text-right tabular-nums"
                                            >
                                                {{ t.workstreams }}
                                            </td>
                                            <td
                                                class="px-4 py-2 text-right tabular-nums"
                                            >
                                                {{ t.completed }}
                                            </td>
                                            <td
                                                :class="
                                                    cn(
                                                        'px-4 py-2 text-right tabular-nums',
                                                        t.blocked > 0 &&
                                                            'text-warning',
                                                    )
                                                "
                                            >
                                                {{ t.blocked }}
                                            </td>
                                        </tr>
                                        <tr
                                            v-if="
                                                report.cross_team.per_team
                                                    .length === 0
                                            "
                                        >
                                            <td
                                                colspan="4"
                                                class="px-4 py-6 text-center text-muted-foreground"
                                            >
                                                No issue involves more than one
                                                team yet.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </OverviewSection>

                    <!-- Overdue / at risk -->
                    <OverviewSection
                        v-if="report.overdue"
                        label="Overdue"
                        :hint="`${report.overdue.count} past their due date`"
                    >
                        <template #action>
                            <Link
                                v-if="report.overdue.count > 10"
                                :href="`/${slug}/issues?filters=${encodeURIComponent(
                                    JSON.stringify({
                                        match: 'all',
                                        groups: [
                                            {
                                                match: 'all',
                                                clauses: [
                                                    {
                                                        field: 'due_date',
                                                        op: 'overdue',
                                                        value: null,
                                                    },
                                                ],
                                            },
                                        ],
                                    }),
                                )}`"
                                class="flex items-center gap-1 text-[11.5px] text-brand hover:underline"
                                >All overdue <ArrowRight class="size-3"
                            /></Link>
                        </template>
                        <div
                            class="divide-y rounded-xl border bg-card text-[12.5px]"
                        >
                            <Link
                                v-for="issue in report.overdue.issues"
                                :key="issue.uuid"
                                :href="issueUrl(issue.identifier)"
                                class="flex items-center gap-2.5 px-3 py-2 hover:bg-accent"
                            >
                                <PriorityIcon :priority="issue.priority" />
                                <IssueIdentifier
                                    :identifier="issue.identifier"
                                />
                                <StatusIcon :status="issue.status" />
                                <span class="min-w-0 flex-1 truncate">{{
                                    issue.title
                                }}</span>
                                <AvatarStack
                                    v-if="issue.assignees.length > 0"
                                    :users="issue.assignees"
                                />
                                <DueDateBadge
                                    :date="issue.due_date"
                                    :category="issue.status_category"
                                    compact
                                />
                            </Link>
                            <p
                                v-if="report.overdue.issues.length === 0"
                                class="flex items-center justify-center gap-2 px-4 py-6 text-center text-muted-foreground"
                            >
                                <AlertTriangle class="size-3.5 opacity-50" />
                                Nothing is overdue.
                            </p>
                        </div>
                    </OverviewSection>
                </div>
            </Deferred>
        </div>
    </div>
</template>
