<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import TandemMark from '@/components/mail/TandemMark.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const perks = ['Personal mail and team inboxes, side by side', 'Internal comments nobody outside ever sees', 'Gmail, IMAP or forwarding. Mail lands in seconds'];
</script>

<template>
    <div class="grid min-h-dvh bg-background lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        <!-- Brand panel -->
        <aside class="relative hidden overflow-hidden bg-brand text-brand-foreground lg:flex lg:flex-col lg:justify-between lg:p-10">
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute -top-32 -right-24 size-96 rounded-full bg-highlight/30 blur-3xl" />
                <div class="absolute -bottom-40 -left-24 size-[28rem] rounded-full bg-brand-foreground/10 blur-3xl" />
                <svg class="absolute inset-0 size-full opacity-[0.07]" aria-hidden="true">
                    <defs>
                        <pattern id="auth-grid" width="32" height="32" patternUnits="userSpaceOnUse">
                            <path d="M32 0H0v32" fill="none" stroke="currentColor" stroke-width="1" />
                        </pattern>
                    </defs>
                    <rect width="100%" height="100%" fill="url(#auth-grid)" />
                </svg>
            </div>

            <Link :href="home()" class="relative z-10 inline-flex items-center gap-2.5 text-lg font-semibold tracking-tight">
                <TandemMark :size="32" />
                Tandem
            </Link>

            <div class="relative z-10 max-w-md">
                <p class="font-display text-5xl leading-[1.05] tracking-tight">“Inbox zero is a team sport.”</p>
                <p class="mt-4 text-sm text-brand-foreground/70">— someone at Tandem, probably on a Friday</p>

                <ul class="mt-10 space-y-3 text-sm">
                    <li v-for="perk in perks" :key="perk" class="flex items-start gap-2.5">
                        <span class="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-highlight text-highlight-foreground"><Check class="size-3" stroke-width="3" /></span>
                        <span class="text-brand-foreground/90">{{ perk }}</span>
                    </li>
                </ul>
            </div>

            <p class="relative z-10 text-xs text-brand-foreground/60">Warm paper, plum accents, no blue inbox in sight.</p>
        </aside>

        <!-- Form -->
        <main class="flex flex-col items-center justify-center px-5 py-10 sm:px-8">
            <div class="w-full max-w-sm animate-rise">
                <Link :href="home()" class="mb-8 inline-flex items-center gap-2 font-semibold tracking-tight lg:hidden">
                    <TandemMark :size="28" />
                    Tandem
                </Link>

                <div v-if="title || description" class="mb-7 space-y-1.5">
                    <h1 v-if="title" class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
                    <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
                </div>

                <slot />
            </div>
        </main>
    </div>
</template>
