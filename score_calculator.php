<?php
// score_calculator.php - Run this periodically to update all user scores
// This can be run as a cron job or manually

session_start();
include "DBConn.php";

// Only allow admin to run this, or run from command line
$is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin';
$is_cli = php_sapi_name() == 'cli';

if (!$is_admin && !$is_cli) {
    die("Access denied. Admin only.");
}

echo "Starting score calculation...\n";

// Get all users
$users_query = $connect->query("SELECT user_id FROM users");
$updated = 0;

while ($user = $users_query->fetch_assoc()) {
    $user_id = $user['user_id'];
    
    // Calculate score
    $metrics = $connect->query("
        SELECT 
            COUNT(DISTINCT CASE WHEN o.order_status = 'delivered' AND o.buyer_confirmed = 1 THEN o.order_id END) as successful_sales,
            COUNT(DISTINCT CASE WHEN r.rating >= 4 THEN r.rating_id END) as positive_ratings,
            COUNT(DISTINCT r.rating_id) as total_ratings,
            AVG(r.rating) as avg_rating,
            COUNT(DISTINCT CASE WHEN n.status = 'accepted' THEN n.negotiation_id END) as successful_offers
        FROM users u
        LEFT JOIN orders o ON u.user_id = o.user_id
        LEFT JOIN ratings r ON u.user_id = r.seller_id
        LEFT JOIN negotiations n ON u.user_id = n.buyer_id AND n.status = 'accepted'
        WHERE u.user_id = $user_id
    ");
    
    $data = $metrics->fetch_assoc();
    
    // Calculate total score
    $score = 0;
    $score += min(($data['successful_sales'] ?? 0) * 10, 200);
    $score += min(($data['positive_ratings'] ?? 0) * 5, 100);
    $score += min(($data['avg_rating'] ?? 0) * 20, 100);
    $score += min(($data['successful_offers'] ?? 0) * 15, 150);
    $score = min($score, 500);
    
    // Determine tier
    if ($score >= 501) $tier = 'diamond';
    elseif ($score >= 301) $tier = 'platinum';
    elseif ($score >= 151) $tier = 'gold';
    elseif ($score >= 51) $tier = 'silver';
    else $tier = 'bronze';
    
    // Get commission rate
    $commission_rate = 10.00;
    if ($score >= 501) $commission_rate = 3.00;
    elseif ($score >= 301) $commission_rate = 5.50;
    elseif ($score >= 151) $commission_rate = 7.00;
    elseif ($score >= 51) $commission_rate = 8.50;
    
    // Update database
    $update_stmt = $connect->prepare("
        INSERT INTO user_scores (user_id, total_score, successful_sales, positive_ratings, average_rating, successful_offers, commission_rate, tier, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            total_score = VALUES(total_score),
            successful_sales = VALUES(successful_sales),
            positive_ratings = VALUES(positive_ratings),
            average_rating = VALUES(average_rating),
            successful_offers = VALUES(successful_offers),
            commission_rate = VALUES(commission_rate),
            tier = VALUES(tier),
            updated_at = NOW()
    ");
    $update_stmt->bind_param("iiidddss", 
        $user_id, 
        $score, 
        $data['successful_sales'] ?? 0, 
        $data['positive_ratings'] ?? 0, 
        $data['avg_rating'] ?? 0, 
        $data['successful_offers'] ?? 0,
        $commission_rate,
        $tier
    );
    
    if ($update_stmt->execute()) {
        $updated++;
    }
    $update_stmt->close();
}

echo "✅ Score calculation complete! Updated $updated users.\n";
?>