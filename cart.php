<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
include "DBConn.php";

$user_id    = $_SESSION['user_id'];
$session_id = session_id();
$message    = '';
$message_type = '';

// ── ensure tables exist ───────────────────────────────────────────────────
$connect->query("CREATE TABLE IF NOT EXISTS orders (
    order_id int auto_increment primary key,
    user_id int not null,
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_amount decimal(10,2),
    shipping_address text,
    tracking_number varchar(100),
    order_status ENUM('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    payment_status ENUM('pending','paid','failed') DEFAULT 'pending',
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE)");

$connect->query("CREATE TABLE IF NOT EXISTS order_lines (
    line_id int auto_increment primary key,
    order_id int not null,
    product_id int not null,
    product_name varchar(200),
    quantity int not null,
    unit_price decimal(10,2) not null,
    line_total decimal(10,2) not null,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE)");

$connect->query("CREATE TABLE IF NOT EXISTS cart (
    cart_id int auto_increment primary key,
    user_id int not null,
    product_id int not null,
    quantity int not null default 1,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES clothes(product_id) ON DELETE CASCADE,
    UNIQUE KEY unique_cart_item (user_id, product_id))");

// ── initialise cart session array ─────────────────────────────────────────
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// ── helpers ───────────────────────────────────────────────────────────────
function syncCartFromDB($connect, $user_id) {
    $stmt = $connect->prepare("
        SELECT ca.product_id, ca.quantity,
               cl.product_name, cl.price, cl.on_sale, cl.sale_price,
               cl.image_url, cl.stock_quantity
        FROM cart ca JOIN clothes cl ON ca.product_id = cl.product_id
        WHERE ca.user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $arr = [];
    foreach ($rows as $r) {
        $arr[$r['product_id']] = $r;
    }
    return $arr;
}

// addItem
function addItem($connect, $user_id, $product_id, $qty = 1) {
    $s = $connect->prepare("INSERT INTO cart (user_id,product_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)");
    $s->bind_param("iii", $user_id, $product_id, $qty);
    $s->execute(); $s->close();
    $_SESSION['cart'] = syncCartFromDB($connect, $user_id);
}

// removeItem
function removeItem($connect, $user_id, $product_id) {
    $s = $connect->prepare("DELETE FROM cart WHERE user_id=? AND product_id=?");
    $s->bind_param("ii", $user_id, $product_id);
    $s->execute(); $s->close();
    unset($_SESSION['cart'][$product_id]);
}

// emptyCart
function emptyCart($connect, $user_id) {
    $s = $connect->prepare("DELETE FROM cart WHERE user_id=?");
    $s->bind_param("i", $user_id);
    $s->execute(); $s->close();
    $_SESSION['cart'] = [];
}

// processInput – update a single item's qty
function processInput($connect, $user_id, $product_id, $new_qty) {
    if ($new_qty < 1) { removeItem($connect, $user_id, $product_id); return; }
    $s = $connect->prepare("UPDATE cart SET quantity=? WHERE user_id=? AND product_id=?");
    $s->bind_param("iii", $new_qty, $user_id, $product_id);
    $s->execute(); $s->close();
    $_SESSION['cart'] = syncCartFromDB($connect, $user_id);
}

// checkout
function checkout($connect, $user_id, $address) {
    $cart = syncCartFromDB($connect, $user_id);
    if (empty($cart)) return null;

    $total = 0;
    foreach ($cart as $item) {
        $price  = ($item['on_sale'] && $item['sale_price']) ? $item['sale_price'] : $item['price'];
        $total += $price * $item['quantity'];
    }

    // generate reference
    $order_num  = 'ORD-' . strtoupper(substr(md5(uniqid()), 0, 8));
    $session_id = session_id();

    // create order — delivered immediately upon checkout, per store policy
    $s = $connect->prepare("INSERT INTO orders (user_id,total_amount,shipping_address,tracking_number,order_status,payment_status) VALUES(?,?,?,?,'delivered','paid')");
    if (!$s) { return ['error' => 'Order creation failed: ' . $connect->error]; }
    $uid_o = (int)$user_id; $tot_o = (float)$total; $addr_o = (string)$address; $onum_o = (string)$order_num;
    $s->bind_param("idss", $uid_o, $tot_o, $addr_o, $onum_o);
    $s->execute();
    $order_id = $connect->insert_id;
    $s->close();

    // insert order lines & decrement stock
    foreach ($cart as $item) {
        $price      = ($item['on_sale'] && $item['sale_price']) ? $item['sale_price'] : $item['price'];
        $line_total = $price * $item['quantity'];
        $s = $connect->prepare("INSERT INTO order_lines (order_id,product_id,product_name,quantity,unit_price,line_total) VALUES(?,?,?,?,?,?)");
        if (!$s) { error_log("order_lines prepare failed: " . $connect->error); continue; }
        $pid_ol = (int)$item['product_id']; $qty_ol = (int)$item['quantity']; $price_ol = (float)$price; $lt_ol = (float)$line_total; $pname_ol = (string)$item['product_name']; $oid_ol = (int)$order_id;
        $s->bind_param("iisidd", $oid_ol, $pid_ol, $pname_ol, $qty_ol, $price_ol, $lt_ol);
        $s->execute(); $s->close();

        $s = $connect->prepare("UPDATE clothes SET stock_quantity=GREATEST(0,stock_quantity-?) WHERE product_id=?");
        $s->bind_param("ii", $item['quantity'], $item['product_id']);
        $s->execute(); $s->close();
    }

    // empty cart
    emptyCart($connect, $user_id);

    return ['order_id' => $order_id, 'order_num' => $order_num, 'session_id' => $session_id, 'total' => $total];
}

// ── action handlers ───────────────────────────────────────────────────────
$checkout_result = null;

// Remove single item
if (isset($_GET['remove'])) {
    removeItem($connect, $user_id, intval($_GET['remove']));
    header('Location: cart.php'); exit();
}

// Empty cart
if (isset($_GET['empty'])) {
    emptyCart($connect, $user_id);
    header('Location: cart.php'); exit();
}

// Update quantity (edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_qty'])) {
    $pid = intval($_POST['product_id']);
    $qty = intval($_POST['quantity']);
    processInput($connect, $user_id, $pid, $qty);
    header('Location: cart.php'); exit();
}

// Checkout
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkout'])) {
    $address = trim($_POST['shipping_address'] ?? $_SESSION['address'] ?? '');
    $checkout_result = checkout($connect, $user_id, $address);
}

