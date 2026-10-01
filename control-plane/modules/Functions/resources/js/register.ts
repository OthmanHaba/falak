import { registerCreateOptions, registerServiceSettingsSections, registerServiceTabs } from '@/lib/registry';
import { SquareFunction } from 'lucide-react';
import { lazy } from 'react';

// Panel code (and Monaco) loads only when a function's tab opens.
const CodeTab = lazy(() => import('./components/code-tab').then((module) => ({ default: module.CodeTab })));
const VersionsTab = lazy(() => import('./components/versions-tab').then((module) => ({ default: module.VersionsTab })));
const SchedulesTab = lazy(() => import('./components/schedules-tab').then((module) => ({ default: module.SchedulesTab })));
const ScalingSettings = lazy(() => import('./components/scaling-settings').then((module) => ({ default: module.ScalingSettings })));
const FunctionStep = lazy(() => import('./components/function-step').then((module) => ({ default: module.FunctionStep })));

const isFunction = (service: { icon: string }) => service.icon === 'function';

// Canvas Create picker → Function (docs/plans/FUNCTIONS.md).
registerCreateOptions({
    id: 'function',
    title: 'Function',
    description: 'Write a Bun + Hono function here; it scales to zero when idle.',
    icon: SquareFunction,
    order: 450,
    permission: 'functions.create',
    stepTitle: 'New function',
    wide: true,
    component: FunctionStep,
});

// Service panel: Code before Deployments, Versions and Schedules after it.
registerServiceTabs(
    { id: 'code', kinds: ['site'], title: 'Code', order: 50, permission: 'functions.view', when: isFunction, component: CodeTab },
    { id: 'versions', kinds: ['site'], title: 'Versions', order: 120, permission: 'functions.view', when: isFunction, component: VersionsTab },
    { id: 'schedules', kinds: ['site'], title: 'Schedules', order: 130, permission: 'functions.view', when: isFunction, component: SchedulesTab },
);

// Settings tab → Scaling (with Deploy's section order).
registerServiceSettingsSections({
    id: 'functions.scaling',
    kinds: ['site'],
    section: 'scaling',
    sectionTitle: 'Scaling',
    order: 250,
    permission: 'functions.view',
    when: (ctx) => isFunction(ctx.service),
    component: ScalingSettings,
});
