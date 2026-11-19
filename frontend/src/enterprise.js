// Enterprise edition parts of the operator SPA (#250).
//
// They live in src/enterprise/ and are found with import.meta.glob, which
// matches nothing when that folder is absent: the Community Edition builds
// without it. The API says which features are on (GET /v1/me, meta.features);
// a view or component that exists here is still shown only when its feature is on.
import { defineAsyncComponent } from 'vue';

const views = import.meta.glob('./enterprise/views/*.vue');
const components = import.meta.glob('./enterprise/components/*.vue');

/** Routes of the enterprise views present in this build; meta.feature names the flag that enables each. */
export const enterpriseRoutes = [
  { path: '/plan', name: 'plan', view: 'PlanView', feature: 'plans' },
]
  .filter((r) => views[`./enterprise/views/${r.view}.vue`])
  .map((r) => ({ path: r.path, name: r.name, component: views[`./enterprise/views/${r.view}.vue`], meta: { feature: r.feature } }));

/** An enterprise component, loaded on first use; null when this build has none by that name. */
export function enterpriseComponent(name) {
  const loader = components[`./enterprise/components/${name}.vue`];
  return loader ? defineAsyncComponent(loader) : null;
}
