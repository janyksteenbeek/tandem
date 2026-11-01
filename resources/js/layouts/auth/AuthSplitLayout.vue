<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import { home } from '@/routes';
import { useBranding } from '@/composables/useBranding';

const branding = useBranding();

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="grid min-h-dvh bg-background lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]"
    >
        <!-- Brand panel -->
        <aside
            class="relative hidden overflow-hidden bg-brand text-brand-foreground lg:flex lg:flex-col lg:justify-between lg:p-10"
        >
            <div
                class="pointer-events-none absolute inset-0"
                aria-hidden="true"
            >
                <div
                    class="absolute -top-32 -right-24 size-96 rounded-full bg-highlight/30 blur-3xl"
                />
                <div
                    class="absolute -bottom-40 -left-24 size-[28rem] rounded-full bg-brand-foreground/10 blur-3xl"
                />
                <svg
                    class="absolute inset-0 size-full opacity-[0.07]"
                    aria-hidden="true"
                >
                    <defs>
                        <pattern
                            id="auth-grid"
                            width="32"
                            height="32"
                            patternUnits="userSpaceOnUse"
                        >
                            <path
                                d="M32 0H0v32"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1"
                            />
                        </pattern>
                    </defs>
                    <rect width="100%" height="100%" fill="url(#auth-grid)" />
                </svg>
            </div>

            <Link
                :href="home()"
                class="relative z-10 inline-flex items-center text-lg"
                :aria-label="`${branding.name} home`"
            >
                <BrandWordmark :height="34" on-dark />
            </Link>

            <div class="relative z-10 max-w-md">
                <p
                    v-if="branding.auth.quote"
                    class="font-display text-5xl leading-[1.05] tracking-tight"
                >
                    “{{ branding.auth.quote }}”
                </p>
                <p
                    v-if="branding.auth.attribution"
                    class="mt-4 text-sm text-brand-foreground/70"
                >
                    — {{ branding.auth.attribution }}
                </p>

                <ul
                    v-if="branding.auth.perks.length"
                    class="mt-10 space-y-3 text-sm"
                >
                    <li
                        v-for="perk in branding.auth.perks"
                        :key="perk"
                        class="flex items-start gap-2.5"
                    >
                        <span
                            class="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-highlight text-highlight-foreground"
                            ><Check class="size-3" stroke-width="3"
                        /></span>
                        <span class="text-brand-foreground/90">{{ perk }}</span>
                    </li>
                </ul>
            </div>

            <p
                v-if="branding.auth.footnote"
                class="relative z-10 text-xs text-brand-foreground/60"
            >
                {{ branding.auth.footnote }}
            </p>
        </aside>

        <!-- Form -->
        <main
            class="flex flex-col items-center justify-center px-5 py-10 sm:px-8"
        >
            <div class="w-full max-w-sm animate-rise">
                <Link
                    :href="home()"
                    class="mb-8 inline-flex items-center lg:hidden"
                    :aria-label="`${branding.name} home`"
                >
                    <BrandWordmark :height="30" />
                </Link>

                <div v-if="title || description" class="mb-7 space-y-1.5">
                    <h1
                        v-if="title"
                        class="text-2xl font-semibold tracking-tight"
                    >
                        {{ title }}
                    </h1>
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>

                <slot />
            </div>
        </main>
    </div>
</template>
