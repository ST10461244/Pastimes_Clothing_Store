<?php
// user_score.php - User Score Dashboard
session_start();
include "DBConn.php";

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// ============================================
// SCORE CALCULATION ENGINE
// ============================================

function calculateUserScore($user_id, $connect) {
    // Get user metrics
    $metrics = $connect->query("
        SELECT 
            COUNT(DISTINCT CASE WHEN o.order_status = 'delivered' AND o.buyer_confirmed = 1 THEN o.order_id END) as successful_sales,
            COUNT(DISTINCT CASE WHEN r.rating >= 4 THEN r.rating_id END) as positive_ratings,
            COUNT(DISTINCT r.rating_id) as total_ratings,
            AVG(r.rating) as avg_rating,
            COUNT(DISTINCT CASE WHEN n.status = 'accepted' THEN n.negotiation_id END) as successful_offers,
            AVG(TIMESTAMPDIFF(HOUR, o.order_date, o.delivery_confirmed_at)) as avg_delivery_hours
        FROM users u
        LEFT JOIN orders o ON u.user_id = o.user_id
        LEFT JOIN ratings r ON u.user_id = r.seller_id
        LEFT JOIN negotiations n ON u.user_id = n.buyer_id AND n.status = 'accepted'
        WHERE u.user_id = $user_id
    ");
    
    $data = $metrics->fetch_assoc();
    
    // Calculate score components
    $score = 0;
    
    // Sales: up to 200 points (10 points per sale)
    $sales_score = min(($data['successful_sales'] ?? 0) * 10, 200);
    $score += $sales_score;
    
    // Positive ratings: up to 100 points (5 points per rating)
    $rating_score = min(($data['positive_ratings'] ?? 0) * 5, 100);
    $score += $rating_score;
    
    // Average rating quality: up to 100 points
    $avg_rating = $data['avg_rating'] ?? 0;
    $quality_score = min($avg_rating * 20, 100);
    $score += $quality_score;
    
    // Successful offers: up to 150 points (15 points per offer)
    $offer_score = min(($data['successful_offers'] ?? 0) * 15, 150);
    $score += $offer_score;
    
    // Bonus for fast delivery (under 48 hours)
    if (!empty($data['avg_delivery_hours']) && $data['avg_delivery_hours'] < 48) {
        $score += 50;
    }
    
    return min($score, 500);
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function getCommissionRate($score) {
    if ($score >= 501) return 3.00;
    if ($score >= 301) return 5.50;
    if ($score >= 151) return 7.00;
    if ($score >= 51) return 8.50;
    return 10.00;
}

function getTier($score) {
    if ($score >= 501) return ['Diamond', '💎', '#00d4ff', 'Elite status, 3% commission'];
    if ($score >= 301) return ['Platinum', '💠', '#e5e4e2', 'Premium seller, 5.5% commission'];
    if ($score >= 151) return ['Gold', '🥇', '#ffd700', 'Trusted seller, 7% commission'];
    if ($score >= 51) return ['Silver', '🥈', '#c0c0c0', 'Verified seller, 8.5% commission'];
    return ['Bronze', '🥉', '#cd7f32', 'New seller, 10% commission'];
}

function getNextTier($score) {
    $tiers = [
        51 => 'Silver',
        151 => 'Gold',
        301 => 'Platinum',
        501 => 'Diamond'
    ];
    
    foreach ($tiers as $threshold => $tier_name) {
        if ($score < $threshold) {
            return ['name' => $tier_name, 'points_needed' => $threshold - $score];
        }
    }
    return ['name' => 'Max Level!', 'points_needed' => 0];
}

// ============================================
// CALCULATE AND UPDATE SCORE
// ============================================

$score = calculateUserScore($user_id, $connect);
$commission = getCommissionRate($score);
$tier = getTier($score);
$next_tier = getNextTier($score);

// Update database
$update_stmt = $connect->prepare("
    INSERT INTO user_scores (user_id, total_score, commission_rate, tier, updated_at) 
    VALUES (?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE 
        total_score = VALUES(total_score),
        commission_rate = VALUES(commission_rate),
        tier = VALUES(tier),
        updated_at = NOW()
");
$update_stmt->bind_param("idss", $user_id, $score, $commission, $tier[0]);
$update_stmt->execute();
$update_stmt->close();

// Get score data
$score_data = $connect->query("SELECT * FROM user_scores WHERE user_id = $user_id")->fetch_assoc();

// Get detailed metrics
$metrics_query = $connect->query("
    SELECT 
        COUNT(DISTINCT CASE WHEN order_status = 'delivered' AND buyer_confirmed = 1 THEN order_id END) as sales_count,
        COUNT(DISTINCT r.rating_id) as rating_count,
        AVG(r.rating) as avg_rating,
        COUNT(DISTINCT CASE WHEN n.status = 'accepted' THEN n.negotiation_id END) as offers_accepted
    FROM users u
    LEFT JOIN orders o ON u.user_id = o.user_id
    LEFT JOIN ratings r ON u.user_id = r.seller_id
    LEFT JOIN negotiations n ON u.user_id = n.buyer_id AND n.status = 'accepted'
    WHERE u.user_id = $user_id
");
$metrics = $metrics_query->fetch_assoc();

// Get recent ratings
$ratings_query = $connect->prepare("
    SELECT r.*, u.username, r.created_at
    FROM ratings r
    JOIN users u ON r.rater_id = u.user_id
    WHERE r.seller_id = ?
    ORDER BY r.created_at DESC
    LIMIT 5
");
$ratings_query->bind_param("i", $user_id);
$ratings_query->execute();
$recent_ratings = $ratings_query->get_result();
$ratings_query->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Score - Pastimes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        
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
        
        .section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .section h2 { color: #333; margin-bottom: 15px; font-size: 20px; }
        
        .score-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 25px;
        }
        .score-card .score-number {
            font-size: 56px;
            font-weight: 700;
            display: inline-block;
        }
        .score-card .score-label {
            font-size: 14px;
            opacity: 0.8;
            margin-top: 4px;
        }
        .score-card .tier-badge {
            display: inline-block;
            padding: 6px 20px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 18px;
            background: rgba(255,255,255,0.2);
            margin-top: 8px;
        }
        .score-card .commission-rate {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 50px;
            background: rgba(39, 174, 96, 0.3);
            font-weight: 600;
            font-size: 16px;
            margin-top: 8px;
        }
        .score-card .score-right {
            text-align: right;
        }
        .score-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .progress-section { margin: 20px 0; }
        .progress-bar {
            width: 100%;
            height: 12px;
            background: rgba(0,0,0,0.1);
            border-radius: 6px;
            overflow: hidden;
            margin-top: 8px;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 6px;
            transition: width 0.8s ease;
        }
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #666;
            margin-top: 4px;
        }
        
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .metric-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .metric-item .value { font-size: 24px; font-weight: 700; color: #333; }
        .metric-item .label { font-size: 12px; color: #999; margin-top: 4px; }
        
        .benefits-list { list-style: none; padding: 0; }
        .benefits-list li { 
            padding: 10px 0; 
            border-bottom: 1px solid #ecf0f1;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .benefits-list li:last-child { border-bottom: none; }
        .benefits-list i { color: #27ae60; font-size: 18px; width: 24px; }
        
        .rating-item {
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .rating-item .stars { color: #f5b301; font-size: 16px; letter-spacing: 1px; }
        .rating-item .meta { font-size: 12px; color: #999; margin-top: 4px; }
        .rating-item .comment { font-style: italic; color: #555; margin-top: 4px; }
        
        .btn {
            padding: 8px 20px;
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
        .btn-outline { background: white; color: #667eea; border: 2px solid #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        
        .tier-badge-lg {
            display: inline-block;
            padding: 8px 24px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 20px;
            color: white;
        }
        
        .empty-state { text-align: center; padding: 20px; color: #999; }
        .empty-state i { font-size: 32px; color: #ddd; margin-bottom: 10px; display: block; }
        
        @media (max-width: 640px) {
            .header { padding: 12px 16px; }
            .score-flex { flex-direction: column; text-align: center; }
            .score-card .score-right { text-align: center; }
            .metric-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-trophy"></i> My Score</h1>
            <div class="header-links">
                <a href="dashboard.php">Dashboard</a>
                <a href="products.php">Shop</a>
                <a href="login.php?logout=1" style="color: #ff6b6b;">Logout</a>
            </div>
        </div>
        
        <!-- Score Card -->
        <div class="score-card">
            <div class="score-flex">
                <div>
                    <div class="score-label">Your Score</div>
                    <div class="score-number"><?php echo $score_data['total_score'] ?? 0; ?></div>
                    <div>
                        <span class="tier-badge">
                            <?php echo $tier[1]; ?> <?php echo $tier[0]; ?>
                        </span>
                        <span class="commission-rate">
                            <i class="fas fa-percentage"></i> <?php echo number_format($commission, 2); ?>% commission
                        </span>
                    </div>
                </div>
                <div class="score-right">
                    <div class="score-label">Tier Benefits</div>
                    <div style="font-size: 14px; opacity: 0.9; margin-top: 4px;">
                        <?php echo $tier[3]; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Progress to Next Tier -->
        <div class="section">
            <h2>📈 Progress to Next Tier</h2>
            <?php if ($next_tier['points_needed'] > 0): ?>
                <div class="progress-section">
                    <div style="display: flex; justify-content: space-between; font-size: 14px;">
                        <span><?php echo $score; ?> points</span>
                        <span><strong><?php echo $next_tier['name']; ?></strong> (<?php echo $next_tier['points_needed']; ?> more points needed)</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min(($score / 500) * 100, 100); ?>%;"></div>
                    </div>
                    <div class="progress-label">
                        <span>Bronze</span>
                        <span>500 points</span>
                    </div>
                </div>
                <div style="margin-top: 10px; font-size: 13px; color: #666;">
                    <i class="fas fa-lightbulb" style="color: #f39c12;"></i> 
                    <?php if ($next_tier['points_needed'] > 50): ?>
                        Complete more sales and get positive ratings to earn points!
                    <?php elseif ($next_tier['points_needed'] > 20): ?>
                        Almost there! A few more sales will get you to <?php echo $next_tier['name']; ?>!
                    <?php else: ?>
                        You're so close! Just <?php echo $next_tier['points_needed']; ?> more points!
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 20px; color: #27ae60;">
                    <i class="fas fa-crown" style="font-size: 32px; display: block; margin-bottom: 10px;"></i>
                    🎉 You've reached the highest tier! You're a Diamond seller!
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Metrics -->
        <div class="section">
            <h2>📊 Your Metrics</h2>
            <div class="metric-grid">
                <div class="metric-item">
                    <div class="value"><?php echo $metrics['sales_count'] ?? 0; ?></div>
                    <div class="label">Successful Sales</div>
                </div>
                <div class="metric-item">
                    <div class="value"><?php echo $metrics['rating_count'] ?? 0; ?></div>
                    <div class="label">Total Ratings</div>
                </div>
                <div class="metric-item">
                    <div class="value"><?php echo number_format($metrics['avg_rating'] ?? 0, 1); ?></div>
                    <div class="label">Average Rating</div>
                </div>
                <div class="metric-item">
                    <div class="value"><?php echo $metrics['offers_accepted'] ?? 0; ?></div>
                    <div class="label">Offers Accepted</div>
                </div>
            </div>
            
            <div style="margin-top: 15px; font-size: 13px; color: #888; text-align: center;">
                <i class="fas fa-info-circle"></i> Scores update automatically based on your activity
            </div>
        </div>
        
        <!-- Tier Benefits -->
        <div class="section">
            <h2>✨ Tier Benefits</h2>
            
            <?php
            $all_tiers = $connect->query("SELECT * FROM commission_tiers ORDER BY min_score ASC");
            ?>
            
            <?php while ($tier_row = $all_tiers->fetch_assoc()): 
                $is_current = ($tier_row['tier_name'] == $tier[0]);
            ?>
            <div style="padding: 12px 15px; margin-bottom: 8px; border-radius: 10px; <?php echo $is_current ? 'background: #f0f4ff; border-left: 4px solid #667eea;' : 'background: #f8f9fa;'; ?>">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <div>
                        <strong style="font-size: 16px;">
                            <?php echo $tier_row['tier_name']; ?> 
                            <?php if ($is_current): ?>
                                <span style="font-size: 12px; background: #667eea; color: white; padding: 2px 10px; border-radius: 20px; margin-left: 8px;">Current</span>
                            <?php endif; ?>
                        </strong>
                        <div style="font-size: 12px; color: #888;"><?php echo $tier_row['benefits']; ?></div>
                    </div>
                    <div>
                        <span style="font-weight: 600; color: #667eea;">
                            <?php echo $tier_row['commission_rate']; ?>% commission
                        </span>
                        <span style="font-size: 12px; color: #888; margin-left: 8px;">
                            (<?php echo $tier_row['min_score']; ?> - <?php echo $tier_row['max_score']; ?> pts)
                        </span>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        
        <!-- Recent Ratings -->
        <div class="section">
            <h2>⭐ Recent Ratings</h2>
            <?php if ($recent_ratings->num_rows > 0): ?>
                <?php while ($rating = $recent_ratings->fetch_assoc()): ?>
                    <div class="rating-item">
                        <div class="stars"><?php echo str_repeat('★', $rating['rating']) . str_repeat('☆', 5 - $rating['rating']); ?></div>
                        <div class="comment"><?php echo htmlspecialchars($rating['comment'] ?? 'No comment left'); ?></div>
                        <div class="meta">From <?php echo htmlspecialchars($rating['username']); ?> &middot; <?php echo date('M d, Y', strtotime($rating['created_at'])); ?></div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-star"></i>
                    <p>No ratings yet. Complete sales to receive ratings from buyers!</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Actions -->
        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px;">
            <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-home"></i> Dashboard</a>
            <a href="products.php" class="btn btn-outline"><i class="fas fa-store"></i> Browse Products</a>
            <?php if ($_SESSION['user_role'] == 'seller'): ?>
                <a href="seller_dashboard.php" class="btn btn-outline"><i class="fas fa-plus"></i> Manage Products</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>