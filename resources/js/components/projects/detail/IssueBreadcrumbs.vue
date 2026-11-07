<script setup lang="ts">
import { Link as LinkIcon, Check } from '@lucide/vue';
import { ref } from 'vue';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { Issue } from '@/types';

const props = defineProps<{ issue: Issue; orgSlug: string; orgName: string }>();

const copied = ref(false);

const copyLink = async () => {
    await navigator.clipboard.writeText(
        `${window.location.origin}/${props.orgSlug}/issues/${props.issue.identifier}`,
    );
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
};
</script>

<template>
    <div class="flex items-center gap-2">
        <Breadcrumb>
            <BreadcrumbList class="text-[12px]">
                <BreadcrumbItem>
                    <BreadcrumbLink :href="`/${orgSlug}/issues`">{{
                        orgName
                    }}</BreadcrumbLink>
                </BreadcrumbItem>
                <template v-if="issue.product">
                    <BreadcrumbSeparator />
                    <BreadcrumbItem>
                        <BreadcrumbLink
                            :href="`/${orgSlug}/products/${issue.product.slug}`"
                            >{{ issue.product.name }}</BreadcrumbLink
                        >
                    </BreadcrumbItem>
                </template>
                <template v-if="issue.project">
                    <BreadcrumbSeparator />
                    <BreadcrumbItem>
                        <BreadcrumbLink
                            :href="`/${orgSlug}/projects/${issue.project.slug}`"
                            >{{ issue.project.name }}</BreadcrumbLink
                        >
                    </BreadcrumbItem>
                </template>
                <template v-if="issue.parent">
                    <BreadcrumbSeparator />
                    <BreadcrumbItem>
                        <BreadcrumbLink
                            :href="`/${orgSlug}/issues/${issue.parent.identifier}`"
                            class="font-mono text-[11px]"
                            >{{ issue.parent.identifier }}</BreadcrumbLink
                        >
                    </BreadcrumbItem>
                </template>
                <BreadcrumbSeparator />
                <BreadcrumbItem>
                    <BreadcrumbPage class="font-mono text-[11px] font-medium">{{
                        issue.identifier
                    }}</BreadcrumbPage>
                </BreadcrumbItem>
            </BreadcrumbList>
        </Breadcrumb>
        <Tooltip>
            <TooltipTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-6 text-muted-foreground"
                    aria-label="Copy link"
                    @click="copyLink"
                >
                    <Check v-if="copied" class="size-3 text-success" />
                    <LinkIcon v-else class="size-3" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{{
                copied ? 'Copied' : 'Copy link'
            }}</TooltipContent>
        </Tooltip>
    </div>
</template>
