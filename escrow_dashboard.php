<?php
// escrow_dashboard.php - Escrow Management Dashboard
session_start();
include "DBConn.php";

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle confirm delivery (buyer confirms receipt)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_delivery'])) {
    $order_id = intval($_POST['order_id']);
    
    $verify_stmt = $connect->prepare("
        SELECT user_id, escrow_status FROM orders WHERE order_id = ? AND escrow_status = 'held'
    ");
    $verify_stmt->bind_param("i", $order_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows > 0) {
        $order = $verify_result->fetch_assoc();
        if ($order['user_id'] == $user_id) {
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
                $message = "✅ Delivery confirmed! Funds have been released to the seller.";
                $message_type = "success";
            } else {
                $message = "Error confirming delivery: " . $connect->error;
                $message_type = "error";
            }
            $update_stmt->close();
        } else {
            $message = "You are not authorized to confirm this delivery.";
            $message_type = "error";
        }
    } else {
        $message = "Order not found or escrow is not held.";
        $message_type = "error";
    }
    $verify_stmt->close();
}

// Handle refund request
if (isset($_GET['refund'])) {
    $order_id = intval($_GET['refund']);
    
    $refund_stmt = $connect->prepare("
        UPDATE orders SET escrow_status = 'refunded', order_status = 'cancelled' 
        WHERE order_id = ? AND user_id = ? AND escrow_status IN ('held', 'pending')
    ");
    $refund_stmt->bind_param("ii", $order_id, $user_id);
    
    if ($refund_stmt->execute() && $refund_stmt->affected_rows > 0) {
        $message = "🔄 Refund requested. Funds will be returned to your account.";
        $message_type = "success";
    } else {
        $message = "Could not process refund. Order may already be completed.";
        $message_type = "error";
    }
    $refund_stmt->close();
}

// Get orders with escrow
$orders_query = $connect->prepare("
    SELECT o.*, 
           u.name as buyer_name, u.surname as buyer_surname,
           s.name as seller_name, s.surname as seller_surname,
           s.user_id as seller_user_id
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.user_id
    LEFT JOIN users s ON o.seller_id = s.user_id
    WHERE o.user_id = ? OR o.seller_id = ?
    ORDER BY o.order_date DESC
");
$orders_query->bind_param("ii", $user_id, $user_id);
$orders_query->execute();
$orders = $orders_query->get_result();
$orders_query->close();

// Stats
$stats_query = $connect->prepare("
    SELECT 
        COUNT(CASE WHEN escrow_status = 'held' THEN 1 END) as held_count,
        COUNT(CASE WHEN escrow_status = 'released' THEN 1 END) as released_count,
        COUNT(CASE WHEN escrow_status = 'refunded' THEN 1 END) as refunded_count,
        SUM(CASE WHEN escrow_status = 'held' THEN escrow_amount ELSE 0 END) as held_total
    FROM orders 
    WHERE user_id = ? OR seller_id = ?
");
$stats_query->bind_param("ii", $user_id, $user_id);
$stats_query->execute();
$stats = $stats_query->get_result()->fetch_assoc();
$stats_query->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escrow Dashboard - Pastimes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            padding: 16px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .header h1 { font-size: 24px; }
        .header-links { display: flex; gap: 14px; align-items: center; }
        .header-links a { color: rgba(255,255,255,0.85); text-decoration: none; font-size: 14px; }
        .header-links a:hover { color: white; }
        .nav-btn { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 8px; }
        .nav-btn:hover { background: rgba(255,255,255,0.25); }
        
        .message { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .section { background: white; border-radius: 15px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section h2 { color: #333; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card { background: #f8f9fa; border-radius: 10px; padding: 18px 20px; text-align: center; }
        .stat-card .stat-number { font-size: 28px; font-weight: bold; color: #333; }
        .stat-card .stat-label { font-size: 13px; color: #999; margin-top: 4px; }
        .stat-card.held .stat-number { color: #f39c12; }
        .stat-card.released .stat-number { color: #27ae60; }
        .stat-card.refunded .stat-number { color: #e74c3c; }
        .stat-card.total .stat-number { color: #667eea; }
        
        .escrow-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 15px;
            border-left: 4px solid #667eea;
            transition: all 0.2s;
        }
        .escrow-card:hover { background: #f0f2f5; }
        .escrow-card.held { border-left-color: #f39c12; }
        .escrow-card.released { border-left-color: #27ae60; }
        .escrow-card.refunded { border-left-color: #e74c3c; }
        
        .escrow-card .escrow-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .escrow-card .escrow-amount { font-size: 24px; font-weight: bold; color: #333; }
        .escrow-card .escrow-status { padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .escrow-status.held { background: #f39c12; color: white; }
        .escrow-status.released { background: #27ae60; color: white; }
        .escrow-status.refunded { background: #e74c3c; color: white; }
        .escrow-status.pending { background: #95a5a6; color: white; }
        .escrow-card .escrow-details { margin-top: 10px; color: #666; font-size: 14px; line-height: 1.8; }
        .escrow-card .escrow-details i { width: 20px; }
        
        .btn {
            padding: 8px 18px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #219a52; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-outline { background: white; color: #667eea; border: 2px solid #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        .btn-sm { padding: 6px 14px; font-size: 12px; }
        
        .empty-state { text-align: center; padding: 40px 20px; color: #999; }
        .empty-state i { font-size: 48px; color: #ddd; margin-bottom: 15px; }
        
        @media (max-width: 768px) { .header { padding: 12px 16px; } .stats-grid { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-shield-alt"></i> Escrow Dashboard</h1>
            <div class="header-links">
    <a href="products.php" class="nav-btn"><i class="fas fa-store"></i> Shop</a>
    <a href="style_feed.php" class="nav-btn"><i class="fas fa-images"></i> Feed</a>
    <a href="negotiation.php" class="nav-btn"><i class="fas fa-handshake"></i> Negotiate</a>
    <a href="cart.php" class="nav-btn"><i class="fas fa-shopping-cart"></i> Cart</a>
    <a href="dashboard.php" class="nav-btn"><i class="fas fa-user"></i> Profile</a>
    <a href="login.php?logout=1" style="color: #ff6b6b;">Logout</a>
</div>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <div class="section">
            <h2><i class="fas fa-chart-bar"></i> Escrow Overview</h2>
            <div class="stats-grid">
                <div class="stat-card held">
                    <div class="stat-number"><?php echo $stats['held_count'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-clock"></i> Held in Escrow</div>
                </div>
                <div class="stat-card released">
                    <div class="stat-number"><?php echo $stats['released_count'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle"></i> Released</div>
                </div>
                <div class="stat-card refunded">
                    <div class="stat-number"><?php echo $stats['refunded_count'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-undo"></i> Refunded</div>
                </div>
                <div class="stat-card total">
                    <div class="stat-number">R<?php echo number_format($stats['held_total'] ?? 0, 2); ?></div>
                    <div class="stat-label"><i class="fas fa-lock"></i> Total Held</div>
                </div>
            </div>
        </div>
        
        <div class="section">
            <h2><i class="fas fa-list"></i> Escrow Transactions</h2>
            
            <?php if ($orders->num_rows > 0): ?>
                <?php while ($order = $orders->fetch_assoc()): 
                    $is_buyer = ($order['user_id'] == $user_id);
                    $status_class = $order['escrow_status'] ?? 'pending';
                ?>
                <div class="escrow-card <?php echo $status_class; ?>">
                    <div class="escrow-header">
                        <div>
                            <div style="font-weight: 600; font-size: 16px;">
                                Order #<?php echo $order['order_id']; ?>
                                <?php if ($order['tracking_number']): ?>
                                    <span style="font-size: 12px; color: #999;">(<?php echo htmlspecialchars($order['tracking_number']); ?>)</span>
                                <?php endif; ?>
                            </div>
                            <div class="escrow-amount">R<?php echo number_format($order['escrow_amount'] ?? $order['total_amount'], 2); ?></div>
                        </div>
                        <div>
                            <span class="escrow-status <?php echo $status_class; ?>"><?php echo ucfirst($status_class); ?></span>
                        </div>
                    </div>
                    
                    <div class="escrow-details">
                        <div><i class="fas fa-user"></i> <?php echo $is_buyer ? 'You are the buyer' : 'You are the seller'; ?></div>
                        <div><i class="fas fa-user"></i> <?php echo $is_buyer ? 'Seller: ' . ($order['seller_name'] ?? 'N/A') . ' ' . ($order['seller_surname'] ?? '') : 'Buyer: ' . ($order['buyer_name'] ?? 'N/A') . ' ' . ($order['buyer_surname'] ?? ''); ?></div>
                        <div><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y H:i', strtotime($order['order_date'])); ?></div>
                    </div>
                    
                    <?php if ($is_buyer && $status_class == 'held' && $order['order_status'] == 'shipped'): ?>
                        <div style="margin-top: 15px;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                <button type="submit" name="confirm_delivery" class="btn btn-success" 
                                        onclick="return confirm('Confirm you have received the item?')">
                                    <i class="fas fa-check"></i> Confirm Delivery & Release Funds
                                </button>
                            </form>
                        </div>
                    <?php elseif ($is_buyer && $status_class == 'held'): ?>
                        <div style="margin-top: 15px;">
                            <a href="?refund=<?php echo $order['order_id']; ?>" class="btn btn-danger btn-sm" 
                               onclick="return confirm('Request a refund?')">
                                <i class="fas fa-undo"></i> Request Refund
                            </a>
                        </div>
                    <?php elseif (!$is_buyer && $status_class == 'held'): ?>
                        <div style="margin-top: 15px; color: #f39c12;">
                            <i class="fas fa-clock"></i> Waiting for buyer to confirm delivery...
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 10px;">
                        <a href="customer_order.php?order_id=<?php echo $order['order_id']; ?>" class="btn btn-outline btn-sm">
                            <i class="fas fa-eye"></i> View Order
                        </a>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-shield-alt"></i>
                    <h3>No Escrow Transactions</h3>
                    <p>Start shopping to create your first escrow transaction!</p>
                    <a href="products.php" class="btn btn-primary" style="margin-top: 15px;">
                        <i class="fas fa-store"></i> Start Shopping
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px;">
            <a href="negotiation.php" class="btn btn-primary"><i class="fas fa-handshake"></i> Negotiations</a>
            <a href="products.php" class="btn btn-outline"><i class="fas fa-store"></i> Browse Products</a>
        </div>
    </div>
</body>
</html>