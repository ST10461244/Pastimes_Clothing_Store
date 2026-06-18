<?php
/**
 * Handles a single uploaded clothing image and returns the relative path to store
 * in clothes.image_url, or null if no file was uploaded.
 *
 * Throws an Exception with a user-friendly message on validation failure, so the
 * calling page can catch it and add it to its existing $errors[] list.
 *
 * @param string $field_name   The <input type="file" name="..."> field name
 * @param string|null $existing_path  Existing image_url value (used on edit, so a
 *                                     blank file input doesn't wipe out the current image)
 * @return string|null
 */
function handle_clothing_image_upload($field_name, $existing_path = null) {
    // No file selected at all for this field
    if (!isset($_FILES[$field_name]) || $_FILES[$field_name]['error'] === UPLOAD_ERR_NO_FILE) {
        return $existing_path;
    }

    $file = $_FILES[$field_name];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'The image is larger than this server allows.',
            UPLOAD_ERR_FORM_SIZE  => 'The image is larger than allowed by the form.',
            UPLOAD_ERR_PARTIAL    => 'The image was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A server extension blocked this upload.',
        ];
        throw new Exception($upload_errors[$file['error']] ?? 'Image upload failed. Please try again.');
    }

    // 5MB max
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('Image must be smaller than 5MB.');
    }

    // Validate it's actually an image, and what kind, using the file's real content (not the filename)
    $image_info = getimagesize($file['tmp_name']);
    if ($image_info === false) {
        throw new Exception('The uploaded file is not a valid image.');
    }

    $allowed_mime_to_ext = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $mime = $image_info['mime'];
    if (!isset($allowed_mime_to_ext[$mime])) {
        throw new Exception('Only JPG, PNG, GIF, or WEBP images are allowed.');
    }
    $ext = $allowed_mime_to_ext[$mime];

    // Destination folder, created if it doesn't exist yet
    $upload_dir = __DIR__ . '/uploads/products';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
            throw new Exception('Could not create the uploads folder on the server.');
        }
    }

    // Unique filename so uploads never collide or overwrite each other
    $filename = uniqid('product_', true) . '.' . $ext;
    $destination = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception('Could not save the uploaded image on the server.');
    }

    // Stored as a relative web path so it works the same in <img src="..."> regardless of domain
    return 'uploads/products/' . $filename;
}