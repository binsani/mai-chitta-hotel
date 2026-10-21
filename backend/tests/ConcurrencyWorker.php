<?php
/**
 * Standalone CLI Concurrency Worker for MariaDB/MySQL InnoDB Multi-Process Race Tests.
 *
 * Usage: php tests/ConcurrencyWorker.php <base64-json-payload>
 */

require __DIR__.'/../vendor/autoload.php';

$payloadJson = base64_decode($argv[1] ?? '', true);
if (! $payloadJson) {
    fwrite(STDERR, "Missing or invalid base64 payload\n");
    exit(2);
}

$input = json_decode($payloadJson, true);
if (! is_array($input)) {
    fwrite(STDERR, "Invalid JSON payload\n");
    exit(2);
}

// Configure environment for the isolated maichitta_test MariaDB/InnoDB database BEFORE booting Laravel
foreach ($input['env'] ?? [] as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

\Illuminate\Support\Facades\Notification::fake();

// Synchronize start across parallel worker processes if barrier timestamp is provided
if (! empty($input['start_at_us'])) {
    $nowUs = (int) (microtime(true) * 1_000_000);
    $waitUs = (int) $input['start_at_us'] - $nowUs;
    if ($waitUs > 0 && $waitUs < 5_000_000) {
        usleep($waitUs);
    }
}

$action = (string) ($input['action'] ?? '');
$params = (array) ($input['params'] ?? []);

try {
    $reservationService = $app->make(\App\Services\ReservationService::class);
    $paymentService = $app->make(\App\Services\PaymentService::class);
    $housekeepingService = $app->make(\App\Services\HousekeepingService::class);
    $maintenanceService = $app->make(\App\Services\MaintenanceService::class);
    $folioService = $app->make(\App\Services\FolioService::class);
    $inventoryService = $app->make(\App\Services\InventoryService::class);
    $procurementService = $app->make(\App\Services\ProcurementService::class);
    $posService = $app->make(\App\Services\PosService::class);

    switch ($action) {
        case 'create_reservation':
            $res = $reservationService->createReservation($params['data'], actor: null, isPublic: true);
            echo json_encode(['ok' => true, 'id' => $res->id, 'reference' => $res->reference, 'status' => $res->status]);
            break;

        case 'create_hold':
            $hold = $reservationService->createHold($params['data'], '127.0.0.1', publicOnly: true);
            echo json_encode(['ok' => true, 'id' => $hold->id, 'hold_token' => $hold->hold_token, 'status' => $hold->status]);
            break;

        case 'assign_room':
            $res = \App\Models\Reservation::findOrFail((int) $params['reservation_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $updated = $reservationService->assignRoom($res, (int) $params['room_id'], $actor);
            echo json_encode(['ok' => true, 'id' => $updated->id, 'assigned_room_id' => $updated->assigned_room_id]);
            break;

        case 'amend_reservation':
            $res = \App\Models\Reservation::findOrFail((int) $params['reservation_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $updated = $reservationService->amendReservation($res, $params['data'], $actor);
            echo json_encode(['ok' => true, 'id' => $updated->id, 'room_type_id' => $updated->room_type_id]);
            break;

        case 'room_out_of_service':
            $room = \App\Models\Room::findOrFail((int) $params['room_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $req = \Illuminate\Http\Request::create("/api/v1/admin/rooms/{$room->id}", 'PUT', $params['data']);
            $req->setUserResolver(fn () => $actor);
            \Illuminate\Support\Facades\Auth::setUser($actor);
            $ctrl = $app->make(\App\Http\Controllers\Api\V1\Admin\RoomController::class);
            $resp = $ctrl->update($req, $room);
            echo json_encode(['ok' => true, 'status_code' => $resp->getStatusCode()]);
            break;

        case 'convert_hold':
            $res = $reservationService->createReservation($params['data'], actor: null, isPublic: true);
            echo json_encode(['ok' => true, 'id' => $res->id, 'reference' => $res->reference]);
            break;

        case 'webhook_paystack':
            config(['services.paystack.secret_key' => $params['secret_key']]);
            \Illuminate\Support\Facades\Http::fake([
                'api.paystack.co/transaction/verify/*' => \Illuminate\Support\Facades\Http::response($params['mock_verify'], 200),
            ]);
            $result = $paymentService->handleWebhook('paystack', $params['raw'], $params['sig'], $params['payload']);
            echo json_encode(['ok' => true, 'result' => $result]);
            break;

        case 'request_refund':
            $res = \App\Models\Reservation::findOrFail((int) $params['reservation_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $rfd = $paymentService->requestRefund($res, $params['data'], $actor);
            echo json_encode(['ok' => true, 'refund_id' => $rfd->id, 'amount_minor' => $rfd->amount_minor]);
            break;

        case 'approve_refund':
            $rfd = \App\Models\PaymentRefund::findOrFail((int) $params['refund_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $updated = $paymentService->reviewRefund($rfd, ['decision' => 'approve', 'payout_method' => 'bank_transfer'], $actor);
            echo json_encode(['ok' => true, 'status' => $updated->status]);
            break;

        case 'reverse_payment':
            $tx = \App\Models\PaymentTransaction::findOrFail((int) $params['transaction_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $rev = $paymentService->reversePayment($tx, 'Concurrent reversal test', $actor);
            echo json_encode(['ok' => true, 'reversal_id' => $rev->id]);
            break;

        case 'hk_start_task':
            $task = \App\Models\HousekeepingTask::findOrFail((int) $params['task_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $updated = $housekeepingService->startTask($task, $actor, 'Worker claiming task');
            echo json_encode(['ok' => true, 'task_id' => $updated->id, 'assigned_to' => $updated->assigned_to, 'status' => $updated->status]);
            break;

        case 'hk_complete_task':
            $task = \App\Models\HousekeepingTask::findOrFail((int) $params['task_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $updated = $housekeepingService->completeTask($task, $actor, 'Worker completing task');
            echo json_encode(['ok' => true, 'task_id' => $updated->id, 'status' => $updated->status]);
            break;

        case 'maint_create_blocking_oos':
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $wo = $maintenanceService->createWorkOrder($params['data'], $actor);
            echo json_encode(['ok' => true, 'wo_id' => $wo->id, 'reference' => $wo->work_order_reference]);
            break;

        case 'folio_sync_accommodation':
            $folio = \App\Models\GuestFolio::findOrFail((int) $params['folio_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $updated = $folioService->syncAccommodationCharges($folio, $actor);
            echo json_encode(['ok' => true, 'folio_id' => $updated->id, 'total_charges_minor' => $updated->total_charges_minor]);
            break;

        case 'folio_post_charge':
            $folio = \App\Models\GuestFolio::findOrFail((int) $params['folio_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $entry = $folioService->postManualCharge($folio, $params['data'], $actor);
            echo json_encode(['ok' => true, 'entry_id' => $entry->id, 'total_amount_minor' => $entry->total_amount_minor]);
            break;

        case 'folio_allocate_payment':
            $folio = \App\Models\GuestFolio::findOrFail((int) $params['folio_id']);
            $tx = \App\Models\PaymentTransaction::findOrFail((int) $params['payment_transaction_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $alloc = $folioService->allocatePaymentToFolio($folio, $tx, isset($params['amount_minor']) ? (int) $params['amount_minor'] : null, $actor);
            echo json_encode(['ok' => true, 'allocation_id' => $alloc->id, 'allocated_amount_minor' => $alloc->allocated_amount_minor]);
            break;

        case 'folio_settle':
            $folio = \App\Models\GuestFolio::findOrFail((int) $params['folio_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $closed = $folioService->settleAndCloseFolio($folio, $params['data'] ?? [], $actor);
            echo json_encode(['ok' => true, 'folio_id' => $closed->id, 'status' => $closed->status]);
            break;

        case 'folio_reverse_entry':
            $entry = \App\Models\FolioEntry::findOrFail((int) $params['entry_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $rev = $folioService->reversePostedEntry($entry, 'Concurrent reversal test', $actor);
            echo json_encode(['ok' => true, 'reversal_id' => $rev->id]);
            break;

        case 'pos_create_order':
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $order = $posService->createOrder($params['data'], $actor);
            echo json_encode([
                'ok' => true,
                'order_id' => $order->id,
                'order_reference' => $order->order_reference,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'stock_consumed' => (bool) $order->stock_consumed,
                'total_minor' => $order->total_minor,
            ]);
            break;

        case 'pos_transition_status':
            $order = \App\Models\PosOrder::findOrFail((int) $params['order_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $updated = $posService->transitionStatus($order, (string) $params['target_status'], $actor, $params['notes'] ?? null, $params['options'] ?? []);
            echo json_encode([
                'ok' => true,
                'order_id' => $updated->id,
                'status' => $updated->status,
                'stock_consumed' => (bool) $updated->stock_consumed,
            ]);
            break;

        case 'pos_room_charge':
            $order = \App\Models\PosOrder::findOrFail((int) $params['order_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $updated = $posService->postOrderChargeToRoomFolio($order, $params['data'] ?? [], $actor);
            echo json_encode([
                'ok' => true,
                'order_id' => $updated->id,
                'payment_status' => $updated->payment_status,
                'folio_entry_id' => $updated->folio_entry_id,
            ]);
            break;

        case 'pos_void_order':
            $order = \App\Models\PosOrder::findOrFail((int) $params['order_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $updated = $posService->voidOrCancelOrder($order, $params['data'], $actor);
            echo json_encode([
                'ok' => true,
                'order_id' => $updated->id,
                'status' => $updated->status,
                'payment_status' => $updated->payment_status,
            ]);
            break;

        case 'pos_record_payment':
            $order = \App\Models\PosOrder::findOrFail((int) $params['order_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $payment = $posService->recordPayment($order, $params['data'], $actor);
            echo json_encode([
                'ok' => true,
                'payment_id' => $payment->id,
                'payment_reference' => $payment->payment_reference,
                'amount_minor' => $payment->amount_minor,
            ]);
            break;

        case 'pos_refund_payment':
            $payment = \App\Models\PosPayment::findOrFail((int) $params['payment_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $refund = $posService->refundOrReversePayment($payment, (string) ($params['reason'] ?? 'Concurrent refund test'), $actor);
            echo json_encode([
                'ok' => true,
                'refund_id' => $refund->id,
                'amount_minor' => $refund->amount_minor,
            ]);
            break;

        case 'inventory_adjust':
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $movement = $inventoryService->recordAdjustmentOrWaste($params['data'], $actor);
            echo json_encode([
                'ok' => true,
                'movement_id' => $movement->id,
                'balance_after' => (float) $movement->balance_after,
            ]);
            break;

        case 'inventory_stock_count':
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $movement = $inventoryService->recordStockCount($params['data'], $actor);
            echo json_encode([
                'ok' => true,
                'movement_id' => $movement->id,
                'balance_after' => (float) $movement->balance_after,
            ]);
            break;

        case 'procurement_receive_goods':
            $po = \App\Models\PurchaseOrder::findOrFail((int) $params['purchase_order_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $grn = $procurementService->receiveGoods($po, $params['data'], $actor);
            echo json_encode([
                'ok' => true,
                'grn_id' => $grn->id,
                'grn_reference' => $grn->grn_reference,
            ]);
            break;

        case 'procurement_review_po':
            $po = \App\Models\PurchaseOrder::findOrFail((int) $params['purchase_order_id']);
            $actor = \App\Models\User::findOrFail((int) $params['actor_id']);
            $updated = $procurementService->reviewPurchaseOrder($po, (string) $params['decision'], $actor, $params['reason'] ?? null);
            echo json_encode([
                'ok' => true,
                'po_id' => $updated->id,
                'status' => $updated->status,
            ]);
            break;

        default:
            throw new \InvalidArgumentException("Unknown worker action: {$action}");
    }
    exit(0);
} catch (\Illuminate\Validation\ValidationException $e) {
    echo json_encode(['ok' => false, 'type' => 'validation', 'errors' => $e->errors(), 'message' => $e->getMessage()]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'type' => get_class($e), 'message' => $e->getMessage()]);
    exit(0);
}
