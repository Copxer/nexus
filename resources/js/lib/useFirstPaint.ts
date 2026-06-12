import { onMounted, ref } from 'vue';

/**
 * Spec 036 — distinguish "first paint" (cold page visit, render
 * skeleton) from "partial reload paint" (Echo/Inertia refresh,
 * keep data + don't re-flash).
 *
 * Pattern: a page's data wrapper renders skeleton until `firstPaint`
 * flips false, then renders the real markup. The flip happens on
 * the next animation frame after mount, so the initial paint shows
 * skeleton + the browser swaps to real data on the next frame. For
 * page-load durations <100ms the skeleton barely flashes; for slow
 * loads (cold DB, big payload) the skeleton holds until the
 * Inertia visit resolves.
 *
 * For partial reloads (`router.reload({ only: [...] })`) the
 * component instance stays mounted, so `firstPaint` is already
 * `false` and no skeleton appears.
 */
export function useFirstPaint() {
    const firstPaint = ref(true);

    onMounted(() => {
        requestAnimationFrame(() => {
            firstPaint.value = false;
        });
    });

    return { firstPaint };
}
