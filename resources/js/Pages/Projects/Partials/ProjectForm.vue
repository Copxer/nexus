<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { projectIconNames, projectIconRegistry } from '@/lib/projectIcons';
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface OptionPill {
    value: string;
    label: string;
    tone: string;
}

interface Options {
    statuses: OptionPill[];
    priorities: OptionPill[];
    colors: string[];
    icons: string[];
}

interface ProjectShape {
    name: string;
    description: string | null;
    status: string | null;
    priority: string | null;
    environment: string | null;
    color: string | null;
    icon: string | null;
}

const props = defineProps<{
    /** Submit endpoint URL. */
    action: string;
    /** HTTP method — Inertia translates `patch` for the update route. */
    method: 'post' | 'patch';
    /** Existing values when editing; sensible defaults when creating. */
    initial: ProjectShape;
    /** Static option lists from the controller. */
    options: Options;
    /** Cancel-target route URL (Index for new, Show for edit). */
    cancelTo: string;
    /** Submit button label. */
    submitLabel: string;
}>();

const form = useForm({
    name: props.initial.name ?? '',
    description: props.initial.description ?? '',
    status: props.initial.status ?? props.options.statuses[0]?.value ?? 'active',
    priority: props.initial.priority ?? props.options.priorities[1]?.value ?? 'medium',
    environment: props.initial.environment ?? '',
    color: props.initial.color ?? props.options.colors[0] ?? null,
    icon: props.initial.icon ?? props.options.icons[0] ?? null,
});

// Map color tokens → swatch classes. Keeps the swatch picker consistent
// with the KpiCard accent vocabulary.
const swatchClass = (color: string): string =>
    (
        ({
            cyan: 'bg-accent-cyan',
            blue: 'bg-accent-blue',
            purple: 'bg-accent-purple',
            magenta: 'bg-accent-magenta',
            success: 'bg-status-success',
            warning: 'bg-status-warning',
        }) as const
    )[color] ?? 'bg-text-muted';

// Tone → pill classes for status/priority radio swatches.
const tonePill = (tone: string): string =>
    (
        ({
            success:
                'border-status-success/40 bg-status-success/10 text-status-success',
            warning:
                'border-status-warning/40 bg-status-warning/10 text-status-warning',
            danger:
                'border-status-danger/40 bg-status-danger/10 text-status-danger',
            info: 'border-status-info/40 bg-status-info/10 text-status-info',
            muted: 'border-border-subtle bg-background-panel-hover text-text-muted',
        }) as const
    )[tone] ?? 'border-border-subtle bg-background-panel-hover text-text-muted';

const validIcons = computed(() =>
    props.options.icons.filter((name): name is (typeof projectIconNames)[number] =>
        (projectIconNames as readonly string[]).includes(name),
    ),
);

const submit = () => {
    if (props.method === 'post') {
        form.post(props.action);
    } else {
        form.patch(props.action);
    }
};
</script>

