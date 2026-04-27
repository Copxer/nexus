<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p class="text-xs font-medium text-status-danger">
            {{ message }}
        </p>
    </div>
</template>
