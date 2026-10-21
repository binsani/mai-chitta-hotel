# Mai Chitta Hotel — Phases 1 to 6

Website & Hotel Management System for **Mai Chitta Hotel** (Zaria, Kaduna State, Nigeria).
- **Phase 1**: Shared-hosting-compatible foundation with Laravel 13 API (`/api/v1`), first-party Sanctum cookie authentication, 21-role RBAC with granular permissions, append-only audit logging, hotel configuration management, and the React/TypeScript administration portal.
- **Phase 2**: Responsive 14-page public website and WordPress-inspired Content Management System (Pages, Reorderable Page Sections, Revision History & Restoration, Safe Preview, Navigation Menus, Media Library with Public/Private Disk Support, Room Content, Offers, Testimonials, Contact Inquiries, and Server-Side SEO Injection).
- **Phase 3**: Room Types, Individual Physical Rooms, Rate Plans & Date Overrides, Centralized Availability & Pricing Engine, Public Online Booking (Search → Quote → Hold → Reservation → Confirmation & Secure Lookup), Front-Desk Reservation Operations (Walk-ins, Room Assignments, Amendments, Check-In, Check-Out, Cancellations, No-Shows), Manual Payment Ledger, Refund Approval Workflows, and Paystack/Flutterwave Webhook Verification.
- **Phase 4**: Housekeeping Management (Task Creation, Staff Assignment, Start/Complete Workflow, Supervisor Inspection with Pass/Fail & Automatic Follow-Up Task Generation, Room Readiness Enforcement on Check-In, and Automatic Post-Checkout Cleaning Tasks), Maintenance Work Orders (Full Lifecycle, Room-Blocking Holds, Arrival Conflict Alerts, Minor-Unit Repair Cost Tracking with Permission Redaction, and Safe Return-to-Service via Housekeeping), and Guest Folios (Append-Only Financial Charge/Credit/Adjustment/Reversal Ledger, Idempotent Accommodation/Tax/Service-Charge Sync, Separation-of-Duties Adjustment Approval, Verified Payment Allocation, Zero-Balance Settlement Enforcement, and Reusable Phase 5 POS Charge Hook).
- **Phase 5**: Restaurant & Bar POS (Outlets, Prep Stations, Menu Categories, Items, Variants, Modifiers, Integer Minor-Unit Totals, State Machine, Shift Sales Reconciliation), Room Service (Verified Checked-In Stays, In-Room Dining Delivery Queue, Cross-Folio IDOR Protection, Idempotent `FolioService::postTrustedExternalCharge` Posting, Automatic Folio Reversals on Order Void), Inventory & Recipe BOM (Moving Weighted Average Costing, Multi-Location Balances, Append-Only Stock Movements with MariaDB Triggers, Single Configurable Consumption Trigger, Negative Stock Prevention & Authorized Overrides, Restock vs Waste on Void), and Procurement (Supplier Directory with Banking/Tax ID Redaction, Internal Purchase Requisitions, Separation-of-Duties Purchase Orders, and Partial Goods Receiving with Over-Delivery Protection).
- **Phase 6**: Multi-Department Accounting & General Ledger (Standard Chart of Accounts across ROOMS, POS, HK, MAINT, and GA; Balanced Double-Entry Journal Entries; MariaDB Immutability Triggers; Journal Reversals; Real-Time Trial Balance, Income Statement P&L, and Balance Sheet), Automated & Manual Night Audit (Operational Pre-Audit Checklist, In-House Stay Accommodation & VAT Posting, Idempotent Business Date Roll, Daily GL Close Entry), and Hotel KPI Analytics (Occupancy %, ADR, RevPAR, and Period Revenue Performance).

---

## 1. Repository & Deployment Structure

We use a **monorepo with `/backend` (Laravel 13) and `/frontend` (React 19 + TypeScript + Vite)** where the production React build compiles directly into `backend/public/assets/` and `backend/public/spa.html`.

### Why this structure?
- **React source stays outside the webroot**: TypeScript source, `node_modules`, and build tooling in `/frontend` are never exposed in `public_html`.
- **Same-origin deployment (`/` + `/admin/*` + `/api/v1/*`)**: Eliminates cross-origin cookie issues (`SameSite=Lax`, first-party `HttpOnly` session cookies via Laravel Sanctum), avoids Apache `mod_dir` trailing-slash redirect conflicts on `/admin`, injects dynamic server-side `<title>`, `<meta>`, `canonical`, and OpenGraph tags via `SpaController`, and requires no Node.js runtime on production cPanel hosting.
- **Clean boundaries**: Backend domain logic (`App\Services`, `App\Policies`, `App\Http\Requests`, `App\Support`) is isolated from the React SPA (`/frontend/src`).

