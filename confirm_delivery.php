<?php
// confirm_delivery.php - Delivery Confirmation API (AJAX)
session_start();
header('Content-Type: application/json');
include "DBConn.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit();
}

$user_id = $_SESSION['user_id'];
$order_id = intval($_POST['order_id'] ?? 0);

if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Order ID required']);
    exit();
}

$verify_stmt = $connect->prepare("
    SELECT user_id, escrow_status FROM orders WHERE order_id = ? AND escrow_status = 'held'
");
$verify_stmt->bind_param("i", $order_id);
$verify_stmt->execute();
$result = $verify_stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode(['success' => false, 'message' => 'Order not found or escrow not held']);
    exit();
}

$order = $result->fetch_assoc();
$verify_stmt->close();

if ($order['user_id'] != $user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$update_stmt = $connect->prepare("
    UPDATE orders SET 
        buyer_confirmed = TRUE, 
        delivery_confirmed_at = NOW(),
        escrow_status = 'released',
        order_status = 'delivered'
    WHERE order_id = ?
");
$update_stmt->bind_param("i", $order_id);

if ($update_stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Delivery confirmed! Funds released to seller.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error confirming delivery']);
}
$update_stmt->close();
?>