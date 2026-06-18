<?php
// negotiation.php - Trust & Negotiation Engine
session_start();
include "DBConn.php";

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$seller_id = isset($_GET['seller_id']) ? intval($_GET['seller_id']) : 0;
$negotiation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = '';
$error = '';
$success = '';

$product = null; 

if ($product_id > 0) {
    $product_query = $connect->prepare("
        SELECT c.*, u.user_id as seller_user_id, u.name as seller_name, u.surname as seller_surname, u.username as seller_username
        FROM clothes c
        LEFT JOIN users u ON c.seller_id = u.user_id
        WHERE c.product_id = ?
    ");
    $product_query->bind_param("i", $product_id);
    $product_query->execute();
    $result = $product_query->get_result();
    $product = $result->fetch_assoc();
    $product_query->close();
    
    if ($product) {
        $seller_id = $product['seller_user_id'];
    }
}

// If product not found with join, try without join
if (!$product && $product_id > 0) {
    $product_query2 = $connect->prepare("SELECT * FROM clothes WHERE product_id = ?");
    $product_query2->bind_param("i", $product_id);
    $product_query2->execute();
    $product = $product_query2->get_result()->fetch_assoc();
    $product_query2->close();
    
    if ($product) {
        $seller_id = $product['seller_id'] ?? 0;
        // Get seller name
        if ($seller_id > 0) {
            $seller_query = $connect->prepare("SELECT name, surname, username FROM users WHERE user_id = ?");
            $seller_query->bind_param("i", $seller_id);
            $seller_query->execute();
            $seller = $seller_query->get_result()->fetch_assoc();
            if ($seller) {
                $product['seller_name'] = $seller['name'] ?? '';
                $product['seller_surname'] = $seller['surname'] ?? '';
                $product['seller_username'] = $seller['username'] ?? '';
                $product['seller_user_id'] = $seller_id;
            }
            $seller_query->close();
        }
    }
}

// ============================================
// HANDLE MAKE OFFER (BUYER)
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['make_offer'])) {
    $product_id = intval($_POST['product_id']);
    $seller_id = intval($_POST['seller_id']);
    $offered_price = floatval($_POST['offered_price']);
    $message_text = trim($_POST['message']);
    $expires_days = intval($_POST['expires_days'] ?? 7);
    
    if ($offered_price <= 0) {
        $error = "Please enter a valid offer price.";
    } elseif ($product && $offered_price >= $product['price']) {
        $error = "Offer price must be lower than the listing price of R" . number_format($product['price'], 2);
    } elseif ($user_id == $seller_id) {
        $error = "You cannot negotiate on your own product.";
    } else {
        // Check if negotiation already exists
        $check_stmt = $connect->prepare("
            SELECT negotiation_id FROM negotiations 
            WHERE buyer_id = ? AND seller_id = ? AND status IN ('pending', 'countered')
        ");
        $check_stmt->bind_param("ii", $user_id, $seller_id);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            $error = "You already have a pending negotiation with this seller.";
        } else {
            $expires_at = date('Y-m-d H:i:s', strtotime("+$expires_days days"));
            $insert_stmt = $connect->prepare("
                INSERT INTO negotiations (buyer_id, seller_id, offered_price, message, expires_at) 
                VALUES (?, ?, ?, ?, ?)
            ");
            // FIXED: 5 variables, 5 types (i = integer, i = integer, d = decimal, s = string, s = string)
            $insert_stmt->bind_param("iidss", $user_id, $seller_id, $offered_price, $message_text, $expires_at);
            
            if ($insert_stmt->execute()) {
                $negotiation_id = $insert_stmt->insert_id;
                
                $history_stmt = $connect->prepare("
                    INSERT INTO offer_history (negotiation_id, offered_by, price, message) 
                    VALUES (?, 'buyer', ?, ?)
                ");
                $history_stmt->bind_param("ids", $negotiation_id, $offered_price, $message_text);
                $history_stmt->execute();
                $history_stmt->close();
                
                $success = "✅ Offer sent successfully! The seller will respond within " . $expires_days . " days.";
                header("Location: negotiation.php?id=$negotiation_id");
                exit();
            } else {
                $error = "Error creating negotiation: " . $connect->error;
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['counter_offer'])) {
    $negotiation_id = intval($_POST['negotiation_id']);
    $counter_price = floatval($_POST['counter_price']);
    $message_text = trim($_POST['message']);
    
    $check_owner = $connect->prepare("
        SELECT seller_id, status, buyer_id FROM negotiations WHERE negotiation_id = ?
    ");
    $check_owner->bind_param("i", $negotiation_id);
    $check_owner->execute();
    $result = $check_owner->get_result();
    $neg = $result->fetch_assoc();
    $check_owner->close();
    
    if (!$neg) {
        $error = "Negotiation not found.";
    } elseif ($neg['seller_id'] != $user_id) {
        $error = "You are not authorized to respond to this negotiation.";
    } elseif ($neg['status'] != 'pending' && $neg['status'] != 'countered') {
        $error = "This negotiation is no longer active.";
    } elseif ($counter_price <= 0) {
        $error = "Please enter a valid counter price.";
    } else {
        $update_stmt = $connect->prepare("
            UPDATE negotiations 
            SET counter_price = ?, status = 'countered', message = ? 
            WHERE negotiation_id = ?
        ");
        $update_stmt->bind_param("dsi", $counter_price, $message_text, $negotiation_id);
        
        if ($update_stmt->execute()) {
            $history_stmt = $connect->prepare("
                INSERT INTO offer_history (negotiation_id, offered_by, price, message) 
                VALUES (?, 'seller', ?, ?)
            ");
            $history_stmt->bind_param("ids", $negotiation_id, $counter_price, $message_text);
            $history_stmt->execute();
            $history_stmt->close();
            
            $success = "✅ Counter-offer sent! The buyer will now respond.";
            header("Location: negotiation.php?id=$negotiation_id");
            exit();
        } else {
            $error = "Error sending counter-offer: " . $connect->error;
        }
        $update_stmt->close();
    }
}

if (isset($_GET['accept'])) {
    $negotiation_id = intval($_GET['accept']);
    
    $neg_query = $connect->prepare("
        SELECT n.* FROM negotiations n WHERE n.negotiation_id = ?
    ");
    $neg_query->bind_param("i", $negotiation_id);
    $neg_query->execute();
    $neg_data = $neg_query->get_result()->fetch_assoc();
    $neg_query->close();
    
    if (!$neg_data) {
        $error = "Negotiation not found.";
    } elseif ($neg_data['seller_id'] != $user_id && $neg_data['buyer_id'] != $user_id) {
        $error = "You are not authorized to accept this offer.";
    } else {
        $final_price = $neg_data['counter_price'] ?? $neg_data['offered_price'];
        $buyer_id = $neg_data['buyer_id'];
        $seller_id = $neg_data['seller_id'];
        
        $accept_stmt = $connect->prepare("
            UPDATE negotiations SET status = 'accepted' WHERE negotiation_id = ?
        ");
        $accept_stmt->bind_param("i", $negotiation_id);
        $accept_stmt->execute();
        $accept_stmt->close();
        
        // Create order with escrow
        $addr_query = $connect->prepare("SELECT address FROM users WHERE user_id = ?");
        $addr_query->bind_param("i", $buyer_id);
        $addr_query->execute();
        $addr_result = $addr_query->get_result()->fetch_assoc();
        $addr_query->close();
        
        $order_stmt = $connect->prepare("
            INSERT INTO orders (user_id, seller_id, negotiation_id, total_amount, escrow_amount, escrow_status, shipping_address, order_status, payment_status) 
            VALUES (?, ?, ?, ?, ?, 'held', ?, 'pending', 'pending')
        ");
        $order_stmt->bind_param("iiidds", 
            $buyer_id, 
            $seller_id, 
            $negotiation_id, 
            $final_price, 
            $final_price,
            $addr_result['address'] ?? ''
        );
        
        if ($order_stmt->execute()) {
            $order_id = $order_stmt->insert_id;
            
            // Update negotiation with order_id
            $update_neg = $connect->prepare("UPDATE negotiations SET order_id = ? WHERE negotiation_id = ?");
            $update_neg->bind_param("ii", $order_id, $negotiation_id);
            $update_neg->execute();
            $update_neg->close();
            
            $success = "✅ Offer accepted! Order #$order_id has been created.";
            header("Location: customer_order.php?order_id=$order_id&negotiation=accepted");
            exit();
        } else {
            $error = "Error creating order: " . $connect->error;
        }
        $order_stmt->close();
    }
}

if (isset($_GET['reject'])) {
    $negotiation_id = intval($_GET['reject']);
    
    $reject_stmt = $connect->prepare("
        UPDATE negotiations SET status = 'rejected' WHERE negotiation_id = ? AND seller_id = ?
    ");
    $reject_stmt->bind_param("ii", $negotiation_id, $user_id);
    $reject_stmt->execute();
    $reject_stmt->close();
    
    $success = "✗ Offer rejected.";
    header("Location: negotiation.php");
    exit();
}

$negotiation = null;
if ($negotiation_id > 0) {
    $neg_detail_query = $connect->prepare("
        SELECT n.*, 
               u1.name as buyer_name, u1.surname as buyer_surname, u1.email as buyer_email,
               u2.name as seller_name, u2.surname as seller_surname, u2.email as seller_email
        FROM negotiations n
        JOIN users u1 ON n.buyer_id = u1.user_id
        JOIN users u2 ON n.seller_id = u2.user_id
        WHERE n.negotiation_id = ?
    ");
    $neg_detail_query->bind_param("i", $negotiation_id);
    $neg_detail_query->execute();
    $negotiation = $neg_detail_query->get_result()->fetch_assoc();
    $neg_detail_query->close();
    
    if ($negotiation) {
        $history_query = $connect->prepare("
            SELECT * FROM offer_history WHERE negotiation_id = ? ORDER BY created_at ASC
        ");
        $history_query->bind_param("i", $negotiation_id);
        $history_query->execute();
        $history = $history_query->get_result()->fetch_all(MYSQLI_ASSOC);
        $history_query->close();
    }
}

$negotiations_query = $connect->prepare("
    SELECT n.*, 
           u1.name as buyer_name, u1.surname as buyer_surname,
           u2.name as seller_name, u2.surname as seller_surname,
           oh.price as last_offer_price, oh.message as last_message,
           oh.offered_by as last_offered_by
    FROM negotiations n
    JOIN users u1 ON n.buyer_id = u1.user_id
    JOIN users u2 ON n.seller_id = u2.user_id
    LEFT JOIN offer_history oh ON oh.negotiation_id = n.negotiation_id AND oh.history_id = (
        SELECT MAX(history_id) FROM offer_history WHERE negotiation_id = n.negotiation_id
    )
    WHERE n.buyer_id = ? OR n.seller_id = ?
    ORDER BY n.created_at DESC
");
$negotiations_query->bind_param("ii", $user_id, $user_id);
$negotiations_query->execute();
$negotiations = $negotiations_query->get_result();
$negotiations_query->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Negotiation Hub - Pastimes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        
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
        .header-links a {
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 14px;
        }
        .header-links a:hover { color: white; }
        .nav-btn {
            background: rgba(255,255,255,0.15);
            padding: 8px 16px;
            border-radius: 8px;
        }
        .nav-btn:hover { background: rgba(255,255,255,0.25); }
        
        .message {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .section h2 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .product-card {
            display: flex;
            gap: 25px;
            align-items: center;
            flex-wrap: wrap;
        }
        .product-card .info { flex: 1; }
        .product-card .info h3 { font-size: 22px; color: #333; }
        .product-card .info .price { font-size: 28px; font-weight: bold; color: #667eea; }
        .product-card .info .seller { color: #666; margin-top: 5px; }
        
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.3); }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #219a52; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; }
        .btn-outline { background: white; color: #667eea; border: 2px solid #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        .btn-sm { padding: 6px 14px; font-size: 13px; }
        
        .offer-form { max-width: 450px; }
        .offer-form .form-group { margin-bottom: 15px; }
        .offer-form label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
            font-size: 14px;
        }
        .offer-form input, .offer-form textarea, .offer-form select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border 0.2s;
        }
        .offer-form input:focus, .offer-form textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        .offer-form textarea { resize: vertical; min-height: 80px; }
        
        .neg-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 12px;
            border-left: 4px solid #667eea;
            transition: all 0.2s;
        }
        .neg-item:hover { background: #f0f2f5; }
        .neg-item .neg-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .neg-item .neg-price { font-size: 22px; font-weight: bold; color: #333; }
        .neg-item .neg-status {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .neg-status.pending { background: #f39c12; color: white; }
        .neg-status.accepted { background: #27ae60; color: white; }
        .neg-status.countered { background: #3498db; color: white; }
        .neg-status.rejected { background: #e74c3c; color: white; }
        .neg-status.expired { background: #95a5a6; color: white; }
        .neg-item .neg-message {
            margin-top: 10px;
            padding: 10px 14px;
            background: white;
            border-radius: 8px;
            color: #555;
        }
        .neg-item .neg-meta {
            font-size: 12px;
            color: #999;
            margin-top: 8px;
        }
        .neg-actions { margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap; }
        
        .counter-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 12px;
        }
        .counter-form input { width: 140px; padding: 8px 10px; border: 2px solid #e0e0e0; border-radius: 6px; }
        .counter-form input:focus { outline: none; border-color: #667eea; }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .empty-state i { font-size: 48px; color: #ddd; margin-bottom: 15px; }
        
        @media (max-width: 768px) {
            .header { padding: 12px 16px; }
            .product-card { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-handshake"></i> Negotiation Hub</h1>
            <div class="header-links">
                <a href="products.php" class="nav-btn"><i class="fas fa-store"></i> Shop</a>
                <a href="style_feed.php" class="nav-btn"><i class="fas fa-images"></i> Feed</a>
                <a href="escrow_dashboard.php" class="nav-btn"><i class="fas fa-shield-alt"></i> Escrow</a>
                <a href="cart.php" class="nav-btn"><i class="fas fa-shopping-cart"></i> Cart</a>
                <a href="dashboard.php" class="nav-btn"><i class="fas fa-user"></i> Profile</a>
                <a href="login.php?logout=1" style="color: #ff6b6b;">Logout</a>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="message success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <!-- Single Negotiation View -->
        <?php if ($negotiation): ?>
            <?php 
                $is_buyer = ($negotiation['buyer_id'] == $user_id);
                $is_seller = ($negotiation['seller_id'] == $user_id);
                $can_respond = ($is_seller && ($negotiation['status'] == 'pending' || $negotiation['status'] == 'countered'));
            ?>
            <div class="section">
                <h2>💬 Negotiation #<?php echo $negotiation['negotiation_id']; ?></h2>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;">
                    <div>
                        <div style="color: #999; font-size: 13px;">Buyer</div>
                        <div style="font-weight: 600; font-size: 18px;">
                            <?php echo htmlspecialchars($negotiation['buyer_name'] . ' ' . $negotiation['buyer_surname']); ?>
                        </div>
                    </div>
                    <div>
                        <div style="color: #999; font-size: 13px;">Seller</div>
                        <div style="font-weight: 600; font-size: 18px;">
                            <?php echo htmlspecialchars($negotiation['seller_name'] . ' ' . $negotiation['seller_surname']); ?>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 30px; flex-wrap: wrap; padding: 15px; background: #f8f9fa; border-radius: 10px; margin-bottom: 20px;">
                    <div>
                        <div style="color: #999; font-size: 12px;">Status</div>
                        <span class="neg-status <?php echo $negotiation['status']; ?>"><?php echo ucfirst($negotiation['status']); ?></span>
                    </div>
                    <div>
                        <div style="color: #999; font-size: 12px;">Offered Price</div>
                        <div style="font-size: 24px; font-weight: bold;">R<?php echo number_format($negotiation['offered_price'], 2); ?></div>
                    </div>
                    <?php if ($negotiation['counter_price']): ?>
                    <div>
                        <div style="color: #999; font-size: 12px;">Counter Price</div>
                        <div style="font-size: 24px; font-weight: bold; color: #3498db;">R<?php echo number_format($negotiation['counter_price'], 2); ?></div>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div style="color: #999; font-size: 12px;">Expires</div>
                        <div><?php echo date('M d, Y', strtotime($negotiation['expires_at'])); ?></div>
                    </div>
                </div>
                
                <?php if ($negotiation['message']): ?>
                <div style="padding: 15px; background: #f0f4ff; border-radius: 10px; margin-bottom: 20px;">
                    <div style="color: #999; font-size: 12px;">Message</div>
                    <div style="color: #333;"><?php echo htmlspecialchars($negotiation['message']); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($history)): ?>
                <div style="margin-bottom: 20px;">
                    <h4 style="color: #555; margin-bottom: 10px;">Offer History</h4>
                    <?php foreach ($history as $h): ?>
                    <div style="padding: 8px 12px; background: white; border-radius: 6px; margin-bottom: 5px; border-left: 3px solid <?php echo $h['offered_by'] == 'buyer' ? '#667eea' : '#e74c3c'; ?>;">
                        <strong><?php echo ucfirst($h['offered_by']); ?></strong> offered 
                        <strong>R<?php echo number_format($h['price'], 2); ?></strong>
                        <?php if ($h['message']): ?>
                            <span style="color: #666;">- <?php echo htmlspecialchars($h['message']); ?></span>
                        <?php endif; ?>
                        <span style="color: #999; font-size: 12px; margin-left: 10px;"><?php echo date('M d, H:i', strtotime($h['created_at'])); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($can_respond): ?>
                <div style="padding-top: 15px; border-top: 2px solid #eee;">
                    <h4 style="color: #555; margin-bottom: 15px;">Respond to Offer</h4>
                    <div class="counter-form">
                        <form method="POST" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                            <input type="hidden" name="negotiation_id" value="<?php echo $negotiation['negotiation_id']; ?>">
                            <input type="number" name="counter_price" step="0.01" min="0.01" 
                                   placeholder="Your counter price" required 
                                   value="<?php echo $negotiation['offered_price'] * 0.9; ?>">
                            <input type="text" name="message" placeholder="Message (optional)">
                            <button type="submit" name="counter_offer" class="btn btn-warning btn-sm">
                                <i class="fas fa-gavel"></i> Counter Offer
                            </button>
                            <a href="?accept=<?php echo $negotiation['negotiation_id']; ?>" class="btn btn-success btn-sm" 
                               onclick="return confirm('Accept this offer?')">
                                <i class="fas fa-check"></i> Accept
                            </a>
                            <a href="?reject=<?php echo $negotiation['negotiation_id']; ?>" class="btn btn-danger btn-sm"
                               onclick="return confirm('Reject this offer?')">
                                <i class="fas fa-times"></i> Reject
                            </a>
                        </form>
                    </div>
                </div>
                <?php elseif ($negotiation['status'] == 'accepted'): ?>
                <div style="padding-top: 15px; border-top: 2px solid #eee;">
                    <p style="color: #27ae60;">✅ This offer has been accepted!</p>
                    <?php if ($negotiation['order_id']): ?>
                        <a href="customer_order.php?order_id=<?php echo $negotiation['order_id']; ?>" class="btn btn-primary">
                            <i class="fas fa-box"></i> View Order
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: 15px;">
                    <a href="negotiation.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to All Negotiations</a>
                </div>
            </div>
        
        <!-- Make Offer Form (Product View) -->
        <?php elseif (isset($product) && $product && isset($product['product_id'])): ?>
            <div class="section">
                <h2>📦 Make an Offer</h2>
                <div class="product-card">
                    <div class="info">
                        <h3><?php echo htmlspecialchars($product['product_name'] ?? 'Product'); ?></h3>
                        <div class="price">R<?php echo number_format($product['price'] ?? 0, 2); ?></div>
                        <div class="seller">👤 Seller: <?php echo htmlspecialchars(($product['seller_name'] ?? 'Unknown') . ' ' . ($product['seller_surname'] ?? '')); ?></div>
                        <?php if (isset($product['description']) && $product['description']): ?>
                            <div style="color: #666; margin-top: 8px;"><?php echo htmlspecialchars($product['description']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php 
                    $seller_user_id = $product['seller_user_id'] ?? $product['seller_id'] ?? 0;
                    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $seller_user_id): 
                    ?>
                        <div>
                            <button onclick="document.getElementById('offerForm').style.display='block'" class="btn btn-primary">
                                <i class="fas fa-gavel"></i> Make Offer
                            </button>
                        </div>
                    <?php else: ?>
                        <div style="color: #f39c12;">
                            <?php if (!isset($_SESSION['user_id'])): ?>
                                Please <a href="login.php">login</a> to make an offer
                            <?php else: ?>
                                You cannot negotiate on your own product
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div id="offerForm" class="section" style="display: <?php echo isset($_POST['make_offer']) ? 'block' : 'none'; ?>">
                <h2>💬 Submit Your Offer</h2>
                <form method="POST" class="offer-form">
                    <input type="hidden" name="product_id" value="<?php echo $product['product_id'] ?? ''; ?>">
                    <input type="hidden" name="seller_id" value="<?php echo $product['seller_user_id'] ?? $product['seller_id'] ?? ''; ?>">
                    
                    <div class="form-group">
                        <label>Your Offer Price (R) <span style="color: #e74c3c;">*</span></label>
                        <input type="number" name="offered_price" step="0.01" min="0.01" 
                               value="<?php echo isset($product['price']) ? number_format($product['price'] * 0.85, 2) : ''; ?>" required>
                        <?php if (isset($product['price'])): ?>
                        <div style="font-size: 12px; color: #999; margin-top: 4px;">
                            Suggested: 85% of listing price (R<?php echo number_format($product['price'] * 0.85, 2); ?>)
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Message to Seller</label>
                        <textarea name="message" placeholder="Why should they accept your offer?"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Offer Expires</label>
                        <select name="expires_days">
                            <option value="3">3 days</option>
                            <option value="7" selected>7 days</option>
                            <option value="14">14 days</option>
                            <option value="30">30 days</option>
                        </select>
                    </div>
                    
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="submit" name="make_offer" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Send Offer
                        </button>
                        <button type="button" onclick="document.getElementById('offerForm').style.display='none'" class="btn btn-outline">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        
        <!-- All Negotiations List -->
        <?php else: ?>
            <div class="section">
                <h2><i class="fas fa-list"></i> Your Negotiations</h2>
                
                <?php if ($negotiations && $negotiations->num_rows > 0): ?>
                    <?php while ($neg = $negotiations->fetch_assoc()): 
                        $is_buyer = ($neg['buyer_id'] == $user_id);
                        $status_class = $neg['status'];
                    ?>
                    <div class="neg-item">
                        <div class="neg-header">
                            <div>
                                <div style="font-weight: 600; font-size: 16px;">
                                    <?php echo $is_buyer ? '← ' . ($neg['seller_name'] ?? 'Unknown') . ' ' . ($neg['seller_surname'] ?? '') : ($neg['buyer_name'] ?? 'Unknown') . ' ' . ($neg['buyer_surname'] ?? '') . ' →'; ?>
                                </div>
                                <div class="neg-price">R<?php echo number_format($neg['offered_price'] ?? 0, 2); ?></div>
                            </div>
                            <div>
                                <span class="neg-status <?php echo $status_class; ?>"><?php echo ucfirst($status_class ?? 'pending'); ?></span>
                                <?php if (isset($neg['counter_price']) && $neg['counter_price']): ?>
                                    <span style="font-size: 13px; color: #3498db; margin-left: 8px;">
                                        Counter: R<?php echo number_format($neg['counter_price'], 2); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (isset($neg['last_message']) && $neg['last_message']): ?>
                            <div class="neg-message">💬 "<?php echo htmlspecialchars($neg['last_message']); ?>"</div>
                        <?php endif; ?>
                        
                        <div class="neg-meta">
                            <?php echo date('M d, Y H:i', strtotime($neg['created_at'] ?? 'now')); ?> &middot; 
                            <?php echo $is_buyer ? 'You are the buyer' : 'You are the seller'; ?>
                            <?php if (isset($neg['expires_at']) && $neg['expires_at']): ?>
                                &middot; Expires: <?php echo date('M d, Y', strtotime($neg['expires_at'])); ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="neg-actions">
                            <a href="negotiation.php?id=<?php echo $neg['negotiation_id']; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                            <?php if (($neg['status'] ?? '') == 'pending' && !$is_buyer): ?>
                                <a href="negotiation.php?accept=<?php echo $neg['negotiation_id']; ?>" class="btn btn-success btn-sm" 
                                   onclick="return confirm('Accept this offer?')">
                                    <i class="fas fa-check"></i> Accept
                                </a>
                            <?php endif; ?>
                            <?php if (($neg['status'] ?? '') == 'accepted' && isset($neg['order_id']) && $neg['order_id']): ?>
                                <a href="customer_order.php?order_id=<?php echo $neg['order_id']; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-box"></i> View Order
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-handshake"></i>
                        <h3>No negotiations yet</h3>
                        <p>Start by making an offer on a product you're interested in!</p>
                        <a href="products.php" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-store"></i> Browse Products
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px;">
            <a href="products.php" class="btn btn-outline"><i class="fas fa-store"></i> Browse Products</a>
            <a href="escrow_dashboard.php" class="btn btn-primary"><i class="fas fa-shield-alt"></i> Escrow Dashboard</a>
        </div>
    </div>
</body>
</html>