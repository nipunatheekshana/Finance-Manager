import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/**
 * Every screen below the app shell is lazy-loaded, so the first paint on a
 * phone only downloads the dashboard.
 */
const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { guest: true, title: 'Sign in' },
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/views/RegisterView.vue'),
    meta: { guest: true, title: 'Create account' },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('@/views/ForgotPasswordView.vue'),
    meta: { guest: true, title: 'Reset password' },
  },
  {
    path: '/reset-password/:token',
    name: 'reset-password',
    component: () => import('@/views/ResetPasswordView.vue'),
    meta: { guest: true, title: 'Choose a new password' },
  },
  {
    path: '/welcome',
    name: 'onboarding',
    component: () => import('@/views/OnboardingView.vue'),
    meta: { auth: true, bare: true, title: 'Set up' },
  },
  {
    path: '/',
    component: () => import('@/components/layout/AppShell.vue'),
    meta: { auth: true },
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('@/views/DashboardView.vue'),
        meta: { title: 'Home' },
      },
      {
        path: 'budget',
        name: 'budget',
        component: () => import('@/views/BudgetView.vue'),
        meta: { title: 'Budget' },
      },
      {
        path: 'plan',
        name: 'plan',
        component: () => import('@/views/MonthlyPlanView.vue'),
        meta: { title: 'Monthly plan' },
      },
      {
        path: 'expenses',
        name: 'expenses',
        component: () => import('@/views/ExpensesView.vue'),
        meta: { title: 'Expenses' },
      },
      {
        path: 'income',
        name: 'income',
        component: () => import('@/views/IncomeView.vue'),
        meta: { title: 'Income' },
      },
      {
        path: 'debts',
        name: 'debts',
        component: () => import('@/views/DebtsView.vue'),
        meta: { title: 'Debts' },
      },
      {
        path: 'debts/:id',
        name: 'debt-detail',
        component: () => import('@/views/DebtDetailView.vue'),
        meta: { title: 'Debt' },
      },
      {
        path: 'savings',
        name: 'savings',
        component: () => import('@/views/SavingsView.vue'),
        meta: { title: 'Savings' },
      },
      {
        path: 'savings/:id',
        name: 'savings-detail',
        component: () => import('@/views/SavingsDetailView.vue'),
        meta: { title: 'Goal' },
      },
      {
        path: 'reports',
        name: 'reports',
        component: () => import('@/views/ReportsView.vue'),
        meta: { title: 'Reports' },
      },
      {
        path: 'calendar',
        name: 'calendar',
        component: () => import('@/views/CalendarView.vue'),
        meta: { title: 'Calendar' },
      },
      {
        path: 'cycle',
        name: 'cycle-progress',
        component: () => import('@/views/CycleProgressView.vue'),
        meta: { title: 'Cycle progress' },
      },
      {
        path: 'cash-flow',
        name: 'cash-flow',
        component: () => import('@/views/CashFlowView.vue'),
        meta: { title: 'Cash flow' },
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('@/views/SettingsView.vue'),
        meta: { title: 'Settings' },
      },
      {
        path: 'settings/income',
        name: 'settings-income',
        component: () => import('@/views/settings/IncomeSetupView.vue'),
        meta: { title: 'Income setup' },
      },
      {
        path: 'settings/categories',
        name: 'settings-categories',
        component: () => import('@/views/settings/CategoriesView.vue'),
        meta: { title: 'Categories' },
      },
      {
        path: 'settings/payment-methods',
        name: 'settings-payment-methods',
        component: () => import('@/views/settings/PaymentMethodsView.vue'),
        meta: { title: 'Payment methods' },
      },
      {
        path: 'settings/recurring',
        name: 'settings-recurring',
        component: () => import('@/views/settings/RecurringView.vue'),
        meta: { title: 'Recurring expenses' },
      },
      {
        path: 'settings/notifications',
        name: 'settings-notifications',
        component: () => import('@/views/settings/NotificationsView.vue'),
        meta: { title: 'Notifications' },
      },
      {
        path: 'settings/security',
        name: 'settings-security',
        component: () => import('@/views/settings/SecurityView.vue'),
        meta: { title: 'Security' },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { title: 'Not found' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior(_to, _from, savedPosition) {
    return savedPosition ?? { top: 0 }
  },
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // Resolve the session once, before the first guarded navigation.
  if (!auth.initialised) {
    await auth.restore()
  }

  if (to.meta.auth && !auth.isAuthenticated) {
    return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }

  if (to.meta.guest && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }

  // A signed-in account that has not finished setup goes to the wizard first.
  if (auth.isAuthenticated && !auth.hasCompletedOnboarding && to.name !== 'onboarding') {
    return { name: 'onboarding' }
  }

  if (to.name === 'onboarding' && auth.hasCompletedOnboarding) {
    return { name: 'dashboard' }
  }

  return true
})

router.afterEach((to) => {
  const title = (to.meta.title as string | undefined) ?? 'Finance Manager'
  document.title = to.name === 'dashboard' ? 'Finance Manager' : `${title} · Finance Manager`
})

export default router
