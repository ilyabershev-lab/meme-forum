<?php

session_start();
require_once 'db.php';

if (!isset($_SESSION['uid'])) {
    header('Location: login.php');
    exit;
}

$uid = $_SESSION['uid'];
$post_id = (int)($_GET['id'] ?? 0);

if ($post_id <= 0) {
    die('Invalid post ID');
}

$stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ? AND user_id = ?");
$stmt->execute([$post_id, $uid]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    die('Post not found or this is not your post');
}

$errors = [];
$text = $post['text'];
$selectedCategory = $post['category_id'];

$stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_POST['edit_post'])) {
    $text = trim($_POST['text'] ?? '');
    $selectedCategory = (int)($_POST['category_id'] ?? 0);

    $existingImages = !empty($post['image_path'])
        ? (json_decode($post['image_path'], true) ?? [$post['image_path']])
        : [];

    $keepImages = $_POST['keep_image'] ?? [];

    foreach ($existingImages as $item) {
        if (!in_array($item, $keepImages)) {
            if (file_exists($item)) {
                unlink($item);
            }
        }
    }

    $imagePaths = array_values($keepImages);
    $allowedExt = ['jpg', 'jpeg', 'png'];
    $maxSize = 5 * 1024 * 1024;

    $hasNewImages = isset($_FILES['image']) && !empty(array_filter($_FILES['image']['error'], fn($e) => $e === 0));

    if ($hasNewImages) {
        foreach ($_FILES['image']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['image']['error'][$key] !== 0) {
                continue;
            }
            $ext = strtolower(pathinfo($_FILES['image']['name'][$key], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt)) {
                $errors['image'] = 'Allowed only jpg, jpeg, png';
                break;
            }
            if ($_FILES['image']['size'][$key] > $maxSize) {
                $errors['image'] = 'Max size 5MB';
                break;
            }
            $fileName = uniqid('meme_') . '.' . $ext;
            $uploadPath = 'uploads/memes/' . $fileName;
            if (move_uploaded_file($tmpName, $uploadPath)) {
                $imagePaths[] = $uploadPath;
            }
        }
    }

    $imagePathJson = !empty($imagePaths) ? json_encode($imagePaths) : null;

    if (empty($text) && empty ($imagePathJson)) {
        $errors['content'] = 'Fill text or upload image';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("UPDATE posts SET text = ?, image_path = ?, category_id = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$text ?: null, $imagePathJson, $selectedCategory, $post_id, $uid]);

        header('Location: my_posts.php');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit post</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: #cdd6f4; }
        .page-center { max-width: 600px; margin: 40px auto; padding: 0 20px; }
        h1 { color: #e94560; margin-bottom: 24px; }
        .card {
            background: #16213e;
            border: 1px solid #0f3460;
            border-radius: 12px;
            padding: 28px;
        }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 6px; color: #aab; font-size: 14px; }
        textarea, select {
            width: 100%;
            background: #0f3460;
            border: 1px solid #1a5276;
            border-radius: 6px;
            color: #cdd6f4;
            padding: 10px;
            font-size: 14px;
            outline: none;
        }
        textarea { resize: vertical; }
        textarea:focus, select:focus { border-color: #e94560; }
        input[type="file"] { color: #cdd6f4; font-size: 14px; }
        .error { color: #e74c3c; font-size: 13px; margin-top: 4px; display: block; }
        .error-box {
            background: #5c1e1e;
            color: #fdd;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .image-preview-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 8px 0 12px;
        }
        .image-preview-item {
            position: relative;
            display: inline-block;
        }
        .image-preview-item img {
            width: 140px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid #0f3460;
            display: block;
            transition: opacity 0.2s, filter 0.2s;
        }
        .image-preview-item.removed img {
            opacity: 0.25;
            filter: grayscale(100%);
        }
        .remove-btn, .undo-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 24px;
            height: 24px;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            transition: opacity 0.2s;
        }
        .remove-btn { background: #c0392b; color: #fff; }
        .undo-btn   { background: #27ae60; color: #fff; }
        .remove-btn:hover, .undo-btn:hover { opacity: 0.8; }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
        .btn-primary  { background: #e94560; color: #fff; }
        .btn-secondary{ background: #2c3e50; color: #cdd6f4; }
        .btn:hover { opacity: 0.85; }
        .hint { font-size: 12px; color: #7f8c8d; margin-top: 4px; }
    </style>
    <script>
        function removeImage(index) {
            document.getElementById('item-' + index).classList.add('removed')
            document.getElementById('keep-' + index).disabled = true
            var btn = document.getElementById('btn-' + index)
            btn.textContent = '✓'
            btn.className = 'undo-btn'
            btn.setAttribute('onclick', 'undoRemove('+ index +')')
        }

        function undoRemove(index) {
            document.getElementById('item-' + index).classList.remove('removed')
            document.getElementById('keep-' + index).disabled = false
            var btn = document.getElementById('btn-' + index)
            btn.textContent = '✕'
            btn.className = 'remove-btn'
            btn.setAttribute('onclick', 'removeImage('+ index +')')
        }
    </script>
</head>
<body>
    <div class="page-center">
        <h1>Edit post</h1>
        <div class="card">
            <form method="post" enctype="multipart/form-data">

                <?php if (isset($errors['content'])): ?>
                    <div class="error-box"><?= $errors['content'] ?></div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Meme text</label>
                    <textarea name="text" rows="5"><?= htmlspecialchars($text) ?></textarea>
                </div>

                <div class="form-group">
                    <label>Replace image (jpg, jpeg, png — max 5MB)</label>
                    <?php if (!empty($post['image_path'])): ?>
                        <?php $currentImages = !empty($post['image_path']) ? (json_decode($post['image_path'], true) ?? [$post['image_path']]) : []; ?>
                        <p class="hint" style="margin-bottom: 8px;">Current images — click ✕ to mark for deletion:</p>
                        <div class="image-preview-grid">
                            <?php foreach ($currentImages as $index => $item): ?>
                                <div class="image-preview-item" id="item-<?= $index ?>">
                                    <img src="<?= htmlspecialchars($item) ?>" alt="image">
                                    <input type="hidden" name="keep_images[]" value="<?= htmlspecialchars($item) ?>" id="keep-<?= $index ?>">
                                    <button type="button" class="remove-btn" id="btn-<?= $index ?>" onclick="removeImage(<?= $index ?>)">✕</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="image[]" accept=".jpg,.jpeg,.png" multiple>
                    <?php if (isset($errors['image'])): ?>
                        <span class="error"><?= $errors['image'] ?></span>
                    <?php endif; ?>
                    <p class="hint">Leave empty to keep the current image.</p>
                </div>

                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id">
                        <option value="0">— Select category —</option>
                        <?php foreach ($categories as $item): ?>
                            <option value="<?= $item['id'] ?>" <?= $selectedCategory == $item['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($item['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['category'])): ?>
                        <span class="error"><?= $errors['category'] ?></span>
                    <?php endif; ?>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 6px;">
                    <button class="btn btn-primary" type="submit" name="edit_post">Save changes</button>
                    <a class="btn btn-secondary" href="my_posts.php">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>