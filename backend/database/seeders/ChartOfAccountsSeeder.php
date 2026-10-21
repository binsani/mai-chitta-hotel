<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // ASSETS (1000s)
            ['code' => '1010', 'name' => 'Cash in Vault / Cash on Hand', 'type' => 'asset', 'normal_balance' => 'debit', 'department' => 'GA', 'description' => 'Main hotel cash reserves'],
            ['code' => '1020', 'name' => 'Front Desk Cash Drawer', 'type' => 'asset', 'normal_balance' => 'debit', 'department' => 'ROOMS', 'description' => 'Cash floats for reception desks'],
            ['code' => '1030', 'name' => 'Restaurant & Bar Cash Float', 'type' => 'asset', 'normal_balance' => 'debit', 'department' => 'POS', 'description' => 'Cash floats for dining and bar terminals'],
            ['code' => '1040', 'name' => 'Operating Bank Account (Main)', 'type' => 'asset', 'normal_balance' => 'debit', 'department' => 'GA', 'description' => 'Primary commercial bank account'],
            ['code' => '1050', 'name' => 'Payment Gateway Clearing (Paystack/Flutterwave)', 'type' => 'asset', 'normal_balance' => 'debit', 'department' => 'GA', 'description' => 'Unsettled online card payments'],
            ['code' => '1110', 'name' => 'Guest Ledger / City Ledger (Accounts Receivable)', 'type' => 'asset', 'normal_balance' => 'debit', 'department' => 'ROOMS', 'description' => 'Unpaid guest folios and corporate billing'],
            ['code' => '1210', 'name' => 'Food & Beverage Inventory Asset', 'type' => 'asset', 'normal_balance' => 'debit', 'department' => 'POS', 'description' => 'Raw food, drinks, and ingredients on hand'],
            ['code' => '1220', 'name' => 'Operating Supplies Inventory Asset', 'type' => 'asset', 'normal_balance' => 'debit', 'department' => 'HK', 'description' => 'Housekeeping linen, guest amenities, cleaning stock'],
            ['code' => '1230', 'name' => 'Maintenance Spares Inventory Asset', 'type' => 'asset', 'normal_balance' => 'debit', 'department' => 'MAINT', 'description' => 'Plumbing, electrical, and HVAC maintenance parts'],

            // LIABILITIES (2000s)
            ['code' => '2010', 'name' => 'Accounts Payable (Suppliers)', 'type' => 'liability', 'normal_balance' => 'credit', 'department' => 'GA', 'description' => 'Trade creditors and procurement invoices'],
            ['code' => '2020', 'name' => 'Advance Guest Deposits', 'type' => 'liability', 'normal_balance' => 'credit', 'department' => 'ROOMS', 'description' => 'Prepayments and holds for future reservations'],
            ['code' => '2030', 'name' => 'Value Added Tax (VAT 7.5%) Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'department' => 'GA', 'description' => 'Federal tax liabilities collected on sales'],
            ['code' => '2040', 'name' => 'State Consumption / Tourism Tax Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'department' => 'GA', 'description' => 'Kaduna state hospitality consumption levy'],
            ['code' => '2050', 'name' => 'Accrued Payroll & Staff Obligations', 'type' => 'liability', 'normal_balance' => 'credit', 'department' => 'GA', 'description' => 'Salaries and wages payable'],

            // EQUITY (3000s)
            ['code' => '3010', 'name' => 'Owner Capital', 'type' => 'equity', 'normal_balance' => 'credit', 'department' => 'GA', 'description' => 'Initial and contributed capital'],
            ['code' => '3020', 'name' => 'Retained Earnings', 'type' => 'equity', 'normal_balance' => 'credit', 'department' => 'GA', 'description' => 'Accumulated historical profits/losses'],

            // REVENUE (4000s)
            ['code' => '4010', 'name' => 'Room Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'department' => 'ROOMS', 'description' => 'Guest room accommodation sales'],
            ['code' => '4020', 'name' => 'Restaurant Food Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'department' => 'POS', 'description' => 'Dining food sales'],
            ['code' => '4030', 'name' => 'Bar & Beverage Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'department' => 'POS', 'description' => 'Bar, wine, and beverage sales'],
            ['code' => '4040', 'name' => 'Room Service Delivery Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'department' => 'POS', 'description' => 'In-room dining tray and delivery service charges'],
            ['code' => '4050', 'name' => 'Laundry & Valet Service Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'department' => 'HK', 'description' => 'Guest laundry and dry cleaning sales'],
            ['code' => '4090', 'name' => 'Miscellaneous Hotel Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'department' => 'GA', 'description' => 'Hall rentals, damages, and sundry sales'],

            // EXPENSES (5000s)
            ['code' => '5010', 'name' => 'Cost of Goods Sold - Food & Beverage', 'type' => 'expense', 'normal_balance' => 'debit', 'department' => 'POS', 'description' => 'Stock consumed in restaurant/bar sales'],
            ['code' => '5020', 'name' => 'Inventory Waste & Spoilage Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'department' => 'POS', 'description' => 'Damaged, expired, or spoiled stock write-offs'],
            ['code' => '5030', 'name' => 'Housekeeping Operating Supplies', 'type' => 'expense', 'normal_balance' => 'debit', 'department' => 'HK', 'description' => 'Toiletries, room amenities, and washing chemicals'],
            ['code' => '5040', 'name' => 'Maintenance Repairs & Parts Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'department' => 'MAINT', 'description' => 'Physical maintenance parts and third-party technician labor'],
            ['code' => '5050', 'name' => 'Salaries & Staff Wages', 'type' => 'expense', 'normal_balance' => 'debit', 'department' => 'GA', 'description' => 'All hotel payroll compensation'],
            ['code' => '5060', 'name' => 'Hotel Utilities (Generator Fuel, Power & Water)', 'type' => 'expense', 'normal_balance' => 'debit', 'department' => 'GA', 'description' => 'Diesel fuel, electricity, water utility bills'],
            ['code' => '5090', 'name' => 'Bank Charges & Gateway Processing Fees', 'type' => 'expense', 'normal_balance' => 'debit', 'department' => 'GA', 'description' => 'Paystack, POS terminal and bank transaction fees'],
        ];

        foreach ($accounts as $data) {
            ChartOfAccount::updateOrCreate(
                ['code' => $data['code']],
                [
                    ...$data,
                    'is_active' => true,
                    'is_system' => true,
                ]
            );
        }
    }
}
