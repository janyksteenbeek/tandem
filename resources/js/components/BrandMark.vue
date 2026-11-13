<script setup lang="ts">
import { useId } from 'vue';
import { useBranding } from '@/composables/useBranding';

/**
 * The mark on its own: two riders in tandem on the violet-to-pink tile,
 * `size` pixels square. A configured logo replaces it.
 */
withDefaults(defineProps<{ size?: number }>(), { size: 28 });

const branding = useBranding();
const uid = useId();

const tileStops = [
    '#6318FD',
    '#711FFC',
    '#7F25FA',
    '#8D2EF8',
    '#9F37F5',
    '#B041F1',
    '#BF4BEE',
    '#CE5AED',
    '#D75DE7',
    '#E265E4',
    '#EE6FE1',
    '#F875DD',
    '#FD7CDB',
];
</script>

<template>
    <template v-if="branding.logo">
        <img
            :src="branding.logo"
            :width="size"
            :height="size"
            :alt="branding.name"
            :class="{ 'dark:hidden': branding.logo_dark }"
            class="shrink-0 rounded-lg object-contain"
            :style="{ width: `${size}px`, height: `${size}px` }"
        />
        <img
            v-if="branding.logo_dark"
            :src="branding.logo_dark"
            :width="size"
            :height="size"
            :alt="branding.name"
            class="hidden shrink-0 rounded-lg object-contain dark:block"
            :style="{ width: `${size}px`, height: `${size}px` }"
        />
    </template>
    <svg
        v-else
        :width="size"
        :height="size"
        viewBox="385 203.5 331 331"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        class="shrink-0"
        aria-hidden="true"
    >
        <defs>
            <linearGradient
                :id="`tile-${uid}`"
                x1="418"
                y1="458"
                x2="654.4"
                y2="220.4"
                gradientUnits="userSpaceOnUse"
            >
                <stop
                    v-for="(color, index) in tileStops"
                    :key="color"
                    :offset="index / (tileStops.length - 1)"
                    :stop-color="color"
                />
            </linearGradient>
            <radialGradient
                :id="`gloss-${uid}`"
                cx="517"
                cy="445"
                r="128"
                gradientUnits="userSpaceOnUse"
            >
                <stop stop-color="#CDA1FF" stop-opacity=".15" />
                <stop offset=".36" stop-color="#DAB2FF" stop-opacity=".11" />
                <stop offset=".7" stop-color="#E1AEFF" stop-opacity=".035" />
                <stop offset="1" stop-color="#E1AEFF" stop-opacity="0" />
            </radialGradient>
            <linearGradient
                :id="`reflection-${uid}`"
                x1="510"
                y1="387"
                x2="564"
                y2="467"
                gradientUnits="userSpaceOnUse"
            >
                <stop stop-color="#F5C5FF" stop-opacity=".28" />
                <stop offset=".55" stop-color="#E3B1FA" stop-opacity=".2" />
                <stop offset="1" stop-color="#AD5AF7" stop-opacity=".02" />
            </linearGradient>
            <linearGradient
                :id="`left-${uid}`"
                x1="484"
                y1="371"
                x2="581"
                y2="424"
                gradientUnits="userSpaceOnUse"
            >
                <stop stop-color="#FFF" />
                <stop offset=".36" stop-color="#FFF" />
                <stop offset=".75" stop-color="#F7EFFC" stop-opacity=".8" />
                <stop offset="1" stop-color="#EEC7F5" stop-opacity=".37" />
            </linearGradient>
            <linearGradient
                :id="`right-${uid}`"
                x1="528"
                y1="439"
                x2="632"
                y2="358"
                gradientUnits="userSpaceOnUse"
            >
                <stop stop-color="#E6B5F4" stop-opacity=".3" />
                <stop offset=".26" stop-color="#FFF" stop-opacity=".86" />
                <stop offset=".48" stop-color="#FFF" />
                <stop offset="1" stop-color="#FFF" />
            </linearGradient>
            <clipPath :id="`clip-${uid}`">
                <rect x="385" y="203.5" width="331" height="331" rx="93" />
            </clipPath>
        </defs>
        <g :clip-path="`url(#clip-${uid})`">
            <rect
                x="385"
                y="203.5"
                width="331"
                height="331"
                :fill="`url(#tile-${uid})`"
            />
            <path
                :fill="`url(#gloss-${uid})`"
                d="M470 210C515 210 550.5 244.5 559.5 287C572 348 553 407.5 551 438C547 479 521 518 478 528L478 620L300 620L300 210Z"
            />
            <path
                :fill="`url(#reflection-${uid})`"
                opacity=".45"
                d="M457 411 C488 437 515 449 543 419 C566 392 581 383 602 391 C630 403 639 435 682 461 C656 465 631 449 609 435 C590 421 578 426 561 452 C540 483 516 491 491 478 C470 466 451 442 457 411 Z"
            />
            <circle cx="530.5" cy="307.8" r="21.5" fill="#FFF" />
            <circle cx="635" cy="307.8" r="21.5" fill="#FFF" />
            <path
                :fill="`url(#left-${uid})`"
                d="M521.44 348.19C504.44 350.29 488.36 358.33 476.2 370.36C465.82 380.63 458 393.13 448.24 403.95C445.87 406.57 443.41 409.09 440.81 411.48C437.91 414.16 434.88 416.72 432.2 419.62C429.12 422.95 426.34 426.97 426.47 431.7C426.66 438.86 433.24 448.56 441.07 448.43C445.67 448.35 449.86 445.41 453.28 442.61C456.71 439.8 459.83 436.61 462.88 433.4C467.36 428.68 471.58 423.71 475.67 418.65C480.55 412.61 485.31 406.46 490.5 400.67C498.43 391.84 507.88 383.21 519.61 380.01C527.75 377.79 534.32 378.52 542.1 381.58C545.31 382.84 548.45 384.39 551.79 385.24C553.62 385.71 555.69 385.97 557.42 385.02C559.55 383.86 560.89 381.09 562.2 379.14C562.83 378.18 563.51 377.25 564.26 376.37C566.38 373.86 568.88 371.7 571.12 369.3C572.24 368.11 573.61 366.63 573.64 364.9C573.68 363.21 572.52 361.75 571.34 360.67C568.74 358.26 565.43 356.6 562.26 355.1C549.63 349.13 535.35 346.47 521.44 348.19Z"
            />
            <path
                :fill="`url(#right-${uid})`"
                d="M623.9 347.89C592.14 352.93 571.27 380.26 552.02 403.44C549.51 406.46 546.97 409.46 544.39 412.42C538.96 418.65 533.38 424.89 529.14 432.02C528.03 433.81 526.98 435.68 526.24 437.65C525.91 438.51 525.64 439.42 525.52 440.34C524.96 444.89 529.42 446.67 533.08 447.59C540.8 449.53 548.73 447.97 555.64 444.22C559.73 442 563.46 439.13 566.88 435.97C576.25 427.32 583.37 416.7 591.12 406.67C597.43 398.5 604.41 390.46 613.53 385.36C623.33 379.96 634.67 377.85 645.77 379.12C648.68 379.45 651.55 380.02 654.36 380.83C658.85 382.13 662.94 383.75 667.28 380.99C669.05 379.87 670.53 378.33 671.78 376.67C672.85 375.25 673.76 373.69 674.38 372.01C677.09 364.66 672.07 358.99 666.32 355.15C654.61 347.33 637.51 345.73 623.9 347.89Z"
            />
        </g>
    </svg>
</template>
