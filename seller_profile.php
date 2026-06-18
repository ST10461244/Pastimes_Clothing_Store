<?php
// seller_profile.php - Custom seller profile with unique URL
session_start();
include "DBConn.php";

$user_id = $_SESSION['user_id'] ?? 0;
$custom_url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (!$custom_url) {
    header('Location: products.php');
    exit();
}

// Get seller data
$seller_query = $connect->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM seller_followers WHERE seller_id = u.user_id) as follower_count,
           (SELECT COUNT(*) FROM seller_followers WHERE seller_id = u.user_id AND follower_id = ?) as is_following
    FROM users u
    WHERE u.custom_url = ? AND u.is_verified_seller = 1
");
$seller_query->bind_param("is", $user_id, $custom_url);
$seller_query->execute();
$seller = $seller_query->get_result()->fetch_assoc();
$seller_query->close();

if (!$seller) {
    header('Location: products.php');
    exit();
}

// Get seller's products
$products_query = $connect->prepare("
    SELECT * FROM clothes WHERE seller_id = ? AND stock_quantity > 0 ORDER BY created_at DESC
");
$products_query->bind_param("i", $seller['user_id']);
$products_query->execute();
$products = $products_query->get_result();
$products_query->close();

// Get seller's style posts
$posts_query = $connect->prepare("
    SELECT * FROM style_posts WHERE seller_id = ? ORDER BY created_at DESC
");
$posts_query->bind_param("i", $seller['user_id']);
$posts_query->execute();
$posts = $posts_query->get_result();
$posts_query->close();

// Handle follow/unfollow
if (isset($_GET['follow']) && $user_id > 0) {
    $follow_stmt = $connect->prepare("INSERT IGNORE INTO seller_followers (follower_id, seller_id) VALUES (?, ?)");
    $follow_stmt->bind_param("ii", $user_id, $seller['user_id']);
    $follow_stmt->execute();
    $follow_stmt->close();
    header("Location: seller_profile.php?url=" . $custom_url);
    exit();
}
if (isset($_GET['unfollow']) && $user_id > 0) {
    $unfollow_stmt = $connect->prepare("DELETE FROM seller_followers WHERE follower_id = ? AND seller_id = ?");
    $unfollow_stmt->bind_param("ii", $user_id, $seller['user_id']);
    $unfollow_stmt->execute();
    $unfollow_stmt->close();
    header("Location: seller_profile.php?url=" . $custom_url);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($seller['shop_name'] ?? $seller['username']); ?> - Pastimes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        
        .profile-header {
            background: white;
            padding: 30px 20px 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .profile-header .banner {
            width: 100%;
            height: 150px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            margin-bottom: -50px;
            overflow: hidden;
        }
        .profile-header .banner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-header .logo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: white;
            margin: 0 auto 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: 700;
            color: #667eea;
            border: 3px solid white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
            z-index: 2;
            overflow: hidden;
        }
        .profile-header .logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-header h1 { font-size: 26px; color: #333; margin-top: 5px; }
        .profile-header .handle { color: #999; font-size: 14px; }
        .profile-header .bio { max-width: 500px; margin: 10px auto; color: #666; font-size: 14px; }
        
        .profile-stats {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin: 15px 0 20px;
        }
        .profile-stats .stat { text-align: center; }
        .profile-stats .stat .number { font-size: 20px; font-weight: bold; color: #333; }
        .profile-stats .stat .label { font-size: 12px; color: #999; }
        
        .follow-btn {
            padding: 8px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            font-size: 14px;
        }
        .follow-btn.following { background: #eee; color: #333; }
        .follow-btn.following:hover { background: #ddd; }
        .follow-btn.follow { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        .follow-btn.follow:hover { transform: scale(1.02); }
        
        .content { max-width: 1000px; margin: 20px auto; padding: 0 20px 60px; }
        .section-title { font-size: 18px; font-weight: 600; color: #333; margin-bottom: 15px; }
        
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }
        .product-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
        }
        .product-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .product-card .image { 
            height: 200px; 
            background: #eee; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 40px; 
            color: #bbb;
            overflow: hidden;
        }
        .product-card .image img { width: 100%; height: 100%; object-fit: cover; }
        .product-card .info { padding: 12px 15px; }
        .product-card .info h3 { font-size: 14px; color: #333; }
        .product-card .info .price { font-weight: bold; color: #667eea; font-size: 18px; margin-top: 4px; }
        
        .posts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        .post-item {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .post-item img { width: 100%; aspect-ratio: 1; object-fit: cover; background: #eee; }
        .post-item .caption { padding: 8px 12px; font-size: 13px; color: #666; }
        
        .no-items {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .no-items i { font-size: 40px; color: #ddd; margin-bottom: 10px; display: block; }
        
        @media (max-width: 640px) {
            .profile-header { padding: 20px 15px; }
            .profile-stats { gap: 20px; }
            .product-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
        }
    </style>
</head>
<body>

    <div class="profile-header">
        <div class="banner">
            <?php if ($seller['shop_banner']): ?>
                <img src="<?php echo htmlspecialchars($seller['shop_banner']); ?>" alt="Banner" onerror="this.style.display='none'">
            <?php endif; ?>
        </div>
        <div class="logo">
            <?php if ($seller['shop_logo']): ?>
                <img src="<?php echo htmlspecialchars($seller['shop_logo']); ?>" alt="Logo" onerror="this.textContent='<?php echo strtoupper(substr($seller['shop_name'] ?? $seller['username'], 0, 1)); ?>'">
            <?php else: ?>
                <?php echo strtoupper(substr($seller['shop_name'] ?? $seller['username'], 0, 1)); ?>
            <?php endif; ?>
        </div>
        <h1><?php echo htmlspecialchars($seller['shop_name'] ?? $seller['username']); ?></h1>
        <div class="handle">@<?php echo htmlspecialchars($seller['custom_url']); ?></div>
        <div class="bio"><?php echo htmlspecialchars($seller['shop_description'] ?? 'Welcome to my shop! Browse my collection of unique clothing items.'); ?></div>
        
        <div class="profile-stats">
            <div class="stat">
                <div class="number"><?php echo $products->num_rows; ?></div>
                <div class="label">Products</div>
            </div>
            <div class="stat">
                <div class="number"><?php echo $seller['follower_count']; ?></div>
                <div class="label">Followers</div>
            </div>
            <div class="stat">
                <div class="number"><?php echo $posts->num_rows; ?></div>
                <div class="label">Posts</div>
            </div>
        </div>
        
        <?php if ($user_id > 0 && $user_id != $seller['user_id']): ?>
            <?php if ($seller['is_following']): ?>
                <a href="?unfollow=1&url=<?php echo $custom_url; ?>" class="follow-btn following">Following</a>
            <?php else: ?>
                <a href="?follow=1&url=<?php echo $custom_url; ?>" class="follow-btn follow">Follow</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="content">
        <!-- Products -->
        <h2 class="section-title">🛍️ Products</h2>
        <?php if ($products->num_rows > 0): ?>
            <div class="product-grid">
                <?php while ($product = $products->fetch_assoc()): ?>
                    <a href="product_detail.php?id=<?php echo $product['product_id']; ?>" class="product-card">
                        <div class="image">
                            <?php if ($product['image_url']): ?>
                                <img src="<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['product_name']); ?>" onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'fas fa-tshirt\'></i>'">
                            <?php else: ?>
                                <i class="fas fa-tshirt"></i>
                            <?php endif; ?>
                        </div>
                        <div class="info">
                            <h3><?php echo htmlspecialchars($product['product_name']); ?></h3>
                            <div class="price">R<?php echo number_format($product['price'], 2); ?></div>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="no-items">
                <i class="fas fa-tshirt"></i>
                <p>No products listed yet</p>
            </div>
        <?php endif; ?>
        
        <!-- Style Posts -->
        <?php if ($posts->num_rows > 0): ?>
            <h2 class="section-title" style="margin-top: 30px;">📸 Style Posts</h2>
            <div class="posts-grid">
                <?php while ($post = $posts->fetch_assoc()): ?>
                    <div class="post-item">
                        <img src="<?php echo htmlspecialchars($post['image_url']); ?>" alt="Style post" onerror="this.style.display='none'">
                        <div class="caption"><?php echo htmlspecialchars($post['caption'] ?? '#' . ($seller['shop_name'] ?? $seller['username'])); ?></div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
        
        <div style="margin-top: 30px; text-align: center;">
            <a href="products.php" style="display: inline-block; padding: 10px 24px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border-radius: 8px; text-decoration: none; font-weight: 600;">
                <i class="fas fa-store"></i> Browse All Products
            </a>
        </div>
    </div>
</body>
</html>