<template>
    <form class="flex flex-col gap-6" @submit.prevent="submit">
        <!-- Name -->
        <div>
            <InputLabel for="project-name" value="Name" />
            <TextInput
                id="project-name"
                v-model="form.name"
                type="text"
                class="mt-1 block w-full"
                required
                autocomplete="off"
                autofocus
            />
            <InputError class="mt-2" :message="form.errors.name" />
        </div>

        <!-- Description -->
        <div>
            <InputLabel for="project-description" value="Description" />
            <textarea
                id="project-description"
                v-model="form.description"
                rows="3"
                class="mt-1 block w-full rounded-md border-border-subtle bg-background-panel-hover/60 text-text-primary shadow-sm focus:border-accent-cyan focus:ring-accent-cyan"
                placeholder="What is this project about?"
            />
            <InputError class="mt-2" :message="form.errors.description" />
        </div>

        <!-- Status -->
        <fieldset>
            <InputLabel value="Status" />
            <div class="mt-2 flex flex-wrap gap-2">
                <label
                    v-for="status in options.statuses"
                    :key="status.value"
                    class="cursor-pointer rounded-full border px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.18em] transition focus-within:ring-2 focus-within:ring-accent-cyan/60"
                    :class="[
                        tonePill(status.tone),
                        form.status === status.value
                            ? 'shadow-[inset_0_0_0_1px_currentColor]'
                            : 'opacity-60 hover:opacity-100',
                    ]"
                >
                    <input
                        v-model="form.status"
                        type="radio"
                        name="project-status"
                        :value="status.value"
                        class="sr-only"
                    />
                    {{ status.label }}
                </label>
            </div>
            <InputError class="mt-2" :message="form.errors.status" />
        </fieldset>

        <!-- Priority -->
        <fieldset>
            <InputLabel value="Priority" />
            <div class="mt-2 flex flex-wrap gap-2">
                <label
                    v-for="priority in options.priorities"
                    :key="priority.value"
                    class="cursor-pointer rounded-full border px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.18em] transition focus-within:ring-2 focus-within:ring-accent-cyan/60"
                    :class="[
                        tonePill(priority.tone),
                        form.priority === priority.value
                            ? 'shadow-[inset_0_0_0_1px_currentColor]'
                            : 'opacity-60 hover:opacity-100',
                    ]"
                >
                    <input
                        v-model="form.priority"
                        type="radio"
                        name="project-priority"
                        :value="priority.value"
                        class="sr-only"
                    />
                    {{ priority.label }}
                </label>
            </div>
            <InputError class="mt-2" :message="form.errors.priority" />
        </fieldset>

        <!-- Environment -->
        <div>
            <InputLabel for="project-environment" value="Environment (optional)" />
            <TextInput
                id="project-environment"
                v-model="form.environment"
                type="text"
                class="mt-1 block w-full"
                placeholder="production, staging, internal…"
                autocomplete="off"
            />
            <InputError class="mt-2" :message="form.errors.environment" />
        </div>

        <!-- Color picker -->
        <fieldset>
            <InputLabel value="Color" />
            <div class="mt-2 flex flex-wrap gap-3">
                <label
                    v-for="color in options.colors"
                    :key="color"
                    class="cursor-pointer rounded-full p-1 transition focus-within:ring-2 focus-within:ring-accent-cyan/60"
                    :class="[
                        form.color === color
                            ? 'ring-2 ring-accent-cyan'
                            : 'opacity-60 hover:opacity-100',
                    ]"
                >
                    <input
                        v-model="form.color"
                        type="radio"
                        name="project-color"
                        :value="color"
                        class="sr-only"
                    />
                    <span
                        class="block h-6 w-6 rounded-full border border-border-subtle"
                        :class="swatchClass(color)"
                        :title="color"
                        aria-hidden="true"
                    />
                    <span class="sr-only">{{ color }}</span>
                </label>
            </div>
            <InputError class="mt-2" :message="form.errors.color" />
        </fieldset>

        <!-- Icon picker -->
        <fieldset>
            <InputLabel value="Icon" />
            <div class="mt-2 grid grid-cols-6 gap-2 sm:grid-cols-12">
                <label
                    v-for="iconName in validIcons"
                    :key="iconName"
                    class="flex cursor-pointer items-center justify-center rounded-lg border p-2 transition focus-within:ring-2 focus-within:ring-accent-cyan/60"
                    :class="
                        form.icon === iconName
                            ? 'border-accent-cyan/60 bg-accent-cyan/10 text-accent-cyan'
                            : 'border-border-subtle bg-background-panel-hover/40 text-text-muted hover:text-text-primary'
                    "
                >
                    <input
                        v-model="form.icon"
                        type="radio"
                        name="project-icon"
                        :value="iconName"
                        class="sr-only"
                    />
                    <component
                        :is="projectIconRegistry[iconName]"
                        class="h-4 w-4"
                        aria-hidden="true"
                    />
                    <span class="sr-only">{{ iconName }}</span>
                </label>
            </div>
            <InputError class="mt-2" :message="form.errors.icon" />
        </fieldset>

        <!-- Actions. Cancel uses router.visit() rather than wrapping a
             button inside a Link — the latter renders <a><button>, which
             is invalid HTML and confuses screen readers. -->
        <div class="flex items-center gap-3">
            <PrimaryButton :disabled="form.processing">
                {{ submitLabel }}
            </PrimaryButton>
            <SecondaryButton type="button" @click="router.visit(cancelTo)">
                Cancel
            </SecondaryButton>
        </div>
    </form>
</template>
