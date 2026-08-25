import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { onApiError } from './services/api'
import { useUiStore } from './stores/ui'
import { useExpenseStore } from './stores/expenses'
import { registerServiceWorker } from './registerServiceWorker'

const app = createApp(App)

app.use(createPinia())
app.use(router)

const ui = useUiStore()
ui.applyTheme()
ui.watchConnectivity()

// Surface non-validation API failures as a toast. Validation errors are shown
// inline against the field that caused them.
onApiError((error) => {
  if (error.isUnauthenticated) return
  ui.error(error.isNetworkError ? 'Connection problem' : 'Something went wrong', error.message)
})

// Flush anything captured offline as soon as the connection comes back.
window.addEventListener('online', () => {
  void (async () => {
    const expenses = useExpenseStore()
    try {
      const synced = await expenses.syncPending()
      if (synced > 0) {
        ui.success(
          `${synced} ${synced === 1 ? 'expense' : 'expenses'} synced`,
          'Your offline entries are now saved.',
        )
      }
    } catch {
      // Leave the queue intact; the next reconnect will retry it.
    }
  })()
})

app.mount('#app')

registerServiceWorker(ui)
