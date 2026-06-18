<?php
session_start();
include "DBConn.php";
include "image_upload.php";

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Must be a seller
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'seller') {
    header('Location: dashboard.php');
    exit();
}

$seller_id = $_SESSION['user_id'];

// Schema check: seller_id must exist on clothes, otherwise every query below will fail
$schema_check = $connect->query("SHOW COLUMNS FROM clothes LIKE 'seller_id'");
if (!$schema_check || $schema_check->num_rows === 0) {
    die("Database error: the <code>clothes</code> table is missing the <code>seller_id</code> column. Please ask an administrator to run <a href='alter_database.php'>alter_database.php</a> once, then reload this page.");
}

$message = '';
$message_type = '';

// Handle Add Clothing Item
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $product_name   = trim($_POST['product_name']);
    $category       = trim($_POST['category']);
    $subcategory    = trim($_POST['subcategory']);
    $price          = trim($_POST['price']);
    $on_sale        = isset($_POST['on_sale']) ? 1 : 0;
    $sale_price     = trim($_POST['sale_price']);
    $stock_quantity = trim($_POST['stock_quantity']);
    $description    = trim($_POST['description']);

    $errors = [];

    if (empty($product_name)) $errors[] = "Product name is required";
    if ($price === '' || !is_numeric($price) || $price < 0) $errors[] = "A valid price is required";
    if ($stock_quantity === '' || !ctype_digit((string)$stock_quantity)) $errors[] = "A valid stock quantity is required";
    if ($on_sale && ($sale_price === '' || !is_numeric($sale_price) || $sale_price < 0)) {
        $errors[] = "A valid sale price is required when the item is on sale";
    }

    $image_path = null;
    try {
        $image_path = handle_clothing_image_upload('image_file');
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }

    if (empty($errors)) {
        $sale_price_val = ($on_sale && $sale_price !== '') ? $sale_price : null;

        $insert_stmt = $connect->prepare("INSERT INTO clothes (product_name, category, subcategory, price, on_sale, sale_price, stock_quantity, description, image_url, seller_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("sssdidissi", $product_name, $category, $subcategory, $price, $on_sale, $sale_price_val, $stock_quantity, $description, $image_path, $seller_id);

        if ($insert_stmt->execute()) {
            $message = "✅ Clothing item added successfully!";
            $message_type = "success";
        } else {
            $message = "Error adding item: " . $connect->error;
            $message_type = "error";
        }
        $insert_stmt->close();
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// Handle Update Clothing Item (only own items)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_product'])) {
    $product_id     = intval($_POST['product_id']);
    $product_name   = trim($_POST['product_name']);
    $category       = trim($_POST['category']);
    $subcategory    = trim($_POST['subcategory']);
    $price          = trim($_POST['price']);
    $on_sale        = isset($_POST['on_sale']) ? 1 : 0;
    $sale_price     = trim($_POST['sale_price']);
    $stock_quantity = trim($_POST['stock_quantity']);
    $description    = trim($_POST['description']);

    $errors = [];

    if (empty($product_name)) $errors[] = "Product name is required";
    if ($price === '' || !is_numeric($price) || $price < 0) $errors[] = "A valid price is required";
    if ($stock_quantity === '' || !ctype_digit((string)$stock_quantity)) $errors[] = "A valid stock quantity is required";
    if ($on_sale && ($sale_price === '' || !is_numeric($sale_price) || $sale_price < 0)) {
        $errors[] = "A valid sale price is required when the item is on sale";
    }

    // Look up the current image, scoped to this seller's own item, so it's preserved if no new file is uploaded
    $current_image = null;
    $existing_stmt = $connect->prepare("SELECT image_url FROM clothes WHERE product_id = ? AND seller_id = ?");
    $existing_stmt->bind_param("ii", $product_id, $seller_id);
    $existing_stmt->execute();
    $existing_row = $existing_stmt->get_result()->fetch_assoc();
    $existing_stmt->close();
    if ($existing_row) {
        $current_image = $existing_row['image_url'];
    }

    $image_path = $current_image;
    try {
        $image_path = handle_clothing_image_upload('image_file', $current_image);
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }

    if (empty($errors)) {
        $sale_price_val = ($on_sale && $sale_price !== '') ? $sale_price : null;

        // seller_id is included in the WHERE clause so a seller cannot edit another seller's item
        $update_stmt = $connect->prepare("UPDATE clothes SET product_name = ?, category = ?, subcategory = ?, price = ?, on_sale = ?, sale_price = ?, stock_quantity = ?, description = ?, image_url = ? WHERE product_id = ? AND seller_id = ?");
        $update_stmt->bind_param("sssdidissii", $product_name, $category, $subcategory, $price, $on_sale, $sale_price_val, $stock_quantity, $description, $image_path, $product_id, $seller_id);

        if ($update_stmt->execute()) {
            if ($update_stmt->affected_rows > 0) {
                // Clean up the old file from disk if it was just replaced
                if ($image_path !== $current_image && $current_image && strpos($current_image, 'uploads/products/') === 0) {
                    $old_file = __DIR__ . '/' . $current_image;
                    if (is_file($old_file)) {
                        @unlink($old_file);
                    }
                }
                $message = "✏️ Clothing item updated successfully!";
                $message_type = "success";
            } else {
                $message = "Item not found or you don't have permission to edit it.";
                $message_type = "error";
            }
        } else {
            $message = "Error updating item: " . $connect->error;
            $message_type = "error";
        }
        $update_stmt->close();
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// Handle Delete Clothing Item (only own items)
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);

    // Look up the image first (scoped to this seller) so we can remove it from disk too
    $img_stmt = $connect->prepare("SELECT image_url FROM clothes WHERE product_id = ? AND seller_id = ?");
    $img_stmt->bind_param("ii", $delete_id, $seller_id);
    $img_stmt->execute();
    $img_row = $img_stmt->get_result()->fetch_assoc();
    $img_stmt->close();

    $delete_stmt = $connect->prepare("DELETE FROM clothes WHERE product_id = ? AND seller_id = ?");
    $delete_stmt->bind_param("ii", $delete_id, $seller_id);
    if ($delete_stmt->execute()) {
        if ($delete_stmt->affected_rows > 0) {
            if ($img_row && $img_row['image_url'] && strpos($img_row['image_url'], 'uploads/products/') === 0) {
                $file_to_delete = __DIR__ . '/' . $img_row['image_url'];
                if (is_file($file_to_delete)) {
                    @unlink($file_to_delete);
                }
            }
            $message = "🗑️ Clothing item deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Item not found or you don't have permission to delete it.";
            $message_type = "error";
        }
    } else {
        $message = "Error deleting item: " . $connect->error;
        $message_type = "error";
    }
    $delete_stmt->close();
}

// Fetch item for editing (if edit clicked, and it belongs to this seller)
$edit_product = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $edit_query = $connect->prepare("SELECT * FROM clothes WHERE product_id = ? AND seller_id = ?");
    $edit_query->bind_param("ii", $edit_id, $seller_id);
    $edit_query->execute();
    $edit_result = $edit_query->get_result();
    $edit_product = $edit_result->fetch_assoc();
    $edit_query->close();
}

