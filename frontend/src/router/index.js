import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useTenantStore } from '../stores/tenant';
import LoginView from '../views/LoginView.vue';
import DashboardView from '../views/DashboardView.vue';
import MonitorListView from '../views/MonitorListView.vue';
import MonitorDetailView from '../views/MonitorDetailView.vue';
import MonitorFormView from '../views/MonitorFormView.vue';
import IncidentListView from '../views/IncidentListView.vue';
import MaintenanceWindowView from '../views/MaintenanceWindowView.vue';
import NotificationChannelView from '../views/NotificationChannelView.vue';
import SettingsView from '../views/SettingsView.vue';
import ApiKeysView from '../views/ApiKeysView.vue';
import SetupView from '../views/SetupView.vue';
import StatusPageView from '../views/StatusPageView.vue';
import MembersView from '../views/MembersView.vue';
import PlatformAdminView from '../views/PlatformAdminView.vue';
import TermsAcceptanceView from '../views/TermsAcceptanceView.vue';
import { enterpriseRoutes } from '../enterprise';

const routes = [
  { path: '/login', name: 'login', component: LoginView },
  { path: '/setup', name: 'setup', component: SetupView },
  { path: '/', redirect: '/dashboard' },
  { path: '/dashboard', name: 'dashboard', component: DashboardView },
  { path: '/monitors', name: 'monitors', component: MonitorListView },
  { path: '/monitors/create', name: 'monitor-create', component: MonitorFormView, meta: { write: true } },
  { path: '/monitors/:id', name: 'monitor-detail', component: MonitorDetailView },
  { path: '/monitors/:id/edit', name: 'monitor-edit', component: MonitorFormView, meta: { write: true } },
  { path: '/incidents', name: 'incidents', component: IncidentListView },
  { path: '/maintenance-windows', name: 'maintenance-windows', component: MaintenanceWindowView },
  { path: '/notification-channels', name: 'notification-channels', component: NotificationChannelView },
  { path: '/status-page', name: 'status-page', component: StatusPageView },
  { path: '/members', name: 'members', component: MembersView },
  { path: '/administration', name: 'administration', component: PlatformAdminView },
  { path: '/terms', name: 'terms', component: TermsAcceptanceView },
  { path: '/api-keys', name: 'api-keys', component: ApiKeysView },
  { path: '/settings', name: 'settings', component: SettingsView },
  // Enterprise views (#250), when this build has them.
  ...enterpriseRoutes,
];

// The SPA is only served under /app/ (see routes/web.php's Route::get('/app/{any?}', ...)
// catch-all) — Vite's build `base: '/app/'` (vite.config.js) already accounts for this when
// resolving asset URLs, but Vue Router's history mode defaults to base '/' and knew nothing
// about it. Every route resolved to a root-relative URL like /monitors instead of
// /app/monitors: in-app (pushState) navigation looked fine since the JS was already loaded,
// but a hard refresh, a typed URL, or a bookmark on any route other than /app/ itself hit no
// matching Laravel route at all and 404'd before the SPA ever loaded.
const router = createRouter({
  history: createWebHistory('/app/'),
  routes,
});

// LoginController never returns a bearer token — auth is entirely the
// Sanctum session cookie. Pinia state (authStore.user) is empty on every
// fresh page load (hard refresh, typed URL, new tab), so without this,
// isAuthenticated is always false on cold boot even with a perfectly
// valid session cookie, and the guard below would bounce straight to
// /login without ever checking. Try to restore the session once per app
// load before making that call.
let didAttemptSessionRestore = false;
// Separate from didAttemptSessionRestore: tracks whether a *tenant-context*
// fetch was already attempted this app load. Without this, every single
// navigation while tenantStore.tenant stays empty (e.g. the account
// genuinely has no memberships, or one fetch attempt failed) would refire
// fetchMe()+fetchTenantContext() on every click — each one capable of
// calling logout() on any transient hiccup and bouncing back to /login,
// which looks like "nothing ever loads" no matter what's clicked.
let didAttemptTenantFetch = false;

router.beforeEach(async (to) => {
  const authStore = useAuthStore();

  if (!authStore.user && !didAttemptSessionRestore) {
    didAttemptSessionRestore = true;
    await authStore.fetchMe();
  }

  if (to.name === 'login') {
    return authStore.isAuthenticated ? { name: 'dashboard' } : true;
  }

  // First-run setup is public: SetupView itself asks the API whether setup is
  // still required and shows "already done" otherwise.
  if (to.name === 'setup') {
    return authStore.isAuthenticated ? { name: 'dashboard' } : true;
  }

  if (!authStore.isAuthenticated) {
    return { name: 'login' };
  }

  // Terms of Use and Privacy Policy not yet accepted (current version): nothing else until accepted.
  if (authStore.user?.terms?.required) {
    return to.name === 'terms' ? true : { name: 'terms', query: { next: to.fullPath } };
  }
  if (to.name === 'terms') {
    return { name: 'dashboard' };
  }

  // A restored/surviving session may still be missing
  // current_tenant_id/current_environment_id (e.g. before tenant context
  // resolution existed). Resolve it once per app load before letting any
  // authenticated route render.
  const tenantStore = useTenantStore();
  if (!tenantStore.tenant && !didAttemptTenantFetch) {
    didAttemptTenantFetch = true;
    await authStore.fetchMe();

    // fetchMe() calls logout() internally on a stale/invalid session
    // instead of throwing, so isAuthenticated must be re-checked here —
    // otherwise a dead session silently lets the user through to a page
    // with no tenant context (e.g. an empty monitors list, no error shown).
    if (!authStore.isAuthenticated) {
      return { name: 'login' };
    }
  }

  // A view of a feature that is off (#250): back to the dashboard.
  if (to.meta.feature && !authStore.features?.[to.meta.feature]) {
    return { name: 'dashboard' };
  }

  // Viewers are read-only (#160): the create/edit forms lead back to the monitors.
  if (to.meta.write && !tenantStore.canWrite) {
    return to.params.id ? { name: 'monitor-detail', params: { id: to.params.id } } : { name: 'monitors' };
  }

  return true;
});

export default router;
