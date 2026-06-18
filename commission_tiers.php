<?php
// commission_tiers.php - Admin view of all commission tiers
session_start();
include "DBConn.php";

// Admin check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit();
}

$message = '';
$message_type = '';

// Handle tier update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_tier'])) {
    $tier_id = intval($_POST['tier_id']);
    $tier_name = trim($_POST['tier_name']);
    $min_score = floatval($_POST['min_score']);
    $max_score = floatval($_POST['max_score']);
    $commission_rate = floatval($_POST['commission_rate']);
    $benefits = trim($_POST['benefits']);
    
    $update_stmt = $connect->prepare("
        UPDATE commission_tiers SET 
            tier_name = ?, 
            min_score = ?, 
            max_score = ?, 
            commission_rate = ?, 
            benefits = ?
        WHERE tier_id = ?
    ");
    $update_stmt->bind_param("sdddsi", $tier_name, $min_score, $max_score, $commission_rate, $benefits, $tier_id);
    
    if ($update_stmt->execute()) {
        $message = "✅ Tier updated successfully!";
        $message_type = "success";
    } else {
        $message = "Error updating tier: " . $connect->error;
        $message_type = "error";
    }
    $update_stmt->close();
}

// Get all tiers
$tiers_query = $connect->query("SELECT * FROM commission_tiers ORDER BY min_score ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commission Tiers - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        
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
        
        .section { background: white; border-radius: 15px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section h2 { color: #333; margin-bottom: 15px; font-size: 20px; }
        
        .tier-card {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 12px;
            background: #f8f9fa;
            border-left: 4px solid #667eea;
        }
        .tier-card .tier-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .tier-card .tier-name { font-size: 18px; font-weight: 700; }
        .tier-card .tier-range { font-size: 13px; color: #888; }
        .tier-card .tier-commission { font-weight: 700; color: #667eea; font-size: 16px; }
        .tier-card .tier-benefits { font-size: 14px; color: #666; margin-top: 8px; }
        
        .btn {
            padding: 6px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            font-size: 13px;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; }
        
        .edit-form { display: none; margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee; }
        .edit-form .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .edit-form input, .edit-form textarea {
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            width: 100%;
        }
        .edit-form input:focus, .edit-form textarea:focus { outline: none; border-color: #667eea; }
        .edit-form textarea { resize: vertical; min-height: 50px; }
        
        @media (max-width: 640px) { .edit-form .form-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-crown"></i> Commission Tiers</h1>
            <div class="header-links">
                <a href="admin_dashboard.php">Admin Dashboard</a>
                <a href="login.php?logout=1" style="color: #ff6b6b;">Logout</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <div class="section">
            <h2>📊 Commission Tiers Overview</h2>
            <p style="color: #666; margin-bottom: 15px;">
                These tiers determine the commission rate for sellers based on their performance score.
            </p>
            
            <?php while ($tier = $tiers_query->fetch_assoc()): ?>
                <div class="tier-card" style="border-left-color: <?php 
                    if ($tier['tier_name'] == 'Diamond') echo '#00d4ff';
                    elseif ($tier['tier_name'] == 'Platinum') echo '#e5e4e2';
                    elseif ($tier['tier_name'] == 'Gold') echo '#ffd700';
                    elseif ($tier['tier_name'] == 'Silver') echo '#c0c0c0';
                    else echo '#cd7f32';
                ?>;">
                    <div class="tier-header">
                        <div>
                            <span class="tier-name"><?php echo $tier['tier_name']; ?></span>
                            <span class="tier-range">(<?php echo $tier['min_score']; ?> - <?php echo $tier['max_score']; ?> points)</span>
                        </div>
                        <div>
                            <span class="tier-commission"><?php echo $tier['commission_rate']; ?>% commission</span>
                            <button onclick="toggleEdit(<?php echo $tier['tier_id']; ?>)" class="btn btn-warning">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </div>
                    </div>
                    <div class="tier-benefits"><?php echo $tier['benefits']; ?></div>
                    
                    <!-- Edit Form -->
                    <div class="edit-form" id="edit-<?php echo $tier['tier_id']; ?>">
                        <form method="POST">
                            <input type="hidden" name="tier_id" value="<?php echo $tier['tier_id']; ?>">
                            <div class="form-grid">
                                <div>
                                    <label style="font-size: 13px; font-weight: 600;">Tier Name</label>
                                    <input type="text" name="tier_name" value="<?php echo $tier['tier_name']; ?>">
                                </div>
                                <div>
                                    <label style="font-size: 13px; font-weight: 600;">Commission Rate (%)</label>
                                    <input type="number" step="0.01" name="commission_rate" value="<?php echo $tier['commission_rate']; ?>">
                                </div>
                                <div>
                                    <label style="font-size: 13px; font-weight: 600;">Min Score</label>
                                    <input type="number" step="0.01" name="min_score" value="<?php echo $tier['min_score']; ?>">
                                </div>
                                <div>
                                    <label style="font-size: 13px; font-weight: 600;">Max Score</label>
                                    <input type="number" step="0.01" name="max_score" value="<?php echo $tier['max_score']; ?>">
                                </div>
                            </div>
                            <div style="margin-top: 10px;">
                                <label style="font-size: 13px; font-weight: 600;">Benefits Description</label>
                                <textarea name="benefits" rows="2"><?php echo $tier['benefits']; ?></textarea>
                            </div>
                            <button type="submit" name="update_tier" class="btn btn-primary" style="margin-top: 10px;">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <button type="button" onclick="toggleEdit(<?php echo $tier['tier_id']; ?>)" class="btn" style="background: #e74c3c; color: white; margin-top: 10px;">
                                Cancel
                            </button>
                        </form>
                    </div>
                </div>
            <?php endwhile; ?>
            
            <div style="margin-top: 20px; padding: 15px; background: #f0f4ff; border-radius: 10px;">
                <h3 style="color: #333; font-size: 14px;">📌 How Scores Work</h3>
                <ul style="color: #666; font-size: 13px; margin-top: 8px; list-style: none; padding: 0;">
                    <li style="padding: 4px 0;">✅ <strong>Sales:</strong> 10 points per successful sale (max 200)</li>
                    <li style="padding: 4px 0;">⭐ <strong>Positive Ratings:</strong> 5 points per rating (max 100)</li>
                    <li style="padding: 4px 0;">📊 <strong>Rating Quality:</strong> Up to 100 points based on average rating</li>
                    <li style="padding: 4px 0;">🤝 <strong>Successful Offers:</strong> 15 points per offer (max 150)</li>
                    <li style="padding: 4px 0;">🚀 <strong>Fast Delivery:</strong> 50 bonus points for delivery under 48 hours</li>
                </ul>
            </div>
        </div>
        
        <script>
            function toggleEdit(id) {
                const form = document.getElementById('edit-' + id);
                if (form.style.display === 'block') {
                    form.style.display = 'none';
                } else {
                    form.style.display = 'block';
                }
            }
        </script>
    </div>
</body>
</html>