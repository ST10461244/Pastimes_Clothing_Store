<?php
// api/feed.php - Load more posts for infinite scroll
session_start();
include "../DBConn.php";

$offset = intval($_GET['offset'] ?? 0);
$limit = 5;
$user_id = $_SESSION['user_id'] ?? 0;

$feed_query = $connect->query("
    SELECT sp.*, 
           u.username, u.name, u.surname, u.shop_name, u.custom_url, u.shop_logo,
           c.product_name, c.price,
           (SELECT COUNT(*) FROM product_interactions WHERE product_id = sp.product_id AND action = 'like') as like_count,
           (SELECT COUNT(*) FROM product_interactions WHERE product_id = sp.product_id AND user_id = $user_id AND action = 'like') as user_liked
    FROM style_posts sp
    JOIN users u ON sp.seller_id = u.user_id
    LEFT JOIN clothes c ON sp.product_id = c.product_id
    WHERE sp.image_url IS NOT NULL
    ORDER BY sp.created_at DESC
    LIMIT $offset, $limit
");

if ($feed_query->num_rows == 0) {
    exit(); // No more posts
}

while ($post = $feed_query->fetch_assoc()):
?>

    <div class="post-card" data-post-id="<?php echo $post['post_id']; ?>">
        <div class="post-header">
            <div class="avatar">
                <?php echo strtoupper(substr($post['shop_name'] ?? $post['username'], 0, 1)); ?>
            </div>
            <div class="shop-info">
                <div class="shop-name">
                    <a href="seller_profile.php?url=<?php echo $post['custom_url'] ?? $post['username']; ?>">
                        <?php echo htmlspecialchars($post['shop_name'] ?? $post['username']); ?>
                    </a>
                </div>
                <div class="post-time"><?php echo time_ago(strtotime($post['created_at'])); ?></div>
            </div>
            <div class="post-actions-header">
                <i class="fas fa-ellipsis-h"></i>
            </div>
        </div>

        <img src="<?php echo htmlspecialchars($post['image_url']); ?>"
            alt="Product"
            class="post-image"
            onerror="this.src='https://via.placeholder.com/600x600/eee/999?text=No+Image'">

        <div class="post-actions">
            <button onclick="toggleLike(<?php echo $post['post_id']; ?>)" id="like-<?php echo $post['post_id']; ?>">
                <i class="fas fa-heart <?php echo $post['user_liked'] ? 'liked' : ''; ?>"></i>
            </button>
            <button onclick="window.location.href='product_detail.php?id=<?php echo $post['product_id']; ?>'">
                <i class="fas fa-comment action-btn"></i>
            </button>
            <button onclick="sharePost('<?php echo htmlspecialchars($post['image_url']); ?>')">
                <i class="fas fa-paper-plane action-btn"></i>
            </button>
            <button class="shop-btn" onclick="window.location.href='product_detail.php?id=<?php echo $post['product_id']; ?>'">
                <i class="fas fa-shopping-bag"></i> View
            </button>
        </div>

        <div class="post-interactions">
            ❤️ <span id="likes-<?php echo $post['post_id']; ?>"><?php echo $post['like_count']; ?></span> likes
        </div>

        <div class="post-caption">
            <?php if ($post['price']): ?>
                <div class="price">R<?php echo number_format($post['price'], 2); ?></div>
            <?php endif; ?>
            <div class="caption-text">
                <span class="username"><?php echo htmlspecialchars($post['username']); ?></span>
                <?php echo htmlspecialchars($post['caption'] ?? ''); ?>
            </div>
        </div>
    </div>

<?php
endwhile;

// Helper function
function time_ago($timestamp)
{
    $diff = time() - $timestamp;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    if ($diff < 2592000) return floor($diff / 604800) . 'w ago';
    if ($diff < 31536000) return floor($diff / 2592000) . 'mo ago';
    return date('M d, Y', $timestamp);
}
?>