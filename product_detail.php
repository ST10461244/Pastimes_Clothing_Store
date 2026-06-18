<?php
// product_detail.php - Product Detail Page
session_start();
include "DBConn.php";

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$product_id) {
    header('Location: products.php');
    exit();
}

// Get product details with seller info
$product_query = $connect->prepare("
    SELECT c.*, u.username, u.name, u.surname, u.shop_name, u.custom_url,
           u.is_verified_seller
    FROM clothes c
    LEFT JOIN users u ON c.seller_id = u.user_id
    WHERE c.product_id = ?
");
$product_query->bind_param("i", $product_id);
$product_query->execute();
$product = $product_query->get_result()->fetch_assoc();
$product_query->close();

if (!$product) {
    header('Location: products.php');
    exit();
}

// Get product images (if table exists)
$images = [];
$images_check = $connect->query("SHOW TABLES LIKE 'product_images'");
if ($images_check && $images_check->num_rows > 0) {
    $images_query = $connect->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC");
    $images_query->bind_param("i", $product_id);
    $images_query->execute();
    $images = $images_query->get_result()->fetch_all(MYSQLI_ASSOC);
    $images_query->close();
}

// Get related products (same category)
$related_query = $connect->prepare("
    SELECT * FROM clothes 
    WHERE category = ? AND product_id != ? AND stock_quantity > 0
    ORDER BY RAND() LIMIT 4
");
$related_query->bind_param("si", $product['category'], $product_id);
$related_query->execute();
$related_products = $related_query->get_result()->fetch_all(MYSQLI_ASSOC);
$related_query->close();

// Handle add to cart
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_to_cart']) && isset($_SESSION['user_id'])) {
    $quantity = intval($_POST['quantity'] ?? 1);
    
    if ($quantity < 1) $quantity = 1;
    if ($quantity > $product['stock_quantity']) $quantity = $product['stock_quantity'];
    
    $connect->query("CREATE TABLE IF NOT EXISTS cart (
        cart_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES clothes(product_id) ON DELETE CASCADE,
        UNIQUE KEY unique_cart_item (user_id, product_id)
    )");
    
    $upsert = $connect->prepare("
        INSERT INTO cart (user_id, product_id, quantity)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
    ");
    $upsert->bind_param("iii", $_SESSION['user_id'], $product_id, $quantity);
    if ($upsert->execute()) {
        $cart_message = "✅ Added to cart!";
        $cart_message_type = "success";
    } else {
        $cart_message = "Error adding to cart.";
        $cart_message_type = "error";
    }
    $upsert->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product['product_name']); ?> - Pastimes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        
        .header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            padding: 16px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .header h1 { font-size: 20px; }
        .header a { color: rgba(255,255,255,0.85); text-decoration: none; }
        .header a:hover { color: white; }
        
        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
        
        .product-detail {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .product-gallery { position: relative; }
        .main-image {
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            border-radius: 12px;
            background: #f0f0f0;
        }
        
        .product-info h1 { font-size: 28px; color: #333; margin-bottom: 10px; }
        .product-price { font-size: 32px; font-weight: 700; color: #667eea; margin-bottom: 10px; }
        .product-price .original { font-size: 20px; color: #999; text-decoration: line-through; margin-left: 10px; }
        
        .seller-info {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 0;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
            margin: 15px 0;
        }
        .seller-info .verified { color: #27ae60; font-size: 14px; }
        
        .product-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #219a52; }
        .btn-outline { background: white; color: #667eea; border: 2px solid #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        
        .related-section { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .related-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px; margin-top: 15px; }
        .related-item { text-decoration: none; color: inherit; }
        .related-item img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 10px; background: #f0f0f0; }
        .related-item h4 { font-size: 14px; margin-top: 8px; color: #333; }
        .related-item .price { font-weight: 600; color: #667eea; }
        
        .message { padding: 12px 18px; border-radius: 10px; margin-bottom: 20px; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        @media (max-width: 768px) {
            .product-detail { grid-template-columns: 1fr; }
            .header { padding: 12px 16px; }
            .container { padding: 10px; }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>🛍️ <?php echo htmlspecialchars($product['product_name']); ?></h1>
    <div>
        <a href="style_feed.php"><i class="fas fa-images"></i> Feed</a>
        <a href="products.php" style="margin-left: 15px;"><i class="fas fa-store"></i> Shop</a>
        <a href="cart.php" style="margin-left: 15px;"><i class="fas fa-shopping-cart"></i> Cart</a>
        <a href="negotiation.php" style="margin-left: 15px;"><i class="fas fa-handshake"></i> Negotiate</a>
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="dashboard.php" style="margin-left: 15px;"><i class="fas fa-user"></i></a>
            <a href="login.php?logout=1" style="margin-left: 15px; color: #ff6b6b;">Logout</a>
        <?php else: ?>
            <a href="login.php" style="margin-left: 15px;">Login</a>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <?php if (isset($cart_message)): ?>
        <div class="message <?php echo $cart_message_type; ?>"><?php echo $cart_message; ?></div>
    <?php endif; ?>

    <!-- Product Detail -->
    <div class="product-detail">
        <div class="product-gallery">
            <img src="<?php echo htmlspecialchars($product['image_url'] ?? 'https://via.placeholder.com/600x600/eee/999?text=No+Image'); ?>" 
                 alt="<?php echo htmlspecialchars($product['product_name']); ?>" 
                 class="main-image">
        </div>

        <div class="product-info">
            <h1><?php echo htmlspecialchars($product['product_name']); ?></h1>
            
            <div class="product-price">
                R<?php echo number_format($product['price'], 2); ?>
                <?php if ($product['on_sale'] && $product['sale_price']): ?>
                    <span class="original">R<?php echo number_format($product['price'], 2); ?></span>
                    <span style="color: #e74c3c; font-size: 16px;">Save <?php echo round((($product['price'] - $product['sale_price']) / $product['price']) * 100); ?>%</span>
                <?php endif; ?>
            </div>
            
            <div style="color: #666; margin: 10px 0;"><?php echo htmlspecialchars($product['description'] ?? ''); ?></div>
            
            <div class="seller-info">
                <span>👤 Seller: 
                    <?php if ($product['shop_name']): ?>
                        <a href="seller_profile.php?url=<?php echo $product['custom_url']; ?>">
                            <?php echo htmlspecialchars($product['shop_name']); ?>
                        </a>
                    <?php else: ?>
                        <?php echo htmlspecialchars($product['username'] ?? 'Admin/Store'); ?>
                    <?php endif; ?>
                    <?php if ($product['is_verified_seller']): ?>
                        <span class="verified"><i class="fas fa-check-circle"></i> Verified</span>
                    <?php endif; ?>
                </span>
            </div>
            
            <div style="color: <?php echo $product['stock_quantity'] <= 0 ? '#e74c3c' : ($product['stock_quantity'] <= 5 ? '#f39c12' : '#27ae60'); ?>; font-weight: 600; margin: 10px 0;">
                <?php if ($product['stock_quantity'] <= 0): ?>
                    ❌ Out of Stock
                <?php elseif ($product['stock_quantity'] <= 5): ?>
                    ⚠️ Only <?php echo $product['stock_quantity']; ?> left!
                <?php else: ?>
                    ✅ In Stock (<?php echo $product['stock_quantity']; ?> available)
                <?php endif; ?>
            </div>
            
            <div class="product-actions">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <form method="POST" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <input type="number" name="quantity" value="1" min="1" max="<?php echo $product['stock_quantity']; ?>" style="width: 60px; padding: 10px; border: 2px solid #e0e0e0; border-radius: 8px; text-align: center;">
                        <button type="submit" name="add_to_cart" class="btn btn-primary" <?php echo $product['stock_quantity'] <= 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-shopping-cart"></i> Add to Cart
                        </button>
                    </form>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary">Login to Buy</a>
                <?php endif; ?>
                <a href="negotiation.php?product_id=<?php echo $product_id; ?>" class="btn btn-success">
                    <i class="fas fa-handshake"></i> Make Offer
                </a>
            </div>
        </div>
    </div>
    
    <!-- Related Products -->
    <?php if (!empty($related_products)): ?>
        <div class="related-section">
            <h2>🔄 You May Also Like</h2>
            <div class="related-grid">
                <?php foreach ($related_products as $related): ?>
                    <a href="product_detail.php?id=<?php echo $related['product_id']; ?>" class="related-item">
                        <img src="<?php echo htmlspecialchars($related['image_url'] ?? 'https://via.placeholder.com/200x200/eee/999?text=No+Image'); ?>" alt="<?php echo htmlspecialchars($related['product_name']); ?>">
                        <h4><?php echo htmlspecialchars($related['product_name']); ?></h4>
                        <div class="price">R<?php echo number_format($related['price'], 2); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px;">
        <a href="products.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Shop</a>
        <a href="negotiation.php?product_id=<?php echo $product_id; ?>" class="btn btn-success"><i class="fas fa-handshake"></i> Make an Offer</a>
    </div>
</div>

</body>
</html>