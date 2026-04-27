<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    href: string;
}>();
</script>

<template>
    <Link
        :href="href"
        class="block w-full px-4 py-2 text-start text-sm leading-5 text-text-secondary transition hover:bg-background-panel-hover hover:text-text-primary focus:bg-background-panel-hover focus:outline-none"
    >
        <slot />
    </Link>
</template>
