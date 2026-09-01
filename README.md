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
demo@financemanager.test        salaried
freelance@financemanager.test   self-employed, paying themselves a draw
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

### Not everyone earns a salary

The app supports four ways of earning, chosen during onboarding and changeable
in Settings whenever work changes — an employee starts a business, a business
owner takes a job, plenty of people do both:

| Mode | Cycle runs | Plan is funded by |
|---|---|---|
| Employed | pay day → day before next pay day | the salary |
| Freelance / project | calendar month | a **draw** you pay yourself |
| Business owner | calendar month | a forecast from recent months |
| Both | pay day | salary **+** draw |

The idea that makes lumpy income workable is the **draw**. You earn 320,000 in
one month and 95,000 the next, but you pay yourself a steady 180,000. Income
collects in a holding pot, the plan draws from it, and what is left is
**runway** — "3.1 months at your current draw".

Because the draw plays exactly the role a salary plays, everything downstream
works unchanged: weekly budgets, daily limits, overspend handling, month-end
surplus. Only [`IncomeForecastService`](app/Services/IncomeForecastService.php)
knows the difference.

Four funding methods are available, with a recommended default per mode:

- **Fixed** — a known salary, the same every cycle.
- **Draw** — a steady self-paid amount, backed by the holding pot.
- **Forecast** — a rolling average of recent cycles, discounted (default 80%)
  so one good month cannot set an unaffordable budget.
- **Only what has arrived** — strict envelope budgeting; nothing can be
  allocated before it is in the bank.

Income is a real ledger: expected, invoiced and received are distinct states,
and **only received income counts toward what you can spend**. Overdue invoices,
low runway and a cycle running behind plan each raise their own alert.

#### Switching modes safely

Three rules, enforced by [`IncomeModeService`](app/Services/IncomeModeService.php):

1. **Finished cycles are never rewritten.** Each plan records the funding method
   it was built with, so old months keep reading correctly.
2. **A cycle-anchor change waits for the next boundary.** Moving cycle dates
   under a plan someone is actively spending against would invalidate their
   weekly budgets, so the switch is deferred and the UI says exactly when it
   lands.
3. **Income sources are archived, never deleted**, so history keeps its labels.

A preview shows all of this before anything is saved.

### Cycles and weeks

A plan labelled **September 2026** is the plan funded by September's pay. With a
pay day of the 25th that cycle runs 25 Sep → 24 Oct; with a calendar-month
anchor it is simply 1 Sep → 30 Sep. Pay days beyond a short month's length are
clamped (31 → 28 in February).

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

### Spending that adds up, not spending that arrives as a bill

Fuel, groceries, eating out: not one payment on a due day, and not really
discretionary either. These get an **allowance** — an amount set aside in the
plan and drawn down through the cycle.

The distinction that matters is against a plain category budget:

| | Category budget | Allowance |
|---|---|---|
| Reserved out of income | no | **yes** |
| Counts against your daily/weekly budget | yes | no — it has its own pot |
| What it does | warns you | ring-fences the money |

So a 50,000 fuel allowance leaves the plan's spending budget 50,000 lower, and
fuel spending draws that pot down instead of competing with day-to-day money.
Spending it is never counted twice: `BudgetCalculationService` covers spending
in an allowance category **up to the amount reserved** and keeps it out of the
weekly and daily pools, precisely because that money was already taken out of
income when the plan was built.

Past the reserved amount, the pot is empty and the money has to come from
somewhere real — so anything beyond it spills into day-to-day spending and
counts against the week it happened in
(`discretionarySpentBetween()`). Overspending fuel therefore tightens this
week's budget rather than quietly enlarging the plan.

Money reserved and never spent is the mirror image: it is still in the bank, so
it joins the month-end leftover below rather than disappearing.

Percentage used is close to meaningless for something spent gradually — 60%
gone is fine on day 20 and alarming on day 3 — so each allowance also reports
what is left **per remaining day** and whether it is running ahead of an even
pace. That is what the dashboard leads with.

Turn a category budget into an allowance with one toggle in Settings →
Categories — the toggle needs a monthly amount, since an allowance has to have
something to reserve. Adjust the amounts for a single cycle in the planner's
**Allowances** step, without touching the standing default.

Adding one to a cycle already under way means reopening the plan: a live plan
goes back to draft so every step is editable, then you finalise again. The
weekly budgets are re-cut on that second finalise, because the pool they divide
has just shrunk — leaving them alone would keep handing out the old, larger
figure every week.

### Recording the salary twice is a correction, not a second pay cheque

Salary day gets re-entered: a typo is fixed, or the figure is saved again once
the transfer actually lands. Both halves of that step are therefore
idempotent — the plan's salary row is *updated* rather than added to, and only
the **difference** in extra income is split across debts and savings. Revise
the figure downward and the split is taken back out, in the reverse order it
went in, never below what has already been paid or saved.

