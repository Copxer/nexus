<script setup lang="ts">
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronUp, LogOut, UserCog } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();

// AppLayout only renders for authenticated users, so auth.user is always present.
const user = computed(() => page.props.auth.user!);

const initials = computed(() => {
    const name = user.value.name ?? '';
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]!.toUpperCase())
        .join('') || '?';
});
</script>

<template>
    <Dropdown align="left" direction="up" width="48">
        <template #trigger>
            <button
                type="button"
                class="flex w-full items-center gap-3 rounded-xl border border-border-subtle bg-slate-950/40 px-2.5 py-2 text-left transition hover:border-accent-cyan/40 hover:bg-background-panel-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
            >
                <span
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-accent-cyan/30 bg-accent-cyan/10 font-mono text-xs font-semibold text-accent-cyan"
                >
                    {{ initials }}
                </span>
                <span class="min-w-0 flex-1">
                    <span
                        class="block truncate text-sm font-medium text-text-primary"
                    >
                        {{ user.name }}
                    </span>
                    <span class="block truncate text-xs text-text-muted">
                        {{ user.email }}
                    </span>
                </span>
                <ChevronUp
                    class="h-4 w-4 shrink-0 text-text-muted"
                    aria-hidden="true"
                />
            </button>
        </template>

        <template #content>
            <DropdownLink :href="route('profile.edit')">
                <span class="flex items-center gap-2">
                    <UserCog class="h-4 w-4" aria-hidden="true" />
                    Profile
                </span>
            </DropdownLink>
            <DropdownLink :href="route('logout')" method="post" as="button">
                <span class="flex items-center gap-2">
                    <LogOut class="h-4 w-4" aria-hidden="true" />
                    Log out
                </span>
            </DropdownLink>
        </template>
    </Dropdown>
</template>
