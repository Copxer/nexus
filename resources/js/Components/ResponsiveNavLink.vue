<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    href: string;
    active?: boolean;
}>();

const classes = computed(() =>
    props.active
        ? 'block w-full border-l-4 border-accent-cyan bg-accent-cyan/10 py-2 pe-4 ps-3 text-start text-base font-medium text-text-primary transition focus:outline-none'
        : 'block w-full border-l-4 border-transparent py-2 pe-4 ps-3 text-start text-base font-medium text-text-muted transition hover:border-border-active hover:bg-background-panel-hover hover:text-text-primary focus:outline-none',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
