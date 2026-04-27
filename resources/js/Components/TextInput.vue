<script setup lang="ts">
import { onMounted, ref } from 'vue';

const model = defineModel<string>({ required: true });

const input = ref<HTMLInputElement | null>(null);

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value?.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <input
        class="rounded-lg border border-border-subtle bg-slate-950/60 px-3 py-2 text-text-primary placeholder:text-text-muted shadow-inner shadow-black/20 transition focus:border-accent-cyan focus:ring-2 focus:ring-accent-cyan/40 focus:ring-offset-0"
        v-model="model"
        ref="input"
    />
</template>
