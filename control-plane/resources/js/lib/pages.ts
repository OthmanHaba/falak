// Inertia page names that exist in this build (same resolution as app.tsx). Lets the shell avoid linking to
// pages a module hasn't shipped yet (e.g. Projects/Canvas while only the Projects backend exists).
const modulePages = import.meta.glob('../../../modules/*/resources/js/pages/**/*.tsx');
const appPages = import.meta.glob('../pages/**/*.tsx');

export function hasPage(name: string): boolean {
    const [module, ...rest] = name.split('/');

    return (
        (rest.length > 0 && `../../../modules/${module}/resources/js/pages/${rest.join('/')}.tsx` in modulePages) ||
        `../pages/${name}.tsx` in appPages
    );
}

/** Whether the Projects UI (grid + canvas) is available to link to. */
export const projectsUi = { index: () => hasPage('Projects/Index'), canvas: () => hasPage('Projects/Canvas') };
