# Decision log

Deliberate deviations from the Development Plan (`docs/development-plan.md`), and other decisions worth keeping. Where this log differs from the plan, this log wins.

| Date | Ticket | Decision | Reason |
| --- | --- | --- | --- |
| 2026-11-14 | T2-6 (#32) | The order-ready notification is email only. There is no database-channel notification. | T2-4's live status page already flips to Ready the moment the barista bumps, so an in-app notice would duplicate it. |
