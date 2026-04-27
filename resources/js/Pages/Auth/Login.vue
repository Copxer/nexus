<script setup lang="ts">
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <h1 class="text-xl font-semibold text-text-primary">
            Sign in
        </h1>
        <p class="mt-1 text-sm text-text-muted">
            Welcome back to your command center.
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

            <div>
                <InputLabel for="password" value="Password" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-2 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="text-sm text-text-secondary">Remember me</span>
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-sm text-text-muted transition hover:text-accent-cyan focus:outline-none"
                >
                    Forgot your password?
                </Link>
            </div>

            <PrimaryButton
                class="w-full"
                :class="{ 'opacity-50': form.processing }"
                :disabled="form.processing"
            >
                Log in
            </PrimaryButton>
        </form>

        <p class="mt-6 text-center text-sm text-text-muted">
            New here?
            <Link
                :href="route('register')"
                class="font-semibold text-accent-cyan transition hover:text-accent-cyan/80"
            >
                Create an account
            </Link>
        </p>
    </GuestLayout>
</template>
