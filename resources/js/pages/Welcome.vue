<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, AtSign, Inbox, MessageSquareText, Workflow, Zap } from '@lucide/vue';
import { computed } from 'vue';
import TandemMark from '@/components/mail/TandemMark.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { home as mailHome } from '@/routes/mail';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const mailUrl = computed(() => (page.props.currentOrganization ? mailHome(page.props.currentOrganization.slug).url : '/'));

const features = [
    {
        icon: MessageSquareText,
        eyebrow: 'Shared inboxes',
        title: 'Shared inboxes with the conversation nobody outside ever sees.',
        body: 'Pull a thread into the team inbox, assign it, and talk about it right underneath the message. Internal comments and @mentions live in Tandem; the customer only ever sees the reply.',
        points: ['Assign to a person or a team', 'Internal comments with @mentions', 'Statuses, tags, snooze and saved views'],
    },
    {
        icon: Zap,
        eyebrow: 'Connections',
        title: 'Gmail, IMAP or plain forwarding. Mail arrives in seconds.',
        body: 'Connect the accounts you already have. Nothing changes at your provider: Tandem keeps everything in sync both ways and shows new mail the moment it lands.',
        points: ['Gmail with one click', 'Any IMAP mailbox', 'A forwarding address for shared aliases'],
    },
    {
        icon: Workflow,
        eyebrow: 'Automations',
        title: 'Rules that read like sentences and run while you sleep.',
        body: 'When a new conversation arrives, if the sender ends with @acme.com, then add the VIP tag and bump the priority. Out-of-office replies, routing to teams, tagging: all with a dry-run before you trust it.',
        points: ['Plain-English rule builder', 'Business-hours aware auto-responders', 'Full audit trail per run'],
    },
];
</script>

