<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();
</script>

<template>
    <Head title="Profile" />

    <AppLayout>
        <template #title>
            <h1 class="text-lg font-semibold text-text-primary">Profile</h1>
        </template>

        <div class="px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl space-y-6">
                <section class="glass-card p-6 sm:p-8">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </section>

                <section class="glass-card p-6 sm:p-8">
                    <UpdatePasswordForm class="max-w-xl" />
                </section>

                <section class="glass-card p-6 sm:p-8">
                    <DeleteUserForm class="max-w-xl" />
                </section>
            </div>
        </div>
    </AppLayout>
</template>
