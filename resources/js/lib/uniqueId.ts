/**
 * Module-level monotonic ID generator. Used by SVG components that need a
 * stable, collision-free `id` for `<defs>` references (gradients, masks,
 * filters). Module scope makes the counter shared across all component
 * instances in the same JS realm.
 *
 * Hydration-safe for the current CSR-only Inertia mount. When SSR is
 * enabled, swap call sites to Vue 3.5+'s `useId()` for true SSR/CSR
 * symmetry.
 */
let counter = 0;

export function uniqueId(prefix = 'id'): string {
    counter += 1;
    return `${prefix}-${counter}`;
}
