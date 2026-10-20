# Calendar money flow: totals convention

The section above the `/calendar` grid has three views: Plan vs actual, Cash flow, Outflows.

- Every figure is net by category. A refund inside a category reduces that category; it is not counted as income.
- Plan vs actual covers ordinary income and spend only. Transfers are excluded on both sides.
- The plan is the month's full schedule of ordinary plans, reconciled or not, so a matched occurrence keeps its plan amount. A planned bill that was never reconciled stays in the plan and is simply absent from actual.
- Cash flow and Outflows also count transfers between a tracked and an untracked account, grouped under Savings & Investments or Transfer. Tracked-to-tracked transfers stay out.
- Cash flow is actuals only. Planned but unposted flows are not drawn.
- Outflows use the same parents (category groups) as the Cash flow overview, so the Outflows total equals the Cash flow expense total.
- The verdict ("you're clear" / "you're short") is the month's actual net plus ordinary planned income and spend still to come.

Code: `App\Support\Calendar\{MonthFlowLoader, CashFlowLoader, CashFlowGraphBuilder}`.
