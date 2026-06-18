<?php
// shop_customize.php - Sellers can customize their shop
session_start();
include "DBConn.php";

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Check if user is a seller
$user_query = $connect->prepare("SELECT user_role, is_verified_seller FROM users WHERE user_id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_data = $user_query->get_result()->fetch_assoc();
$user_query->close();

if ($user_data['user_role'] != 'seller') {
    header('Location: dashboard.php');
    exit();
}

// Handle shop update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_shop'])) {
    $shop_name = trim($_POST['shop_name']);
    $shop_description = trim($_POST['shop_description']);
    $custom_url = trim($_POST['custom_url']);
    
    // Validate custom URL
    $custom_url = preg_replace('/[^a-zA-Z0-9_]/', '', $custom_url);
    $custom_url = strtolower($custom_url);
    
    if (empty($shop_name)) {
        $message = "Shop name is required";
        $message_type = "error";
    } elseif (empty($custom_url)) {
        $message = "Custom URL is required";
        $message_type = "error";
    } else {
        // Check if URL is taken
        $url_check = $connect->prepare("SELECT user_id FROM users WHERE custom_url = ? AND user_id != ?");
        $url_check->bind_param("si", $custom_url, $user_id);
        $url_check->execute();
        $url_check->store_result();
        
        if ($url_check->num_rows > 0) {
            $message = "This URL is already taken. Please choose another.";
            $message_type = "error";
        } else {
            $update_stmt = $connect->prepare("
                UPDATE users SET 
                    shop_name = ?, 
                    shop_description = ?, 
                    custom_url = ?,
                    is_verified_seller = 1
                WHERE user_id = ?
            ");
            $update_stmt->bind_param("sssi", $shop_name, $shop_description, $custom_url, $user_id);
            
            if ($update_stmt->execute()) {
                $message = "✅ Shop updated successfully! Your shop is now live at: /seller_profile.php?url=" . $custom_url;
                $message_type = "success";
            } else {
                $message = "Error updating shop: " . $connect->error;
                $message_type = "error";
            }
            $update_stmt->close();
        }
        $url_check->close();
    }
}

// Handle style post creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_post'])) {
    $caption = trim($_POST['caption']);
    $product_id = intval($_POST['product_id'] ?? 0);
    $image_path = null;
    
    // Handle image upload
    if (isset($_FILES['post_image']) && $_FILES['post_image']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['post_image'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $image_info = getimagesize($file['tmp_name']);
        
        if ($image_info && in_array($image_info['mime'], $allowed_types)) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('post_', true) . '.' . $ext;
            $upload_dir = __DIR__ . '/uploads/posts';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $destination = $upload_dir . '/' . $filename;
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $image_path = 'uploads/posts/' . $filename;
            } else {
                $message = "Failed to upload image";
                $message_type = "error";
            }
        } else {
            $message = "Invalid image format. Please use JPG, PNG, GIF, or WEBP.";
            $message_type = "error";
        }
    } else {
        $message = "Please select an image for your post";
        $message_type = "error";
    }
    
    if ($image_path) {
        $insert_stmt = $connect->prepare("
            INSERT INTO style_posts (seller_id, product_id, image_url, caption) 
            VALUES (?, ?, ?, ?)
        ");
        $insert_stmt->bind_param("iiss", $user_id, $product_id, $image_path, $caption);
        
        if ($insert_stmt->execute()) {
            $message = "✅ Style post created successfully!";
            $message_type = "success";
        } else {
            $message = "Error creating post: " . $connect->error;
            $message_type = "error";
        }
        $insert_stmt->close();
    }
}

// Get current user data
$current_user_query = $connect->prepare("SELECT * FROM users WHERE user_id = ?");
$current_user_query->bind_param("i", $user_id);
$current_user_query->execute();
$current_user = $current_user_query->get_result()->fetch_assoc();
$current_user_query->close();

// Get seller's products for post creation
$products_query = $connect->prepare("SELECT product_id, product_name FROM clothes WHERE seller_id = ?");
$products_query->bind_param("i", $user_id);
$products_query->execute();
$seller_products = $products_query->get_result();
$products_query->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Customize - Pastimes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        
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
        .header-links a { color: rgba(255,255,255,0.85); text-decoration: none; margin-left: 15px; }
        .header-links a:hover { color: white; }
        
        .message { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .section h2 { color: #333; margin-bottom: 15px; font-size: 20px; }
        .section p { color: #666; font-size: 14px; margin-bottom: 15px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; color: #555; margin-bottom: 5px; font-size: 14px; }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border 0.2s;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        
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
        .btn-outline { background: white; color: #667eea; border: 2px solid #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        
        .url-preview {
            background: #f8f9fa;
            padding: 10px 14px;
            border-radius: 8px;
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        .url-preview strong { color: #333; }
        
        @media (max-width: 640px) {
            .header { padding: 12px 16px; }
            .section { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-store"></i> Shop Customize</h1>
            <div class="header-links">
                <a href="seller_dashboard.php">Dashboard</a>
                <a href="products.php">Shop</a>
                <a href="login.php?logout=1" style="color: #ff6b6b;">Logout</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <!-- Shop Settings -->
        <div class="section">
            <h2>🏪 Shop Settings</h2>
            <p>Customize your shop profile. This is what buyers will see when they visit your shop.</p>
            
            <form method="POST">
                <div class="form-group">
                    <label>Shop Name *</label>
                    <input type="text" name="shop_name" value="<?php echo htmlspecialchars($current_user['shop_name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Shop Description</label>
                    <textarea name="shop_description" rows="3"><?php echo htmlspecialchars($current_user['shop_description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Custom URL *</label>
                    <input type="text" name="custom_url" value="<?php echo htmlspecialchars($current_user['custom_url'] ?? ''); ?>" required placeholder="your-shop-name">
                    <div class="url-preview">
                        🔗 Your shop URL: <strong><?php echo 'http://localhost/pre_owned_clothing_store/seller_profile.php?url=' . htmlspecialchars($current_user['custom_url'] ?? 'your-shop-name'); ?></strong>
                    </div>
                </div>
                
                <button type="submit" name="update_shop" class="btn btn-primary"><i class="fas fa-save"></i> Save Shop</button>
            </form>
        </div>
        
        <!-- Create Style Post -->
        <div class="section">
            <h2>📸 Create Style Post</h2>
            <p>Share your products with the community. Posts appear in the Style Feed.</p>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Product (optional)</label>
                    <select name="product_id">
                        <option value="0">No product linked</option>
                        <?php while ($product = $seller_products->fetch_assoc()): ?>
                            <option value="<?php echo $product['product_id']; ?>">
                                <?php echo htmlspecialchars($product['product_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Image *</label>
                    <input type="file" name="post_image" accept="image/jpeg,image/png,image/gif,image/webp" required>
                </div>
                
                <div class="form-group">
                    <label>Caption</label>
                    <textarea name="caption" rows="2" placeholder="Describe your style..."></textarea>
                </div>
                
                <button type="submit" name="create_post" class="btn btn-success"><i class="fas fa-plus"></i> Create Post</button>
            </form>
        </div>
        
        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px;">
            <a href="style_feed.php" class="btn btn-primary"><i class="fas fa-images"></i> View Style Feed</a>
            <a href="seller_dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </div>
</body>
</html>