// Fetch only this seller's clothing items
$products_stmt = $connect->prepare("SELECT * FROM clothes WHERE seller_id = ? ORDER BY product_id DESC");
$products_stmt->bind_param("i", $seller_id);
$products_stmt->execute();
$products_result = $products_stmt->get_result();

// Fetch ratings left on this seller's products
$ratings_table_check = $connect->query("SHOW TABLES LIKE 'ratings'");
$ratings_list = [];
$avg_rating = null;
$rating_count = 0;
if ($ratings_table_check && $ratings_table_check->num_rows > 0) {
    $ratings_stmt = $connect->prepare("
        SELECT r.rating, r.comment, r.created_at, ol.product_name, u.username AS buyer_username
        FROM ratings r
        JOIN order_lines ol ON r.line_id = ol.line_id
        JOIN users u ON r.user_id = u.user_id
        WHERE r.seller_id = ?
        ORDER BY r.created_at DESC");
    $ratings_stmt->bind_param("i", $seller_id);
    $ratings_stmt->execute();
    $ratings_list = $ratings_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ratings_stmt->close();

    $rating_count = count($ratings_list);
    if ($rating_count > 0) {
        $avg_rating = array_sum(array_column($ratings_list, 'rating')) / $rating_count;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Dashboard - Pastimes</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }

        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .admin-header {
            background: linear-gradient(135deg, #6f42c1 0%, #4a2a8f 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .admin-header h1 {
            font-size: 24px;
        }

        .admin-header p {
            color: #ddd;
            margin-top: 5px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .nav-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            transition: transform 0.2s;
        }

        .nav-btn:hover {
            transform: scale(1.05);
            background: rgba(255,255,255,0.25);
        }

        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            transition: transform 0.2s;
        }

        .logout-btn:hover {
            transform: scale(1.05);
        }

        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .section h2 {
            color: #1a1a2e;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-row input[type="checkbox"] {
            width: auto;
        }

        input, textarea, select {
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            width: 100%;
            box-sizing: border-box;
        }

        textarea {
            resize: vertical;
        }

        button {
            background: linear-gradient(135deg, #6f42c1 0%, #4a2a8f 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }

        button:hover {
            opacity: 0.9;
        }

        .btn-danger {
            background: #e74c3c;
        }

        .btn-warning {
            background: #f39c12;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .thumb {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            background: #f0f0f0;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-sale {
            background: #e74c3c;
            color: white;
        }

        .badge-stock-ok {
            background: #27ae60;
            color: white;
        }

        .badge-stock-low {
            background: #f39c12;
            color: white;
        }

        .badge-stock-out {
            background: #e74c3c;
            color: white;
        }

        .action-buttons a, .action-buttons button {
            display: inline-block;
            padding: 6px 12px;
            margin: 2px;
            font-size: 12px;
            text-decoration: none;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            color: white;
        }

        .price-strike {
            text-decoration: line-through;
            color: #999;
            font-size: 12px;
            margin-right: 6px;
        }

        .modal {
            display: flex;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
            overflow-y: auto;
            padding: 20px;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            width: 600px;
            max-width: 90%;
            margin: auto;
        }

        .modal-content h3 {
            margin-bottom: 20px;
        }

        .close-modal {
            float: right;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }

        .close-modal:hover {
            color: #333;
        }

        .rating-summary {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 15px;
            background: #fff9e6;
            border-radius: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .rating-summary .avg-score {
            font-size: 32px;
            font-weight: 700;
            color: #d99c00;
        }

        .rating-summary .avg-stars {
            color: #f5b301;
            font-size: 20px;
            letter-spacing: 2px;
        }

        .rating-summary .count {
            color: #888;
            font-size: 13px;
        }

        .rating-card {
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 10px;
        }

        .rating-card .stars-display {
            color: #f5b301;
            font-size: 16px;
            letter-spacing: 1px;
        }

        .rating-card .rating-meta {
            font-size: 12px;
            color: #999;
            margin: 4px 0 6px;
        }

        .rating-card .rating-comment {
            font-size: 14px;
            color: #444;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <div>
                <h1>🏷️ Seller Dashboard</h1>
                <p>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username']); ?> — manage your own clothing listings</p>
            </div>
            <div class="header-actions">
    <a href="dashboard.php" class="nav-btn">← My Profile</a>
    <a href="style_feed.php" class="nav-btn">Feed</a>
    <a href="negotiation.php" class="nav-btn">Negotiate</a>
    <a href="escrow_dashboard.php" class="nav-btn">Escrow</a>
    <a href="products.php" class="nav-btn">🛍️ Shop</a>
    <a href="login.php?logout=1" class="logout-btn">Logout</a>
</div>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Add Clothing Item Form -->
        <div class="section">
            <h2>Add New Clothing Item</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <input type="text" name="product_name" placeholder="Product Name *" required>
                    <input type="text" name="category" placeholder="Category (e.g. Shirts)">
                    <input type="text" name="subcategory" placeholder="Subcategory (e.g. T-Shirts)">
                    <input type="number" step="0.01" min="0" name="price" placeholder="Price (R) *" required>
                    <input type="number" min="0" name="stock_quantity" placeholder="Stock Quantity *" required>
                    <div>
                        <label style="font-size:13px; color:#555; display:block; margin-bottom:4px;">Product Image</label>
                        <input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp">
                    </div>
                    <input type="number" step="0.01" min="0" name="sale_price" placeholder="Sale Price (R)">
                    <div class="checkbox-row">
                        <input type="checkbox" id="add_on_sale" name="on_sale" value="1">
                        <label for="add_on_sale">On Sale</label>
                    </div>
                    <textarea name="description" placeholder="Description" rows="2"></textarea>
                </div>
                <button type="submit" name="add_product" style="margin-top: 15px;">Add Item</button>
            </form>
        </div>

        <!-- Edit Clothing Item Modal -->
        <?php if ($edit_product): ?>
        <div class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="window.location.href='seller_dashboard.php'">&times;</span>
                <h3>Edit Clothing Item</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="product_id" value="<?php echo $edit_product['product_id']; ?>">
                    <div class="form-grid">
                        <input type="text" name="product_name" value="<?php echo htmlspecialchars($edit_product['product_name']); ?>" placeholder="Product Name *" required>
                        <input type="text" name="category" value="<?php echo htmlspecialchars($edit_product['category'] ?? ''); ?>" placeholder="Category">
                        <input type="text" name="subcategory" value="<?php echo htmlspecialchars($edit_product['subcategory'] ?? ''); ?>" placeholder="Subcategory">
                        <input type="number" step="0.01" min="0" name="price" value="<?php echo htmlspecialchars($edit_product['price']); ?>" placeholder="Price (R) *" required>
                        <input type="number" min="0" name="stock_quantity" value="<?php echo htmlspecialchars($edit_product['stock_quantity']); ?>" placeholder="Stock Quantity *" required>
                        <div>
                            <label style="font-size:13px; color:#555; display:block; margin-bottom:4px;">
                                Product Image <?php echo $edit_product['image_url'] ? '(leave blank to keep current)' : ''; ?>
                            </label>
                            <?php if ($edit_product['image_url']): ?>
                                <img src="<?php echo htmlspecialchars($edit_product['image_url']); ?>" class="thumb" style="margin-bottom:6px;" alt="">
                            <?php endif; ?>
                            <input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp">
                        </div>
                        <input type="number" step="0.01" min="0" name="sale_price" value="<?php echo htmlspecialchars($edit_product['sale_price'] ?? ''); ?>" placeholder="Sale Price (R)">
                        <div class="checkbox-row">
                            <input type="checkbox" id="edit_on_sale" name="on_sale" value="1" <?php echo $edit_product['on_sale'] ? 'checked' : ''; ?>>
                            <label for="edit_on_sale">On Sale</label>
                        </div>
                        <textarea name="description" placeholder="Description" rows="3"><?php echo htmlspecialchars($edit_product['description'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" name="update_product" style="margin-top: 15px;">Save Changes</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- This Seller's Clothing Items Table -->
        <div class="section">
            <h2>My Clothing Listings</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($products_result->num_rows === 0): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; color:#999; padding: 30px;">You haven't listed any items yet. Add one above to get started.</td>
                        </tr>
                        <?php endif; ?>
                        <?php while ($p = $products_result->fetch_assoc()): ?>
                        <?php
                            $stock = (int)$p['stock_quantity'];
                            $stock_class = $stock == 0 ? 'badge-stock-out' : ($stock <= 5 ? 'badge-stock-low' : 'badge-stock-ok');
                            $img = htmlspecialchars($p['image_url'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <?php if ($img): ?>
                                    <img class="thumb" src="<?php echo $img; ?>" alt="" onerror="this.style.visibility='hidden'">
                                <?php else: ?>
                                    <div class="thumb"></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $p['product_id']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($p['product_name']); ?>
                                <?php if ($p['on_sale']): ?>
                                    <span class="badge badge-sale">Sale</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($p['category'] . ($p['subcategory'] ? ' / ' . $p['subcategory'] : '')); ?></td>
                            <td>
                                <?php if ($p['on_sale'] && $p['sale_price']): ?>
                                    <span class="price-strike">R<?php echo number_format($p['price'], 2); ?></span>
                                    R<?php echo number_format($p['sale_price'], 2); ?>
                                <?php else: ?>
                                    R<?php echo number_format($p['price'], 2); ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo $stock_class; ?>"><?php echo $stock; ?></span></td>
                            <td class="action-buttons">
                                <a href="?edit_id=<?php echo $p['product_id']; ?>" class="btn-warning">✏️ Edit</a>
                                <a href="?delete_id=<?php echo $p['product_id']; ?>" class="btn-danger" onclick="return confirm('Delete this item permanently?')">🗑️ Delete</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- My Ratings -->
        <div class="section">
            <h2>⭐ Ratings from Buyers</h2>
            <?php if ($rating_count > 0): ?>
                <div class="rating-summary">
                    <div class="avg-score"><?php echo number_format($avg_rating, 1); ?></div>
                    <div>
                        <div class="avg-stars"><?php echo str_repeat('★', round($avg_rating)) . str_repeat('☆', 5 - round($avg_rating)); ?></div>
                        <div class="count"><?php echo $rating_count; ?> rating<?php echo $rating_count == 1 ? '' : 's'; ?></div>
                    </div>
                </div>
                <?php foreach ($ratings_list as $r): ?>
                    <div class="rating-card">
                        <div class="stars-display"><?php echo str_repeat('★', $r['rating']) . str_repeat('☆', 5 - $r['rating']); ?></div>
                        <div class="rating-meta">
                            <?php echo htmlspecialchars($r['product_name']); ?> &middot;
                            <?php echo htmlspecialchars($r['buyer_username']); ?> &middot;
                            <?php echo date('d M Y', strtotime($r['created_at'])); ?>
                        </div>
                        <?php if ($r['comment']): ?>
                            <div class="rating-comment">"<?php echo htmlspecialchars($r['comment']); ?>"</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color:#999; padding: 10px 0;">No ratings yet. Once buyers rate items they bought from you, their feedback will show up here.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>