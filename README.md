# Finance Manager

A personal money-management PWA built around one question: *my salary just
arrived — where should it go, and how much can I spend today?*

Laravel 13 + Vue 3 in a single application. All amounts are Sri Lankan Rupees.

---

## Quick start

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

# Point DB_* at a MySQL/MariaDB database, then:
php artisan migrate --seed

npm run build          # or: npm run dev
php artisan serve
```

Open <http://localhost:8000> and sign in with the seeded account:

```
demo@financemanager.test
password
```

The demo account is a worked example: LKR 280,000 salary on day 25, five
recurring bills, two credit cards tracked separately, an installment debt, an
emergency fund, a few weeks of logged spending, and a finished previous cycle
whose leftover is still waiting on a decision. Every figure on screen is
calculated from that data — nothing on the dashboard is hard-coded.

### Requirements

| | |
|---|---|
| PHP | 8.3+ (with `bcmath`) |
| Database | MySQL 8+ / MariaDB 10.6+ |
| Node | 20+ |

---

## How the money model works

### Amounts are never floats

Every monetary value is `DECIMAL(15,2)` in MySQL, a decimal string in PHP, and
a decimal string in TypeScript. Arithmetic goes through
[`App\Support\Money`](app/Support/Money.php), which wraps bcmath and rounds
half-up to two places. A value only becomes a JavaScript number at the moment
it is formatted for display or handed to a chart.

```php
Money::add('280000', '20000');   // "300000.00"
Money::split('90000', 4);        // ["22500.00", ...] — always sums back
Money::percentage('65000', '300000'); // 21.67
```

### The salary cycle, not the calendar month

A plan labelled **September 2026** is the plan funded by September's salary. If
the salary day is the 25th, that cycle runs 25 Sep → 24 Oct. With a salary day
of the 1st it collapses to the calendar month. Salary days beyond a short
month's length are clamped (31 → 28 in February).

Weeks are real calendar windows, not "week 1–4" fictions: a 30-day cycle is
split into four weeks of 8/8/7/7 days.

### One formula, one place

```
  total income
− fixed expenses
− debt payments
− savings
− buffer
= spending budget
```

This lives only in [`FinancialPlanService`](app/Services/FinancialPlanService.php).
The spending budget is always *derived*, never stored independently, so a plan
can never disagree with its own parts. If the parts exceed income, the plan is
over-allocated and cannot be finalised unless the user explicitly accepts a
deficit.

### Recurrences are counted, not estimated

A weekly expense is expanded across the real dates inside the cycle. A 30-day
cycle containing five Mondays costs five weeks of cigarettes, not four —
see [`RecurringTransactionService`](app/Services/RecurringTransactionService.php).

### Nothing moves without the user

When a week is overspent the app presents the options — reduce next week, use
the buffer, reduce a category, or ignore — with the exact effect of each, and
changes nothing until one is chosen. Every applied choice is written to
`budget_adjustments`.

### Going over a weekly limit is never silent

Recording an expense is never blocked: the money has already been spent, and a
tool that refuses to write it down stops being trusted. But crossing a weekly
limit is not allowed to slip past unnoticed either.

As the amount is typed, the form asks the server what the expense *would* do
([`ExpenseImpactService`](app/Services/ExpenseImpactService.php), `POST
/api/expenses/preview` — it writes nothing) and shows the answer live:

- under budget: "LKR 6,475 would be left for the rest of week 1 — that is
  LKR 925 a day for the 7 days remaining"
- near the limit: the same figures, in amber
- over the limit: **"This puts you LKR 2,500.00 over your week 1 budget"**, plus
  a checkbox reading *"I know this goes over — save it and let me choose what to
  do."* The save button is disabled until it is ticked, then reads "Save anyway".

The moment the expense saves, the write returns the week's new state and the
overspend choices open immediately — adjust next week, use the buffer, reduce a
category, or accept it — rather than waiting to be discovered on the Budget
screen later. It is the same sheet and the same audit trail; only the timing
changes.

A category limit that would be crossed is called out in the same panel, but it
does not gate the save: category budgets warn, they never block (§27).

### A credit card being paid down is still a card being used

Spending on a payment method linked to a debt raises that debt's balance
immediately, and the payoff estimate is recalculated from the real balance.
Payoff dates are always labelled as estimates.

### Leftover money does not evaporate

When a cycle ends with money unspent, that money is real — it is still in the
bank. Left untracked it silently falls out of the plan while the balance keeps
growing, so at month end the app asks what should happen to it:

```
LAST CYCLE LEFT OVER

  Unspent budget    LKR 17,400
  Unused buffer     LKR 20,000
  ─────────────────────────────
  Total             LKR 37,400
