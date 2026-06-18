<?php
// api/like.php - Like/Unlike posts API
session_start();
header('Content-Type: application/json');
include "../DBConn.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Please login to like posts']);
    exit();
}

$user_id = $_SESSION['user_id'];
$post_id = intval($_POST['post_id'] ?? 0);

if (!$post_id) {
    echo json_encode(['error' => 'Post ID required']);
    exit();
}

// Get the product_id from the post
$product_query = $connect->prepare("SELECT product_id FROM style_posts WHERE post_id = ?");
$product_query->bind_param("i", $post_id);
$product_query->execute();
$product_result = $product_query->get_result();
$post = $product_result->fetch_assoc();

if (!$post) {
    echo json_encode(['error' => 'Post not found']);
    exit();
}
$product_query->close();

$product_id = $post['product_id'];

// Check if already liked
$check = $connect->prepare("
    SELECT interaction_id FROM product_interactions 
    WHERE user_id = ? AND product_id = ? AND action = 'like'
");
$check->bind_param("ii", $user_id, $product_id);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    // Unlike
    $delete = $connect->prepare("
        DELETE FROM product_interactions 
        WHERE user_id = ? AND product_id = ? AND action = 'like'
    ");
    $delete->bind_param("ii", $user_id, $product_id);
    $delete->execute();
    $delete->close();
    $liked = false;
} else {
    // Like
    $insert = $connect->prepare("
        INSERT INTO product_interactions (user_id, product_id, action) 
        VALUES (?, ?, 'like')
    ");
    $insert->bind_param("ii", $user_id, $product_id);
    $insert->execute();
    $insert->close();
    $liked = true;
}

$check->close();

// Get updated like count
$count = $connect->prepare("
    SELECT COUNT(*) as count FROM product_interactions 
    WHERE product_id = ? AND action = 'like'
");
$count->bind_param("i", $product_id);
$count->execute();
$result = $count->get_result()->fetch_assoc();
$count->close();

echo json_encode([
    'liked' => $liked,
    'like_count' => $result['count']
]);
