<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';

interface WebsiteShape {
    id: number;
    name: string;
    url: string;
    method: string;
    expected_status_code: number;
    timeout_ms: number;
    check_interval_seconds: number;
}

interface MethodOption {
    value: string;
    label: string;
}

interface IntervalOption {
    value: number;
    label: string;
}

const props = defineProps<{
    website: WebsiteShape;
    options: {
        methods: MethodOption[];
        common_intervals: IntervalOption[];
    };
}>();

const form = useForm({
    name: props.website.name,
    url: props.website.url,
    method: props.website.method,
    expected_status_code: props.website.expected_status_code,
    timeout_ms: props.website.timeout_ms,
    check_interval_seconds: props.website.check_interval_seconds,
});

const submit = () => {
    form.patch(route('monitoring.websites.update', props.website.id));
};
</script>

<template>
    <Head :title="`Edit ${website.name}`" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('monitoring.websites.show', website.id)"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    {{ website.name }}
                </Link>
                <h1 class="text-lg font-semibold text-text-primary">
                    Edit monitor
                </h1>
            </div>
        </template>

        <div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
            <form
                class="glass-card flex flex-col gap-5 p-6"
                @submit.prevent="submit"
            >
                <div class="flex flex-col gap-2">
                    <InputLabel for="name" value="Name" />
                    <TextInput id="name" v-model="form.name" type="text" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="flex flex-col gap-2">
                    <InputLabel for="url" value="URL" />
                    <TextInput id="url" v-model="form.url" type="url" />
                    <InputError :message="form.errors.url" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-2">
                        <InputLabel for="method" value="HTTP method" />
                        <select
                            id="method"
                            v-model="form.method"
                            class="rounded-md border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                        >
                            <option
                                v-for="opt in options.methods"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.method" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <InputLabel
                            for="expected_status_code"
                            value="Expected status code"
                        />
                        <input
                            id="expected_status_code"
                            v-model.number="form.expected_status_code"
                            type="number"
                            min="100"
                            max="599"
                            class="rounded-lg border border-border-subtle bg-slate-950/60 px-3 py-2 text-text-primary placeholder:text-text-muted shadow-inner shadow-black/20 transition focus:border-accent-cyan focus:ring-2 focus:ring-accent-cyan/40 focus:ring-offset-0"
                        />
                        <InputError
                            :message="form.errors.expected_status_code"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-2">
                        <InputLabel for="timeout_ms" value="Timeout (ms)" />
                        <input
                            id="timeout_ms"
                            v-model.number="form.timeout_ms"
                            type="number"
                            min="1000"
                            max="60000"
                            class="rounded-lg border border-border-subtle bg-slate-950/60 px-3 py-2 text-text-primary placeholder:text-text-muted shadow-inner shadow-black/20 transition focus:border-accent-cyan focus:ring-2 focus:ring-accent-cyan/40 focus:ring-offset-0"
                        />
                        <InputError :message="form.errors.timeout_ms" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <InputLabel
                            for="check_interval_seconds"
                            value="Check interval"
                        />
                        <select
                            id="check_interval_seconds"
                            v-model.number="form.check_interval_seconds"
                            class="rounded-md border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                        >
                            <option
                                v-for="opt in options.common_intervals"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </option>
                        </select>
                        <InputError
                            :message="form.errors.check_interval_seconds"
                        />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <Link
                        :href="route('monitoring.websites.show', website.id)"
                        class="text-sm font-semibold text-text-secondary hover:text-text-primary"
                    >
                        Cancel
                    </Link>
                    <PrimaryButton :disabled="form.processing">
                        Save changes
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