```

Five choices, each showing its exact effect before it is taken: pay down a
debt, move it to a savings goal, add it to next month's spending, split it
across several, or leave it in the bank.

The same rule as the overspend flow applies —
[`CycleSurplusService`](app/Services/CycleSurplusService.php) moves nothing
until a choice is made. Paying a debt records a real `DebtPayment` (an extra
payment, so it does not consume a scheduled installment); adding to savings
records a real `SavingsTransaction`. Carrying forward sets the next plan's
`opening_balance`, which counts toward its total income and therefore its
spending budget. Buffer swept out is marked used, so the plan's own figures
stay consistent if it is ever reopened.

A cycle can only be settled once, and only after it has actually ended.

### One payment method per card

An account can hold any number of credit cards. Each card is its own debt with
its own balance, limit and payoff estimate, and each gets its own payment
method — created automatically by
[`CardPaymentMethodService`](app/Services/CardPaymentMethodService.php) when the
card is added, whether that happens during onboarding or later from the Debts
screen.

That link is what makes spending attributable: charging something to "Amex Gold"
moves the Amex balance and nothing else. A single shared "Credit Card" entry
would quietly charge whichever card happened to be created first.

The details:

- The account's seeded generic "Credit Card" method is adopted (and renamed) by
  the first real card, so a one-card user is never asked to choose between
  "Credit Card" and their card's name.
- Renaming a card renames its method — unless the user renamed that method
  themselves, in which case their name is kept.
- A name clash gets a numeric suffix, since method names are unique per account.
- Deleting a card hides its method if it was never used, and keeps it if it has
  expenses, so history still reads correctly.
- Accounts that predate this behaviour can be backfilled with
  `php artisan finance:link-cards`.

Non-card debts (installments, loans) get no payment method: you make payments
against them, you do not spend on them.

---

## Architecture

### Backend

```
app/
  Support/Money.php            Decimal arithmetic (bcmath)
  Enums/                       DebtType, PlanStatus, Frequency, AlertType, …
  Models/                      21 Eloquent models
  Policies/                    Per-model ownership checks
  Services/
    SalaryCycleService           Cycle boundaries and week windows
    RecurringTransactionService  Expands recurrences onto real dates
    FinancialPlanService         The monthly plan calculation
    BudgetCalculationService     Monthly / weekly / daily / category budgets
    BudgetAdjustmentService      The overspend options and their effects
    ExpenseService               Expense writes, card linkage, offline sync
    CardPaymentMethodService     One payment method per credit card
    ExpenseImpactService         What an expense would do, before it is saved
    CycleSurplusService          What happens to a finished cycle's leftover
    DebtPaymentService           Payments, card charges, reversals
    DebtPayoffService            Payoff estimates with or without interest
    SavingsService               Deposits, withdrawals, transfers
    CashFlowService              What is left, what is coming, where it ends
    AffordabilityService         "Can I afford this?"
    FinancialHealthService       The 0–100 progress indicator
    ReportService                Report aggregations
    DashboardService             The whole home screen in one pass
    AlertService                 Dashboard alerts
    AuditService                 Audit trail
```

Controllers stay thin: they authorise, validate through a Form Request, call a
service, and return an API Resource.

### Frontend

```
resources/js/
  types/          Strict TypeScript models (money is a string everywhere)
  services/       api.ts (typed ApiError), offlineQueue.ts
  stores/         auth, ui, dashboard, budget, expenses, debts, savings
  composables/    useCurrency (formatLKR), useDates
  components/     common, layout, dashboard, expenses, budgets, debts,
                  savings, charts
  views/          One per screen, all lazily loaded
```

---

## Multi-tenancy

Many independent accounts share one database. Every financial record carries a
`user_id`, every model has a policy, and every query is scoped through the
signed-in user's relationships rather than a bare id from the request.

Authorisation runs *inside* the Form Request, before validation, so a request
naming another account's record is refused outright instead of returning a 422
that would confirm the record exists.

`MultiUserIsolationTest` proves this end to end: it populates two accounts with
their own bills, cards, goals, plans and spending, then asserts that every list
endpoint, dashboard figure, report and calendar is scoped to one account, and
that ~35 cross-account URLs are all denied.

## Security

- Sanctum session authentication over a shared origin — no token in
  `localStorage`. CSRF is enforced through the standard cookie flow. Session
  login always goes through the `web` guard explicitly rather than whichever
  guard happens to be the ambient default.
- Every model has a policy; every request that names an id checks ownership.
  Ids from the client are never trusted (`Rule::exists(...)->where('user_id')`).
- Rate limits: 5/min on credential endpoints keyed by both IP and email, 120/min
  on the authenticated API.
- API resources return only the fields a screen needs. Financial records are
  never written to the log.
- The audit trail records deliberate changes (plan reopened, budget adjusted,
  expense edited) with only the fields that changed — not a second copy of the
  user's finances.

---

## Offline support

Expense entry works without a connection. Each entry is queued locally with a
client-generated UUID; that UUID is the expense's identity server-side, so
replaying a queue — after a failed sync, a refresh, or two tabs at once —
cannot create duplicates. The queue flushes automatically on reconnect.

The service worker (`resources/js/sw.ts`, built to `/sw.js` so it can control
the whole origin) keeps the shell, the reference data and the last dashboard
response available offline.

---

## Testing

```bash
php artisan test           # 210 tests
npx vue-tsc --noEmit       # strict type check
npm run build
```

Tests run against MySQL rather than SQLite, because the reporting queries use
MySQL functions and the schema depends on MySQL index and foreign-key
behaviour. Create the test database once:

```sql
CREATE DATABASE finance_manager_test;
```

Coverage includes the money primitive, authentication and authorisation, the
salary-planning formula and over-allocation rules, weekly/daily/category budget
maths, the overspend adjustment flow, debt payoff and credit-card behaviour,
savings transfers, recurrence counting, cash flow, month-end surplus handling,
pre-save budget impact, and a full end-to-end acceptance run through the HTTP
API in `FullWorkflowTest`.

---

## Scheduled work

```bash
php artisan finance:refresh-alerts   # rebuild dashboard alerts (queued per user)
php artisan finance:close-plans      # close cycles that have ended
php artisan finance:link-cards       # backfill per-card payment methods (one-off)
```

Both are registered in `routes/console.php`. In production run
`php artisan schedule:work` (or a cron entry) and a queue worker.

---

## A note on the health score

The 0–100 figure is an app-generated progress indicator built from six weighted
factors, all defined in `FinancialHealthService::WEIGHTS`. It is not a credit
score or any kind of professional assessment, and the UI says so wherever it
appears. The same applies to payoff dates and month-end projections: they are
estimates from the user's own plan, labelled as such.
