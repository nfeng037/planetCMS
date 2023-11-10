<?php
// edit_page.php

session_start();

require 'db_connect.php';
require 'check_access.php';
require './lib/htmlpurifier-4.15.0/library/HTMLPurifier.auto.php';
require_once 'image_functions.php';

if (!isset($_SESSION['user_id']) || !checkUserRole('admin')) {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';

// Get the page ID from a GET request or from the session
$page_id = $_GET['page_id'] ?? $_SESSION['page_id_to_edit'];

$stmt = $pdo->prepare("SELECT * FROM pages WHERE page_id = :page_id");
$stmt->execute([':page_id' => $page_id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT category_id, category_name FROM categories");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $category_id = $_POST['category'];
    $imageUploaded = false; // Flag to check if the image is uploaded and valid

    $config = HTMLPurifier_Config::createDefault();
    $purifier = new HTMLPurifier($config);
    $clean_html = $purifier->purify($description);

    if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
        $image = $_FILES['image'];
        $temporaryPath = $image['tmp_name'];
        $imageFilename = basename($image['name']);
        $newPath = file_upload_path($image['name']); // Absolute path
        $relativePath = file_upload_path($image['name'], 'uploads', true); // Relative path
    
        // Check if the file is an image
        if (file_is_an_image($temporaryPath, $newPath)) {
            if (move_uploaded_file($temporaryPath, $newPath)) {
                $resizedFilename = 'resized_' . $imageFilename;
                $resizedPath = file_upload_path($resizedFilename); // Absolute path for resizing
    
                // Resize and save the image
                if (resize_image($newPath, $resizedPath, 400)) {
                    $resizedRelativePath = 'uploads/' . $resizedFilename; 
    
                    // Insert or Update image information into the images table with reference to the page
                    $updateImageStmt = $pdo->prepare("UPDATE images SET file_name = :file_name WHERE page_id = :page_id");
                    $updateImageStmt->execute([
                        ':file_name' => $resizedRelativePath,
                        ':page_id' => $page_id
                    ]);
                    
                    // Update the pages table with the new image URL
                    $stmt = $pdo->prepare("UPDATE pages SET image_url = :image_url WHERE page_id = :page_id");
                    $stmt->execute([
                        ':image_url' => $resizedRelativePath,
                        ':page_id' => $page_id
                    ]);
    
                    $imageUploaded = true;
                } else {
                    $error = 'Image could not be resized.';
                }
            } else {
                $error = 'The file could not be uploaded.';
            }
        } else {
            $error = 'The file is not a valid image.';
        }
    }    

    // Proceed with updating the page only if there's no error
    if (!$error) {
        $pdo->beginTransaction();

        try {
            // Update page details
            $stmt = $pdo->prepare("UPDATE pages SET title = :title, content = :content, category_id = :category_id WHERE page_id = :page_id");
            $stmt->execute([
                ':title' => $name,
                ':content' => $clean_html,
                ':category_id' => $category_id,
                ':page_id' => $page_id
            ]);

            if ($imageUploaded) {
                // Update the pages table with the new image URL
                $updatePageStmt = $pdo->prepare("UPDATE pages SET image_url = :image_url WHERE page_id = :page_id");
                $updatePageStmt->execute([
                    ':image_url' => $resizedRelativePath,
                    ':page_id' => $page_id
                ]);


                $updateImageStmt = $pdo->prepare("UPDATE images SET file_name = :file_name WHERE page_id = :page_id");
                $updateImageStmt->execute([
                    ':file_name' => $resizedRelativePath,
                    ':page_id' => $page_id
                ]);
            }
            

            $pdo->commit();
            $success = 'Page updated successfully.';
            header("Location: view.php?page_id=" . $page_id);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error updating the page: ' . $e->getMessage();
        }
    }
}

?>




<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Page - <?= $page['title'] ?></title>
    <link rel="stylesheet" href="styles.css">
    <!-- Include Quill library for rich text editor -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main>
        <h1>Edit Page - <?= $page['title'] ?></h1>
        
        <!-- Error or success messages -->
        <?php if ($error): ?>
            <p class="error"><?= $error; ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="success"><?= $success; ?></p>
        <?php endif; ?>

        <form id="editForm" action="edit_page.php?page_id=<?= $page_id; ?>" method="post" enctype="multipart/form-data">
            <div>
                <label for="name">Name:</label>
                <input type="text" id="name" name="name" required value="<?= htmlspecialchars($page['title']); ?>">
            </div>
            
            <div>
                <label for="category">Category:</label>
                <select id="category" name="category" required>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['category_id']; ?>" <?php if ($category['category_id'] == $page['category_id']) echo 'selected'; ?>>
                            <?= htmlspecialchars($category['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="description">Description:</label>
                <!-- Include the Quill editor container -->
                <div id="editor-container"><?= $page['content']; ?></div>
                <input type="hidden" name="description" id="hidden-description">
            </div>
            
            <div>
                <label for="image">Change Image (optional):</label>
                <input type="file" id="image" name="image">
                <?php if (!empty($page['image_url'])): ?>
                    <img src="<?= htmlspecialchars($page['image_url']); ?>" alt="Current Image" class="edit-image-preview">
                <?php endif; ?>
            </div>

            <div>
                <input type="submit" value="Update Page">
            </div>
        </form>
    </main>

    <!-- Include Quill JS for the rich text editor -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script>
        var quill = new Quill('#editor-container', {
            theme: 'snow'
        });

        document.addEventListener('DOMContentLoaded', (event) => {
            var form = document.querySelector('#editForm');
            form.onsubmit = function(e) {
                var description = document.querySelector('#hidden-description');
                description.value = quill.root.innerHTML;
            };
        });
    </script>

</body>
</html>