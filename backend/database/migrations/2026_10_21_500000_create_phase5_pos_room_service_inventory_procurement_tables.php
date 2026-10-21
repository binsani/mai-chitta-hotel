<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Implementation:
 * - Restaurant, Bar & Room-Service Outlets, Preparation Stations, Menu Categories, Menu Items, Variants & Modifiers
 * - Inventory Categories, Storage Locations, Inventory Items (Weighted Average Costing), Stock Balances, Batches & Append-Only Stock Movements
 * - Menu Recipes (BOM) linking Menu Items/Variants to Inventory Ingredients
 * - POS & Room-Service Orders, Order Lines, Order Histories, and Append-Only POS Payments Ledger
 * - Suppliers, Purchase Requisitions, Purchase Orders, and Goods Receivings (GRN) with Partial Delivery & Discrepancy Tracking
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // A. INVENTORY & STOCK CONTROL TABLES (Created first so Menu Items & Recipes can reference them)
        // =====================================================================

        // 1. Inventory Categories
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // 2. Storage Locations (e.g., Main Store, Kitchen Store, Bar Store, Housekeeping Store)
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('location_type', 32)->default('store'); // main_store | kitchen | bar | outlet | housekeeping | general
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Inventory Items (with Base Unit, Purchase Unit Conversion Factor, and Weighted Average Cost in Minor Units)
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 48)->unique();
            $table->foreignId('inventory_category_id')->nullable()->constrained('inventory_categories', 'id', 'inv_item_cat_id_fk')->nullOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('base_unit', 24)->default('unit'); // g | kg | ml | l | unit | piece | bottle | can | pack | portion
            $table->string('purchase_unit', 24)->default('unit'); // carton | bag | crate | kg | l | unit
            $table->decimal('purchase_to_base_factor', 14, 4)->default(1.0000); // e.g., 1 carton = 24 bottles -> 24.0000
            $table->decimal('current_stock', 14, 4)->default(0.0000); // Aggregate across all storage locations in base_unit
            $table->decimal('reorder_level', 14, 4)->default(0.0000); // Minimum stock threshold in base_unit
            $table->string('currency_code', 3)->default('NGN');
            $table->string('costing_method', 24)->default('weighted_average'); // weighted_average
            $table->bigInteger('unit_cost_minor')->default(0); // Authoritative weighted-average cost per 1 base_unit in minor units (kobo)
            $table->decimal('unit_cost', 14, 2)->default(0.00);
            $table->bigInteger('last_purchase_unit_cost_minor')->default(0); // Cost per 1 base_unit from most recent receiving
            $table->boolean('track_batches')->default(false);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'inv_item_created_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'current_stock', 'reorder_level'], 'inv_items_stock_alert_idx');
        });

        // 4. Inventory Stock Balances (Per Item + Storage Location)
        Schema::create('inventory_stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items', 'id', 'inv_bal_item_id_fk')->cascadeOnDelete();
            $table->foreignId('storage_location_id')->constrained('storage_locations', 'id', 'inv_bal_loc_id_fk')->restrictOnDelete();
            $table->decimal('quantity', 14, 4)->default(0.0000); // In item's base_unit
            $table->timestamps();

            $table->unique(['inventory_item_id', 'storage_location_id'], 'inv_bal_item_loc_unique');
        });

        // 5. Inventory Batches (Optional Batch & Expiry Tracking on Receiving)
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items', 'id', 'inv_batch_item_id_fk')->cascadeOnDelete();
            $table->foreignId('storage_location_id')->constrained('storage_locations', 'id', 'inv_batch_loc_id_fk')->restrictOnDelete();
            $table->string('batch_number', 64);
            $table->date('expiry_date')->nullable();
            $table->decimal('received_quantity', 14, 4);
            $table->decimal('remaining_quantity', 14, 4);
            $table->bigInteger('unit_cost_minor')->default(0);
            $table->unsignedBigInteger('goods_receiving_id')->nullable();
            $table->timestamps();

            $table->index(['inventory_item_id', 'expiry_date'], 'inv_batch_item_expiry_idx');
        });

        // 6. Stock Movements (Append-Only Traceable Inventory Ledger)
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_reference', 40)->unique();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->string('unique_source_key', 120)->nullable()->unique(); // Prevents duplicate recipe/direct consumption per order line
            $table->foreignId('inventory_item_id')->constrained('inventory_items', 'id', 'stk_mov_item_id_fk')->restrictOnDelete();
            $table->foreignId('storage_location_id')->constrained('storage_locations', 'id', 'stk_mov_loc_id_fk')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches', 'id', 'stk_mov_batch_id_fk')->nullOnDelete();
            $table->string('movement_type', 32); // goods_received | pos_consumption | room_service_consumption | transfer_out | transfer_in | waste | adjustment | stock_count | supplier_return | restock_return
            $table->decimal('quantity_delta', 14, 4); // Signed change in base_unit (+ for additions, - for deductions)
            $table->decimal('balance_before', 14, 4); // Location balance before movement
            $table->decimal('balance_after', 14, 4); // Location balance after movement
            $table->string('unit', 24); // Item's base_unit snapshot
            $table->string('currency_code', 3)->default('NGN');
            $table->bigInteger('unit_cost_minor')->default(0); // Cost per 1 base_unit at time of movement
            $table->bigInteger('total_cost_minor')->default(0); // Signed or absolute minor-unit valuation of movement
            $table->string('source_type', 48)->nullable(); // pos_order | goods_receiving | manual_adjustment | stock_count | transfer
            $table->string('source_id', 64)->nullable();
            $table->boolean('is_negative_override')->default(false);
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users', 'id', 'stk_mov_actor_id_fk')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['inventory_item_id', 'occurred_at'], 'stk_mov_item_date_idx');
            $table->index(['movement_type', 'occurred_at'], 'stk_mov_type_date_idx');
            $table->index(['source_type', 'source_id'], 'stk_mov_source_idx');
        });

        // =====================================================================
        // B. RESTAURANT, BAR & ROOM-SERVICE OUTLETS, MENUS & RECIPES
        // =====================================================================

        // 7. POS Outlets (Restaurant, Bar, Room Service, Pool Bar, etc.)
        Schema::create('pos_outlets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('type', 32)->default('restaurant'); // restaurant | bar | room_service | cafe | lounge | other
            $table->text('description')->nullable();
            $table->foreignId('default_storage_location_id')->nullable()->constrained('storage_locations', 'id', 'pos_outlet_loc_id_fk')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('accepts_orders')->default(true);
            $table->boolean('supports_room_service')->default(false);
            $table->boolean('supports_room_charge')->default(true);
            $table->decimal('tax_percentage', 5, 2)->nullable(); // Null = use system default
            $table->decimal('service_charge_percentage', 5, 2)->nullable(); // Null = use system default
            $table->json('operating_hours')->nullable();
            $table->timestamps();
        });

        // 8. Preparation Stations (e.g., Main Kitchen, Grill, Pastry, Main Bar)
        Schema::create('pos_prep_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_outlet_id')->nullable()->constrained('pos_outlets', 'id', 'prep_station_outlet_fk')->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 9. Menu Categories
        Schema::create('menu_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_outlet_id')->nullable()->constrained('pos_outlets', 'id', 'menu_cat_outlet_id_fk')->nullOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // 10. Menu Items
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 48)->unique();
            $table->foreignId('pos_outlet_id')->nullable()->constrained('pos_outlets', 'id', 'menu_item_outlet_id_fk')->nullOnDelete();
            $table->foreignId('menu_category_id')->constrained('menu_categories', 'id', 'menu_item_cat_id_fk')->restrictOnDelete();
            $table->foreignId('pos_prep_station_id')->nullable()->constrained('pos_prep_stations', 'id', 'menu_item_prep_st_fk')->nullOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('currency_code', 3)->default('NGN');
            $table->bigInteger('price_minor'); // Authoritative selling price in minor units (kobo)
            $table->decimal('price', 14, 2);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_sold_out')->default(false);
            $table->boolean('is_room_service_eligible')->default(true);
            $table->string('stock_tracking_policy', 24)->default('none'); // none | recipe | direct_item
            $table->foreignId('direct_inventory_item_id')->nullable()->constrained('inventory_items', 'id', 'menu_item_inv_item_fk')->nullOnDelete();
            $table->decimal('direct_deduction_quantity', 14, 4)->default(1.0000); // In inventory_item base_unit
            $table->decimal('tax_percentage', 5, 2)->nullable();
            $table->decimal('service_charge_percentage', 5, 2)->nullable();
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'menu_item_created_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['menu_category_id', 'is_active', 'is_sold_out'], 'menu_items_cat_active_idx');
        });

        // 11. Menu Item Variants (Sizes / Portions)
        Schema::create('menu_item_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items', 'id', 'menu_var_item_id_fk')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120); // e.g., Regular, Large, Bottle, Glass
            $table->bigInteger('price_minor'); // Selling price for this variant in minor units
            $table->decimal('price', 14, 2);
            $table->decimal('recipe_multiplier', 8, 4)->default(1.0000); // Scales recipe/direct deduction quantities
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_sold_out')->default(false);
            $table->timestamps();

            $table->unique(['menu_item_id', 'code'], 'menu_var_item_code_unique');
        });

        // 12. Menu Item Modifiers / Add-ons
        Schema::create('menu_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items', 'id', 'menu_mod_item_id_fk')->cascadeOnDelete();
            $table->string('name', 120); // e.g., Extra Cheese, Spicy, No Onions, Extra Plantain
            $table->bigInteger('price_minor')->default(0); // Additional price in minor units
            $table->decimal('price', 14, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 13. Menu Recipes / Bill of Materials (BOM)
        Schema::create('menu_item_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items', 'id', 'recipe_menu_item_id_fk')->cascadeOnDelete();
            $table->foreignId('menu_item_variant_id')->nullable()->constrained('menu_item_variants', 'id', 'recipe_variant_id_fk')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items', 'id', 'recipe_inv_item_id_fk')->restrictOnDelete();
            $table->decimal('quantity_required', 14, 4); // Quantity required per 1 unit of menu item in `unit`
            $table->string('unit', 24); // Matches inventory_item base_unit or purchase_unit
            $table->decimal('conversion_to_base', 14, 4)->default(1.0000); // Multiplier to convert `quantity_required` into inventory_item base_unit
            $table->decimal('waste_percentage', 5, 2)->default(0.00); // Expected prep waste allowance %
            $table->integer('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['menu_item_id', 'menu_item_variant_id', 'inventory_item_id'], 'recipe_item_var_inv_unique');
        });

        // =====================================================================
        // C. POS & ROOM-SERVICE ORDERS, LINES, HISTORIES & PAYMENTS
        // =====================================================================

        // 14. POS Orders (Covers Dine-In, Bar, Takeaway, and Room Service)
        Schema::create('pos_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_reference', 40)->unique();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets', 'id', 'pos_ord_outlet_id_fk')->restrictOnDelete();
            $table->string('order_type', 24)->default('dine_in'); // dine_in | bar | takeaway | room_service
            $table->string('table_or_location', 64)->nullable(); // Table number or Room number display
            $table->integer('covers')->default(1);

            // Room-service & Guest-Folio links
            $table->foreignId('reservation_id')->nullable()->constrained('reservations', 'id', 'pos_ord_res_id_fk')->restrictOnDelete();
            $table->foreignId('guest_folio_id')->nullable()->constrained('guest_folios', 'id', 'pos_ord_folio_id_fk')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms', 'id', 'pos_ord_room_id_fk')->nullOnDelete();
            $table->string('guest_name_snapshot', 160)->nullable();
            $table->text('delivery_instructions')->nullable();

            // Explicit Order State Machine: draft -> submitted -> preparing -> ready -> out_for_delivery -> served | delivered | cancelled | voided
            $table->string('status', 32)->default('draft');
            // Explicit Payment Status: unpaid | partially_paid | paid | charged_to_room | refunded | voided
            $table->string('payment_status', 32)->default('unpaid');
            $table->string('settlement_method', 32)->nullable(); // cash | pos_card | bank_transfer | room_charge | split

            // Financial snapshots in integer minor units (authoritative)
            $table->string('currency_code', 3)->default('NGN');
            $table->bigInteger('subtotal_minor')->default(0); // Gross sum of line subtotals before order-level discount
            $table->string('discount_type', 24)->nullable(); // fixed | percentage
            $table->decimal('discount_value', 14, 2)->default(0.00);
            $table->bigInteger('discount_minor')->default(0);
            $table->string('discount_reason', 255)->nullable();
            $table->foreignId('discount_approved_by')->nullable()->constrained('users', 'id', 'pos_ord_disc_by_fk')->nullOnDelete();
            $table->decimal('tax_percentage_snapshot', 5, 2)->default(0.00);
            $table->bigInteger('tax_minor')->default(0);
            $table->decimal('service_charge_percentage_snapshot', 5, 2)->default(0.00);
            $table->bigInteger('service_charge_minor')->default(0);
            $table->bigInteger('total_minor')->default(0); // (subtotal_minor - discount_minor) + tax_minor + service_charge_minor
            $table->bigInteger('amount_paid_minor')->default(0); // Direct POS payments + posted room charge amount
            $table->bigInteger('amount_refunded_minor')->default(0);
            $table->bigInteger('balance_due_minor')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0.00);
            $table->decimal('amount_paid', 14, 2)->default(0.00);
            $table->decimal('balance_due_amount', 14, 2)->default(0.00);

            // Stock consumption & Room-charge tracking
            $table->boolean('stock_consumed')->default(false);
            $table->timestamp('stock_consumed_at')->nullable();
            $table->foreignId('folio_entry_id')->nullable()->constrained('folio_entries', 'id', 'pos_ord_folio_entry_fk')->nullOnDelete();
            $table->timestamp('room_charged_at')->nullable();

            // Staff & lifecycle timestamps
            $table->text('special_instructions')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'pos_ord_created_by_fk')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users', 'id', 'pos_ord_sub_by_fk')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->foreignId('served_by')->nullable()->constrained('users', 'id', 'pos_ord_served_by_fk')->nullOnDelete();
            $table->timestamp('served_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users', 'id', 'pos_ord_deliv_by_fk')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users', 'id', 'pos_ord_voided_by_fk')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['pos_outlet_id', 'status', 'created_at'], 'pos_ord_outlet_status_idx');
            $table->index(['order_type', 'status'], 'pos_ord_type_status_idx');
            $table->index(['reservation_id', 'payment_status'], 'pos_ord_res_pay_idx');
        });

        // 15. POS Order Lines (with Snapshotted Menu Prices & Modifiers)
        Schema::create('pos_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_order_id')->constrained('pos_orders', 'id', 'pos_line_ord_id_fk')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items', 'id', 'pos_line_item_id_fk')->restrictOnDelete();
            $table->foreignId('menu_item_variant_id')->nullable()->constrained('menu_item_variants', 'id', 'pos_line_var_id_fk')->nullOnDelete();
            $table->string('item_name_snapshot', 160);
            $table->string('variant_name_snapshot', 120)->nullable();
            $table->string('prep_station_name_snapshot', 120)->nullable();
            $table->integer('quantity');
            $table->string('currency_code', 3)->default('NGN');
            $table->bigInteger('unit_price_minor'); // Base item/variant price snapshot in minor units
            $table->bigInteger('modifiers_unit_minor')->default(0); // Sum of selected modifiers per 1 unit in minor units
            $table->bigInteger('line_subtotal_minor'); // (unit_price_minor + modifiers_unit_minor) * quantity
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_subtotal', 14, 2);
            $table->json('modifiers_snapshot')->nullable(); // Array of {id, name, price_minor, price}
            $table->string('special_instructions', 255)->nullable();
            $table->string('prep_status', 24)->default('pending'); // pending | preparing | ready | served | cancelled
            $table->timestamps();
        });

        // 16. POS Order Histories (Append-Only Audit Trail)
        Schema::create('pos_order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_order_id')->constrained('pos_orders', 'id', 'pos_hist_ord_id_fk')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('from_payment_status', 32)->nullable();
            $table->string('to_payment_status', 32)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users', 'id', 'pos_hist_actor_id_fk')->nullOnDelete();
            $table->timestamp('created_at');
        });

        // 17. POS Payments (Append-Only Payment & Refund/Reversal Ledger for Direct POS Sales)
        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_reference', 40)->unique();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->foreignId('pos_order_id')->constrained('pos_orders', 'id', 'pos_pay_ord_id_fk')->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets', 'id', 'pos_pay_outlet_id_fk')->restrictOnDelete();
            $table->string('type', 24)->default('payment'); // payment | refund | reversal
            $table->string('method', 32); // cash | pos_card | bank_transfer
            $table->string('currency_code', 3)->default('NGN');
            $table->bigInteger('amount_minor'); // Positive for payment, negative for refund/reversal
            $table->decimal('amount', 14, 2);
            $table->string('status', 24)->default('completed'); // completed | voided
            $table->string('receipt_number', 64)->nullable();
            $table->string('external_reference', 80)->nullable();
            $table->unsignedBigInteger('reversed_payment_id')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users', 'id', 'pos_pay_rec_by_fk')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users', 'id', 'pos_pay_app_by_fk')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->foreign('reversed_payment_id', 'pos_pay_rev_id_fk')->references('id')->on('pos_payments')->restrictOnDelete();
            $table->index(['pos_outlet_id', 'paid_at'], 'pos_pay_outlet_date_idx');
        });

        // =====================================================================
        // D. SUPPLIERS, PURCHASE REQUISITIONS, PURCHASE ORDERS & GOODS RECEIVING
        // =====================================================================

        // 18. Suppliers
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code', 40)->unique();
            $table->string('name', 160);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 24)->default('active'); // active | suspended | inactive
            // Sensitive financial/tax details (redacted unless actor holds `procurement.suppliers.sensitive`)
            $table->string('tax_id', 64)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account_number', 40)->nullable();
            $table->string('bank_account_name', 160)->nullable();
            $table->string('payment_terms', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sup_created_by_fk')->nullOnDelete();
            $table->timestamps();
        });

        // 19. Supplier Supported Inventory Items
        Schema::create('supplier_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers', 'id', 'sup_item_sup_id_fk')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items', 'id', 'sup_item_inv_id_fk')->cascadeOnDelete();
            $table->string('supplier_sku', 64)->nullable();
            $table->bigInteger('last_quoted_unit_cost_minor')->default(0); // Per purchase_unit
            $table->integer('lead_time_days')->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();

            $table->unique(['supplier_id', 'inventory_item_id'], 'sup_inv_item_unique');
        });

        // 20. Purchase Requisitions
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('requisition_reference', 40)->unique();
            $table->foreignId('storage_location_id')->nullable()->constrained('storage_locations', 'id', 'pr_loc_id_fk')->nullOnDelete();
            $table->string('status', 32)->default('pending_approval'); // draft | pending_approval | approved | rejected | ordered | cancelled
            $table->string('priority', 24)->default('normal'); // low | normal | high | urgent
            $table->date('required_by_date')->nullable();
            $table->text('reason');
            $table->text('review_notes')->nullable();
            $table->foreignId('requested_by')->constrained('users', 'id', 'pr_req_by_fk')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'id', 'pr_rev_by_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority'], 'pr_status_prio_idx');
        });

        // 21. Purchase Requisition Items
        Schema::create('purchase_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions', 'id', 'pri_pr_id_fk')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items', 'id', 'pri_inv_id_fk')->restrictOnDelete();
            $table->decimal('quantity_requested', 14, 4);
            $table->decimal('quantity_approved', 14, 4)->nullable();
            $table->string('unit', 24); // purchase_unit or base_unit
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        // 22. Purchase Orders
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_reference', 40)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers', 'id', 'po_sup_id_fk')->restrictOnDelete();
            $table->foreignId('purchase_requisition_id')->nullable()->constrained('purchase_requisitions', 'id', 'po_pr_id_fk')->nullOnDelete();
            $table->foreignId('storage_location_id')->constrained('storage_locations', 'id', 'po_loc_id_fk')->restrictOnDelete();
            // Status: draft | pending_approval | approved | partially_received | received | cancelled
            $table->string('status', 32)->default('pending_approval');
            $table->date('expected_delivery_date')->nullable();
            $table->string('currency_code', 3)->default('NGN');
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->constrained('users', 'id', 'po_created_by_fk')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users', 'id', 'po_approved_by_fk')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users', 'id', 'po_cancelled_by_fk')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status'], 'po_sup_status_idx');
            $table->index(['status', 'expected_delivery_date'], 'po_status_deliv_idx');
        });

        // 23. Purchase Order Items
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders', 'id', 'poi_po_id_fk')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items', 'id', 'poi_inv_id_fk')->restrictOnDelete();
            $table->string('unit', 24); // Purchase unit or base unit
            $table->decimal('conversion_to_base', 14, 4)->default(1.0000); // Multiplier to convert 1 `unit` into item's `base_unit`
            $table->decimal('quantity_ordered', 14, 4);
            $table->decimal('quantity_received', 14, 4)->default(0.0000);
            $table->decimal('quantity_rejected', 14, 4)->default(0.0000);
            $table->bigInteger('unit_cost_minor'); // Cost per 1 `unit` in minor units
            $table->bigInteger('line_total_minor'); // round(quantity_ordered * unit_cost_minor)
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        // 24. Goods Receivings (GRN)
        Schema::create('goods_receivings', function (Blueprint $table) {
            $table->id();
            $table->string('receiving_reference', 40)->unique();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders', 'id', 'grn_po_id_fk')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers', 'id', 'grn_sup_id_fk')->restrictOnDelete();
            $table->foreignId('storage_location_id')->constrained('storage_locations', 'id', 'grn_loc_id_fk')->restrictOnDelete();
            $table->string('supplier_delivery_note', 80)->nullable();
            $table->string('supplier_invoice_reference', 80)->nullable();
            $table->string('currency_code', 3)->default('NGN');
            $table->bigInteger('total_received_cost_minor')->default(0);
            $table->decimal('total_received_cost', 14, 2)->default(0.00);
            $table->boolean('has_discrepancy')->default(false);
            $table->text('discrepancy_notes')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users', 'id', 'grn_rec_by_fk')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['purchase_order_id', 'received_at'], 'grn_po_date_idx');
        });

        // 25. Goods Receiving Items
        Schema::create('goods_receiving_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receiving_id')->constrained('goods_receivings', 'id', 'grni_grn_id_fk')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items', 'id', 'grni_poi_id_fk')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items', 'id', 'grni_inv_id_fk')->restrictOnDelete();
            $table->decimal('quantity_received', 14, 4); // Accepted quantity in PO line `unit`
            $table->decimal('quantity_rejected', 14, 4)->default(0.0000);
            $table->decimal('base_quantity_received', 14, 4); // Accepted quantity converted to `base_unit`
            $table->bigInteger('unit_cost_minor'); // Actual received cost per 1 PO `unit`
            $table->bigInteger('line_total_minor');
            $table->string('batch_number', 64)->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('has_price_discrepancy')->default(false);
            $table->boolean('has_quantity_discrepancy')->default(false);
            $table->string('discrepancy_reason', 255)->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements', 'id', 'grni_stk_mov_fk')->nullOnDelete();
            $table->timestamps();
        });

        // =====================================================================
        // E. MARIADB / MYSQL APPEND-ONLY IMMUTABILITY TRIGGERS
        // =====================================================================
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_stock_movements_no_update;
                CREATE TRIGGER trg_stock_movements_no_update
                BEFORE UPDATE ON stock_movements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "Stock movements are append-only and immutable at the database level.";
                END;

                DROP TRIGGER IF EXISTS trg_stock_movements_no_delete;
                CREATE TRIGGER trg_stock_movements_no_delete
                BEFORE DELETE ON stock_movements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "Stock movements cannot be deleted at the database level.";
                END;

                DROP TRIGGER IF EXISTS trg_pos_payments_no_update;
                CREATE TRIGGER trg_pos_payments_no_update
                BEFORE UPDATE ON pos_payments
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "POS payment ledger rows are immutable at the database level.";
                END;

                DROP TRIGGER IF EXISTS trg_pos_payments_no_delete;
                CREATE TRIGGER trg_pos_payments_no_delete
                BEFORE DELETE ON pos_payments
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "POS payment ledger rows cannot be deleted at the database level.";
                END;
            ');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_stock_movements_no_update;
                DROP TRIGGER IF EXISTS trg_stock_movements_no_delete;
                DROP TRIGGER IF EXISTS trg_pos_payments_no_update;
                DROP TRIGGER IF EXISTS trg_pos_payments_no_delete;
            ');
        }

        Schema::dropIfExists('goods_receiving_items');
        Schema::dropIfExists('goods_receivings');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('supplier_inventory_items');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('pos_payments');
        Schema::dropIfExists('pos_order_histories');
        Schema::dropIfExists('pos_order_items');
        Schema::dropIfExists('pos_orders');
        Schema::dropIfExists('menu_item_recipes');
        Schema::dropIfExists('menu_item_modifiers');
        Schema::dropIfExists('menu_item_variants');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menu_categories');
        Schema::dropIfExists('pos_prep_stations');
        Schema::dropIfExists('pos_outlets');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_batches');
        Schema::dropIfExists('inventory_stock_balances');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('storage_locations');
        Schema::dropIfExists('inventory_categories');
    }
};