// Sync cart from DB on every load
$_SESSION['cart'] = syncCartFromDB($connect, $user_id);
$cart_items = $_SESSION['cart'];

$subtotal = 0;
foreach ($cart_items as $item) {
    $up        = ($item['on_sale'] && $item['sale_price']) ? $item['sale_price'] : $item['price'];
    $subtotal += $up * $item['quantity'];
}
$total_qty = array_sum(array_column($cart_items, 'quantity'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Shopping Cart – Pastimes</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#f4f4f8;color:#333}
.header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.header h1{font-size:22px}
.header-links{display:flex;gap:14px;align-items:center}
.header-links a{color:rgba(255,255,255,.9);text-decoration:none;font-size:14px}
.header-links a:hover{color:#fff}
.continue-btn{background:#fff;color:#764ba2;padding:7px 16px;border-radius:20px;font-weight:700!important}
.page{max-width:960px;margin:30px auto;padding:0 20px 60px}
.flash{padding:12px 18px;border-radius:8px;font-size:14px;font-weight:500;margin-bottom:20px}
.flash.success{background:#e8f5e9;color:#2e7d32;border-left:4px solid #2e7d32}
.flash.error{background:#fdecea;color:#c62828;border-left:4px solid #c62828}

/* checkout success card */
.checkout-card{background:#fff;border-radius:14px;padding:36px;text-align:center;box-shadow:0 4px 16px rgba(0,0,0,.1);margin-bottom:30px}
.checkout-card .icon{font-size:60px;margin-bottom:16px}
.checkout-card h2{font-size:24px;color:#2e7d32;margin-bottom:8px}
.checkout-card p{color:#555;margin:6px 0;font-size:15px}
.ref-box{background:#f4f4f8;border-radius:10px;padding:16px 24px;display:inline-block;margin:16px 0;text-align:left}
.ref-box span{display:block;font-size:13px;color:#888;margin-bottom:2px}
.ref-box strong{font-size:18px;color:#333;letter-spacing:1px}
.checkout-actions{display:flex;gap:12px;justify-content:center;margin-top:20px;flex-wrap:wrap}
.btn-primary{padding:12px 28px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;text-decoration:none;cursor:pointer}
.btn-outline{padding:12px 28px;background:#fff;color:#667eea;border:2px solid #667eea;border-radius:10px;font-size:15px;font-weight:700;text-decoration:none}

/* cart table */
.cart-card{background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);overflow:hidden;margin-bottom:24px}
.cart-card table{width:100%;border-collapse:collapse}
.cart-card thead th{background:#667eea;color:#fff;padding:12px 14px;text-align:left;font-size:13px;text-transform:uppercase;letter-spacing:.5px}
.cart-card tbody tr{border-bottom:1px solid #f0f0f0}
.cart-card tbody tr:last-child{border-bottom:none}
.cart-card tbody td{padding:12px 14px;vertical-align:middle;font-size:14px}
.item-img{width:60px;height:60px;object-fit:cover;border-radius:8px}
.item-name{font-weight:600;color:#222}
.item-cat{font-size:11px;color:#aaa;margin-top:2px}
.price-original{font-size:12px;color:#aaa;text-decoration:line-through}
.price-sale{color:#e74c3c;font-weight:700}
.price-normal{color:#667eea;font-weight:700}
.qty-form{display:flex;align-items:center;gap:6px}
.qty-input{width:56px;padding:6px 8px;border:2px solid #e0e0e0;border-radius:6px;font-size:14px;text-align:center}
.qty-input:focus{outline:none;border-color:#667eea}
.update-btn{padding:6px 10px;background:#667eea;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600}
.remove-link{color:#e74c3c;text-decoration:none;font-size:13px;font-weight:600}
.remove-link:hover{text-decoration:underline}
.line-total{font-weight:700}

/* summary */
.summary-card{background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);padding:24px}
.sum-row{display:flex;justify-content:space-between;padding:8px 0;font-size:15px;border-bottom:1px solid #f0f0f0}
.sum-row:last-of-type{border-bottom:none}
.sum-total{display:flex;justify-content:space-between;padding:14px 0 0;font-size:20px;font-weight:700;color:#667eea;border-top:2px solid #667eea;margin-top:8px}
.sum-actions{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}
.checkout-form{flex:1}
.checkout-form input[type=text]{width:100%;padding:10px 14px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;margin-bottom:10px}
.checkout-form input[type=text]:focus{outline:none;border-color:#667eea}
.checkout-submit-btn{width:100%;padding:14px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:700;cursor:pointer}
.empty-btn{padding:10px 16px;background:#fdecea;color:#e74c3c;border:2px solid #e74c3c;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;white-space:nowrap;align-self:flex-start}

/* empty state */
.empty-cart{text-align:center;padding:60px 20px;background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07)}
.empty-cart .icon{font-size:64px;margin-bottom:16px}
.empty-cart h2{font-size:22px;margin-bottom:8px}
.empty-cart p{color:#888;margin-bottom:24px}
.shop-now{display:inline-block;padding:12px 30px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:10px;font-weight:700;text-decoration:none}

@media(max-width:600px){
  .header{padding:12px 16px}
  .cart-card thead th:first-child,.cart-card tbody td:first-child{display:none}
  .page{padding:0 12px 40px}
}
</style>
</head>
<body>

<div class="header">
  <h1>&#128722; Shopping Cart</h1>
  <div class="header-links">
    <a href="products.php" class="continue-btn">&#8592; Continue Shopping</a>
    <a href="order_history.php">My Orders</a>
    <a href="login.php?logout=1">Logout</a>
  </div>
</div>

<div class="page">

<?php if ($checkout_result): ?>
  <!-- ── Checkout Success ── -->
  <div class="checkout-card">
    <div class="icon">&#10003;&#65039;</div>
    <h2>Order Placed Successfully!</h2>
    <p>Thank you for your purchase. Here are your reference details:</p>
    <div class="ref-box">
      <span>Order Number</span>
      <strong><?php echo htmlspecialchars($checkout_result['order_num']); ?></strong>
      <span style="margin-top:10px">Session ID</span>
      <strong><?php echo htmlspecialchars($checkout_result['session_id']); ?></strong>
      <span style="margin-top:10px">Order Total</span>
      <strong>R<?php echo number_format($checkout_result['total'], 2); ?></strong>
    </div>
    <div class="checkout-actions">
      <a href="index.php" class="btn-outline">&#8592; Back to Home</a>
      <a href="order_history.php" class="btn-primary">View Order History</a>
      <a href="customer_order.php" class="btn-primary" style="background:linear-gradient(135deg,#f5b301,#e69500)">&#11088; Rate Your Items</a>
    </div>
  </div>

<?php elseif (empty($cart_items)): ?>
  <div class="empty-cart">
    <div class="icon">&#128722;</div>
    <h2>Your cart is empty</h2>
    <p>Looks like you haven't added anything yet.</p>
    <a href="products.php" class="shop-now">Browse Products</a>
  </div>

<?php else: ?>
  <div class="cart-card">
    <table>
      <thead>
        <tr>
          <th>Image</th><th>Product</th><th>Unit Price</th><th>Qty</th><th>Line Total</th><th>Remove</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cart_items as $item):
          $on_sale   = $item['on_sale'] && $item['sale_price'];
          $unit      = $on_sale ? $item['sale_price'] : $item['price'];
          $line      = $unit * $item['quantity'];
        ?>
        <tr>
          <td><img class="item-img"
               src="<?php echo htmlspecialchars($item['image_url'] ?? 'https://via.placeholder.com/60'); ?>"
               onerror="this.src='https://via.placeholder.com/60?text=?'"
               alt="<?php echo htmlspecialchars($item['product_name']); ?>"></td>
          <td>
            <div class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
          </td>
          <td>
            <?php if ($on_sale): ?>
              <div class="price-original">R<?php echo number_format($item['price'],2); ?></div>
              <div class="price-sale">R<?php echo number_format($unit,2); ?></div>
            <?php else: ?>
              <div class="price-normal">R<?php echo number_format($unit,2); ?></div>
            <?php endif; ?>
          </td>
          <td>
            <form class="qty-form" method="POST">
              <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
              <input type="number" name="quantity" class="qty-input"
                     value="<?php echo $item['quantity']; ?>"
                     min="1" max="<?php echo $item['stock_quantity']; ?>">
              <button type="submit" name="update_qty" class="update-btn">&#10003;</button>
            </form>
          </td>
          <td class="line-total">R<?php echo number_format($line,2); ?></td>
          <td>
            <a class="remove-link"
               href="cart.php?remove=<?php echo $item['product_id']; ?>"
               onclick="return confirm('Remove this item?')">&#10005;</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="summary-card">
    <div class="sum-row"><span>Items</span><span><?php echo $total_qty; ?></span></div>
    <div class="sum-row"><span>Subtotal</span><span>R<?php echo number_format($subtotal,2); ?></span></div>
    <div class="sum-row"><span>Shipping</span><span style="color:#2e7d32">Free</span></div>
    <div class="sum-total"><span>Total</span><span>R<?php echo number_format($subtotal,2); ?></span></div>

    <div class="sum-actions">
      <a href="cart.php?empty=1" class="empty-btn"
         onclick="return confirm('Clear entire cart?')">&#128465; Empty Cart</a>
      <form class="checkout-form" method="POST">
        <input type="text" name="shipping_address"
               placeholder="Shipping address"
               value="<?php echo htmlspecialchars($_SESSION['address'] ?? ''); ?>" required>
        <button type="submit" name="checkout" class="checkout-submit-btn">
          &#128722; Checkout &rarr;
        </button>
      </form>
    </div>
  </div>
<?php endif; ?>

</div>
</body>
</html>