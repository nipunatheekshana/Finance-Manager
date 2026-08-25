import type { ExpenseDraft, QueuedExpense } from '@/types'

/**
 * A small local queue for expenses captured without a connection.
 *
 * Each entry carries a client-generated uuid. The server treats that uuid as
 * the identity of the expense, so replaying the queue — after a failed sync, a
 * refresh, or two tabs syncing at once — can never create duplicates.
 */
const STORAGE_KEY = 'fm-pending-expenses'

function safeRead(): QueuedExpense[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return []
    const parsed: unknown = JSON.parse(raw)
    return Array.isArray(parsed) ? (parsed as QueuedExpense[]) : []
  } catch {
    return []
  }
}

function safeWrite(entries: QueuedExpense[]): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(entries))
  } catch {
    /* Quota exceeded or storage disabled; the queue is best-effort. */
  }
}

export function newClientUuid(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID()
  }

  // Fallback for older WebViews.
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
    const random = (Math.random() * 16) | 0
    const value = char === 'x' ? random : (random & 0x3) | 0x8
    return value.toString(16)
  })
}

export const offlineQueue = {
  all(): QueuedExpense[] {
    return safeRead()
  },

  count(): number {
    return safeRead().length
  },

  /** Queue a draft, returning the entry that was stored. */
  enqueue(draft: ExpenseDraft): QueuedExpense {
    const entry: QueuedExpense = {
      ...draft,
      client_uuid: draft.client_uuid ?? newClientUuid(),
      queued_at: new Date().toISOString(),
    }

    const entries = safeRead()
    entries.push(entry)
    safeWrite(entries)

    return entry
  },

  /** Remove the entries that the server confirmed. */
  remove(clientUuids: string[]): void {
    const remove = new Set(clientUuids)
    safeWrite(safeRead().filter((entry) => !remove.has(entry.client_uuid)))
  },

  clear(): void {
    safeWrite([])
  },
}
