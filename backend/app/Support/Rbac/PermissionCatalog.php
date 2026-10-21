<?php

namespace App\Support\Rbac;

/**
 * Single source of truth for permissions and the default role matrix.
 * The seeder syncs the database from this file; it is safe to re-run.
 *
 * Permission slugs follow "<module>.<action>".
 */
final class PermissionCatalog
{
    /** @return array<string, array<string, string>> module => [action => label] */
    public static function permissions(): array
    {
        return [
            'dashboard' => ['view' => 'View dashboard'],
            'website' => [
                'view' => 'View website content', 'create' => 'Create website content',
                'edit' => 'Edit website content', 'publish' => 'Publish website content',
                'delete' => 'Delete website content', 'settings' => 'Manage website settings',
            ],
            'media' => ['view' => 'View media library', 'upload' => 'Upload media', 'delete' => 'Delete media'],
            'reservations' => [
                'view' => 'View reservations', 'create' => 'Create reservations', 'edit' => 'Edit reservations',
                'assign' => 'Assign physical rooms', 'cancel' => 'Cancel reservations', 'settings' => 'Manage reservation settings',
                'export' => 'Export reservations',
            ],
            'frontdesk' => ['view' => 'View front desk', 'checkin' => 'Check guests in', 'checkout' => 'Check guests out'],
            'rooms' => ['view' => 'View rooms', 'create' => 'Create rooms', 'edit' => 'Edit rooms', 'rates' => 'Manage room rates'],
            'guests' => ['view' => 'View guests', 'create' => 'Create guests', 'edit' => 'Edit guests', 'sensitive' => 'View sensitive guest details', 'export' => 'Export guests'],
            'housekeeping' => [
                'view' => 'View housekeeping',
                'update' => 'Update room cleaning status',
                'manage' => 'Manage housekeeping tasks',
                'assign' => 'Assign housekeeping tasks',
                'complete' => 'Complete housekeeping tasks',
                'inspect' => 'Inspect cleaned rooms',
            ],
            'inventory' => [
                'view' => 'View inventory items and stock balances',
                'create' => 'Create inventory items and categories',
                'edit' => 'Edit inventory items and recipes',
                'adjust' => 'Adjust stock and record stock counts',
                'waste' => 'Record stock waste and spoilage',
                'negative_override' => 'Authorize negative stock exceptions',
                'costs.view' => 'View inventory unit costs and stock valuation',
                'export' => 'Export inventory and stock reports',
            ],
            'procurement' => [
                'view' => 'View suppliers, requisitions, and purchase orders',
                'suppliers.manage' => 'Create and manage suppliers',
                'suppliers.sensitive' => 'View sensitive supplier banking and tax details',
                'create' => 'Create purchase requisitions and draft purchase orders',
                'requisitions.approve' => 'Approve or reject purchase requisitions',
                'approve' => 'Approve or cancel purchase orders',
                'receive' => 'Record goods receiving and stock intake',
                'costs.view' => 'View purchase order prices and supplier invoice costs',
            ],
            'pos' => [
                'view' => 'View restaurant, bar, and POS orders',
                'outlets.manage' => 'Manage POS outlets and preparation stations',
                'menu' => 'Manage menu categories, items, variants, and modifiers',
                'sell' => 'Create and submit POS orders',
                'prepare' => 'Update kitchen/bar preparation status',
                'discount' => 'Apply authorized POS discounts',
                'void' => 'Void or cancel submitted POS orders',
                'pay' => 'Record POS order payments',
                'refund' => 'Issue or approve POS payment refunds and reversals',
                'room_service.view' => 'View room-service orders and queue',
                'room_service.create' => 'Create room-service orders for checked-in stays',
                'room_service.deliver' => 'Update room-service delivery status',
                'room_charge.post' => 'Post room-service and POS charges to guest folios',
                'reports.view' => 'View POS shift summaries and sales reconciliation reports',
            ],
            'hr' => ['view' => 'View staff directory', 'edit' => 'Edit staff records', 'confidential' => 'Access confidential HR records'],
            'payroll' => ['view' => 'View payroll', 'prepare' => 'Prepare payroll', 'approve' => 'Approve payroll', 'export' => 'Export payroll'],
            'finance' => ['view' => 'View finance', 'record' => 'Record transactions', 'approve' => 'Approve transactions', 'refund' => 'Issue refunds', 'export' => 'Export financial data'],
            'accounting' => [
                'view' => 'View chart of accounts and general ledger',
                'accounts.manage' => 'Create and modify chart of accounts',
                'journals.create' => 'Create and post manual journal entries',
                'journals.reverse' => 'Reverse posted journal entries',
                'reports.view' => 'View trial balance, P&L, balance sheet, and ledger reports',
                'reports.export' => 'Export accounting and financial reports',
            ],
            'night_audit' => [
                'view' => 'View night audit status and historical runs',
                'run' => 'Execute daily night audit and roll hotel business date',
                'reports.view' => 'View and export night audit reports',
            ],
            'analytics' => [
                'view' => 'View hotel KPI metrics, occupancy, ADR, and RevPAR',
                'export' => 'Export analytical and operational dashboards',
            ],
            'folios' => [
                'view' => 'View guest folios',
                'create_charge' => 'Post charges to guest folios',
                'adjust' => 'Approve or post folio credits and adjustments',
                'settle' => 'Settle and close guest folios',
                'refund' => 'Approve folio refunds and settlement exceptions',
                'reports.view' => 'View folio financial reports',
            ],
            'payments' => ['view' => 'View payments', 'record' => 'Record manual payments', 'settings' => 'Manage payment gateway settings'],
            'maintenance' => [
                'view' => 'View maintenance',
                'create' => 'Report maintenance issues',
                'update' => 'Update maintenance work orders',
                'assign' => 'Assign maintenance work orders',
                'manage' => 'Manage maintenance work orders',
                'close' => 'Close or reopen maintenance work orders',
                'costs.view' => 'View maintenance labor and parts costs',
            ],
            'reports' => ['view' => 'View reports', 'export' => 'Export reports'],
            'users' => ['view' => 'View users', 'manage' => 'Manage users'],
            'roles' => ['view' => 'View roles and permissions', 'manage' => 'Manage roles and permissions'],
            'audit' => ['view' => 'View audit logs'],
            'settings' => ['view' => 'View system settings', 'edit' => 'Edit system settings'],
        ];
    }

