<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';

interface HostPayload {
    id: number;
    name: string;
    provider: string | null;
    endpoint_url: string | null;
    connection_type: string | null;
    project: { id: number; name: string } | null;
}

const props = defineProps<{
    host: HostPayload;
    options: {
        connection_types: { value: string; label: string; enabled: boolean }[];
    };
}>();

const form = useForm({
    name: props.host.name,
    provider: props.host.provider ?? '',
    endpoint_url: props.host.endpoint_url ?? '',
});

const submit = () => {
    form.patch(route('monitoring.hosts.update', props.host.id));
};
</script>

<template>
    <Head :title="`Edit ${host.name}`" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('monitoring.hosts.show', host.id)"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    {{ host.name }}
                </Link>
                <h1 class="text-lg font-semibold text-text-primary">
                    Edit host
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
                    <InputLabel for="provider" value="Provider" />
                    <TextInput
                        id="provider"
                        v-model="form.provider"
                        type="text"
                    />
                    <InputError :message="form.errors.provider" />
                </div>

                <div class="flex flex-col gap-2">
                    <InputLabel for="endpoint_url" value="Endpoint URL" />
                    <TextInput
                        id="endpoint_url"
                        v-model="form.endpoint_url"
                        type="url"
                    />
                    <InputError :message="form.errors.endpoint_url" />
                </div>

                <p class="text-xs text-text-muted">
                    Project and connection type are fixed after
                    creation. Archive and re-create the host if you need
                    to change those.
                </p>

                <div class="flex items-center justify-end gap-3">
                    <Link
                        :href="route('monitoring.hosts.show', host.id)"
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