```text
mai chitta hotel/
├── backend/                           # Laravel 13 application (deploy outside public_html)
│   ├── app/
│   │   ├── Console/Commands/
│   │   │   └── CreateAdminCommand.php # Interactive `php artisan hotel:create-admin`
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── SpaController.php  # Serves spa.html with server-side SEO/OpenGraph tags
│   │   │   │   └── Api/V1/
│   │   │   │       ├── AuthController.php
│   │   │   │       ├── PublicWebsiteController.php
│   │   │   │       └── Admin/
│   │   │   │           ├── AuditLogController.php
│   │   │   │           ├── MediaController.php
│   │   │   │           ├── MenuController.php
│   │   │   │           ├── OfferController.php
│   │   │   │           ├── PageController.php
│   │   │   │           ├── RoleController.php
│   │   │   │           ├── RoomContentController.php
│   │   │   │           ├── SettingsController.php
│   │   │   │           ├── TestimonialController.php
│   │   │   │           ├── UserController.php
│   │   │   │           └── WebsiteSettingsController.php
│   │   │   ├── Middleware/{AssignRequestId,EnsureUserIsActive}.php
│   │   │   ├── Requests/
│   │   │   │   ├── Auth/LoginRequest.php
│   │   │   │   └── Admin/{SaveOfferRequest,SavePageRequest,SaveRoleRequest,SaveRoomContentRequest,SaveTestimonialRequest,SaveUserRequest,UpdateSettingsRequest}.php
│   │   │   └── Resources/UserResource.php
│   │   ├── Models/{AuditLog,ContactInquiry,MediaAsset,NavigationItem,NavigationMenu,Offer,Page,PageRevision,PageSection,Permission,Role,RoomContent,Setting,Testimonial,User}.php
│   │   ├── Notifications/ResetPasswordNotification.php
│   │   ├── Policies/{PagePolicy,RolePolicy,UserPolicy}.php
│   │   ├── Services/{AuditLogger,CmsService,MediaService,SettingsService,UserAdminService}.php
│   │   └── Support/
│   │       ├── HtmlSanitizer.php
│   │       └── Rbac/PermissionCatalog.php
│   ├── database/
│   │   ├── migrations/
│   │   │   ├── 2026_10_21_100000_create_rbac_tables.php
│   │   │   ├── 2026_10_21_100100_create_audit_logs_and_settings_tables.php
│   │   │   └── 2026_10_21_200000_create_cms_tables.php
│   │   └── seeders/{CmsContentSeeder,DatabaseSeeder,RolesAndPermissionsSeeder}.php
│   ├── public/
│   │   ├── assets/                    # Compiled Vite hashed JS/CSS bundles
│   │   ├── placeholders/              # Replaceable SVG hotel illustrations
│   │   └── spa.html                   # Compiled SPA shell rendered by SpaController
│   ├── routes/{api,console,web}.php
│   └── tests/Feature/{Phase1FoundationTest,Phase2CmsTest}.php
└── frontend/                          # React 19 + TypeScript (strict) + Vite + Tailwind CSS
    └── src/
        ├── auth/AuthProvider.tsx
        ├── components/ui.tsx
        ├── layouts/AdminLayout.tsx
        ├── lib/{api,cms,navigation,utils}.ts
        └── pages/{admin,auth,cms,general,public}.tsx
```

---

## 2. Shared-Hosting (cPanel) Compatibility Matrix

### Verified in this codebase
- **No Redis, Docker, or Node.js runtime required in production**: Sessions, cache, and queues default to the `database` driver; React compiles to static assets in `backend/public/assets/` and `backend/public/spa.html`.
- **No permanent queue daemon required**: `routes/console.php` runs `queue:work --stop-when-empty --max-time=50` via a 1-minute cPanel Cron Job with overlap protection.
- **Private files stay outside `public_html`**: Application code, `.env`, and `storage/app/private` reside in `/home/<cpanel_user>/mai-chitta/backend`. Private media assets are served only through the authenticated `/api/v1/admin/media/{mediaAsset}/download` endpoint.

### Checklist to verify on the target cPanel host before cutover
1. **PHP Version**:
   - **Target**: PHP **8.3+** (required by Laravel 13, `"php": "^8.3"`).
   - **If the cPanel host only provides PHP 8.2**: Laravel 13 will not run on PHP 8.2. Either enable PHP 8.3/8.4 in cPanel **MultiPHP Manager**, or pin `laravel/framework` to `^12.0` (which supports PHP 8.2–8.4) without changing any application code in `app/`.
2. **Required PHP Extensions** (enable in cPanel *Select PHP Version*):
   `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd` (or `imagick`), `hash`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `PDO`, `pdo_mysql`, `session`, `tokenizer`, `xml`, `zip`.
3. **Database Version**:
   - MySQL **8.0+** or MariaDB **10.6+** (required for `JSON` columns and `utf8mb4` indexes).
   - Verify database user has `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES` privileges.
