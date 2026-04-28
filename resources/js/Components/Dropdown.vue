<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        align?: 'left' | 'right';
        direction?: 'down' | 'up';
        width?: '48';
        contentClasses?: string;
    }>(),
    {
        align: 'right',
        direction: 'down',
        width: '48',
        contentClasses: 'py-1 bg-background-panel backdrop-blur-xl',
    },
);

const closeOnEscape = (e: KeyboardEvent) => {
    if (open.value && e.key === 'Escape') {
        open.value = false;
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => document.removeEventListener('keydown', closeOnEscape));

const widthClass = computed(() => {
    return {
        48: 'w-48',
    }[props.width.toString()];
});

const alignmentClasses = computed(() => {
    const horiz =
        props.align === 'left'
            ? 'ltr:origin-top-left rtl:origin-top-right start-0'
            : 'ltr:origin-top-right rtl:origin-top-left end-0';

    // Direction `up` flips the panel above the trigger — used by the sidebar
    // user card so the menu doesn't render below the viewport.
    return props.direction === 'up'
        ? `${horiz} bottom-full mb-2`
        : `${horiz} top-full mt-2`;
});

const open = ref(false);
</script>

<template>
    <div class="relative">
        <div @click="open = !open">
            <slot name="trigger" />
        </div>

        <!-- Full Screen Dropdown Overlay -->
        <div
            v-show="open"
            class="fixed inset-0 z-40"
            @click="open = false"
        ></div>

        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-show="open"
                class="absolute z-50 rounded-md shadow-lg"
                :class="[widthClass, alignmentClasses]"
                style="display: none"
                @click="open = false"
            >
                <div
                    class="overflow-hidden rounded-lg border border-border-subtle shadow-panel"
                    :class="contentClasses"
                >
                    <slot name="content" />
                </div>
            </div>
        </Transition>
    </div>
</template>
