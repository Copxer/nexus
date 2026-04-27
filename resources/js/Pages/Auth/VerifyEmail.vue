<script setup lang="ts">
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head title="Email Verification" />

        <h1 class="text-xl font-semibold text-text-primary">
            Verify your email
        </h1>
        <p class="mt-2 text-sm text-text-secondary">
            We just sent a verification link to your inbox. Click it to finish
            setting up your account. Didn't get it? We'll happily resend.
        </p>

        <div
            v-if="verificationLinkSent"
            class="mt-4 rounded-lg border border-status-success/40 bg-status-success/10 px-3 py-2 text-sm font-medium text-status-success"
        >
            A new verification link has been sent.
        </div>

        <form class="mt-6 space-y-3" @submit.prevent="submit">
            <PrimaryButton
                class="w-full"
                :class="{ 'opacity-50': form.processing }"
                :disabled="form.processing"
            >
                Resend verification email
            </PrimaryButton>

            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="block w-full text-center text-sm text-text-muted transition hover:text-accent-cyan focus:outline-none"
                >Log out</Link
            >
        </form>
    </GuestLayout>
</template>