`extra_income_applied` on the plan is what makes the second part possible: it
records how much extra has actually been distributed, which `extra_income`
alone cannot say.

Accounts that recorded a salary twice before this have inflated income
ledgers. `php artisan finance:dedupe-salaries` reports them and removes them
with `--force`, keeping the most recent figure.

### One board for the whole cycle

Every part of a plan reported its own progress somewhere — bills on the
dashboard, debt on the debt screen, allowances on the budget screen, spending
in the weeks — and nowhere put them side by side. **Cycle progress** does:
income, bills, allowances, debt, savings, day-to-day spending and buffer, each
with what was planned, what has actually happened, and whether it is done.

Two ideas make the board readable:

- **Progress is measured against time.** 40% settled means something very
  different on day 3 and on day 27, so the headline shows commitments settled
  *and* how far the cycle has run, and calls the plan behind only when the
  first falls more than five points below the second.
- **Only commitments count.** Bills, debt and savings are obligations to
  discharge; day-to-day spending is money to *use*. Counting spending as
  progress would make an underspent cycle look like a failing one, so it is
  tracked in its own section and left out of the headline.

Skipped and postponed bills stay listed but stop counting as owed, so the
board never reports a debt to something the plan already dropped. Past cycles
are readable from the same screen by picking them from the list.

[`CycleProgressService`](app/Services/CycleProgressService.php) assembles it
from the services that already do the arithmetic; it computes nothing new.

### A debt taken on mid-cycle

A loan opened on the 10th is real money owed this month, but the plan was
balanced without it — and `updateAllocations` could only change allocations
that already existed, so there was no way to add one at all.

The planner's debt step now lists any debt missing from the cycle and offers to
add it. Because the plan is already running, exactly one thing has to give, and
the user picks which — each choice showing the figure it would leave behind:

| Source | What changes |
|---|---|
| Day-to-day money | The spending budget shrinks, and the **remaining** weeks are re-cut — never below what a week has already spent |
| Buffer | The safety net shrinks; weekly budgets are untouched |
| Save less this month | Lowest-priority goal first, but only money **not yet moved** into the goal |
| Take this month's saving back | A real withdrawal from the goal, when the money has already gone in |
| Another debt | Lowest-interest debt first, never below what is already paid |

The last two are separate on purpose. Reducing a plan's savings allocation
cannot touch money that is already sitting in the goal, so once a deposit is
made that lever gives nothing — and the honest answer is a withdrawal, which
moves real money and belongs on the goal's history where it can be seen.

The floors are the point: money already spent, saved or paid cannot be taken
back, so [`PlanCommitmentService`](app/Services/PlanCommitmentService.php)
refuses a source that cannot cover the payment rather than quietly taking what
it can. Every addition is written to the audit trail with the source that
funded it.

### Nothing moves without the user

When a week is overspent the app presents the options — reduce next week, use
the buffer, reduce a category, or ignore — with the exact effect of each, and
changes nothing until one is chosen. Every applied choice is written to
`budget_adjustments`.

An alert is a statement about the present, so it is withdrawn the moment its
condition clears. Covering an overspend takes the banner down; covering only
part of it leaves the banner up with the smaller figure; deleting the expense
that caused it clears it too. Every path that changes a week re-checks its
alerts inside `BudgetAdjustmentService`, so no caller can leave a banner
contradicting the figures beside it.

"Take it from next week" is a **move**, so it has two halves: the later week
gives the money up and the overspent week receives it. Only the first half
existed at one point, which left the user poorer next week and still over this
week. A week can only give what it has, so the amount recorded is what actually
moved, and the cycle's weekly total is unchanged by the transfer.

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

Spending against an **allowance** is the exception, because the week is not
paying for it. The preview asks the allowance first and only charges the week
with whatever spills past it, so a 15,000 fuel bill against a 20,000 fuel
allowance reads *"Comes out of your Transport allowance — 5,000 left of
20,000"*, with no weekly warning and no checkbox to tick. Where it is split,
the panel says so outright: *"5,000 from your Transport allowance, the last
3,000 from this week."* Warning someone for spending exactly what they set
aside is how a budget loses its authority.

### Anything owed this cycle can be settled early

"Still to pay this cycle" lists both kinds of commitment together — fixed bills
and the debt instalments planned for the cycle — sorted by due date, because
splitting them across two screens only hid the instalments.

Tap a bill and it is payable in place: confirm the amount and it leaves the
list. Typing a
different figure records it as the actual amount while the plan keeps what was
budgeted, so the two stay comparable.

Tap a debt instalment and it opens that debt's payment screen with the
payment already started (`/debts/{id}?pay=1`), because a card payment needs the
real balance and the payoff maths beside it.

