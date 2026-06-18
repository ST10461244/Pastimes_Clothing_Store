<?php
// style_feed.php - Instagram-style vertical scrolling feed
session_start();
include "DBConn.php";

$user_id = $_SESSION['user_id'] ?? 0;

// Check if user is logged in for like functionality
$is_logged_in = isset($_SESSION['user_id']);

// Get feed posts with like counts and user like status
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
    LIMIT 20
");

if (!$feed_query) {
    die("Error fetching feed: " . $connect->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Style Feed - Pastimes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            background: #fafafa; 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        
        .feed-container { 
            max-width: 600px; 
            margin: 0 auto; 
            padding: 0 0 60px 0;
        }
        
        /* Header */
        .feed-header {
            background: white;
            padding: 15px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid #dbdbdb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .feed-header h2 {
            font-size: 22px;
            font-weight: 600;
            color: #262626;
        }
        .feed-header h2 i { color: #667eea; }
        .feed-header .nav-links {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .feed-header .nav-links a {
            color: #262626;
            text-decoration: none;
            font-size: 20px;
        }
        .feed-header .nav-links a:hover { color: #667eea; }
        
        /* Post Card */
        .post-card {
            background: white;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .post-header {
            display: flex;
            align-items: center;
            padding: 12px 16px;
        }
        .post-header .avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            font-weight: 600;
            flex-shrink: 0;
        }
        .post-header .shop-info {
            margin-left: 12px;
            flex: 1;
        }
        .post-header .shop-name {
            font-weight: 600;
            color: #262626;
            font-size: 14px;
        }
        .post-header .shop-name a {
            text-decoration: none;
            color: #262626;
        }
        .post-header .shop-name a:hover { text-decoration: underline; }
        .post-header .post-time {
            font-size: 11px;
            color: #8e8e8e;
        }
        .post-header .post-actions-header {
            color: #8e8e8e;
            font-size: 16px;
        }
        
        .post-image {
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            background: #f0f0f0;
        }
        
        .post-actions {
            display: flex;
            padding: 8px 16px;
            gap: 16px;
        }
        .post-actions button {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #262626;
            transition: all 0.2s;
            padding: 4px;
        }
        .post-actions button:hover { transform: scale(1.1); }
        .post-actions .liked { color: #ed4956; }
        .post-actions .action-btn { font-size: 20px; }
        .post-actions .shop-btn {
            margin-left: auto;
            background: #667eea;
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
        }
        .post-actions .shop-btn:hover { background: #5a67d8; }
        
        .post-interactions {
            padding: 0 16px 6px;
            font-size: 14px;
            font-weight: 600;
            color: #262626;
        }
        
        .post-caption {
            padding: 0 16px 12px;
        }
        .post-caption .price {
            font-weight: 600;
            font-size: 16px;
            color: #2c3e50;
            margin-bottom: 2px;
        }
        .post-caption .caption-text {
            color: #262626;
            font-size: 14px;
        }
        .post-caption .caption-text .username {
            font-weight: 600;
            margin-right: 6px;
        }
        
        /* Load More */
        .load-more {
            text-align: center;
            padding: 30px 20px;
            color: #8e8e8e;
        }
        .load-more .spinner {
            display: inline-block;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .empty-feed {
            text-align: center;
            padding: 80px 20px;
            color: #8e8e8e;
        }
        .empty-feed i { font-size: 64px; color: #dbdbdb; margin-bottom: 20px; }
        .empty-feed h3 { font-size: 22px; color: #262626; margin-bottom: 8px; }
        
        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            border: none;
            box-shadow: 0 4px 12px rgba(102,126,234,0.4);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
            z-index: 50;
        }
        .fab:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(102,126,234,0.5);
        }
        
        @media (max-width: 640px) {
            .feed-container { padding: 0; }
            .post-card { border-radius: 0; margin-bottom: 15px; }
            .feed-header { padding: 12px 16px; }
        }
    </style>
</head>
<body>

    <div class="feed-container" id="feed">
        <!-- Header -->
        <div class="feed-header">
            <h2><i class="fas fa-store"></i> Style Feed</h2>
           <div class="nav-links">
    <a href="products.php" title="Shop"><i class="fas fa-shopping-bag"></i></a>
    <a href="negotiation.php" title="Negotiate"><i class="fas fa-handshake"></i></a>
    <a href="escrow_dashboard.php" title="Escrow"><i class="fas fa-shield-alt"></i></a>
    <a href="cart.php" title="Cart"><i class="fas fa-shopping-cart"></i></a>
    <?php if ($is_logged_in): ?>
        <a href="dashboard.php" title="Profile"><i class="fas fa-user"></i></a>
        <a href="login.php?logout=1" title="Logout" style="font-size: 16px; color: #e74c3c;"><i class="fas fa-sign-out-alt"></i></a>
    <?php endif; ?>
</div>
        </div>
        
        <!-- Feed Posts -->
        <?php if ($feed_query->num_rows > 0): ?>
            <?php while ($post = $feed_query->fetch_assoc()): ?>
            <div class="post-card" data-post-id="<?php echo $post['post_id']; ?>">
                <!-- Header -->
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
                
                <!-- Image -->
                <img src="<?php echo htmlspecialchars($post['image_url']); ?>" 
                     alt="Product" 
                     class="post-image" 
                     onerror="this.src='https://via.placeholder.com/600x600/eee/999?text=No+Image'">
                
                <!-- Actions -->
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
                
                <!-- Likes -->
                <div class="post-interactions">
                    ❤️ <span id="likes-<?php echo $post['post_id']; ?>"><?php echo $post['like_count']; ?></span> likes
                </div>
                
                <!-- Caption -->
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
            <?php endwhile; ?>
            
            <!-- Load More -->
            <div class="load-more" id="loadMore">
                <span class="spinner" id="loading" style="display: none;"><i class="fas fa-spinner"></i></span>
                <span id="load-more-text">Scroll for more...</span>
            </div>
            
        <?php else: ?>
            <div class="empty-feed">
                <i class="fas fa-images"></i>
                <h3>No posts yet</h3>
                <p style="font-size: 14px;">Sellers haven't shared any style posts yet.</p>
                <a href="products.php" style="display: inline-block; margin-top: 20px; padding: 10px 24px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border-radius: 8px; text-decoration: none; font-weight: 600;">Start Shopping</a>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Floating Action Button -->
    <a href="shop_customize.php" class="fab" title="Share your style">
        <i class="fas fa-plus"></i>
    </a>

    <script>
        let isLoading = false;
        let offset = <?php echo $feed_query->num_rows; ?>;
        let hasMore = true;
        
        // Like/Unlike function
        function toggleLike(postId) {
            <?php if (!$is_logged_in): ?>
                window.location.href = 'login.php';
                return;
            <?php endif; ?>
            
            fetch('api/like.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'post_id=' + postId
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    return;
                }
                const likeBtn = document.getElementById('like-' + postId);
                const likesSpan = document.getElementById('likes-' + postId);
                
                if (data.liked) {
                    likeBtn.querySelector('i').classList.add('liked');
                } else {
                    likeBtn.querySelector('i').classList.remove('liked');
                }
                likesSpan.textContent = data.like_count;
            })
            .catch(error => console.error('Error:', error));
        }
        
        // Share function
        function sharePost(imageUrl) {
            if (navigator.share) {
                navigator.share({
                    title: 'Check out this style!',
                    text: 'I found this amazing piece on Pastimes Clothing Store!',
                    url: window.location.href
                }).catch(() => {});
            } else {
                // Fallback: copy to clipboard
                const dummy = document.createElement('input');
                document.body.appendChild(dummy);
                dummy.value = window.location.href;
                dummy.select();
                document.execCommand('copy');
                document.body.removeChild(dummy);
                alert('Link copied to clipboard! Share it with your friends.');
            }
        }
        
        // Infinite scroll
        window.addEventListener('scroll', function() {
            if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 500) {
                if (!isLoading && hasMore) {
                    isLoading = true;
                    document.getElementById('loading').style.display = 'inline-block';
                    document.getElementById('load-more-text').textContent = 'Loading...';
                    
                    fetch('api/feed.php?offset=' + offset)
                        .then(response => response.text())
                        .then(html => {
                            if (html.trim()) {
                                // Insert new posts before the load more div
                                const loadMoreDiv = document.getElementById('loadMore');
                                loadMoreDiv.insertAdjacentHTML('beforebegin', html);
                                
                                // Count new posts added
                                const newPosts = html.match(/<div class="post-card/g);
                                offset += newPosts ? newPosts.length : 0;
                                
                                document.getElementById('loading').style.display = 'none';
                                document.getElementById('load-more-text').textContent = 'Scroll for more...';
                            } else {
                                hasMore = false;
                                document.getElementById('load-more-text').textContent = '🎉 You\'ve seen it all!';
                                document.getElementById('loading').style.display = 'none';
                            }
                            isLoading = false;
                        })
                        .catch(() => {
                            document.getElementById('loading').style.display = 'none';
                            document.getElementById('load-more-text').textContent = 'Error loading. Try again.';
                            isLoading = false;
                        });
                }
            }
        });
        
        // Time ago function (PHP equivalent in JS)
        function timeAgo(timestamp) {
            const now = new Date();
            const diff = Math.floor((now - timestamp) / 1000);
            
            const intervals = [
                { label: 'year', seconds: 31536000 },
                { label: 'month', seconds: 2592000 },
                { label: 'week', seconds: 604800 },
                { label: 'day', seconds: 86400 },
                { label: 'hour', seconds: 3600 },
                { label: 'minute', seconds: 60 }
            ];
            
            for (const interval of intervals) {
                const count = Math.floor(diff / interval.seconds);
                if (count > 0) {
                    return count + ' ' + interval.label + (count > 1 ? 's' : '') + ' ago';
                }
            }
            return 'just now';
        }
    </script>
</body>
</html>

<?php
// Helper function for time ago
function time_ago($timestamp) {
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