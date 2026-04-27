<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Forgot Password" />

        <h1 class="text-xl font-semibold text-text-primary">
            Reset your password
        </h1>
        <p class="mt-2 text-sm text-text-secondary">
            Enter your email and we'll send you a one-time link to set a new
            password.
        </p>

        <div
            v-if="status"
            class="mt-4 rounded-lg border border-status-success/40 bg-status-success/10 px-3 py-2 text-sm font-medium text-status-success"
        >
            {{ status }}
        </div>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-2 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <PrimaryButton
                class="w-full"
                :class="{ 'opacity-50': form.processing }"
                :disabled="form.processing"
            >
                Email password reset link
            </PrimaryButton>
        </form>
    </GuestLayout>
</template>