The amount is pre-filled with **what this cycle still asks for**, not the
debt's standing planned payment — the planner can change the figure for a
single month, and part of it may already be paid. The sheet says which it is
("Planned this cycle · 6,000 already paid") so the number is never a mystery.
The date defaults to today, so paying the 15th's card bill on the 2nd is just a
payment on the 2nd: `DebtPaymentService` credits it to whichever cycle contains
the payment date, so the plan's allocation shows it as paid and the instalment
drops off the list.

Settling a bill is deliberately not treated as editing the plan. A paid bill
was always counted in the plan's total, so recording it mid-cycle moves no
money and changes no budget — which is why it works on an active plan, while
skipping or postponing a bill (both of which *do* re-cut the spending budget)
stays locked until the plan is reopened.

The cash-flow screen breaks the cycle's commitments into the three things they
actually are — **bills still to pay**, **debt payments still to make** and
**savings still to put aside** — each row tappable to the screen that settles
it. All three were computed by `CashFlowService` from the beginning; only the
bills were ever shown.

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

  Unspent budget      LKR 17,400
  Unused allowances   LKR  8,200
  Unused buffer       LKR 20,000
  ───────────────────────────────
  Total               LKR 45,600
```

Three pots, all of it money still sitting in the account: day-to-day budget
that went unspent, allowance money reserved for a category and never drawn, and
buffer that was never needed.

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
stay consistent if it is ever reopened — and it is drawn last, only once the
unspent budget and unused allowances have been used up, so a partial allocation
leaves the safety net intact for as long as possible.

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
    BudgetCycleService           Cycle boundaries and week windows
    IncomeForecastService        What funds a cycle: salary, draw or forecast
    IncomeModeService            Switching between ways of earning, safely
    RecurringTransactionService  Expands recurrences onto real dates
    FinancialPlanService         The monthly plan calculation
    BudgetCalculationService     Monthly / weekly / daily / category budgets
    BudgetAdjustmentService      The overspend options and their effects
    ExpenseService               Expense writes, card linkage, offline sync
    CardPaymentMethodService     One payment method per credit card
    ExpenseImpactService         What an expense would do, before it is saved
    CycleProgressService         Planned against actual, entity by entity
    UserProfileService           The account's history and lifetime totals
    PlanCommitmentService        Adding a debt to a cycle already running
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

## The account's own page

**Profile** is the account's record of itself: a unique handle, a picture, and
the history behind every other screen.

- **All time** — debt paid off and debts cleared, money saved, cycles planned
  and finished, expenses logged. The figures that say whether the last year
  actually went anywhere.
- **Months** — every cycle, newest first, with what was budgeted against what
  was spent, plus the debt paid and money saved in each.
- **Debt** — each debt's progress from its original amount, which are cleared
  and when, and the recent payments behind it.
- **Savings** — each goal against its target.
- **Activity** — the audit trail in plain language ("Adjusted a weekly budget",
  "Reopened a plan"), paginated separately so opening the profile stays quick.

The handle is lowercase and unique, checked against a reserved list so nothing
can be called `settings` or `admin`, and is derived from the name at sign-up so
no one has to choose one before they can use the app. Pictures are validated as
real images, capped at 2 MB — under the `post_max_size` a shared host typically
allows, so an oversized file is refused clearly rather than arriving as an empty
request — and the previous file is deleted only once the new one is stored.

Note that [`ProfileController`](app/Http/Controllers/Api/ProfileController.php)
owns the *financial* profile (salary, cycle day, funding), while
[`UserProfileController`](app/Http/Controllers/Api/UserProfileController.php)
owns this page, under `/api/me`.

---

## Offline support

Expense entry works without a connection. Each entry is queued locally with a
client-generated UUID; that UUID is the expense's identity server-side, so
replaying a queue — after a failed sync, a refresh, or two tabs at once —
cannot create duplicates. The queue flushes automatically on reconnect.

The service worker (`resources/js/sw.ts`, built to `/sw.js` so it can control
the whole origin) keeps the shell, the reference data and the last dashboard
response available offline.

### Installing it

The app installs to a home screen or a desktop, over HTTPS, on all three
platforms — but each one offers it differently, so the app offers it itself
from **More → Add to home screen** on a phone and **Install app** in the
sidebar on a computer:

| | How it installs |
|---|---|
| Android (Chrome, Edge) | `beforeinstallprompt` is captured at start-up and replayed from our own button, so it is one tap |
| Desktop (Chrome, Edge) | the same one-tap prompt |
| iPhone, iPad | Safari has no install API at all — the sheet shows the Share → Add to Home Screen steps, and says that Chrome on iOS cannot do it |

iOS ignores the manifest, so the icon, name and status bar come from the
`apple-*` tags in `resources/views/app.blade.php` instead. `sw.js` is served
`no-cache` — a cached worker can never be replaced, which would freeze a bad
deploy onto every installed device.

---

## Testing

```bash
php artisan test           # 355 tests
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
pre-save budget impact, income modes and safe mode switching, and a full
end-to-end acceptance run through the HTTP API in `FullWorkflowTest`.

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
