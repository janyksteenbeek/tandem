<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import { home } from '@/routes';
import { useBranding } from '@/composables/useBranding';

const branding = useBranding();

/** The quote arrives one word at a time, so each word is its own element. */
const quoteWords = computed(() => {
    const quote = branding.auth.quote?.trim();

    return quote ? `\u201c${quote}\u201d`.split(/\s+/) : [];
});

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
            class="auth-panel relative hidden overflow-hidden lg:flex lg:flex-col lg:justify-between lg:p-10"
        >
            <div
                class="pointer-events-none absolute inset-0"
                aria-hidden="true"
            >
                <div class="auth-glow auth-glow-warm" />
                <div class="auth-glow auth-glow-cool" />
                <svg class="absolute inset-0 size-full opacity-[0.06]">
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

            <p
                v-if="quoteWords.length"
                class="relative z-10 max-w-xl font-display text-6xl leading-[1.02] tracking-tight text-balance"
            >
                <span class="sr-only">{{ branding.auth.quote }}</span>
                <span aria-hidden="true">
                    <template v-for="(word, index) in quoteWords" :key="index">
                        <span class="auth-word" :style="{ '--i': index }">{{
                            word
                        }}</span
                        >{{ ' ' }}
                    </template>
                </span>
            </p>

            <span class="relative z-10 h-5" aria-hidden="true" />
        </aside>

        <!-- Form -->
        <main
            class="flex flex-col items-center justify-center px-5 py-10 [view-transition-name:main] sm:px-8"
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