    /** @return list<string> */
    public static function allSlugs(): array
    {
        $out = [];
        foreach (self::permissions() as $module => $actions) {
            foreach (array_keys($actions) as $action) {
                $out[] = "$module.$action";
            }
        }

        return $out;
    }

    /**
     * Default role matrix. "*" grants every permission (Super Administrator only).
     * A trailing ".*" grants every action within a module.
     *
     * @return array<string, array{name: string, description: string, permissions: list<string>}>
     */
    public static function roles(): array
    {
        $readAll = array_values(array_filter(self::allSlugs(), fn ($s) => str_ends_with($s, '.view') && ! in_array($s, ['users.view', 'roles.view'], true)));

        return [
            'super-admin' => ['name' => 'Super Administrator', 'description' => 'Full technical control of the system.', 'permissions' => ['*']],
            'general-manager' => ['name' => 'Hotel Owner / General Manager', 'description' => 'Oversight and approvals across operations and finance.', 'permissions' => [
                ...$readAll, 'users.view', 'roles.view', 'reports.export', 'procurement.*', 'inventory.*', 'pos.*',
                'finance.approve', 'finance.refund', 'finance.export',
                'accounting.*', 'night_audit.*', 'analytics.*',
                'folios.*', 'housekeeping.*', 'maintenance.*',
                'payroll.approve', 'hr.confidential', 'website.*', 'media.upload', 'settings.edit', 'reservations.settings', 'reservations.export', 'guests.sensitive',
            ]],
            'hotel-manager' => ['name' => 'Hotel Manager', 'description' => 'Day-to-day operations management.', 'permissions' => [
                'dashboard.view', 'reservations.*', 'frontdesk.*', 'rooms.*', 'guests.*', 'housekeeping.*', 'maintenance.*',
                'folios.view', 'folios.create_charge', 'folios.adjust', 'folios.settle', 'folios.reports.view',
                'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.adjust', 'inventory.waste', 'inventory.negative_override', 'inventory.costs.view', 'inventory.export',
                'procurement.view', 'procurement.suppliers.manage', 'procurement.suppliers.sensitive', 'procurement.create', 'procurement.requisitions.approve', 'procurement.approve', 'procurement.receive', 'procurement.costs.view',
                'pos.view', 'pos.outlets.manage', 'pos.menu', 'pos.sell', 'pos.prepare', 'pos.discount', 'pos.void', 'pos.pay', 'pos.refund',
                'pos.room_service.view', 'pos.room_service.create', 'pos.room_service.deliver', 'pos.room_charge.post', 'pos.reports.view',
                'hr.view', 'reports.view', 'reports.export',
                'website.view', 'website.create', 'website.edit', 'media.view', 'media.upload', 'users.view', 'settings.view', 'payments.view', 'payments.record',
            ]],
            'front-desk-supervisor' => ['name' => 'Front Desk Supervisor', 'description' => 'Supervises front desk operations, room assignments, and guest folios.', 'permissions' => [
                'dashboard.view', 'reservations.*', 'frontdesk.*', 'rooms.view', 'guests.view', 'guests.create', 'guests.edit',
                'housekeeping.view', 'housekeeping.manage', 'housekeeping.assign',
                'maintenance.view', 'maintenance.create',
                'payments.view', 'payments.record',
                'folios.view', 'folios.create_charge', 'folios.adjust', 'folios.settle', 'folios.reports.view',
                'pos.view', 'pos.room_service.view', 'pos.room_service.create', 'pos.room_charge.post',
                'night_audit.view', 'night_audit.run', 'night_audit.reports.view',
            ]],
            'receptionist' => ['name' => 'Receptionist', 'description' => 'Front desk check-in/out and guest handling.', 'permissions' => [
                'dashboard.view', 'reservations.view', 'reservations.create', 'reservations.edit', 'reservations.assign', 'frontdesk.*', 'rooms.view',
                'guests.view', 'guests.create', 'guests.edit', 'housekeeping.view', 'payments.view', 'payments.record', 'maintenance.create', 'maintenance.view',
                'folios.view', 'folios.create_charge', 'folios.settle',
                'pos.room_service.view', 'pos.room_service.create', 'pos.room_charge.post',
            ]],
            'reservation-officer' => ['name' => 'Reservation Officer', 'description' => 'Manages bookings and availability.', 'permissions' => [
                'dashboard.view', 'reservations.view', 'reservations.create', 'reservations.edit', 'reservations.assign', 'reservations.cancel', 'reservations.export',
                'rooms.view', 'guests.view', 'guests.create', 'guests.edit', 'payments.view', 'folios.view',
            ]],
            'housekeeping-supervisor' => ['name' => 'Housekeeping Supervisor', 'description' => 'Supervises housekeeping staff and room status.', 'permissions' => [
                'dashboard.view', 'housekeeping.*', 'rooms.view', 'maintenance.view', 'maintenance.create', 'inventory.view', 'procurement.create',
            ]],
            'housekeeping-staff' => ['name' => 'Housekeeping Staff', 'description' => 'Updates cleaning status of assigned rooms.', 'permissions' => [
                'dashboard.view', 'housekeeping.view', 'housekeeping.update', 'housekeeping.complete', 'rooms.view', 'maintenance.create',
            ]],
            'housekeeper' => ['name' => 'Housekeeper', 'description' => 'Completes assigned room cleaning tasks.', 'permissions' => [
                'dashboard.view', 'housekeeping.view', 'housekeeping.update', 'housekeeping.complete', 'rooms.view', 'maintenance.create',
            ]],
            'inventory-officer' => ['name' => 'Inventory Officer', 'description' => 'Manages stock, stock counts, waste, and goods receiving.', 'permissions' => [
                'dashboard.view', 'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.adjust', 'inventory.waste', 'inventory.costs.view', 'inventory.export',
                'procurement.view', 'procurement.create', 'procurement.receive', 'procurement.costs.view',
            ]],
            'procurement-officer' => ['name' => 'Procurement Officer', 'description' => 'Manages suppliers, purchase requisitions, and purchase orders.', 'permissions' => [
                'dashboard.view', 'procurement.view', 'procurement.suppliers.manage', 'procurement.create', 'procurement.costs.view',
                'inventory.view', 'inventory.costs.view',
            ]],
            'accountant' => ['name' => 'Accountant / Finance Officer', 'description' => 'Records and reconciles financial transactions.', 'permissions' => [
                'dashboard.view', 'finance.view', 'finance.record', 'finance.approve', 'finance.refund', 'finance.export',
                'accounting.*', 'night_audit.view', 'night_audit.reports.view', 'analytics.*',
                'folios.*', 'payments.*', 'reports.view', 'reports.export',
                'reservations.view', 'procurement.view', 'procurement.approve', 'procurement.costs.view', 'procurement.suppliers.sensitive',
                'inventory.view', 'inventory.costs.view', 'inventory.export',
                'pos.view', 'pos.pay', 'pos.refund', 'pos.reports.view',
                'payroll.view', 'maintenance.view', 'maintenance.costs.view',
            ]],
            'cashier' => ['name' => 'Cashier', 'description' => 'Posts charges, allocates payments, and settles guest folios and POS bills.', 'permissions' => [
                'dashboard.view', 'reservations.view', 'payments.view', 'payments.record',
                'folios.view', 'folios.create_charge', 'folios.settle',
                'pos.view', 'pos.sell', 'pos.pay', 'pos.room_service.view', 'pos.room_charge.post',
            ]],
            'hr-manager' => ['name' => 'HR Manager', 'description' => 'Manages staff records including confidential HR data.', 'permissions' => [
                'dashboard.view', 'hr.*', 'payroll.view',
            ]],
            'payroll-officer' => ['name' => 'Payroll Officer', 'description' => 'Prepares payroll for approval.', 'permissions' => [
                'dashboard.view', 'payroll.view', 'payroll.prepare', 'payroll.export', 'hr.view',
            ]],
            'pos-supervisor' => ['name' => 'Restaurant / POS Supervisor', 'description' => 'Supervises restaurant, bar, and room-service operations, menus, discounts, and voids.', 'permissions' => [
                'dashboard.view', 'pos.view', 'pos.outlets.manage', 'pos.menu', 'pos.sell', 'pos.prepare', 'pos.discount', 'pos.void', 'pos.pay', 'pos.refund',
                'pos.room_service.view', 'pos.room_service.create', 'pos.room_service.deliver', 'pos.room_charge.post', 'pos.reports.view',
                'inventory.view', 'inventory.waste', 'inventory.negative_override', 'procurement.create',
            ]],
            'pos-staff' => ['name' => 'Restaurant / POS Staff', 'description' => 'Records restaurant, bar, and room-service sales and preparation.', 'permissions' => [
                'dashboard.view', 'pos.view', 'pos.sell', 'pos.prepare', 'pos.pay',
                'pos.room_service.view', 'pos.room_service.create', 'pos.room_service.deliver', 'pos.room_charge.post',
            ]],
            'maintenance-supervisor' => ['name' => 'Maintenance Supervisor', 'description' => 'Supervises maintenance work orders, assignments, and costs.', 'permissions' => [
                'dashboard.view', 'maintenance.*', 'rooms.view', 'housekeeping.view',
            ]],
            'maintenance-staff' => ['name' => 'Maintenance Staff', 'description' => 'Handles maintenance work orders.', 'permissions' => [
                'dashboard.view', 'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.manage', 'rooms.view',
            ]],
            'maintenance-technician' => ['name' => 'Maintenance Technician', 'description' => 'Executes and resolves assigned maintenance work orders.', 'permissions' => [
                'dashboard.view', 'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.manage', 'rooms.view',
            ]],
            'auditor' => ['name' => 'Auditor / Read-only Manager', 'description' => 'Read-only access across modules and the audit trail.', 'permissions' => [
                ...$readAll, 'users.view', 'roles.view',
            ]],
        ];
    }

    /** Expand wildcards into concrete slugs. @return list<string> */
    public static function expand(array $patterns): array
    {
        $all = self::allSlugs();
        if (in_array('*', $patterns, true)) {
            return $all;
        }
        $out = [];
        foreach ($patterns as $p) {
            if (str_ends_with($p, '.*')) {
                $prefix = substr($p, 0, -1);
                $out = [...$out, ...array_filter($all, fn ($s) => str_starts_with($s, $prefix))];
            } elseif (in_array($p, $all, true)) {
                $out[] = $p;
            } else {
                throw new \InvalidArgumentException("Unknown permission [$p] in role matrix.");
            }
        }

        return array_values(array_unique($out));
    }
}
