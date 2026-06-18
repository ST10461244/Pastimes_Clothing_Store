<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
include "DBConn.php";

$user_id = $_SESSION['user_id'];

// Ensure tables exist
$connect->query("CREATE TABLE IF NOT EXISTS orders (
    order_id int auto_increment primary key, user_id int not null,
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, total_amount decimal(10,2),
    shipping_address text, tracking_number varchar(100),
    order_status ENUM('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    payment_status ENUM('pending','paid','failed') DEFAULT 'pending',
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE)");

$connect->query("CREATE TABLE IF NOT EXISTS order_lines (
    line_id int auto_increment primary key, order_id int not null,
    product_id int not null, product_name varchar(200),
    quantity int not null, unit_price decimal(10,2) not null,
    line_total decimal(10,2) not null,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE)");

// Fetch all orders for this user
$stmt = $connect->prepare("
    SELECT o.order_id, o.tracking_number, o.order_date, o.total_amount,
           o.order_status, o.payment_status, o.shipping_address
    FROM orders o
    WHERE o.user_id = ?
    ORDER BY o.order_date DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch lines for each order
$all_lines = [];
foreach ($orders as $order) {
    $ls = $connect->prepare("SELECT * FROM order_lines WHERE order_id = ?");
    $ls->bind_param("i", $order['order_id']);
    $ls->execute();
    $all_lines[$order['order_id']] = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();
}

$grand_total = array_sum(array_column($orders, 'total_amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Order History – Pastimes</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#f4f4f8;color:#333}
.header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.header h1{font-size:22px}
.header-links{display:flex;gap:14px;align-items:center}
.header-links a{color:rgba(255,255,255,.9);text-decoration:none;font-size:14px}
.header-links a:hover{color:#fff}
.shop-btn{background:#fff;color:#764ba2;padding:7px 16px;border-radius:20px;font-weight:700!important}
.page{max-width:960px;margin:30px auto;padding:0 20px 60px}
.page-title{font-size:22px;font-weight:700;margin-bottom:20px;color:#333}

.empty-state{text-align:center;padding:60px 20px;background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07)}
.empty-state .icon{font-size:56px;margin-bottom:14px}
.empty-state h2{font-size:20px;margin-bottom:8px}
.empty-state p{color:#888;margin-bottom:20px}
.shop-link{display:inline-block;padding:11px 26px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:10px;font-weight:700;text-decoration:none}

.order-card{background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);margin-bottom:24px;overflow:hidden}
.order-header{background:#f8f8ff;padding:14px 20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;border-bottom:1px solid #eee}
.order-ref{font-size:16px;font-weight:700;color:#667eea;flex:1;min-width:160px}
.order-date{font-size:13px;color:#888}
.order-addr{font-size:13px;color:#555;flex:1}
.status-badge{padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;text-transform:uppercase}
.status-pending{background:#fff3e0;color:#e65100}
.status-paid{background:#e8f5e9;color:#2e7d32}
.status-failed{background:#fdecea;color:#c62828}
.status-delivered{background:#e8f5e9;color:#1b5e20}
.status-shipped{background:#e3f2fd;color:#0277bd}

.lines-table{width:100%;border-collapse:collapse}
.lines-table th{background:#667eea;color:#fff;padding:10px 16px;text-align:left;font-size:13px;text-transform:uppercase;letter-spacing:.4px}
.lines-table td{padding:10px 16px;border-bottom:1px solid #f0f0f0;font-size:14px}
.lines-table tr:last-child td{border-bottom:none}

.order-total-row{padding:12px 20px;display:flex;justify-content:flex-end;border-top:2px solid #f0f0f0;background:#fafafa}
.order-total-row span{font-size:15px;font-weight:700;color:#667eea}

.grand-total-card{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:14px;padding:24px 30px;display:flex;justify-content:space-between;align-items:center;margin-top:10px}
.grand-total-card .label{font-size:16px;opacity:.9}
.grand-total-card .amount{font-size:28px;font-weight:700}

@media(max-width:600px){
  .header{padding:12px 16px}
  .page{padding:0 12px 40px}
  .lines-table th:nth-child(3),.lines-table td:nth-child(3){display:none}
}
</style>
</head>
<body>

<div class="header">
  <h1>&#128203; Order History</h1>
  <div class="header-links">
    <a href="products.php" class="shop-btn">&#8592; Shop</a>
    <a href="customer_order.php">&#11088; Rate Items</a>
    <a href="cart.php">&#128722; Cart</a>
    <a href="login.php?logout=1">Logout</a>
  </div>
</div>

<div class="page">
  <h2 class="page-title">My Purchases</h2>

  <?php if (empty($orders)): ?>
  <div class="empty-state">
    <div class="icon">&#128203;</div>
    <h2>No orders yet</h2>
    <p>Your completed purchases will appear here.</p>
    <a href="products.php" class="shop-link">Start Shopping</a>
  </div>

  <?php else: ?>

    <?php foreach ($orders as $order):
      $lines = $all_lines[$order['order_id']] ?? [];
    ?>
    <div class="order-card">
      <div class="order-header">
        <div class="order-ref">&#35; <?php echo htmlspecialchars($order['tracking_number']); ?></div>
        <div class="order-date">&#128197; <?php echo date('d M Y, H:i', strtotime($order['order_date'])); ?></div>
        <div class="order-addr">&#128205; <?php echo htmlspecialchars($order['shipping_address'] ?: '—'); ?></div>
        <span class="status-badge status-<?php echo $order['payment_status']; ?>">
          <?php echo ucfirst($order['payment_status']); ?>
        </span>
        <span class="status-badge status-<?php echo $order['order_status']; ?>">
          <?php echo ucfirst($order['order_status']); ?>
        </span>
      </div>

      <table class="lines-table">
        <thead>
          <tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr>
        </thead>
        <tbody>
          <?php foreach ($lines as $line): ?>
          <tr>
            <td><?php echo htmlspecialchars($line['product_name']); ?></td>
            <td><?php echo $line['quantity']; ?></td>
            <td>R<?php echo number_format($line['unit_price'],2); ?></td>
            <td>R<?php echo number_format($line['line_total'],2); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="order-total-row">
        <span>Order Total: R<?php echo number_format($order['total_amount'],2); ?></span>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="grand-total-card">
      <span class="label">Grand Total of All Purchases</span>
      <span class="amount">R<?php echo number_format($grand_total,2); ?></span>
    </div>

  <?php endif; ?>
</div>
</body>
</html>