<template>
    <Head title="Email you love, inboxes your team runs together" />

    <div class="min-h-dvh bg-background text-foreground">
        <!-- Nav -->
        <header class="sticky top-0 z-30 border-b border-border/60 bg-background/80 backdrop-blur">
            <nav class="mx-auto flex h-14 w-full max-w-6xl items-center justify-between px-4 sm:px-6" aria-label="Main">
                <Link href="/" class="inline-flex items-center gap-2 font-semibold tracking-tight">
                    <TandemMark :size="28" />
                    Tandem
                </Link>
                <div class="flex items-center gap-1.5 sm:gap-2">
                    <template v-if="user">
                        <Button as-child class="bg-brand text-brand-foreground hover:bg-brand/90">
                            <Link :href="mailUrl">Open mail <ArrowRight /></Link>
                        </Button>
                    </template>
                    <template v-else>
                        <Button as-child variant="ghost">
                            <Link :href="login()">Log in</Link>
                        </Button>
                        <Button as-child class="bg-brand text-brand-foreground hover:bg-brand/90">
                            <Link :href="register()">Start free</Link>
                        </Button>
                    </template>
                </div>
            </nav>
        </header>

        <main>
            <!-- Hero -->
            <section class="relative overflow-hidden">
                <div class="pointer-events-none absolute inset-0 -z-0" aria-hidden="true">
                    <div class="absolute top-[-10rem] left-1/2 size-[36rem] -translate-x-1/2 rounded-full bg-brand-soft/70 blur-3xl dark:bg-brand/15" />
                    <div class="absolute top-40 right-[-8rem] size-72 rounded-full bg-highlight/20 blur-3xl" />
                </div>

                <div class="relative mx-auto grid w-full max-w-6xl gap-12 px-4 pt-16 pb-12 sm:px-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:items-center lg:pt-24 lg:pb-20">
                    <div class="animate-rise">
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/20 bg-brand-soft px-3 py-1 text-xs font-medium text-brand-soft-foreground">
                            <Inbox class="size-3.5" /> Personal mail and team inboxes, one app
                        </span>
                        <h1 class="mt-5 font-display text-5xl leading-[1.02] tracking-tight sm:text-6xl lg:text-7xl">
                            Email you love.<br />
                            Inboxes your team <span class="text-brand">runs together.</span>
                        </h1>
                        <p class="mt-6 max-w-xl text-lg leading-relaxed text-muted-foreground">
                            Tandem is the email client you keep open all day: calm, fast, and easy on the eyes. When a thread needs more than one pair of hands, share it with the team, talk about it in private, and reply as one.
                        </p>
                        <div class="mt-8 flex flex-wrap items-center gap-3">
                            <Button as-child size="lg" class="bg-brand text-brand-foreground hover:bg-brand/90">
                                <Link :href="user ? mailUrl : register()">{{ user ? 'Open your mail' : 'Create your account' }} <ArrowRight /></Link>
                            </Button>
                            <Button v-if="!user" as-child size="lg" variant="outline">
                                <Link :href="login()">I already have one</Link>
                            </Button>
                        </div>
                        <p class="mt-4 text-xs text-muted-foreground">No credit card. Your mail stays at your provider; Tandem only syncs it.</p>
                    </div>

                    <!-- Product preview -->
                    <div class="relative animate-rise [animation-delay:120ms]">
                        <svg viewBox="0 0 640 420" class="w-full drop-shadow-[0_24px_48px_-24px_oklch(0.2_0.02_60/40%)]" role="img" aria-labelledby="preview-title">
                            <title id="preview-title">A Tandem window: a shared inbox list on the left, an open conversation with an internal comment on the right</title>
                            <rect x="0.5" y="0.5" width="639" height="419" rx="16" class="fill-card stroke-border" />
                            <!-- rail -->
                            <rect x="1" y="1" width="56" height="418" rx="15" class="fill-surface-sunken" />
                            <rect x="14" y="16" width="28" height="28" rx="8" class="fill-brand" />
                            <path d="M20 26l8 5 8-5" class="stroke-brand-foreground" stroke-width="2" fill="none" stroke-linecap="round" />
                            <circle cx="28" cy="72" r="5" class="fill-muted-foreground/40" />
                            <circle cx="28" cy="96" r="5" class="fill-brand" />
                            <circle cx="28" cy="120" r="5" class="fill-muted-foreground/40" />
                            <!-- list -->
                            <rect x="57" y="1" width="230" height="418" class="fill-surface" />
                            <text x="76" y="36" class="fill-foreground" font-size="14" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Support</text>
                            <text x="76" y="54" class="fill-muted-foreground" font-size="10" font-family="Instrument Sans, ui-sans-serif, system-ui">support@northwind.test · 3 open</text>
                            <g>
                                <rect x="66" y="70" width="212" height="58" rx="10" class="fill-brand-soft/70" />
                                <circle cx="84" cy="92" r="3" class="fill-brand" />
                                <text x="96" y="90" class="fill-foreground" font-size="11" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Ada Lovelace</text>
                                <text x="96" y="106" class="fill-foreground" font-size="10" font-family="Instrument Sans, ui-sans-serif, system-ui">Invoice 42 looks off</text>
                                <text x="96" y="120" class="fill-muted-foreground" font-size="9" font-family="Instrument Sans, ui-sans-serif, system-ui">Hi team, the total on invoice 42…</text>
                                <rect x="222" y="82" width="46" height="14" rx="7" class="fill-highlight/80" />
                                <text x="245" y="92" text-anchor="middle" class="fill-highlight-foreground" font-size="8" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">billing</text>
                            </g>
                            <g v-for="(row, i) in [
                                { y: 136, name: 'Grace Hopper', subject: 'Can we add a seat?' },
                                { y: 202, name: 'Linus T.', subject: 'Login loop on Safari' },
                                { y: 268, name: 'Margaret H.', subject: 'Thank you!' },
                            ]" :key="i">
                                <rect x="66" :y="row.y" width="212" height="58" rx="10" class="fill-transparent" />
                                <text x="96" :y="row.y + 20" class="fill-foreground" font-size="11" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">{{ row.name }}</text>
                                <text x="96" :y="row.y + 36" class="fill-foreground" font-size="10" font-family="Instrument Sans, ui-sans-serif, system-ui">{{ row.subject }}</text>
                                <rect x="96" :y="row.y + 44" width="140" height="5" rx="2.5" class="fill-muted" />
                                <line x1="76" :y1="row.y + 58" x2="268" :y2="row.y + 58" class="stroke-border" />
                            </g>
                            <!-- conversation -->
                            <text x="308" y="40" class="fill-foreground" font-size="16" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Invoice 42 looks off</text>
                            <rect x="308" y="52" width="60" height="16" rx="8" class="fill-success/20" />
                            <circle cx="318" cy="60" r="3" class="fill-success" />
                            <text x="326" y="64" class="fill-foreground" font-size="9" font-family="Instrument Sans, ui-sans-serif, system-ui">Open</text>
                            <rect x="374" y="52" width="92" height="16" rx="8" class="fill-secondary" />
                            <circle cx="384" cy="60" r="5" class="fill-brand/70" />
                            <text x="393" y="64" class="fill-foreground" font-size="9" font-family="Instrument Sans, ui-sans-serif, system-ui">Assigned to Sam</text>
                            <!-- message -->
                            <rect x="308" y="84" width="312" height="110" rx="12" class="fill-surface stroke-border" />
                            <circle cx="330" cy="106" r="10" class="fill-brand-soft" />
                            <text x="330" y="110" text-anchor="middle" class="fill-brand-soft-foreground" font-size="9" font-weight="700" font-family="Instrument Sans, ui-sans-serif, system-ui">AL</text>
                            <text x="348" y="104" class="fill-foreground" font-size="11" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Ada Lovelace</text>
                            <text x="348" y="117" class="fill-muted-foreground" font-size="9" font-family="Instrument Sans, ui-sans-serif, system-ui">to support · 9:41</text>
                            <rect x="324" y="134" width="270" height="6" rx="3" class="fill-muted" />
                            <rect x="324" y="148" width="240" height="6" rx="3" class="fill-muted" />
                            <rect x="324" y="162" width="190" height="6" rx="3" class="fill-muted" />
                            <!-- internal comment -->
                            <g>
                                <path d="M336 210h284a12 12 0 0 1 12 12v66a12 12 0 0 1-12 12H348a12 12 0 0 1-12-12v-10l-14 10v-66a12 12 0 0 1 12-12z" class="fill-highlight/25 stroke-highlight/70" />
                                <circle cx="360" cy="232" r="9" class="fill-highlight" />
                                <text x="360" y="236" text-anchor="middle" class="fill-highlight-foreground" font-size="8" font-weight="700" font-family="Instrument Sans, ui-sans-serif, system-ui">SR</text>
                                <text x="376" y="229" class="fill-foreground" font-size="10" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Sam Rivera</text>
                                <text x="440" y="229" class="fill-muted-foreground" font-size="8" font-family="Instrument Sans, ui-sans-serif, system-ui">internal · only the team sees this</text>
                                <text x="376" y="250" class="fill-foreground" font-size="10" font-family="Instrument Sans, ui-sans-serif, system-ui">
                                    <tspan class="fill-brand" font-weight="600">@Priya</tspan> can you check the VAT line? I think
                                </text>
                                <text x="376" y="266" class="fill-foreground" font-size="10" font-family="Instrument Sans, ui-sans-serif, system-ui">the rate changed in October.</text>
                                <text x="376" y="286" class="fill-muted-foreground" font-size="8" font-family="Instrument Sans, ui-sans-serif, system-ui">Automation “VIP customers” added tag VIP and set priority high</text>
                            </g>
                            <!-- composer -->
                            <rect x="308" y="316" width="312" height="80" rx="12" class="fill-card stroke-border" />
                            <text x="324" y="340" class="fill-muted-foreground" font-size="10" font-family="Instrument Sans, ui-sans-serif, system-ui">Reply to Ada…</text>
                            <rect x="548" y="364" width="60" height="22" rx="7" class="fill-brand" />
                            <text x="578" y="379" text-anchor="middle" class="fill-brand-foreground" font-size="10" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Send</text>
                        </svg>
                    </div>
                </div>
            </section>

            <!-- Narrative -->
            <section class="border-y bg-surface">
                <div class="mx-auto grid w-full max-w-6xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:gap-16 lg:py-20">
                    <div>
                        <p class="text-xs font-semibold tracking-wide text-brand uppercase">Personal</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">Your mail, finally pleasant.</h2>
                        <p class="mt-4 leading-relaxed text-muted-foreground">
                            Warm paper tones instead of blinding white, keyboard shortcuts for everything, a command palette, snooze, stars and saved views. The inbox you would pick for yourself, even if nobody else used it.
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold tracking-wide text-brand uppercase">Together</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">Shared inboxes your team runs as one.</h2>
                        <p class="mt-4 leading-relaxed text-muted-foreground">
                            support@, billing@, hello@: every conversation has an owner, a status and a place for the team to think out loud. No more forwarding threads and hoping someone picks it up.
                        </p>
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section class="mx-auto w-full max-w-6xl space-y-20 px-4 py-20 sm:px-6 lg:space-y-28 lg:py-28">
                <article v-for="(feature, i) in features" :key="feature.eyebrow" class="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
                    <div :class="i % 2 === 1 ? 'lg:order-2' : ''">
                        <span class="inline-flex size-10 items-center justify-center rounded-xl bg-brand-soft text-brand-soft-foreground">
                            <component :is="feature.icon" class="size-5" />
                        </span>
                        <p class="mt-5 text-xs font-semibold tracking-wide text-brand uppercase">{{ feature.eyebrow }}</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">{{ feature.title }}</h2>
                        <p class="mt-4 leading-relaxed text-muted-foreground">{{ feature.body }}</p>
                        <ul class="mt-6 space-y-2 text-sm">
                            <li v-for="point in feature.points" :key="point" class="flex items-center gap-2.5">
                                <span class="size-1.5 rounded-full bg-brand" />
                                {{ point }}
                            </li>
                        </ul>
                    </div>

                    <!-- Feature visuals -->
                    <div :class="i % 2 === 1 ? 'lg:order-1' : ''">
                        <!-- 1: comment thread -->
                        <div v-if="i === 0" class="rounded-2xl border bg-card p-5 shadow-float">
                            <div class="flex items-center gap-3">
                                <span class="flex size-9 items-center justify-center rounded-full bg-brand-soft text-xs font-bold text-brand-soft-foreground">AL</span>
                                <div>
                                    <p class="text-sm font-semibold">Ada Lovelace</p>
                                    <p class="text-xs text-muted-foreground">Invoice 42 looks off</p>
                                </div>
                                <span class="ml-auto rounded-full bg-success/15 px-2 py-0.5 text-xs">Open</span>
                            </div>
                            <div class="mt-4 space-y-1.5">
                                <div class="h-2 w-5/6 rounded bg-muted" />
                                <div class="h-2 w-2/3 rounded bg-muted" />
                            </div>
                            <div class="mt-5 rounded-xl border border-highlight/60 bg-highlight/15 p-4">
                                <p class="text-[11px] font-semibold tracking-wide text-highlight-foreground uppercase dark:text-highlight">Internal comment</p>
                                <p class="mt-1.5 text-sm"><span class="font-semibold text-brand">@Priya</span> can you check the VAT line? I think the rate changed in October.</p>
                                <p class="mt-2 text-xs text-muted-foreground">Sam Rivera · Only the team sees this</p>
                            </div>
                            <div class="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                                <span class="rounded-full bg-secondary px-2 py-0.5">Assigned to Sam</span>
                                <span class="rounded-full bg-secondary px-2 py-0.5">billing</span>
                                <span class="rounded-full bg-secondary px-2 py-0.5">VIP</span>
                            </div>
                        </div>

                        <!-- 2: connections -->
                        <div v-else-if="i === 1" class="grid gap-3 sm:grid-cols-3">
                            <div v-for="source in [{ name: 'Gmail', hint: 'OAuth, two clicks', icon: AtSign }, { name: 'IMAP', hint: 'Any provider', icon: Inbox }, { name: 'Forwarding', hint: 'For shared aliases', icon: ArrowRight }]" :key="source.name" class="rounded-2xl border bg-card p-4 shadow-pane">
                                <component :is="source.icon" class="size-5 text-brand" />
                                <p class="mt-3 text-sm font-semibold">{{ source.name }}</p>
                                <p class="text-xs text-muted-foreground">{{ source.hint }}</p>
                                <div class="mt-4 flex items-center gap-1.5 text-[11px] text-success">
                                    <span class="unread-dot bg-success" /> Synced just now
                                </div>
                            </div>
                        </div>

                        <!-- 3: rule -->
                        <div v-else class="rounded-2xl border bg-surface p-5 shadow-pane">
                            <div class="space-y-2 text-sm">
                                <div class="flex items-start gap-3 rounded-xl border bg-card p-3">
                                    <span class="font-display text-xl text-brand">When</span>
                                    <span class="pt-0.5">a new conversation arrives in <span class="font-medium">Support</span></span>
                                </div>
                                <div class="flex items-start gap-3 rounded-xl border bg-card p-3">
                                    <span class="font-display text-xl text-brand">If</span>
                                    <span class="pt-0.5">sender ends with <code class="rounded bg-brand-soft px-1 font-mono text-xs text-brand-soft-foreground">@acme.com</code></span>
                                </div>
                                <div class="flex items-start gap-3 rounded-xl border bg-card p-3">
                                    <span class="font-display text-xl text-brand">Then</span>
                                    <span class="pt-0.5">add tag <span class="rounded-full bg-secondary px-2 py-0.5 text-xs">VIP</span> and set priority to <span class="font-medium text-warning-foreground dark:text-warning">high</span></span>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center gap-2 rounded-lg bg-success/15 px-3 py-2 text-xs">
                                <span class="size-2 rounded-full bg-success" /> Dry run: it would run. 2 of 2 conditions passed.
                            </div>
                        </div>
                    </div>
                </article>
            </section>

            <!-- How sharing works -->
            <section class="border-y bg-surface">
                <div class="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
                    <div class="mx-auto max-w-2xl text-center">
                        <p class="text-xs font-semibold tracking-wide text-brand uppercase">How sharing works</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">Nothing moves at your provider. Everything moves for your team.</h2>
                        <p class="mt-4 leading-relaxed text-muted-foreground">Sharing a conversation changes who sees it inside Tandem, and nothing else. Keep it in your mailbox and show it to the team, or hand it over completely.</p>
                    </div>

                    <svg viewBox="0 0 920 300" class="mx-auto mt-12 w-full max-w-4xl" role="img" aria-labelledby="sharing-title">
                        <title id="sharing-title">Diagram: a conversation in a personal inbox is shared into a team inbox, where teammates comment and reply</title>
                        <defs>
                            <marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse">
                                <path d="M0 0L10 5 0 10z" class="fill-brand" />
                            </marker>
                        </defs>

                        <!-- personal -->
                        <rect x="20" y="60" width="250" height="180" rx="18" class="fill-card stroke-border" />
                        <text x="44" y="94" class="fill-foreground" font-size="15" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Your inbox</text>
                        <text x="44" y="112" class="fill-muted-foreground" font-size="11" font-family="Instrument Sans, ui-sans-serif, system-ui">you@company.com · Gmail</text>
                        <rect x="44" y="130" width="202" height="46" rx="10" class="fill-brand-soft/70 stroke-brand/30" />
                        <text x="58" y="149" class="fill-foreground" font-size="11" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Invoice 42 looks off</text>
                        <text x="58" y="165" class="fill-muted-foreground" font-size="10" font-family="Instrument Sans, ui-sans-serif, system-ui">stays right here</text>
                        <rect x="44" y="188" width="202" height="30" rx="10" class="fill-surface-sunken" />
                        <rect x="44" y="190" width="120" height="6" rx="3" class="fill-transparent" />

                        <!-- arrow -->
                        <path d="M280 150 C 330 150, 330 150, 380 150" class="stroke-brand" stroke-width="2.5" fill="none" marker-end="url(#arrow)" />
                        <rect x="282" y="112" width="98" height="24" rx="12" class="fill-brand" />
                        <text x="331" y="128" text-anchor="middle" class="fill-brand-foreground" font-size="11" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Share with team</text>

                        <!-- team -->
                        <rect x="390" y="30" width="330" height="240" rx="18" class="fill-card stroke-brand/40" stroke-width="1.5" />
                        <text x="414" y="64" class="fill-foreground" font-size="15" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Billing team inbox</text>
                        <text x="414" y="82" class="fill-muted-foreground" font-size="11" font-family="Instrument Sans, ui-sans-serif, system-ui">billing@company.com · 4 teammates</text>
                        <rect x="414" y="100" width="282" height="46" rx="10" class="fill-brand-soft/70 stroke-brand/30" />
                        <text x="428" y="119" class="fill-foreground" font-size="11" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Invoice 42 looks off</text>
                        <text x="428" y="135" class="fill-muted-foreground" font-size="10" font-family="Instrument Sans, ui-sans-serif, system-ui">now visible here too · assigned to Sam</text>
                        <path d="M430 160h250a10 10 0 0 1 10 10v46a10 10 0 0 1-10 10H440a10 10 0 0 1-10-10v-8l-10 8v-46a10 10 0 0 1 10-10z" class="fill-highlight/25 stroke-highlight/70" />
                        <text x="446" y="181" class="fill-foreground" font-size="10" font-weight="600" font-family="Instrument Sans, ui-sans-serif, system-ui">Sam · internal</text>
                        <text x="446" y="197" class="fill-foreground" font-size="10" font-family="Instrument Sans, ui-sans-serif, system-ui">“Priya, VAT line?” — only the team sees this.</text>
                        <text x="446" y="215" class="fill-muted-foreground" font-size="9" font-family="Instrument Sans, ui-sans-serif, system-ui">Replies go out as billing@, threaded for everyone.</text>
                        <!-- avatars -->
                        <g>
                            <circle cx="650" cy="62" r="11" class="fill-brand-soft stroke-card" stroke-width="2" />
                            <circle cx="668" cy="62" r="11" class="fill-highlight stroke-card" stroke-width="2" />
                            <circle cx="686" cy="62" r="11" class="fill-info/60 stroke-card" stroke-width="2" />
                        </g>

                        <!-- arrow out -->
                        <path d="M730 150 C 780 150, 780 150, 830 150" class="stroke-brand" stroke-width="2.5" fill="none" marker-end="url(#arrow)" />
                        <text x="780" y="138" text-anchor="middle" class="fill-muted-foreground" font-size="10" font-family="Instrument Sans, ui-sans-serif, system-ui">one reply</text>

                        <!-- customer -->
                        <circle cx="870" cy="150" r="30" class="fill-surface-sunken stroke-border" />
                        <text x="870" y="155" text-anchor="middle" class="fill-foreground" font-size="12" font-weight="700" font-family="Instrument Sans, ui-sans-serif, system-ui">Ada</text>
                        <text x="870" y="200" text-anchor="middle" class="fill-muted-foreground" font-size="10" font-family="Instrument Sans, ui-sans-serif, system-ui">sees only the reply</text>
                    </svg>
                </div>
            </section>

            <!-- CTA -->
            <section class="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
                <div class="relative overflow-hidden rounded-3xl bg-brand px-6 py-14 text-center text-brand-foreground sm:px-12">
                    <div class="pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-highlight/30 blur-3xl" aria-hidden="true" />
                    <div class="pointer-events-none absolute -bottom-28 -left-16 size-80 rounded-full bg-brand-foreground/10 blur-3xl" aria-hidden="true" />
                    <h2 class="relative text-3xl font-semibold tracking-tight sm:text-4xl">Bring your team in.</h2>
                    <p class="relative mx-auto mt-3 max-w-lg text-brand-foreground/80">Connect an account, invite a teammate, share one conversation. You will get it in about a minute.</p>
                    <div class="relative mt-8 flex flex-wrap items-center justify-center gap-3">
                        <Button as-child size="lg" class="bg-brand-foreground text-brand hover:bg-brand-foreground/90">
                            <Link :href="user ? mailUrl : register()">{{ user ? 'Open your mail' : 'Create your account' }}</Link>
                        </Button>
                        <Button v-if="!user" as-child size="lg" variant="ghost" class="text-brand-foreground hover:bg-brand-foreground/10 hover:text-brand-foreground">
                            <Link :href="login()">Log in</Link>
                        </Button>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div class="mx-auto flex w-full max-w-6xl flex-col items-center justify-between gap-4 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:px-6">
                <div class="inline-flex items-center gap-2">
                    <TandemMark :size="22" />
                    <span>Tandem · email for people and the teams they work with</span>
                </div>
                <div class="flex items-center gap-4">
                    <Link :href="login()" class="hover:text-foreground">Log in</Link>
                    <Link :href="register()" class="hover:text-foreground">Register</Link>
                </div>
            </div>
        </footer>
    </div>
</template>
