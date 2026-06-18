<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

include "DBConn.php";

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle Add to Cart
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_to_cart'])) {
    $product_id = intval($_POST['product_id']);
    $quantity   = max(1, intval($_POST['quantity'] ?? 1));

    // Ensure cart table exists
    $connect->query("CREATE TABLE IF NOT EXISTS cart (
        cart_id    INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        product_id INT NOT NULL,
        quantity   INT NOT NULL DEFAULT 1,
        added_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id)    REFERENCES users(user_id)   ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES clothes(product_id) ON DELETE CASCADE,
        UNIQUE KEY unique_cart_item (user_id, product_id)
    )");

    // Check stock
    $stock_stmt = $connect->prepare("SELECT stock_quantity, product_name FROM clothes WHERE product_id = ?");
    $stock_stmt->bind_param("i", $product_id);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result()->fetch_assoc();
    $stock_stmt->close();

    if (!$stock_result) {
        $message = "Product not found.";
        $message_type = "error";
    } elseif ($stock_result['stock_quantity'] < $quantity) {
        $message = "Sorry, only " . $stock_result['stock_quantity'] . " item(s) in stock.";
        $message_type = "error";
    } else {
        // Insert or increment quantity
        $upsert = $connect->prepare("
            INSERT INTO cart (user_id, product_id, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ");
        $upsert->bind_param("iii", $user_id, $product_id, $quantity);
        if ($upsert->execute()) {
            $message = "&#10003; \"" . htmlspecialchars($stock_result['product_name']) . "\" added to cart!";
            $message_type = "success";
        } else {
            $message = "Could not add to cart. Please try again.";
            $message_type = "error";
        }
        $upsert->close();
    }
}

// Filters
$category_filter = trim($_GET['category'] ?? '');
$search_filter   = trim($_GET['search'] ?? '');
$sale_filter     = isset($_GET['on_sale']) && $_GET['on_sale'] == '1';

$where_clauses = ["stock_quantity > 0"];
$params        = [];
$types         = "";

if ($category_filter !== '') {
    $where_clauses[] = "category = ?";
    $params[]        = $category_filter;
    $types          .= "s";
}
if ($search_filter !== '') {
    $where_clauses[] = "(product_name LIKE ? OR description LIKE ?)";
    $like            = "%" . $search_filter . "%";
    $params[]        = $like;
    $params[]        = $like;
    $types          .= "ss";
}
if ($sale_filter) {
    $where_clauses[] = "on_sale = 1";
}

$where_sql = implode(" AND ", $where_clauses);
$sql       = "SELECT * FROM clothes WHERE $where_sql ORDER BY created_at DESC";

$stmt = $connect->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch distinct categories for filter dropdown
$cats_result = $connect->query("SELECT DISTINCT category FROM clothes WHERE category IS NOT NULL ORDER BY category");
$categories  = [];
while ($r = $cats_result->fetch_assoc()) {
    $categories[] = $r['category'];
}

// Cart item count for badge
$cart_count_stmt = $connect->prepare("SELECT COALESCE(SUM(quantity),0) AS total FROM cart WHERE user_id = ?");
$cart_count_stmt->bind_param("i", $user_id);
$cart_count_stmt->execute();
$cart_count = (int)$cart_count_stmt->get_result()->fetch_assoc()['total'];
$cart_count_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop - Pastimes</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f4f8;
            color: #333;
        }

        /* ── Header ── */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .header h1 { font-size: 22px; }
        .header-right { display: flex; align-items: center; gap: 14px; }
        .cart-btn {
            background: white;
            color: #764ba2;
            border: none;
            padding: 8px 18px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .cart-badge {
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
        }
        .logout-link {
            color: rgba(255,255,255,0.85);
            font-size: 13px;
            text-decoration: none;
        }
        .logout-link:hover { color: white; }

        /* ── Filters ── */
        .filters-bar {
            background: white;
            padding: 16px 30px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
        }
        .filters-bar form { display: flex; gap: 10px; flex-wrap: wrap; width: 100%; }
        .filters-bar input[type="text"],
        .filters-bar select {
            padding: 9px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }
        .filters-bar input[type="text"]:focus,
        .filters-bar select:focus { border-color: #667eea; }
        .filters-bar input[type="text"] { flex: 1; min-width: 200px; }
        .filter-btn {
            padding: 9px 20px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }
        .clear-btn {
            padding: 9px 16px;
            background: #f0f0f0;
            color: #555;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
        .sale-label { display: flex; align-items: center; gap: 6px; font-size: 14px; white-space: nowrap; }

        /* ── Flash message ── */
        .flash {
            margin: 16px 30px 0;
            padding: 12px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
        }
        .flash.success { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #2e7d32; }
        .flash.error   { background: #fdecea; color: #c62828; border-left: 4px solid #c62828; }

        /* ── Product grid ── */
        .shop-container { padding: 20px 30px 40px; }
        .results-info { font-size: 14px; color: #666; margin-bottom: 16px; }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 24px;
        }

        .product-card {
            background: white;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            flex-direction: column;
        }
        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }

        .product-img-wrap { position: relative; overflow: hidden; height: 220px; }
        .product-img-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        .product-card:hover .product-img-wrap img { transform: scale(1.04); }

        .sale-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            background: #e74c3c;
            color: white;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            text-transform: uppercase;
        }

        .product-body { padding: 16px; flex: 1; display: flex; flex-direction: column; gap: 6px; }
        .product-category { font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; }
        .product-name { font-size: 16px; font-weight: 600; color: #222; }
        .product-desc { font-size: 13px; color: #666; line-height: 1.4; flex: 1; }

        .product-price-row { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
        .price-original { font-size: 13px; color: #aaa; text-decoration: line-through; }
        .price-current { font-size: 20px; font-weight: 700; color: #667eea; }
        .price-current.on-sale { color: #e74c3c; }

        .stock-info { font-size: 12px; color: #888; }
        .stock-low { color: #e67e22; font-weight: 600; }

        /* ── Add to cart form ── */
        .add-to-cart-form {
            padding: 0 16px 16px;
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .qty-input {
            width: 60px;
            padding: 8px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            text-align: center;
        }
        .qty-input:focus { outline: none; border-color: #667eea; }
        .add-btn {
            flex: 1;
            padding: 10px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .add-btn:hover { opacity: 0.9; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state h3 { font-size: 20px; margin-bottom: 8px; }

        @media (max-width: 600px) {
            .header { padding: 12px 16px; }
            .filters-bar { padding: 12px 16px; }
            .shop-container { padding: 16px; }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>&#128734; Pastimes Shop</h1>
    <div class="header-right">
    <a href="style_feed.php" class="cart-btn" style="background: #e1306c; color: white;">
        <i class="fas fa-images"></i> Feed
    </a>
    <a href="negotiation.php" class="cart-btn" style="background: #3498db; color: white;">
        <i class="fas fa-handshake"></i> Negotiate
    </a>
    <a href="escrow_dashboard.php" class="cart-btn" style="background: #f39c12; color: white;">
        <i class="fas fa-shield-alt"></i> Escrow
    </a>
    <a href="cart.php" class="cart-btn">
        🛒 Cart
        <?php if ($cart_count > 0): ?>
            <span class="cart-badge"><?php echo $cart_count; ?></span>
        <?php endif; ?>
    </a>
    <a href="dashboard.php" class="logout-link">My Profile</a>
    <a href="login.php?logout=1" class="logout-link">Logout</a>
</div>
</div>

<div class="filters-bar">
    <form method="GET">
        <input type="text" name="search" placeholder="&#128269; Search products..."
               value="<?php echo htmlspecialchars($search_filter); ?>">
        <select name="category">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"
                    <?php echo $category_filter === $cat ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label class="sale-label">
            <input type="checkbox" name="on_sale" value="1" <?php echo $sale_filter ? 'checked' : ''; ?>>
            On Sale only
        </label>
        <button type="submit" class="filter-btn">Filter</button>
        <a href="products.php" class="clear-btn">Clear</a>
    </form>
</div>

<?php if ($message): ?>
    <div class="flash <?php echo $message_type; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<div class="shop-container">
    <p class="results-info"><?php echo count($products); ?> item(s) found</p>

    <?php if (empty($products)): ?>
        <div class="empty-state">
            <h3>No products found</h3>
            <p>Try adjusting your search or filter.</p>
        </div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($products as $p): ?>
                <?php
                    $display_price = ($p['on_sale'] && $p['sale_price']) ? $p['sale_price'] : $p['price'];
                    $img = htmlspecialchars($p['image_url'] ?? '');
                    $low_stock = $p['stock_quantity'] <= 2;
                ?>
                <div class="product-card">
                    <div class="product-img-wrap">
                        <?php if ($img): ?>
                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($p['product_name']); ?>"
                                 onerror="this.src='https://via.placeholder.com/400x220?text=No+Image'">
                        <?php else: ?>
                            <img src="https://via.placeholder.com/400x220?text=No+Image" alt="No image">
                        <?php endif; ?>
                        <?php if ($p['on_sale'] && $p['sale_price']): ?>
                            <span class="sale-badge">Sale</span>
                        <?php endif; ?>
                    </div>

                    <div class="product-body">
                        <div class="product-category">
                            <?php echo htmlspecialchars($p['category'] . ($p['subcategory'] ? ' / ' . $p['subcategory'] : '')); ?>
                        </div>
                        <a href="product_detail.php?id=<?php echo $p['product_id']; ?>" style="text-decoration: none; color: inherit;">
                            <div class="product-name"><?php echo htmlspecialchars($p['product_name']); ?></div>
                        </a>
                        <div class="product-desc"><?php echo htmlspecialchars($p['description'] ?? ''); ?></div>
                        <div class="product-price-row">
                            <?php if ($p['on_sale'] && $p['sale_price']): ?>
                                <span class="price-original">R<?php echo number_format($p['price'], 2); ?></span>
                            <?php endif; ?>
                            <span class="price-current <?php echo ($p['on_sale'] && $p['sale_price']) ? 'on-sale' : ''; ?>">
                                R<?php echo number_format($display_price, 2); ?>
                            </span>
                        </div>
                        <div class="stock-info <?php echo $low_stock ? 'stock-low' : ''; ?>">
                            <?php echo $low_stock ? 'Only ' . $p['stock_quantity'] . ' left!' : $p['stock_quantity'] . ' in stock'; ?>
                        </div>
                        <a href="negotiation.php?product_id=<?php echo $p['product_id']; ?>" class="btn btn-success" style="padding: 6px 12px; border-radius: 6px; text-decoration: none; color: white; background: #27ae60; font-size: 12px;">
                            <i class="fas fa-handshake"></i> Offer
                        </a>
                    </div>

                    <div class="add-to-cart-form">
                        <form method="POST" style="display: flex; gap: 8px; width: 100%;">
                            <input type="hidden" name="product_id" value="<?php echo $p['product_id']; ?>">
                            <input type="number" name="quantity" class="qty-input" value="1"
                                min="1" max="<?php echo $p['stock_quantity']; ?>">
                            <button type="submit" name="add_to_cart" class="add-btn">+ Add</button>
                            <a href="product_detail.php?id=<?php echo $p['product_id']; ?>" class="add-btn" style="background: #6c757d; text-decoration: none; text-align: center; padding: 8px 12px; border-radius: 8px; color: white; font-weight: 600;">
                                View
                            </a>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
