# Customer dashboard and SMM provider integration

The owner upload `tdc-tech-dashboard (1).html` is the design source. The live customer template is `app/templates/customer-dashboard.html`; public homepage and login templates remain separate. Dashboard data uses the existing authenticated API, not prototype balances or simulated payments.

## Administration

Open `/admin/providers.php`, enter provider name, HTTPS endpoint, API key, provider currency, currency units per GBP, and profit markup. Keys are encrypted with the existing application encryption key and never returned through the administration API. A blank key on edit preserves the saved key. Save validates balance authentication and currency; sync imports services and reprices them. Disabling a provider stops new sales.

Pricing: `GBP retail rate = ceil((provider rate / units per GBP) × (1 + markup / 100) × 100) / 100`. Per-1,000 orders round upward to the nearest penny. Package services use the fixed advertised rate. Currency conversion is explicit, not automatic live FX. Changing conversion/profit takes effect on the next sync. Existing orders retain their charged amounts.

Supported automatic order types: Default, Package, Custom Comments and Custom Comments Package. Other types are excluded until their required inputs are supported. Invalid rates/limits are skipped. Services removed from a provider catalog are disabled without deleting historical records.

## Delivery safety

Migration `005_smm_providers.sql` adds provider catalog and order outbox tables. Each submission has a per-user idempotency key and payload hash. The wallet and service are locked during order creation, and the wallet debit and delivery job commit together. Clients reuse their request key on an uncertain HTTP response. The worker claims each delivery job once.

The cron `/etc/cron.d/tdc-smm` invokes `bin/smm-worker.php` as www-data every minute. A private lock prevents overlap. Interrupted or uncertain submissions move to review and are not automatically resent. Admins link an externally confirmed provider order ID through the provider page. The worker polls known orders, records delivery progress and refunds rejected/cancelled orders or undelivered quantities from partial orders exactly once. Manual order status buttons cannot override provider-tracked orders.

Use the provider page to check provider balance and reconcile uncertain submissions. Provider acceptance is not proof of fulfillment; completion is confirmed by subsequent status polling. Refills/cancellation capabilities are displayed as supplied by the provider; the customer form does not yet expose separate refill/cancel requests.

## Initial deployment and verification

Fetched and fast-forwarded the owner upload, commit `9f8ab7065593dc93c6929709bb63e8cb4cb51d76`. Digitex Smart Solutions is the configured integration endpoint. Initial profit markup: 25%; USD conversion: 1.322474 USD per GBP using the ECB 2026-10-05 reference rates (`https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml`). Administrator can change these values.

Live read-only service/balance/status checks succeeded. 4,085 usable services imported; 516 malformed rate/quantity entries excluded. Isolated database tests passed: insufficient funds, retry idempotency, four concurrent requests creating one order, one provider dispatch, completion status, rejected and partial refunds, uncertain-response review, valid quantity/link validation, private provider address rejection, FX/profit calculation and encryption roundtrip. Provider add/error/timeout responses were simulated in isolation; no paid delivery order was placed.

Live browser checks passed at mobile and desktop widths: authenticated dashboard, real balances/history, service categories/selection/quotes, insufficient funds HTTP 402, CSRF rejection HTTP 419, profile name update, provider key validation, one-click sync, anonymous redirect and no JavaScript errors. Temporary QA identities/sessions were removed. Existing 14 users, zero orders and zero ledger balance were preserved.

The initial SMM deployment found missing payment credentials. The subsequent customer-workspace repair recovered the authorized existing Xdigitex Pay merchant key privately, configured the TDC callback/webhook, and enabled KES and USD conversion rates. The key is server-only and excluded from Git. Authenticated read-only gateway checks passed; no payment was initiated during QA.

Production file/database backups and isolated test evidence are in `/root/backups/tdc-dashboard-20261006/`. Keep these private.


## Customer workspace repair — 2026-10-06

New responsive public landing page, clean page URLs (legacy .php redirects; API endpoints retain their contracts), and owner-HTML profile layout with persisted name, bio, timezone, theme, secure photo upload and password-protected email/password changes.

Cart products use administrator service prices. Checkout is placed above the products, quotes server-side, locks the wallet and creates all pending template requests in one transaction. A unique receipt prevents duplicate charges on retry. Templates require TDC fulfillment; this does not claim assets are delivered automatically.

Support tickets and messages persist in the database. Customers can access only their own tickets; admins can view all tickets, reply, close and reopen. Customer and admin inboxes poll every five seconds. Admin overview counts open tickets.

Cron `/etc/cron.d/tdc-smm` syncs SMM orders every minute. `/etc/cron.d/tdc-payments` reconciles pending provider payments every minute even if the customer closes the return page. No wallet credit occurs before verified payment completion. Reference, currency and amount must match; row locks ensure one credit and receipt.

Live deposit UI has KES and USD rates enabled, £5 minimum, and a local-currency quote before payment. Rate references: CBK 2026-10-05 (171.76 KES per GBP) and ECB 2026-10-05 (1.322474 USD per GBP). Other mobile-money countries require an enabled rate. Card checkout uses the provider page; mobile money uses the local status page. Actual settlement still requires the customer's payment; no phone prompt or paid provider order was initiated during QA.

Isolated tests passed checkout totals, insufficient funds, retries, wallet debit once, support access control, replies/closure, saved profiles and password checks. Live browser tests passed desktop/mobile landing, cart, profile, ticket/admin reply polling, clean URL redirects, payment conversion preview, insufficient-funds checkout with no order/charge, photo upload/reencoding and rejection of non-image uploads. Payment fixture tests rejected missing/wrong fields, withheld pending credit, and credited/queued one receipt on duplicate completion.

Temporary QA users, sessions, support messages and uploaded photo were removed. The deployment started with 14 users and no orders/payments. A customer subsequently registered and initiated a real mobile-money deposit; it was accepted by the gateway and then marked failed by the provider, so its ledger credit was reversed. Cleanup left 15 real users, zero orders, one failed payment and no posted wallet credit. No existing customer data was removed. Private backup and test receipts: `/root/backups/tdc-ui-20261006/`. Files, database and web-server configuration were backed up before changes.
