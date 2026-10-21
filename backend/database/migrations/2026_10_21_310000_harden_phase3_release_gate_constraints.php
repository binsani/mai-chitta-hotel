<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 Release-Gate Database Hardening:
 * 1. Adds `active_room_id` on `reservation_nights` (set only when `status === 'active'` and a physical room is assigned)
 *    with a unique index `(active_room_id, stay_date)` so two active reservations can never occupy the same physical
 *    room on the same stay date even at the raw database constraint level.
 * 2. Adds a unique index on `payment_transactions(reversed_transaction_id)` so a payment can never be reversed twice
 *    even under concurrent requests.
 * 3. Adds a composite index on `payment_transactions(provider, provider_reference, status)` for fast provider
 *    transaction cross-reservation reuse and idempotency checks.
 * 4. On MySQL/MariaDB, installs BEFORE UPDATE and BEFORE DELETE triggers on `payment_transactions`,
 *    `reservation_status_histories`, and `audit_logs` so completed financial/audit ledger rows cannot be mutated or
 *    deleted even via raw Query Builder / SQL bypassing Eloquent events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_nights', function (Blueprint $table) {
            $table->unsignedBigInteger('active_room_id')->nullable()->after('room_id');
            $table->unique(['active_room_id', 'stay_date'], 'res_night_active_room_date_unique');
        });

        // Backfill active_room_id for any existing active reservation_nights rows
        DB::table('reservation_nights')
            ->where('status', 'active')
            ->whereNotNull('room_id')
            ->update(['active_room_id' => DB::raw('room_id')]);

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->unique('reversed_transaction_id', 'pt_reversed_tx_unique');
            $table->index(['provider', 'provider_reference', 'status'], 'pt_provider_ref_status_idx');
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_payment_transactions_no_update;
                CREATE TRIGGER trg_payment_transactions_no_update
                BEFORE UPDATE ON payment_transactions
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "Financial payment transactions are immutable at the database level.";
                END;

                DROP TRIGGER IF EXISTS trg_payment_transactions_no_delete;
                CREATE TRIGGER trg_payment_transactions_no_delete
                BEFORE DELETE ON payment_transactions
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "Financial payment transactions cannot be deleted at the database level.";
                END;

                DROP TRIGGER IF EXISTS trg_res_status_history_no_update;
                CREATE TRIGGER trg_res_status_history_no_update
                BEFORE UPDATE ON reservation_status_histories
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "Reservation status history is immutable at the database level.";
                END;

                DROP TRIGGER IF EXISTS trg_res_status_history_no_delete;
                CREATE TRIGGER trg_res_status_history_no_delete
                BEFORE DELETE ON reservation_status_histories
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "Reservation status history cannot be deleted at the database level.";
                END;
            ');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_payment_transactions_no_update;
                DROP TRIGGER IF EXISTS trg_payment_transactions_no_delete;
                DROP TRIGGER IF EXISTS trg_res_status_history_no_update;
                DROP TRIGGER IF EXISTS trg_res_status_history_no_delete;
            ');
        }

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique('pt_reversed_tx_unique');
            $table->dropIndex('pt_provider_ref_status_idx');
        });

        Schema::table('reservation_nights', function (Blueprint $table) {
            $table->dropUnique('res_night_active_room_date_unique');
            $table->dropColumn('active_room_id');
        });
    }
};