4. **Document Root & Public Storage Symlink**:
   - Place the repository at `/home/<cpanel_user>/mai-chitta/`.
   - Create the public storage symlink for public CMS media uploads:
     ```bash
     cd /home/<cpanel_user>/mai-chitta/backend
     php artisan storage:link
     ```
   - **Option A (Preferred — Addon/Subdomain or cPanel root override)**: Point the domain document root to `/home/<cpanel_user>/mai-chitta/backend/public`.
   - **Option B (Primary domain locked to `public_html`)**: Replace `public_html` with a symlink (`ln -s /home/<cpanel_user>/mai-chitta/backend/public /home/<cpanel_user>/public_html`), or copy `backend/public/*` into `public_html` and update `index.php` paths to `../mai-chitta/backend/vendor/autoload.php` and `../mai-chitta/backend/bootstrap/app.php`.
5. **Cron Jobs**:
   Add one Cron Job (Every Minute `* * * * *`) in cPanel:
   ```bash
   /usr/local/bin/php /home/<cpanel_user>/mai-chitta/backend/artisan schedule:run >> /dev/null 2>&1
   ```
6. **SMTP / Transactional Email**:
   Verify outbound port `587` (STARTTLS) or `465` (SMTPS) is open on the cPanel host and set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` in `backend/.env`.

---

## 3. First Administrator Creation, Seeders & RBAC

No production administrator password is ever stored in migrations, seeders, or source control.

1. Run migrations and seed the 21 system roles, 93 permissions, and initial Phase 2 CMS pages/menus (idempotent and safe in production):
   ```bash
   cd backend
   php artisan migrate --force
   php artisan db:seed --class=RolesAndPermissionsSeeder --force
   php artisan db:seed --class=CmsContentSeeder --force
   php artisan storage:link
   ```
2. Create the first Super Administrator interactively (password input is hidden and validated):
   ```bash
   php artisan hotel:create-admin
   ```

### Seeded Roles (21 Roles Total)
| Slug | Role Name | Scope Highlights |
|---|---|---|
| `super-admin` | Super Administrator | Full technical control of the system (`*` wildcard). |
| `general-manager` | Hotel Owner / General Manager | Oversight & approvals across operations, finance, procurement, inventory, POS, HR, and settings. |
| `hotel-manager` | Hotel Manager | Day-to-day operations across rooms, reservations, housekeeping, maintenance, folios, inventory, procurement, and POS. |
| `front-desk-supervisor` | Front Desk Supervisor | Supervises front desk operations, room assignments, folios, room service, and housekeeping. |
| `receptionist` | Receptionist | Front desk check-in/out, reservations, guests, manual payment recording, folios, room service. |
| `reservation-officer` | Reservation Officer | Reservations, room availability, guest records, booking holds. |
| `housekeeping-supervisor` | Housekeeping Supervisor | Supervises housekeeping staff, inspections, room readiness status, maintenance reporting. |
| `housekeeping-staff` | Housekeeping Staff | Assigned room cleaning status updates, task completion, maintenance reporting. |
| `housekeeper` | Housekeeper | Completes assigned room cleaning tasks. |
| `pos-supervisor` | Restaurant / POS Supervisor | Supervises restaurant, bar, and room-service operations, menus, authorized discounts, voids, and reports. |
| `pos-staff` | Restaurant / POS Staff | Records restaurant, bar, and room-service sales, kitchen/bar prep status, and payments. |
| `inventory-officer` | Inventory Officer | Manages stock items, locations, stock counts, adjustments, waste, and goods receiving. |
| `procurement-officer` | Procurement Officer | Manages suppliers, purchase requisitions, and purchase orders. |
| `cashier` | Cashier | Posts charges, allocates payments, settles guest folios and POS bills. |
| `accountant` | Accountant / Finance Officer | Financial ledger, refunds, payment gateway reconciliation, supplier sensitive banking/tax details, and cost valuation. |
| `hr-manager` | HR Manager | Staff directory, edits, confidential HR records. |
| `payroll-officer` | Payroll Officer | Payroll preparation and export (requires GM approval). |
| `maintenance-supervisor` | Maintenance Supervisor | Supervises maintenance work orders, assignments, and costs. |
| `maintenance-staff` | Maintenance Staff | Maintenance work order handling and resolution. |
| `maintenance-technician` | Maintenance Technician | Executes and resolves assigned maintenance work orders. |
| `auditor` | Auditor / Read-only Manager | Read-only access across modules and the immutable audit log. |

---

## 4. Local Development & Testing

### Backend (`http://localhost:8000`)
```bash
cd backend
php artisan migrate
php artisan db:seed          # Seeds RBAC + CMS pages/menus; in local env also creates demo users (<role-slug>@example.test)
php artisan storage:link
php artisan hotel:create-admin
php artisan serve
```

### Frontend (`http://localhost:5173/` in dev, or `http://localhost:8000/` from build)
```bash
cd frontend
npm run dev                  # Proxies /api, /sanctum, /storage, and /placeholders to http://localhost:8000
npm run lint                 # Runs ESLint across all TypeScript/React files
npm run build                # Typechecks (tsc -b) and compiles static assets to ../backend/public/{assets,spa.html}
```

### Automated Feature Tests
```bash
cd backend
php artisan test
```
