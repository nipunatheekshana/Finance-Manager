import axios, {
  AxiosError,
  type AxiosInstance,
  type AxiosRequestConfig,
  type AxiosResponse,
} from 'axios'
import type { ValidationErrors } from '@/types'

/**
 * A single typed error shape for the whole app, so views never have to reason
 * about raw Axios internals.
 */
export class ApiError extends Error {
  readonly status: number
  readonly errors: ValidationErrors
  readonly isNetworkError: boolean

  constructor(message: string, status: number, errors: ValidationErrors = {}, isNetworkError = false) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
    this.isNetworkError = isNetworkError
  }

  /** The first message for a field, for inline display next to an input. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }

  get isValidation(): boolean {
    return this.status === 422
  }

  get isUnauthenticated(): boolean {
    return this.status === 401
  }

  get isForbidden(): boolean {
    return this.status === 403
  }

  get isThrottled(): boolean {
    return this.status === 429
  }
}

/** Called when the session has expired, wired up by the auth store. */
type UnauthenticatedHandler = () => void
let onUnauthenticated: UnauthenticatedHandler | null = null

export function setUnauthenticatedHandler(handler: UnauthenticatedHandler): void {
  onUnauthenticated = handler
}

/** Emitted for errors worth showing as a toast; the UI subscribes to this. */
type ErrorListener = (error: ApiError) => void
const errorListeners = new Set<ErrorListener>()

export function onApiError(listener: ErrorListener): () => void {
  errorListeners.add(listener)
  return () => errorListeners.delete(listener)
}

const client: AxiosInstance = axios.create({
  baseURL: '/api',
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  // Same-origin session cookie auth, so no token is ever kept in storage.
  withCredentials: true,
  withXSRFToken: true,
  timeout: 20000,
})

/**
 * Human wording for the failures a user can actually act on. Technical detail
 * stays in the server logs.
 */
function friendlyMessage(status: number, serverMessage?: string): string {
  switch (status) {
    case 401:
      return 'Your session has expired. Please sign in again.'
    case 403:
      return 'You do not have access to that.'
    case 404:
      return 'We could not find what you were looking for.'
    case 419:
      return 'Your session expired. Please refresh the page and try again.'
    case 422:
      return serverMessage || 'Please check the highlighted fields.'
    case 429:
      return 'That is a lot of requests at once. Please wait a moment and try again.'
    case 500:
    case 502:
    case 503:
    case 504:
      return 'Something went wrong on our side. Please try again in a moment.'
    default:
      return serverMessage || 'Something went wrong. Please try again.'
  }
}

client.interceptors.response.use(
  (response) => response,
  (error: AxiosError<{ message?: string; errors?: ValidationErrors }>) => {
    // No response at all: offline, DNS failure or a timeout.
    if (!error.response) {
      const apiError = new ApiError(
        navigator.onLine
          ? 'We could not reach the server. Please try again.'
          : 'You are offline. We will retry when you reconnect.',
        0,
        {},
        true,
      )
      errorListeners.forEach((listener) => listener(apiError))
      return Promise.reject(apiError)
    }

    const { status, data } = error.response
    const apiError = new ApiError(
      friendlyMessage(status, data?.message),
      status,
      data?.errors ?? {},
    )

    if (status === 401 || status === 419) {
      onUnauthenticated?.()
    }

    // Validation errors belong beside the fields, not in a global toast.
    if (status !== 422) {
      errorListeners.forEach((listener) => listener(apiError))
    }

    return Promise.reject(apiError)
  },
)

/**
 * Prime the CSRF cookie. Required once before the first state-changing request
 * of a session.
 */
export async function ensureCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

async function unwrap<T>(promise: Promise<AxiosResponse<T>>): Promise<T> {
  const response = await promise
  return response.data
}

export const api = {
  get: <T>(url: string, config?: AxiosRequestConfig): Promise<T> => unwrap(client.get<T>(url, config)),

  post: <T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> =>
    unwrap(client.post<T>(url, data, config)),

  put: <T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> =>
    unwrap(client.put<T>(url, data, config)),

  patch: <T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> =>
    unwrap(client.patch<T>(url, data, config)),

  delete: <T>(url: string, config?: AxiosRequestConfig): Promise<T> =>
    unwrap(client.delete<T>(url, config)),
}

export default client
