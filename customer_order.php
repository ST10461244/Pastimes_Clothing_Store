<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
include "DBConn.php";

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Ensure ratings table exists (in case alter_database.php hasn't been run yet)
$ratings_check = $connect->query("SHOW TABLES LIKE 'ratings'");
if (!$ratings_check || $ratings_check->num_rows === 0) {
    $connect->query("CREATE TABLE IF NOT EXISTS ratings (
        rating_id INT AUTO_INCREMENT PRIMARY KEY,
        line_id INT NOT NULL,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        user_id INT NOT NULL,
        seller_id INT DEFAULT NULL,
        rating TINYINT NOT NULL,
        comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_line_rating (line_id),
        FOREIGN KEY (line_id) REFERENCES order_lines(line_id) ON DELETE CASCADE,
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES clothes(product_id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (seller_id) REFERENCES users(user_id) ON DELETE SET NULL
    )");
}

// Handle rating submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_rating'])) {
    $line_id = intval($_POST['line_id']);
    $rating  = intval($_POST['rating']);
    $comment = trim($_POST['comment'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $message = "Please select a rating between 1 and 5 stars.";
        $message_type = "error";
    } else {
        // Verify this line item actually belongs to an order placed by this user,
        // and grab the product_id / order_id / seller_id needed to store the rating
        $verify_stmt = $connect->prepare("
            SELECT ol.line_id, ol.order_id, ol.product_id, c.seller_id
            FROM order_lines ol
            JOIN orders o ON ol.order_id = o.order_id
            LEFT JOIN clothes c ON ol.product_id = c.product_id
            WHERE ol.line_id = ? AND o.user_id = ?");
        $verify_stmt->bind_param("ii", $line_id, $user_id);
        $verify_stmt->execute();
        $line_data = $verify_stmt->get_result()->fetch_assoc();
        $verify_stmt->close();

        if (!$line_data) {
            $message = "That item couldn't be found in your orders.";
            $message_type = "error";
        } else {
            $order_id   = $line_data['order_id'];
            $product_id = $line_data['product_id'];
            $seller_id  = $line_data['seller_id']; // may be NULL for admin-listed items

            // One rating per line item: insert, or update if they're changing their mind
            $rate_stmt = $connect->prepare("
                INSERT INTO ratings (line_id, order_id, product_id, user_id, seller_id, rating, comment)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), created_at = CURRENT_TIMESTAMP");
            $rate_stmt->bind_param("iiiiiis", $line_id, $order_id, $product_id, $user_id, $seller_id, $rating, $comment);

            if ($rate_stmt->execute()) {
                $message = "⭐ Thank you for your rating!";
                $message_type = "success";
            } else {
                $message = "Error saving rating: " . $connect->error;
                $message_type = "error";
            }
            $rate_stmt->close();
        }
    }
}

// Fetch this user's orders with their line items and any existing rating per line
$orders_stmt = $connect->prepare("
    SELECT order_id, order_date, total_amount, order_status, payment_status, tracking_number
    FROM orders
    WHERE user_id = ?
    ORDER BY order_date DESC");
$orders_stmt->bind_param("i", $user_id);
$orders_stmt->execute();
$orders = $orders_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$orders_stmt->close();

$lines_by_order = [];
foreach ($orders as $order) {
    $lines_stmt = $connect->prepare("
        SELECT ol.line_id, ol.product_id, ol.product_name, ol.quantity, ol.unit_price, ol.line_total,
               c.image_url, r.rating, r.comment AS rating_comment
        FROM order_lines ol
        LEFT JOIN clothes c ON ol.product_id = c.product_id
        LEFT JOIN ratings r ON r.line_id = ol.line_id
        WHERE ol.order_id = ?");
    $lines_stmt->bind_param("i", $order['order_id']);
    $lines_stmt->execute();
    $lines_by_order[$order['order_id']] = $lines_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lines_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My Orders & Ratings – Pastimes</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#f4f4f8;color:#333}
.header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.2);flex-wrap:wrap;gap:10px}
.header h1{font-size:22px}
.header-links{display:flex;gap:14px;align-items:center}
.header-links a{color:rgba(255,255,255,.9);text-decoration:none;font-size:14px}
.header-links a:hover{color:#fff}
.shop-btn{background:#fff;color:#764ba2;padding:7px 16px;border-radius:20px;font-weight:700!important}
.page{max-width:960px;margin:30px auto;padding:0 20px 60px}
.page-title{font-size:22px;font-weight:700;margin-bottom:6px;color:#333}
.page-sub{color:#888;font-size:14px;margin-bottom:24px}

.message{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:14px}
.success{background:#e8f5e9;color:#1b5e20;border:1px solid #c8e6c9}
.error{background:#fdecea;color:#c62828;border:1px solid #f5c6cb}

.empty-state{text-align:center;padding:60px 20px;background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07)}
.empty-state .icon{font-size:56px;margin-bottom:14px}
.empty-state h2{font-size:20px;margin-bottom:8px}
.empty-state p{color:#888;margin-bottom:20px}
.shop-link{display:inline-block;padding:11px 26px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:10px;font-weight:700;text-decoration:none}

.order-card{background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);margin-bottom:24px;overflow:hidden}
.order-header{background:#f8f8ff;padding:14px 20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;border-bottom:1px solid #eee}
.order-ref{font-size:15px;font-weight:700;color:#667eea}
.order-date{font-size:13px;color:#888;margin-left:auto}
.status-badge{padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;text-transform:uppercase}
.status-delivered{background:#e8f5e9;color:#1b5e20}
.status-pending{background:#fff3e0;color:#e65100}
.status-shipped{background:#e3f2fd;color:#0277bd}
.status-processing{background:#fff8e1;color:#f9a825}
.status-cancelled{background:#fdecea;color:#c62828}

.item-row{display:flex;gap:16px;padding:18px 20px;border-bottom:1px solid #f0f0f0;align-items:flex-start;flex-wrap:wrap}
.item-row:last-child{border-bottom:none}
.item-img{width:64px;height:64px;object-fit:cover;border-radius:8px;background:#f0f0f0;flex-shrink:0}
.item-info{flex:1;min-width:180px}
.item-name{font-weight:600;color:#222;font-size:15px}
.item-meta{font-size:13px;color:#888;margin-top:3px}

.rate-area{min-width:240px}
.stars{display:flex;gap:4px;margin-bottom:8px}
.star-label{cursor:pointer;font-size:26px;color:#ddd;transition:color .15s}
.star-label.filled{color:#f5b301}
.star-input{display:none}
.comment-input{width:100%;padding:8px 10px;border:2px solid #e0e0e0;border-radius:8px;font-size:13px;resize:vertical;margin-bottom:8px}
.comment-input:focus{outline:none;border-color:#667eea}
.rate-submit{padding:7px 16px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer}

.already-rated{background:#fafafa;border-radius:10px;padding:12px 14px}
.already-rated .stars-display{color:#f5b301;font-size:18px;letter-spacing:2px;margin-bottom:4px}
.already-rated .rated-comment{font-size:13px;color:#555;font-style:italic}
.already-rated .rated-label{font-size:12px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}

@media(max-width:600px){
  .header{padding:12px 16px}
  .page{padding:0 12px 40px}
  .item-row{flex-direction:column}
}
</style>
</head>
<body>

<div class="header">
  <h1>⭐ My Orders &amp; Ratings</h1>
  <div class="header-links">
    <a href="products.php" class="shop-btn">← Shop</a>
    <a href="order_history.php">Order History</a>
    <a href="dashboard.php">My Profile</a>
    <a href="login.php?logout=1">Logout</a>
  </div>
</div>

<div class="page">
  <h2 class="page-title">Rate Your Purchases</h2>
  <p class="page-sub">Let us and the seller know how each item was — quality, fit, and whether it arrived in good condition.</p>

  <?php if ($message): ?>
    <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <?php if (empty($orders)): ?>
  <div class="empty-state">
    <div class="icon">📦</div>
    <h2>No orders yet</h2>
    <p>Once you've made a purchase, you'll be able to rate each item here.</p>
    <a href="products.php" class="shop-link">Start Shopping</a>
  </div>

  <?php else: ?>
    <?php foreach ($orders as $order):
      $lines = $lines_by_order[$order['order_id']] ?? [];
    ?>
    <div class="order-card">
      <div class="order-header">
        <div class="order-ref">Order #<?php echo htmlspecialchars($order['tracking_number'] ?: $order['order_id']); ?></div>
        <span class="status-badge status-<?php echo htmlspecialchars($order['order_status']); ?>">
          <?php echo ucfirst($order['order_status']); ?>
        </span>
        <div class="order-date"><?php echo date('d M Y, H:i', strtotime($order['order_date'])); ?></div>
      </div>

      <?php foreach ($lines as $line): ?>
      <div class="item-row">
        <img class="item-img" src="<?php echo htmlspecialchars($line['image_url'] ?: ''); ?>" alt="" onerror="this.style.visibility='hidden'">
        <div class="item-info">
          <div class="item-name"><?php echo htmlspecialchars($line['product_name']); ?></div>
          <div class="item-meta">Qty: <?php echo $line['quantity']; ?> &middot; R<?php echo number_format($line['unit_price'], 2); ?> each</div>
        </div>

        <div class="rate-area">
          <?php if ($line['rating']): ?>
            <div class="already-rated">
              <div class="rated-label">Your rating</div>
              <div class="stars-display"><?php echo str_repeat('★', $line['rating']) . str_repeat('☆', 5 - $line['rating']); ?></div>
              <?php if ($line['rating_comment']): ?>
                <div class="rated-comment">"<?php echo htmlspecialchars($line['rating_comment']); ?>"</div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <form method="POST" class="rate-form">
              <input type="hidden" name="line_id" value="<?php echo $line['line_id']; ?>">
              <div class="stars" data-line="<?php echo $line['line_id']; ?>">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                  <input type="radio" class="star-input" name="rating" value="<?php echo $i; ?>" id="star_<?php echo $line['line_id']; ?>_<?php echo $i; ?>" required>
                  <label class="star-label" for="star_<?php echo $line['line_id']; ?>_<?php echo $i; ?>" data-value="<?php echo $i; ?>">★</label>
                <?php endfor; ?>
              </div>
              <textarea class="comment-input" name="comment" rows="2" placeholder="Optional comment (e.g. fit, quality, delivery condition)"></textarea>
              <button type="submit" name="submit_rating" class="rate-submit">Submit Rating</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
// Light star-hover/select behaviour. Stars are rendered 5..1 in the DOM (for the
// CSS-only "highlight this star and all to its left" trick), so we read the
// data-value to know which one was actually clicked/hovered.
document.querySelectorAll('.stars').forEach(function(group) {
  const labels = Array.from(group.querySelectorAll('.star-label'));
  function paint(selected) {
    labels.forEach(function(label) {
      const val = parseInt(label.getAttribute('data-value'), 10);
      label.classList.toggle('filled', val <= selected);
    });
  }
  labels.forEach(function(label) {
    const val = parseInt(label.getAttribute('data-value'), 10);
    label.addEventListener('mouseenter', function() { paint(val); });
    label.addEventListener('click', function() { paint(val); });
  });
  group.addEventListener('mouseleave', function() {
    const checked = group.querySelector('input.star-input:checked');
    paint(checked ? parseInt(checked.value, 10) : 0);
  });
});
</script>

</body>
</html>