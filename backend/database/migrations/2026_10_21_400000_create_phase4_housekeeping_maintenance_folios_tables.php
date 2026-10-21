<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Implementation:
 * - Housekeeping Management (`housekeeping_tasks`, `housekeeping_inspections`, `housekeeping_task_histories`)
 * - Maintenance Work Orders (`maintenance_work_orders`, `maintenance_work_order_histories`)
 * - Guest Folios & Charge Ledger (`guest_folios`, `folio_entries`, `folio_payment_allocations`)
 * - Operational Notifications (`operational_notifications`)
 * - Additive room inspection metadata columns (`last_cleaned_at`, `last_inspected_at`, `last_inspected_by`)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('rooms', 'last_cleaned_at')) {
                $table->timestamp('last_cleaned_at')->nullable()->after('housekeeping_status');
            }
            if (! Schema::hasColumn('rooms', 'last_inspected_at')) {
                $table->timestamp('last_inspected_at')->nullable()->after('last_cleaned_at');
            }
            if (! Schema::hasColumn('rooms', 'last_inspected_by')) {
                $table->foreignId('last_inspected_by')->nullable()->after('last_inspected_at')->constrained('users')->nullOnDelete();
            }
        });

        // 1. Housekeeping Tasks
        Schema::create('housekeeping_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_reference', 40)->unique();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->unsignedBigInteger('parent_task_id')->nullable(); // For corrective follow-up tasks after failed inspection
            $table->string('task_type', 40); // checkout_cleaning | stayover_cleaning | deep_cleaning | inspection_followup | maintenance_followup | manual_request
            $table->string('priority', 24)->default('normal'); // low | normal | high | urgent
            $table->string('status', 32)->default('pending'); // pending | assigned | in_progress | completed | inspected | failed_inspection | cancelled
            $table->date('scheduled_date');
            $table->time('due_time')->nullable();
            $table->string('title', 180);
            $table->text('instructions')->nullable();
            $table->text('completion_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('parent_task_id')->references('id')->on('housekeeping_tasks')->nullOnDelete();
            $table->index(['room_id', 'status']);
            $table->index(['scheduled_date', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['reservation_id', 'task_type']);
        });

        // 2. Housekeeping Inspections
        Schema::create('housekeeping_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('housekeeping_task_id')->constrained('housekeeping_tasks', 'id', 'hk_insp_task_id_fk')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->string('outcome', 24); // passed | failed
            $table->json('checklist')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('followup_task_id')->nullable();
            $table->foreignId('inspected_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('inspected_at');
            $table->timestamps();

            $table->foreign('followup_task_id', 'hk_insp_followup_task_fk')->references('id')->on('housekeeping_tasks')->nullOnDelete();
            $table->index(['room_id', 'inspected_at']);
        });

        // 3. Housekeeping Task Histories (Append-Only Audit Trail)
        Schema::create('housekeeping_task_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('housekeeping_task_id')->constrained('housekeeping_tasks', 'id', 'hk_hist_task_id_fk')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('from_assigned_to')->nullable()->constrained('users', 'id', 'hk_hist_from_user_fk')->nullOnDelete();
            $table->foreignId('to_assigned_to')->nullable()->constrained('users', 'id', 'hk_hist_to_user_fk')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users', 'id', 'hk_hist_changed_by_fk')->nullOnDelete();
            $table->timestamp('created_at');
        });

        // 4. Maintenance Work Orders
        Schema::create('maintenance_work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('work_order_reference', 40)->unique();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->string('area_label', 120)->nullable(); // For non-room areas or supplementary room location detail
            $table->string('category', 40); // plumbing | electrical | hvac | furniture | appliance | bathroom | door_lock | painting_civil | WiFi_tv | other
            $table->string('priority', 24)->default('normal'); // low | normal | high | emergency
            $table->string('status', 32)->default('reported'); // reported | acknowledged | assigned | in_progress | awaiting_parts | resolved | closed | cancelled
            $table->string('title', 180);
            $table->text('description');
            $table->text('Internal_notes')->nullable();
            $table->text('diagnosis_notes')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->text('parts_Wait_notes')->nullable();
            $table->boolean('blocks_room')->default(false);
            $table->date('blocked_from')->nullable();
            $table->date('blocked_until')->nullable();
            $table->boolean('has_arrival_conflict')->default(false);
            $table->json('conflicting_reservation_ids')->nullable();
            $table->string('currency_code', 3)->default('NGN');
            $table->unsignedBigInteger('parts_cost_minor')->default(0);
            $table->unsignedBigInteger('labor_cost_minor')->default(0);
            $table->unsignedBigInteger('total_cost_minor')->default(0);
            $table->decimal('parts_cost', 14, 2)->default(0);
            $table->decimal('labor_cost', 14, 2)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->string('vendor_name', 160)->nullable();
            $table->string('vendor_invoice_reference', 80)->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('followup_housekeeping_task_id')->nullable();
            $table->timestamps();

            $table->foreign('followup_housekeeping_task_id', 'mwo_followup_hk_task_fk')->references('id')->on('housekeeping_tasks')->nullOnDelete();
            $table->index(['room_id', 'status', 'blocks_room'], 'mwo_room_status_block_idx');
            $table->index(['status', 'priority'], 'mwo_status_priority_idx');
            $table->index(['assigned_to', 'status'], 'mwo_assigned_status_idx');
        });

        // 5. Maintenance Work Order Histories
        Schema::create('maintenance_work_order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_work_order_id')->constrained('maintenance_work_orders', 'id', 'mwo_hist_mwo_id_fk')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users', 'id', 'mwo_hist_changed_by_fk')->nullOnDelete();
            $table->timestamp('created_at');
        });

        // 6. Guest Folios
        Schema::create('guest_folios', function (Blueprint $table) {
            $table->id();
            $table->string('folio_reference', 40)->unique();
            $table->foreignId('reservation_id')->unique()->constrained('reservations')->restrictOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('guest_name', 160);
            $table->string('status', 32)->default('open'); // open | settled | closed | voided
            $table->string('currency_code', 3)->default('NGN');
            $table->bigInteger('subtotal_charges_minor')->default(0);
            $table->bigInteger('taxes_minor')->default(0);
            $table->bigInteger('service_charges_minor')->default(0);
            $table->bigInteger('total_charges_minor')->default(0);
            $table->bigInteger('total_credits_minor')->default(0); // Discounts, allowances, credit adjustments
            $table->bigInteger('total_payments_minor')->default(0); // Net allocated verified payments
            $table->bigInteger('total_refunds_minor')->default(0); // Processed refunds on folio
            $table->bigInteger('balance_due_minor')->default(0); // total_charges_minor - total_credits_minor - total_payments_minor + total_refunds_minor
            $table->decimal('total_charges_amount', 14, 2)->default(0);
            $table->decimal('total_credits_amount', 14, 2)->default(0);
            $table->decimal('total_payments_amount', 14, 2)->default(0);
            $table->decimal('total_refunds_amount', 14, 2)->default(0);
            $table->decimal('balance_due_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('settled_with_exception')->default(false);
            $table->string('settlement_exception_reason', 255)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'balance_due_minor']);
            $table->index('guest_name');
        });

        // 7. Folio Entries (Append-Only Financial Charge / Credit / Adjustment / Reversal Ledger)
        Schema::create('folio_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_reference', 40)->unique();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->foreignId('guest_folio_id')->constrained('guest_folios')->restrictOnDelete();
            $table->foreignId('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->string('entry_type', 40); // accommodation | manual_charge | pos_charge | cancellation_fee | tax | service_charge | discount | allowance | credit_adjustment | debit_adjustment | reversal
            $table->string('direction', 16); // charge | credit
            $table->string('category', 48)->default('general'); // room_rate | laundry | minibar | extra_bed | late_checkout | early_checkin | damage | miscellaneous | pos_restaurant | tax | service_charge | adjustment | reversal
            $table->string('description', 255);
            $table->date('service_date');
            $table->integer('quantity')->default(1);
            $table->string('currency_code', 3)->default('NGN');
            $table->bigInteger('unit_amount_minor')->default(0);
            $table->bigInteger('net_amount_minor')->default(0);
            $table->bigInteger('tax_amount_minor')->default(0);
            $table->bigInteger('service_charge_minor')->default(0);
            $table->bigInteger('total_amount_minor'); // Signed impact on charges/credits or positive magnitude paired with direction
            $table->decimal('total_amount', 14, 2);
            $table->string('status', 24)->default('posted'); // pending_approval | posted | rejected
            $table->string('source_type', 48)->nullable(); // reservation_night | reservation_tax | reservation_fee | cancellation_fee | pos_order | manual
            $table->string('source_id', 64)->nullable();
            $table->string('active_source_key', 120)->nullable()->unique(); // Enforce unique active source posting (e.g., folio:1:night:2026-11-10:unit:1)
            $table->unsignedBigInteger('reversed_entry_id')->nullable()->unique(); // Ensures a posted entry can only be reversed once
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('reversed_entry_id')->references('id')->on('folio_entries')->restrictOnDelete();
            $table->index(['guest_folio_id', 'status', 'service_date']);
            $table->index(['source_type', 'source_id']);
        });

        // 8. Folio Payment Allocations (Links verified PaymentTransactions to GuestFolios)
        Schema::create('folio_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('allocation_reference', 40)->unique();
            $table->foreignId('guest_folio_id')->constrained('guest_folios', 'id', 'fpa_folio_id_fk')->restrictOnDelete();
            $table->foreignId('reservation_id')->constrained('reservations', 'id', 'fpa_reservation_id_fk')->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions', 'id', 'fpa_pay_tx_id_fk')->restrictOnDelete();
            $table->string('allocation_type', 24)->default('payment'); // payment | unallocation | refund
            $table->string('currency_code', 3)->default('NGN');
            $table->bigInteger('allocated_amount_minor'); // Positive for payment allocation, negative for unallocation/reversal
            $table->decimal('allocated_amount', 14, 2);
            $table->text('notes')->nullable();
            $table->foreignId('allocated_by')->nullable()->constrained('users', 'id', 'fpa_allocated_by_fk')->nullOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamps();

            $table->index(['guest_folio_id', 'payment_transaction_id'], 'fpa_folio_tx_idx');
        });

        // 9. Operational Notifications (Internal Staff Alerts)
        Schema::create('operational_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64); // housekeeping.assigned | housekeeping.failed_inspection | maintenance.urgent | maintenance.arrival_conflict | folio.adjustment_pending | folio.settlement_exception
            $table->string('severity', 24)->default('info'); // info | warning | critical
            $table->string('title', 180);
            $table->text('message');
            $table->string('target_permission', 64)->nullable();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->foreignId('read_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_read', 'severity', 'created_at']);
            $table->index(['target_user_id', 'is_read']);
        });

        // 10. Install MySQL/MariaDB database-level immutability triggers on posted folio_entries and folio_payment_allocations
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_folio_entries_no_update;
                CREATE TRIGGER trg_folio_entries_no_update
                BEFORE UPDATE ON folio_entries
                FOR EACH ROW
                BEGIN
                    IF OLD.status = "posted" OR OLD.status = "rejected" THEN
                        SIGNAL SQLSTATE "45000"
                        SET MESSAGE_TEXT = "Posted or reviewed folio ledger entries are immutable at the database level.";
                    END IF;
                END;

                DROP TRIGGER IF EXISTS trg_folio_entries_no_delete;
                CREATE TRIGGER trg_folio_entries_no_delete
                BEFORE DELETE ON folio_entries
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "Folio ledger entries cannot be deleted at the database level.";
                END;

                DROP TRIGGER IF EXISTS trg_folio_allocations_no_update;
                CREATE TRIGGER trg_folio_allocations_no_update
                BEFORE UPDATE ON folio_payment_allocations
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "Folio payment allocations are immutable at the database level.";
                END;

                DROP TRIGGER IF EXISTS trg_folio_allocations_no_delete;
                CREATE TRIGGER trg_folio_allocations_no_delete
                BEFORE DELETE ON folio_payment_allocations
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "Folio payment allocations cannot be deleted at the database level.";
                END;
            ');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_folio_entries_no_update;
                DROP TRIGGER IF EXISTS trg_folio_entries_no_delete;
                DROP TRIGGER IF EXISTS trg_folio_allocations_no_update;
                DROP TRIGGER IF EXISTS trg_folio_allocations_no_delete;
            ');
        }

        Schema::dropIfExists('operational_notifications');
        Schema::dropIfExists('folio_payment_allocations');
        Schema::dropIfExists('folio_entries');
        Schema::dropIfExists('guest_folios');
        Schema::dropIfExists('maintenance_work_order_histories');
        Schema::dropIfExists('maintenance_work_orders');
        Schema::dropIfExists('housekeeping_task_histories');
        Schema::dropIfExists('housekeeping_inspections');
        Schema::dropIfExists('housekeeping_tasks');

        Schema::table('rooms', function (Blueprint $table) {
            if (Schema::hasColumn('rooms', 'last_inspected_by')) {
                $table->dropConstrainedForeignId('last_inspected_by');
            }
            if (Schema::hasColumn('rooms', 'last_inspected_at')) {
                $table->dropColumn('last_inspected_at');
            }
            if (Schema::hasColumn('rooms', 'last_cleaned_at')) {
                $table->dropColumn('last_cleaned_at');
            }
        });
    }
};
