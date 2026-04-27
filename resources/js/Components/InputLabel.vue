<script setup lang="ts">
defineProps<{
    value?: string;
}>();
</script>

<template>
    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-text-secondary">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
