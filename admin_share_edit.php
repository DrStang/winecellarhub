<?php
// admin_share_edit.php (snippet)
require __DIR__.'/db.php';
require __DIR__.'/auth.php';

if (!$_SESSION['is_admin']) {
    die("Access denied. Admins only.");
}

$token = $_GET['t'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isIndex = isset($_POST['is_indexable']) ? 1 : 0;
    $expires = trim($_POST['expires_at'] ?? '');
    $upd = $winelist_pdo->prepare("UPDATE public_shares SET is_indexable=:i, expires_at=NULLIF(:e,'') WHERE token=:t");
    $upd->execute([':i'=>$isIndex, ':e'=>$expires, ':t'=>$token]);
    header("Location: admin_share_edit.php?t=".urlencode($token)."&saved=1");
    exit;
}
$stmt = $winelist_pdo->prepare("SELECT token,title,excerpt,is_indexable,expires_at FROM public_shares WHERE token=:t");
$stmt->execute([':t'=>$token]);
$share = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<form method="post" style="max-width:520px">
    <h3>Edit Share</h3>
    <p><strong>Token:</strong> <?= htmlspecialchars($share['token']) ?></p>
    <label><input type="checkbox" name="is_indexable" <?= $share['is_indexable'] ? 'checked' : '' ?>> Indexable (allow search engines)</label>
    <div style="margin-top:10px">
        <label>Expires at (optional): <input type="date" name="expires_at" value="<?= htmlspecialchars(substr((string)$share['expires_at'],0,10)) ?>"></label>
    </div>
    <div style="margin-top:14px">
        <button type="submit">Save</button>
    </div>
</form>

