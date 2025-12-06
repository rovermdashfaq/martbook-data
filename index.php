<?php
// Admin Manager - Single Directory File/Folder Management

// Configuration
$baseDir = __DIR__;
$scriptName = basename($_SERVER['SCRIPT_NAME']);
$max_storage_bytes = 5 * 1024 * 1024 * 1024; // 5 GB Placeholder

if (isset($_GET['log'])) {
    $logFile = $baseDir . '/activity.log';
    if (file_exists($logFile)) {
        echo file_get_contents($logFile);
    } else {
        echo 'No activity log found.';
    }
    exit;
}

// Function to calculate used disk space (simplified for the baseDir)
function getUsedDiskSpace($dir) {
    $size = 0;
    try {
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        }
    } catch (Exception $e) {
        // Log or handle error if directory is unreadable
        return 0;
    }
    return $size;
}

// Function to count total files and folders recursively
function countAssets($dir) {
    $files = 0;
    $folders = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $folders++;
            } else {
                $files++;
            }
        }
    } catch (Exception $e) {
        return ['files' => 0, 'folders' => 0];
    }
    return ['files' => $files, 'folders' => $folders];
}

$used_storage_bytes = getUsedDiskSpace($baseDir);
$storage_percent = ($used_storage_bytes / $max_storage_bytes) * 100;
$assets = countAssets($baseDir);

// Function to create links using URL path
function createLink($subPath, $scriptName) {
    // Ensure $subPath is trimmed for clean URL
    $cleanPath = trim($subPath, '/');
    if (empty($cleanPath)) {
        return $scriptName; // Link to root
    }
    // Use the script name and append ?path=
    return $scriptName . '?path=' . urlencode($cleanPath);
}

// Parse URL for subpath using ?path= query parameter
$subPath = $_GET['path'] ?? '';
$currentSubPath = $subPath;

// Security: Prevent directory traversal
$realBase = realpath($baseDir);
// Calculate the full path: baseDir + currentSubPath
$fullPath = rtrim($baseDir . '/' . $currentSubPath, '/');
$realPath = realpath($fullPath) ?: $fullPath;

// Check if $realPath starts with $realBase (security check)
if (strpos($realPath, $realBase) !== 0) {
    // If traversal detected, reset to the base directory
    $currentSubPath = '';
    $fullPath = $baseDir;
}

// Check if the current folder actually exists
$folderExists = is_dir($fullPath);

if (!$folderExists) {
    // If folder doesn't exist, reset path to base directory to prevent errors
    $currentSubPath = '';
    $fullPath = $baseDir;
    $folderExists = is_dir($fullPath);
}

// Get the redirect target based on the current context (used for POST actions)
$redirectUrl = createLink($currentSubPath, $scriptName);

// Activity logging function
function logActivity($action, $details) {
    global $baseDir;
    $logEntry = date('Y-m-d H:i:s') . " | " . $action . " | " . $details . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
    $logFile = $baseDir . '/activity.log';
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// --- ZIP Download Handling ---
if (isset($_GET['zip']) && !empty($_GET['zip'])) {
    $zipItems = explode('|', $_GET['zip']);
    // Read the custom name parameter, fallback to generated name
    $downloadAsName = $_GET['name'] ?? null;
    $password = $_GET['password'] ?? null;
    
    // Create a temporary ZIP file name
    // Use the custom name if provided and ends with .zip, otherwise append .zip
    if ($downloadAsName && strtolower(substr($downloadAsName, -4)) === '.zip') {
        $zipFileName = basename($downloadAsName);
    } else if ($downloadAsName) {
         $zipFileName = basename($downloadAsName) . '.zip';
    } else {
        $zipFileName = 'download_' . time() . '.zip';
    }
    
    // Ensure unique path in temp dir
    $zipFilePath = sys_get_temp_dir() . '/' . uniqid('zip_') . $zipFileName;
    
    $zip = new ZipArchive();
    if ($zip->open($zipFilePath, ZipArchive::CREATE) === TRUE) {
        if ($password) {
            $zip->setPassword($password);
        }
        $foundFiles = false;
        foreach ($zipItems as $itemName) {
            $cleanName = basename($itemName); // Ensure no traversal within the list items
            $itemPath = $fullPath . '/' . $cleanName;
            
            if (file_exists($itemPath)) {
                $foundFiles = true;
                if (is_file($itemPath)) {
                    $zip->addFile($itemPath, $cleanName);
                    if ($password) {
                        $zip->setEncryptionName($cleanName, ZipArchive::EM_AES_256);
                    }
                } elseif (is_dir($itemPath)) {
                    // Recursive function to add directory contents
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($itemPath, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($iterator as $file) {
                        // Create the relative path inside the zip
                        $relativePath = $cleanName . '/' . $iterator->getSubPathName();
                        if ($file->isDir()) {
                            // Add folder entry
                            $zip->addEmptyDir($relativePath);
                        } else {
                            $zip->addFile($file->getRealPath(), $relativePath);
                            if ($password) {
                                $zip->setEncryptionName($relativePath, ZipArchive::EM_AES_256);
                            }
                        }
                    }
                }
            }
        }
        $zip->close();

        if ($foundFiles && file_exists($zipFilePath)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
            header('Content-Length: ' . filesize($zipFilePath));
            readfile($zipFilePath);
            unlink($zipFilePath); // Clean up temp file
            exit;
        }
    }
    // If we reach here, either zip failed or no files were found
    header("Location: " . $redirectUrl . '?error=1&msg=Failed to create download archive.');
    exit;
}
// --- End ZIP Download Handling ---

// Recursive delete function
function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

// Handle file operations (only if folder exists)
if ($folderExists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $success = false;
    $successMsg = '';
    $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === 'true';
    
    // All paths are relative to $fullPath which is already secured
    
    if ($action === 'create_folder') {
        $folderName = $_POST['folder_name'] ?? '';
        if (!empty($folderName)) {
            $newPath = $fullPath . '/' . basename($folderName);
            $success = mkdir($newPath, 0777, true);
            if ($success) {
                $successMsg = 'Folder created successfully: ' . basename($folderName);
                logActivity('create_folder', basename($folderName));
            }
        }
    }
    
    if ($action === 'create_file') {
        $fileName = $_POST['file_name'] ?? '';
        $fileContent = $_POST['file_content'] ?? '';
        if (!empty($fileName)) {
            $newFile = $fullPath . '/' . basename($fileName);
            $success = (file_put_contents($newFile, $fileContent) !== false);
            if ($success) {
                $successMsg = 'File created successfully: ' . basename($fileName);
                logActivity('create_file', basename($fileName));
            }
        }
    }
    
    if ($action === 'edit_file') {
        $fileName = $_POST['file_name'] ?? '';
        $fileContent = $_POST['file_content'] ?? '';
        if (!empty($fileName)) {
            $editFile = $fullPath . '/' . basename($fileName);
            // Prevent editing the manager script itself
            if (basename($editFile) !== $scriptName) {
                $success = (file_put_contents($editFile, $fileContent) !== false);
                if ($success) {
                    $successMsg = 'File saved successfully';
                    logActivity('edit_file', basename($fileName));
                }
            }
        }
    }
    
    if ($action === 'rename') {
        $oldName = $_POST['old_name'] ?? '';
        $newName = $_POST['new_name'] ?? '';
        if (!empty($oldName) && !empty($newName)) {
            $oldPath = $fullPath . '/' . basename($oldName);
            $newPath = $fullPath . '/' . basename($newName);
            // Prevent renaming the manager script itself
            if (basename($oldPath) !== $scriptName) {
                $success = rename($oldPath, $newPath);
                if ($success) {
                    $meta_extensions = ['.lock', '.pinned', '.viewname'];
                    foreach ($meta_extensions as $ext) {
                        $oldMeta = $oldPath . $ext;
                        if (file_exists($oldMeta)) {
                            rename($oldMeta, $newPath . $ext);
                        }
                    }
                    $oldFullSubPath = ltrim($currentSubPath . '/' . $oldName, '/');
                    $newFullSubPath = ltrim($currentSubPath . '/' . $newName, '/');
                    $oldIcon = $baseDir . '/Icons/' . str_replace('/', '_', $oldFullSubPath) . '_icon.png';
                    $newIcon = $baseDir . '/Icons/' . str_replace('/', '_', $newFullSubPath) . '_icon.png';
                    if (file_exists($oldIcon)) {
                        rename($oldIcon, $newIcon);
                    }
                    $successMsg = 'Item renamed successfully';
                    logActivity('rename', basename($oldName) . ' -> ' . basename($newName));
                }
            }
        }
    }
    
    // --- Delete Multiple ---
    if ($action === 'delete_multiple') {
        $itemsToDelete = $_POST['items'] ?? '';
        $successCount = 0;
        if (!empty($itemsToDelete)) {
            $itemNames = explode('|', $itemsToDelete);
            $meta_extensions = ['.lock', '.pinned', '.viewname'];
            foreach ($itemNames as $itemName) {
                if (empty($itemName)) continue;

                $itemPath = $fullPath . '/' . basename($itemName);
                $itemFullSubPath = ltrim($currentSubPath . '/' . $itemName, '/');
                $iconPath = $baseDir . '/Icons/' . str_replace('/', '_', $itemFullSubPath) . '_icon.png';
                if (file_exists($iconPath)) unlink($iconPath);
                foreach ($meta_extensions as $ext) {
                    $metaFile = $itemPath . $ext;
                    if (file_exists($metaFile)) unlink($metaFile);
                }

                if (basename($itemPath) !== $scriptName) {
                    $deleteSuccess = false;
                    if (is_file($itemPath)) {
                        $deleteSuccess = unlink($itemPath);
                    } elseif (is_dir($itemPath)) {
                        $deleteSuccess = deleteDirectory($itemPath);
                    }
                    if ($deleteSuccess) {
                        $successCount++;
                        logActivity('delete_multiple_item', basename($itemName));
                    }
                }
            }
            $success = ($successCount > 0); // Consider it a success if at least one item was deleted
            if ($success) {
                $successMsg = 'Successfully deleted ' . $successCount . ' items';
                 logActivity('delete_multiple', $successCount . ' items deleted.');
            }
        }
    }
    // --- End Delete Multiple ---

    if ($action === 'delete') {
        $itemName = $_POST['item_name'] ?? '';
        if (!empty($itemName)) {
            $itemPath = $fullPath . '/' . basename($itemName);
            $meta_extensions = ['.lock', '.pinned', '.viewname'];
            foreach ($meta_extensions as $ext) {
                $metaFile = $itemPath . $ext;
                if (file_exists($metaFile)) unlink($metaFile);
            }
            $itemFullSubPath = ltrim($currentSubPath . '/' . $itemName, '/');
            $iconPath = $baseDir . '/Icons/' . str_replace('/', '_', $itemFullSubPath) . '_icon.png';
            if (file_exists($iconPath)) unlink($iconPath);
            // Prevent deleting the manager script itself
            if (basename($itemPath) !== $scriptName) {
                if (is_file($itemPath)) {
                    $success = unlink($itemPath);
                } elseif (is_dir($itemPath)) {
                    $success = deleteDirectory($itemPath);
                }
                if ($success) {
                    $successMsg = 'Successfully deleted ' . basename($itemName);
                    logActivity('delete', basename($itemName));
                }
            }
        }
    }
    
    // --- File Upload Logic ---
    if ($action === 'upload') {
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $fileName = basename($_FILES['file']['name']);
            // Convert .apk to .zip logic (as in original)
            if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'apk') {
                $fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.zip';
            }
            $uploadFile = $fullPath . '/' . $fileName;
            $success = move_uploaded_file($_FILES['file']['tmp_name'], $uploadFile);
            if ($success) {
                $successMsg = 'File uploaded successfully: ' . $fileName;
                logActivity('upload', $fileName);
            }
        }
    }
    
    // --- Upload Multiple Files Logic ---
    if ($action === 'upload_multiple') {
        if (isset($_FILES['multiple_files'])) {
            $successCount = 0;
            $fileNames = $_FILES['multiple_files']['name'];
            $fileTmps = $_FILES['multiple_files']['tmp_name'];
            $fileErrors = $_FILES['multiple_files']['error'];
            
            foreach ($fileNames as $index => $fileName) {
                if ($fileErrors[$index] === UPLOAD_ERR_OK) {
                    // Convert .apk to .zip logic (as in original)
                    if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'apk') {
                        $fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.zip';
                    }
                    // *** SECURITY: Use basename() to prevent path traversal ***
                    $uploadFile = $fullPath . '/' . basename($fileName);
                    if (move_uploaded_file($fileTmps[$index], $uploadFile)) {
                        $successCount++;
                        logActivity('upload_multiple_item', basename($fileName));
                    }
                }
            }
            $success = ($successCount > 0);
            if ($success) {
                $successMsg = 'Successfully uploaded ' . $successCount . ' files.';
                logActivity('upload_multiple', $successCount . ' files uploaded.');
            }
        }
    }
    
    // --- START: Upload Folder Logic ---
    if ($action === 'upload_folder') {
        if (isset($_FILES['folder_files'])) {
            $successCount = 0;
            $fileNames = $_FILES['folder_files']['name'];
            $fileTmps = $_FILES['folder_files']['tmp_name'];
            $fileErrors = $_FILES['folder_files']['error'];
            
            $uploadedRootFolder = ''; // To log only the base folder
            
            foreach ($fileNames as $index => $fileName) {
                if ($fileErrors[$index] === UPLOAD_ERR_OK) {
                    
                    // $fileName contains the relative path, e.g., "MyFolder/image.jpg"
                    // Sanitize the relative path to prevent traversal
                    $sanitizedRelativePath = str_replace('..', '', $fileName);
                    $relativeDir = dirname($sanitizedRelativePath);
                    $baseName = basename($sanitizedRelativePath);
                    
                    if($uploadedRootFolder === '' && strpos($sanitizedRelativePath, '/') !== false) {
                        $uploadedRootFolder = explode('/', $sanitizedRelativePath)[0];
                    }

                    // Create the relative directory structure
                    $targetDir = $fullPath;
                    if ($relativeDir !== '.') {
                        $targetDir = $fullPath . '/' . $relativeDir;
                        if (!is_dir($targetDir)) {
                            mkdir($targetDir, 0777, true);
                        }
                    }
                    
                    $fullUploadPath = $targetDir . '/' . $baseName;

                    // Convert .apk to .zip logic
                    if (strtolower(pathinfo($fullUploadPath, PATHINFO_EXTENSION)) === 'apk') {
                        $fullUploadPath = pathinfo($fullUploadPath, PATHINFO_FILENAME) . '.zip';
                    }

                    if (move_uploaded_file($fileTmps[$index], $fullUploadPath)) {
                        $successCount++;
                        logActivity('upload_folder_item', $sanitizedRelativePath); 
                    }
                }
            }
            $success = ($successCount > 0);
            if ($success) {
                $logDetail = $uploadedRootFolder ?: ($successCount . ' files');
                $successMsg = 'Successfully uploaded folder contents (' . $successCount . ' files).';
                logActivity('upload_folder', $logDetail);
            }
        }
    }
    // --- END: Upload Folder Logic ---

    // --- Upload from Link Logic ---
    if ($action === 'upload_from_link') {
        $fileLink = $_POST['file_link'] ?? '';
        $customName = $_POST['custom_name'] ?? '';
        if (!empty($fileLink)) {
            $fileName = basename(parse_url($fileLink, PHP_URL_PATH));
            $fileName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $fileName); // Sanitize filename
            if (empty($fileName)) {
                 $fileName = 'downloaded_' . time();
            }
            // Use custom name if provided
            if (!empty($customName)) {
                $fileName = basename($customName);
            }

            $uploadFile = $fullPath . '/' . $fileName;
            $fileContent = @file_get_contents($fileLink);
            if ($fileContent !== false) {
                $success = file_put_contents($uploadFile, $fileContent) !== false;
                if ($success) {
                    $successMsg = 'File downloaded and uploaded successfully: ' . basename($fileName);
                    logActivity('upload_from_link', basename($fileName));
                }
            }
        }
    }
    
    // --- Extract ZIP Handling (with a small modification for 'upload_and_extract') ---
    if ($action === 'extract_zip' || $action === 'upload_and_extract') {
        $fileName = '';
        $zipPath = '';

        if ($action === 'upload_and_extract') {
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $fileName = basename($_FILES['file']['name']);
                $zipPath = sys_get_temp_dir() . '/' . uniqid('extract_upload_') . $fileName;
                if (!move_uploaded_file($_FILES['file']['tmp_name'], $zipPath)) {
                    $success = false;
                    $successMsg = 'Failed to upload ZIP for extraction.';
                }
            } else {
                $success = false;
            }
        } else {
            $fileName = $_POST['file_name'] ?? '';
            $zipPath = $fullPath . '/' . basename($fileName);
        }

        if ($zipPath && file_exists($zipPath)) {
            $extractTo = $_POST['extract_to'] ?? pathinfo($fileName, PATHINFO_FILENAME);
            $extractPath = $fullPath . '/' . basename($extractTo);
            if (!is_dir($extractPath)) {
                mkdir($extractPath, 0777, true);
            }
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === TRUE) {
                $zip->extractTo($extractPath);
                $zip->close();
                $success = true;
                $successMsg = ($action === 'upload_and_extract') ? 'ZIP uploaded and extracted successfully' : 'ZIP extracted successfully';
                logActivity($action, basename($fileName));
            } else {
                $success = false;
                $successMsg = 'Failed to open ZIP archive.';
            }

            // Cleanup temp file if it was an upload and extract action
            if ($action === 'upload_and_extract' && file_exists($zipPath)) {
                unlink($zipPath);
            }
        }
    }
    // --- End Extract ZIP Handling ---

    // --- Compress Image Handling ---
    if ($action === 'compress_image') {
        $fileName = $_POST['file_name'] ?? '';
        $quality = (int)($_POST['quality'] ?? 75);
        if (!empty($fileName)) {
            $imagePath = $fullPath . '/' . basename($fileName);
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $compressedPath = $fullPath . '/' . pathinfo($fileName, PATHINFO_FILENAME) . '_compressed.' . $ext;
            $success = false;
            if (extension_loaded('gd')) {
                if ($ext === 'jpg' || $ext === 'jpeg') {
                    $image = @imagecreatefromjpeg($imagePath);
                    if ($image) {
                        $success = imagejpeg($image, $compressedPath, $quality);
                        imagedestroy($image);
                    }
                } elseif ($ext === 'png') {
                    // PNG compression level is 0 (no compression) to 9 (max compression)
                    $pngLevel = max(0, min(9, round(9 * (100 - $quality) / 100)));
                    $image = @imagecreatefrompng($imagePath);
                    if ($image) {
                        $success = imagepng($image, $compressedPath, $pngLevel);
                        imagedestroy($image);
                    }
                }
            }
            if ($success) {
                $successMsg = 'Image compressed successfully';
                logActivity('compress_image', basename($fileName));
            } else {
                $successMsg = extension_loaded('gd') ? 'Image compression failed.' : 'GD extension not loaded.';
            }
        }
    }
    // --- End Compress Image Handling ---

    // --- Lock/Unlock with Password ---
    if ($action === 'lock_with_password') {
        $itemName = $_POST['item_name'] ?? '';
        $password = $_POST['password'] ?? '';
        if (!empty($itemName) && !empty($password)) {
            $itemPath = $fullPath . '/' . basename($itemName);
            $lockFile = $itemPath . '.lock';
            $success = file_put_contents($lockFile, password_hash($password, PASSWORD_DEFAULT)) !== false;
            if ($success) {
                $successMsg = 'Item locked successfully';
                logActivity('lock', basename($itemName));
            }
        }
    }

    if ($action === 'unlock_with_password') {
        $itemName = $_POST['item_name'] ?? '';
        $password = $_POST['password'] ?? '';
        $afterAction = $_POST['after_action'] ?? '';
        if (!empty($itemName) && !empty($password)) {
            $itemPath = $fullPath . '/' . basename($itemName);
            $lockFile = $itemPath . '.lock';
            $storedHash = file_get_contents($lockFile);
            
            if ($storedHash && password_verify($password, $storedHash)) {
                $success = true;
                $successMsg = 'Item unlocked successfully';
                logActivity('unlock', basename($itemName));
                
                // Only permanently unlock (delete .lock file) if a permanent action is requested
                if (empty($afterAction) || $afterAction === 'delete') { 
                    $deleteSuccess = unlink($lockFile);
                    if (!$deleteSuccess) {
                        $successMsg .= ' (Warning: Lock file could not be permanently deleted)';
                    }
                } 

                // Redirect logic
                if ($afterAction) {
                    $redirectUrl .= '?success=1&msg=' . urlencode($successMsg) . '&after_action=' . urlencode($afterAction) . '&after_item=' . urlencode(basename($itemName));
                } else {
                    $redirectUrl .= '?success=1&msg=' . urlencode($successMsg);
                }
            } else {
                $success = false;
                $redirectUrl .= '?error=1&msg=Wrong password';
            }
        } else {
            $redirectUrl .= '?error=1&msg=Invalid input';
        }
        header("Location: " . $redirectUrl);
        exit;
    }
    // --- End Lock/Unlock with Password ---

    // --- Toggle Pin ---
    if ($action === 'toggle_pin') {
        $itemName = $_POST['item_name'] ?? '';
        if (!empty($itemName)) {
            $itemPath = $fullPath . '/' . basename($itemName);
            $pinFile = $itemPath . '.pinned';
            if (file_exists($pinFile)) {
                $success = unlink($pinFile);
                $successMsg = 'Item unpinned successfully';
                logActivity('unpin', basename($itemName));
            } else {
                $success = touch($pinFile);
                $successMsg = 'Item pinned successfully';
                logActivity('pin', basename($itemName));
            }
        }
    }
    // --- Set View Name ---
    if ($action === 'set_view_name') {
        $itemName = $_POST['item_name'] ?? '';
        $viewName = $_POST['view_name'] ?? '';
        if (!empty($itemName)) {
            $itemPath = $fullPath . '/' . basename($itemName);
            $viewFile = $itemPath . '.viewname';
            $viewNameTrim = trim($viewName);
            if ($viewNameTrim === '') {
                if (file_exists($viewFile)) {
                    $success = unlink($viewFile);
                    $successMsg = 'View name removed';
                    logActivity('remove_view_name', basename($itemName));
                } else {
                    $success = true;
                    $successMsg = 'No view name to remove';
                }
            } else {
                $success = file_put_contents($viewFile, $viewNameTrim) !== false;
                $successMsg = 'View name set successfully';
                logActivity('set_view_name', basename($itemName) . ' -> ' . $viewNameTrim);
            }
        }
    }
    // --- Upload Custom Icon ---
    if ($action === 'upload_icon') {
        $itemName = $_POST['item_name'] ?? '';
        if (!empty($itemName) && isset($_FILES['icon']) && $_FILES['icon']['error'] === UPLOAD_ERR_OK) {
            $iconsDir = $baseDir . '/Icons';
            if (!is_dir($iconsDir)) {
                mkdir($iconsDir, 0777, true);
            }
            $itemFullSubPath = ltrim($currentSubPath . '/' . basename($itemName), '/');
            // Use base path for name creation to ensure uniqueness across folders
            $iconName = str_replace('/', '_', $itemFullSubPath) . '_icon.png';
            $iconPath = $iconsDir . '/' . $iconName;
            $success = move_uploaded_file($_FILES['icon']['tmp_name'], $iconPath);
            if ($success) {
                $successMsg = 'Custom icon uploaded successfully';
                logActivity('upload_icon', basename($itemName));
            }
        }
    }
    // --- End Upload Custom Icon ---

    // --- START: AJAX/Redirect Handling ---
    if ($isAjax) {
        // Handle AJAX response for actions that support it (upload_multiple, upload_folder)
        header('Content-Type: application/json');
        if ($success) {
            echo json_encode(['success' => true, 'msg' => $successMsg]);
        } else {
            $errorMsg = $successMsg ?: 'Something went wrong.';
            echo json_encode(['success' => false, 'msg' => $errorMsg]);
        }
        exit;
    }

    // Standard Redirect for non-AJAX forms
    if ($success) {
        $redirectUrl .= '?success=1&msg=' . urlencode($successMsg);
    } elseif ($action !== 'unlock_with_password') { // unlock has its own redirect
        $errorMsg = $successMsg ?: 'Something went wrong.';
        $redirectUrl .= '?error=1&msg=' . urlencode($errorMsg);
    }
    header("Location: " . $redirectUrl);
    exit;
    // --- END: AJAX/Redirect Handling ---
}

// Get files and folders (only if folder exists)
$items = [];
if ($folderExists) {
    $scan = scandir($fullPath);
    foreach ($scan as $item) {
        // Skip current directory (.), parent directory (..)
        if ($item === '.' || $item === '..') continue; 
        
        $itemPath = $fullPath . '/' . $item;
        $isLocked = file_exists($itemPath . '.lock');
        $itemFullSubPath = ltrim($currentSubPath . '/' . $item, '/');
        $hasCustomIcon = file_exists($baseDir . '/Icons/' . str_replace('/', '_', $itemFullSubPath) . '_icon.png');
        // Subtract 2 for . and .. in directory count
        $totalAssets = is_dir($itemPath) ? count(scandir($itemPath)) - 2 : 0; 
        $items[] = [
            'name' => $item,
            'is_dir' => is_dir($itemPath),
            'size' => is_file($itemPath) ? filesize($itemPath) : 0,
            'date' => date('m/d/Y', filemtime($itemPath)),
            'ext' => is_file($itemPath) ? pathinfo($item, PATHINFO_EXTENSION) : '',
            'path' => $itemPath, // full path on server
            'is_locked' => $isLocked,
            'has_custom_icon' => $hasCustomIcon,
            'total_assets' => $totalAssets,
            'is_pinned' => file_exists($itemPath . '.pinned'),
            'view_name' => file_exists($itemPath . '.viewname') ? trim(file_get_contents($itemPath . '.viewname')) : '',
        ];
    }
}

usort($items, function($a, $b) {
    if ($a['is_pinned'] !== $b['is_pinned']) {
        return $a['is_pinned'] ? -1 : 1;
    }
    return strcmp($a['name'], $b['name']);
});

function formatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' kB';
    return $bytes . ' B';
}

function getFileIcon($ext) {
    $images = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico'];
    $videos = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', '3gp'];
    $audio = ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'wma'];
    // Added php, html, css, js, json, xml, txt, md, sh to this list
    $code = ['php', 'html', 'css', 'js', 'json', 'xml', 'txt', 'md', 'sh', 'sql', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'ts', 'jsx', 'tsx'];
    $documents = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'rtf', 'odt', 'ods', 'odp'];
    $archives = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'iso'];
    $executables = ['exe', 'bin', 'msi', 'dmg', 'deb', 'rpm', 'apk'];
    $fonts = ['ttf', 'otf', 'woff', 'woff2', 'eot'];
    $databases = ['db', 'sqlite'];
    $configs = ['ini', 'cfg', 'conf', 'yaml', 'yml', 'properties'];
    
    // Updated icons for better look
    if (in_array(strtolower($ext), $images)) return ['icon' => 'fa-image', 'color' => 'blue', 'type' => 'image', 'label' => 'Image'];
    if (in_array(strtolower($ext), $videos)) return ['icon' => 'fa-video', 'color' => 'gray', 'type' => 'video', 'label' => 'Video'];
    if (in_array(strtolower($ext), $audio)) return ['icon' => 'fa-music', 'color' => 'purple', 'type' => 'audio', 'label' => 'Audio'];
    if (in_array(strtolower($ext), $code)) return ['icon' => 'fa-file-code', 'color' => 'blue', 'type' => 'code', 'label' => 'Code'];
    if (in_array(strtolower($ext), $documents)) return ['icon' => 'fa-file-alt', 'color' => 'purple', 'type' => 'document', 'label' => 'Document'];
    if (in_array(strtolower($ext), $archives)) return ['icon' => 'fa-file-archive', 'color' => 'green', 'type' => 'archive', 'label' => 'Archive'];
    if (in_array(strtolower($ext), $executables)) return ['icon' => 'fa-cog', 'color' => 'red', 'type' => 'executable', 'label' => 'Executable'];
    if (in_array(strtolower($ext), $fonts)) return ['icon' => 'fa-font', 'color' => 'gray', 'type' => 'font', 'label' => 'Font'];
    if (in_array(strtolower($ext), $databases)) return ['icon' => 'fa-database', 'color' => 'indigo', 'type' => 'database', 'label' => 'Database'];
    if (in_array(strtolower($ext), $configs)) return ['icon' => 'fa-cogs', 'color' => 'teal', 'type' => 'config', 'label' => 'Config'];
    return ['icon' => 'fa-file', 'color' => 'gray', 'type' => 'file', 'label' => 'File'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin File Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* BASE & GAPS */
        /* Full Screen Modals/Sidebar: Add the top gap on mobile */
        #editModal, #mediaModal, #fileModal {
            top: 0;
        }
        /* #uploadModal is no longer full-screen */

        @media (max-width: 640px) {
            /* Change: Apply padding to main-content for white gap */
            #main-content {
                padding-top: calc(0.87cm + 0.1cm); 
            }
            #main-content > .bg-gray-800 > div {
                position: relative;
                z-index: 10;
                background-color: #1f2937; /* Ensure header content is dark */
            }
            /* Change: Use margin-top to create white gap in full-screen modals */
            #editModal .h-full, #fileModal .h-full {
                margin-top: calc(0.87cm + 0.1cm); /* Push content down by gap */
                height: calc(100vh - calc(0.87cm + 0.1cm));
            }
        }
        
        .blur-bg {
            backdrop-filter: blur(10px);
            background-color: rgba(0, 0, 0, 0.7);
        }
        .thumbnail {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 4px;
        }
        /* New class for main content height */
        .h-screen-minus-header {
            /* Full screen height - (Top Bar 52px) - (Breadcrumb 52px) - (Storage Bar 32px) = 136px */
            height: calc(100vh - 136px); 
            overflow-y: auto;
        }

        /* Loader Bar Styling */
        #bottomLoaderContainer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
            background: #1f2937;
            padding: 4px 8px;
            display: none;
            box-shadow: 0 -2px 5px rgba(0, 0, 0, 0.5);
        }
        #bottomLoaderProgress {
            color: white;
            font-size: 0.75rem;
            margin-bottom: 2px;
        }
        #bottomLoader {
            width: 100%;
            background-color: #374151;
            border-radius: 4px;
            overflow: hidden;
            height: 6px;
        }
        #loaderBar {
            height: 100%;
            background-color: #10b981;
            width: 0%;
            /* Changed transition to linear for smoother real progress */
            transition: width 0.1s linear;
        }
        
        /* Custom Selection Styles */
        .item-row.selected {
            background-color: #d1fae5; /* Tailwind raw green-100 */
        }
        .item-row.selected:hover {
            background-color: #a7f3d0; /* Tailwind raw green-200 */
        }

        /* Hide checkboxes initially unless in selection mode */
        input[type="checkbox"] {
            display: none;
        }
        .item-row.selected input[type="checkbox"] {
            display: block; /* Show check mark only when selected */
        }

        /* Lock Icon */
        .lock-icon {
            color: #ef4444;
            font-size: 0.75rem;
        }

        /* Custom Icon Thumbnail */
        .custom-icon {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 4px;
        }
        
        /* New Button UI */
        .btn-flat {
            padding: 4px 8px;
            border-radius: 6px;
            font-weight: 500;
            transition: background-color 0.15s, transform 0.1s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            font-size: 0.75rem;
        }
        .btn-yellow { background-color: #f59e0b; color: white; }
        .btn-yellow:hover { background-color: #d97706; transform: translateY(-1px); }
        .btn-blue { background-color: #3b82f6; color: white; }
        .btn-blue:hover { background-color: #2563eb; transform: translateY(-1px); }
        .btn-green { background-color: #10b981; color: white; }
        .btn-green:hover { background-color: #059669; transform: translateY(-1px); }
        .btn-gray { background-color: #6b7280; color: white; }
        .btn-gray:hover { background-color: #4b5563; transform: translateY(-1px); }
        
        /* Single Select Bar - Condensing text and using flat design */
        #singleSelectBar button {
            white-space: nowrap; /* Keep text in one line */
            padding: 4px 6px; /* Reduced padding */
            font-size: 0.75rem; /* Smaller font */
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 2px;
        }

        /* Line Numbers Styles */
        #lineNumbers, #createLineNumbers { /* Added #createLineNumbers */
            line-height: 1.5em;
            white-space: pre;
            padding-top: 16px; /* Match textarea padding */
            padding-bottom: 16px; /* Match textarea padding */
            font-size: 0.875rem; /* Match text-sm */
        }
        #edit_file_content, #create_file_content { /* Added #create_file_content */
            line-height: 1.5em;
            font-size: 0.875rem; /* Match text-sm */
        }
        
        /* Storage Bar Styling */
        #storageBar {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            z-index: 50;
            background: white;
            padding: 4px;
            border-top: 1px solid #e5e7eb;
            font-size: 0.75rem;
            color: #4b5563;
        }
        #storageBarProgress {
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }
        #storageBarFill {
            height: 100%;
            background-color: #10b981;
            width: <?php echo $storage_percent; ?>%;
        }
        .storage-text {
            font-size: 0.75rem;
            color: #4b5563;
        }

        /* --- New Modal Animation Styles --- */
        .popup-modal {
            align-items: flex-end; /* Overrides items-center */
            justify-content: center;
        }
        .popup-content {
            transform: translateY(100%);
            transition: transform 0.3s ease-out;
            margin-bottom: 1rem; /* Space from bottom edge */
            max-width: 500px; /* Add a max-width for consistency */
            width: 100%;
        }
        .popup-modal:not(.hidden) .popup-content {
            transform: translateY(0);
        }
        /* Fix for share modal which has a custom class */
        .share-modal {
             margin-bottom: 1rem;
             max-width: 500px;
             width: 100%;
        }
        /* Apply transform to share-modal directly */
        #shareModal .share-modal {
            transform: translateY(100%);
            transition: transform 0.3s ease-out;
        }
        #shareModal:not(.hidden) .share-modal {
            transform: translateY(0);
        }
        /* Fix details modal content which is not on a bg-white */
        #detailsModal .bg-blue-500 {
            transform: translateY(100%);
            transition: transform 0.3s ease-out;
            margin-bottom: 1rem;
             max-width: 500px;
             width: 100%;
        }
        #detailsModal:not(.hidden) .bg-blue-500 {
            transform: translateY(0);
        }
        /* --- End New Modal Animation Styles --- */
        
        /* Accordion Styles */
        details summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.25rem 0.5rem;
            background-color: #f3f4f6;
            border-radius: 0.375rem;
            cursor: pointer;
            transition: background-color 0.2s;
            font-size: 0.75rem;
        }
        details summary:hover {
            background-color: #e5e7eb;
        }
        details[open] summary {
            background-color: #e5e7eb;
        }

        /* Sidebar Menu Styles */
        #sideMenu {
            position: fixed;
            top: calc(0.87cm + 0.1cm);
            left: 0;
            height: calc(100vh - calc(0.87cm + 0.1cm));
            width: 80%;
            max-width: 320px;
            background-color: white;
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            z-index: 50;
            overflow-y: auto;
        }
        #sideMenu.open {
            transform: translateX(0);
        }
        #menuOverlay {
            position: fixed;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.5);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease-in-out;
            z-index: 40;
        }
        #menuOverlay.open {
            opacity: 1;
            pointer-events: auto;
        }
        #sideMenu .text-white {
            color: white;
        }
        /* Switch Styles */
        .switch {
            position: relative;
            display: inline-block;
            width: 34px;
            height: 20px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 12px;
            width: 12px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #2196F3;
        }
        input:checked + .slider:before {
            transform: translateX(14px);
        }

        /* Bold Effects */
        #sideMenu details summary {
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        /* Small Fonts */
        body {
            font-size: 0.75rem;
        }
        h1, h2, h3 {
            font-size: 0.875rem;
        }
        .text-lg {
            font-size: 0.75rem;
        }
        .text-sm {
            font-size: 0.625rem;
        }

        #itemsContainer.grid-view {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 16px;
  padding: 16px;
}
#itemsContainer.grid-view .item-row {
  background-color: white;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 12px;
  text-align: center;
  transition: all 0.2s;
}
#itemsContainer.grid-view .item-row:hover {
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
#itemsContainer.grid-view .item-row .flex {
  flex-direction: column;
  align-items: center;
}
#itemsContainer.grid-view .item-row .w-12 {
  width: 64px;
  height: 64px;
  margin-bottom: 8px;
}
#itemsContainer.grid-view .item-row h3 {
  font-size: 0.875rem;
  margin-bottom: 4px;
}
#itemsContainer.grid-view .item-row p {
  font-size: 0.75rem;
}
#itemsContainer.grid-view .item-row input[type="checkbox"] {
  position: absolute;
  top: 8px;
  left: 8px;
}

    </style>
</head>
<body class="bg-gray-50">
    <div id="menuOverlay" class="" onclick="hideSideMenu()"></div>
    <div id="bottomLoaderContainer">
        <div id="bottomLoaderProgress">0.00%</div>
        <div id="bottomLoader">
            <div class="loader-bar" id="loaderBar"></div>
        </div>
    </div>
    
    <div id="main-content" class="transition-all duration-300 pb-8">
        
        <?php if (isset($_GET['success']) && isset($_GET['msg'])): ?>
            <div id="successMsg" class="bg-green-500 text-white p-4 text-center">
                <?php echo htmlspecialchars(urldecode($_GET['msg'])); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && isset($_GET['msg'])): ?>
            <div id="errorMsg" class="bg-red-500 text-white p-4 text-center">
                <?php echo htmlspecialchars(urldecode($_GET['msg'])); ?>
            </div>
        <?php elseif (isset($_GET['error'])): ?>
             <div id="errorMsg" class="bg-red-500 text-white p-4 text-center">
                Something went wrong.
            </div>
        <?php endif; ?>

        <div class="bg-gray-800 text-white sticky top-0 z-80 shadow-lg">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-4">
                    <button onclick="showSideMenu()" class="text-2xl hover:text-red-400">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 id="titleText" class="text-xl font-semibold">Admin Manager</h1>
                </div>
                <div class="flex items-center gap-4">
                    <div id="normalTools" class="flex items-center gap-4">
                        <button id="searchBtn" onclick="toggleSearch()" class="text-2xl hover:text-red-400">
                            <i class="fas fa-search"></i>
                        </button>
                        <button id="reloadBtn" onclick="reloadPage()" class="text-2xl hover:text-red-400">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                        <button id="createBtn" onclick="showCreateModal()" class="text-2xl hover:text-red-400">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    <div id="selectionTools" class="flex items-center gap-4 hidden">
                        <button onclick="selectAllItems()" class="text-xl hover:text-red-400 flex items-center">
                            <i class="fas fa-check-square"></i>
                        </button>
                        <button id="topDownloadBtn" onclick="handleTopDownload()" class="text-xl hover:text-red-400 flex items-center">
                            <i class="fas fa-download"></i>
                        </button>
                        <button id="topDeleteBtn" onclick="handleTopDelete()" class="text-xl hover:text-red-400 flex items-center">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div id="singleSelectBar" class="hidden bg-gray-100 px-4 py-2 flex gap-2 items-center sticky top-[52px] z-60 backdrop-blur-sm border-b border-gray-200 overflow-x-auto">
            <button id="barDetails" onclick="showDetails()" class="btn-flat bg-cyan-500 text-white hover:bg-cyan-600">
                <i class="fas fa-info-circle"></i>Details
            </button>
            <button id="barEdit" onclick="editItem()" class="btn-flat bg-blue-500 text-white hover:bg-blue-600">
                <i class="fas fa-edit"></i>Edit
            </button>
            <button id="barView" onclick="openOrViewItem()" class="btn-flat bg-green-500 text-white hover:bg-green-600">
                <i class="fas fa-eye"></i>View/Open
            </button>
            <button onclick="showShareModal(currentItem, currentIsDir)" class="btn-flat bg-indigo-500 text-white hover:bg-indigo-600">
                <i class="fas fa-share"></i>Share
            </button>
            <button onclick="renameItem()" class="btn-flat bg-yellow-500 text-white hover:bg-yellow-600">
                <i class="fas fa-signature"></i>Rename
            </button>
            <button id="barDownload" onclick="showSingleDownloadAsModal()" class="btn-flat bg-purple-500 text-white hover:bg-purple-600">
                <i class="fas fa-download"></i>Download
            </button>
            <button id="barExtract" onclick="extractItem()" class="hidden btn-flat bg-orange-500 text-white hover:bg-orange-600">
                <i class="fas fa-file-archive"></i>Extract ZIP
            </button>
            <button id="barCompress" onclick="compressItem()" class="hidden btn-flat bg-teal-500 text-white hover:bg-teal-600">
                <i class="fas fa-compress"></i>Compress
            </button>
            <button id="barLock" onclick="toggleLock()" class="btn-flat bg-gray-500 text-white hover:bg-gray-600">
                <i class="fas fa-lock"></i>Lock
            </button>
            <button onclick="showIconUploadModal()" class="btn-flat bg-pink-500 text-white hover:bg-pink-600">
                <i class="fas fa-image"></i>Icon
            </button>
            <button id="barPin" onclick="togglePin()" class="btn-flat bg-amber-500 text-white hover:bg-amber-600">
                <i class="fas fa-thumbtack"></i> Pin
            </button>
            <button onclick="setViewName()" class="btn-flat bg-violet-500 text-white hover:bg-violet-600">
                <i class="fas fa-mask"></i> View Name
            </button>
            <button id="barDelete" onclick="handleTopDelete()" class="btn-flat bg-red-500 text-white hover:bg-red-600">
                <i class="fas fa-trash"></i>Delete
            </button>
        </div>

        <div class="bg-white border-b border-gray-200 px-4 py-3 sticky top-[52px] z-70">
            <div class="flex items-center gap-2 text-gray-600 overflow-x-auto">
                <?php 
                // Base link to the script's directory
                $rootUrl = $scriptName;
                $currentProjectName = basename($baseDir);
                ?>
                <a href="<?php echo $rootUrl; ?>" class="flex items-center gap-2 hover:text-red-600">
                    <i class="fas fa-home text-red-500 text-2xl"></i>
                    <span class="font-medium">Root (<?php echo htmlspecialchars($currentProjectName); ?>)</span>
                </a>
                
                <?php 
                // Show breadcrumbs for subfolders
                if ($subPath): 
                    $pathParts = explode('/', $subPath);
                    $cumulativePath = '';
                    for ($i = 0; $i < count($pathParts); $i++):
                        if (empty($pathParts[$i])) continue;
                        
                        $cumulativePath .= ($i > 0 ? '/' : '') . $pathParts[$i];
                ?>
                        <i class="fas fa-chevron-right text-gray-400 text-sm"></i>
                        <a href="<?php echo createLink($cumulativePath, $scriptName); ?>" class="hover:text-red-600">
                            <?php echo htmlspecialchars($pathParts[$i]); ?>
                        </a>
                    <?php endfor; 
                endif; ?>
            </div>
        </div>

        <div id="searchBar" class="bg-white border-b border-gray-200 px-4 py-2 hidden flex items-center">
            <input type="text" id="searchInput" placeholder="Search files and folders..." class="flex-grow px-4 py-2 border rounded-lg" oninput="filterItems()">
            <button onclick="webSearch()" class="ml-2 text-2xl hover:text-red-400">
                <i class="fas fa-globe"></i>
            </button>
        </div>

        <div id="itemsContainer" class="max-w-4xl mx-auto pb-6 h-screen-minus-header">
            <?php if ($folderExists): ?>
                
                <?php 
                // --- START: Parent Directory Logic ---
                $parentLink = '';
                $show_up_link = false;

                if ($subPath) {
                    $currentPathSegments = explode('/', $subPath);
                    
                    // If we are deeper than the base directory
                    if (count($currentPathSegments) > 0) {
                        array_pop($currentPathSegments);
                        $parentSubPath = implode('/', $currentPathSegments);
                        $parentLink = createLink($parentSubPath, $scriptName);
                        $show_up_link = true;
                    }
                }
                
                if ($show_up_link):
                // --- END: Parent Directory Logic ---
                ?>
                    <div class="bg-white border-b border-gray-200 hover:bg-gray-50 transition-colors item-row">
                        <div class="flex items-center px-4 py-3">
                            <a href="<?php echo $parentLink; ?>" class="flex items-center flex-1 min-w-0 text-gray-500">
                                <div class="w-12 h-12 flex items-center justify-center mr-4">
                                    <i class="fas fa-level-up-alt text-gray-400 text-3xl"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-lg font-medium truncate">..</h3>
                                </div>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php foreach ($items as $item): ?>
                    <?php 
                        // Construct the full relative subpath for file operations/links
                        $itemFullSubPath = ltrim($currentSubPath . '/' . $item['name'], '/');
                        // Construct the URL path relative to the document root for image/media display
                        $itemUrlPath = '/' . $itemFullSubPath; // Assumes script is in document root
                        
                        // Pass required attributes for JS long-press handler
                        $item_data_attrs = "data-name='" . htmlspecialchars($item['name']) . "' data-is-dir='" . ($item['is_dir'] ? 'true' : 'false') . "' data-type='" . ($item['is_dir'] ? 'folder' : getFileIcon($item['ext'])['type']) . "' data-ext='" . htmlspecialchars($item['ext']) . "' data-locked='" . ($item['is_locked'] ? 'true' : 'false') . "' data-size='" . $item['size'] . "' data-date='" . $item['date'] . "' data-total-assets='" . $item['total_assets'] . "' data-is-pinned='" . ($item['is_pinned'] ? 'true' : 'false') . "' data-view-name='" . htmlspecialchars($item['view_name']) . "'";
                        
                        // Add class to identify rows for selection
                        $row_classes = 'item-row item-selectable bg-white border-b border-gray-200 hover:bg-gray-50 transition-colors';
                    ?>
                    <?php if ($item['is_dir']): ?>
                        <div class="<?php echo $row_classes; ?>" <?php echo $item_data_attrs; ?> data-link="<?php echo createLink($itemFullSubPath, $scriptName); ?>">
                            <div onclick="openFileOrEdit('<?php echo htmlspecialchars($item['name']); ?>', 'folder', true)" class="flex items-center px-4 py-3 cursor-pointer">
                                <input type="checkbox" id="check-<?php echo htmlspecialchars($item['name']); ?>" data-item-name="<?php echo htmlspecialchars($item['name']); ?>" class="mr-4 w-5 h-5 text-green-600 bg-gray-100 border-gray-300 rounded focus:ring-green-500" onclick="toggleSelection(event, this)">
                                
                                <div class="flex items-center flex-1 min-w-0">
                                    <div class="w-12 h-12 flex items-center justify-center mr-4 relative">
                                        <?php if ($item['has_custom_icon']): ?>
                                            <img src="/Icons/<?php echo str_replace('/', '_', $itemFullSubPath); ?>_icon.png" class="custom-icon" alt="Custom Icon">
                                        <?php else: ?>
                                            <i class="fas fa-folder text-yellow-600 text-3xl"></i>
                                        <?php endif; ?>
                                        <?php if ($item['is_pinned']): ?>
                                            <i class="fas fa-thumbtack text-amber-500 absolute top-1 left-1 transform -rotate-45 text-sm"></i>
                                        <?php endif; ?>
                                        <?php if ($item['view_name']): ?>
                                            <i class="fas fa-mask text-violet-500 absolute bottom-1 right-1 text-sm"></i>
                                        <?php endif; ?>
                                        <?php if ($item['is_locked']): ?>
                                            <i class="fas fa-lock lock-icon absolute -top-1 -right-1"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="text-lg font-medium text-gray-900 truncate"><?php echo htmlspecialchars($item['view_name'] ?: $item['name']); ?></h3>
                                        <p class="text-sm text-gray-500"><?php echo $item['date']; ?> • <?php echo $item['total_assets']; ?> items</p>
                                    </div>
                                </div>
                                </div>
                        </div>
                    <?php else: ?>
                        <?php $fileInfo = getFileIcon($item['ext']); ?>
                        <div class="<?php echo $row_classes; ?>" <?php echo $item_data_attrs; ?>>
                            <div onclick="openFileOrEdit('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo $fileInfo['type']; ?>', false)" class="flex items-center px-4 py-3 cursor-pointer">
                                <input type="checkbox" id="check-<?php echo htmlspecialchars($item['name']); ?>" data-item-name="<?php echo htmlspecialchars($item['name']); ?>" class="mr-4 w-5 h-5 text-green-600 bg-gray-100 border-gray-300 rounded focus:ring-green-500" onclick="toggleSelection(event, this)">
                                
                                <div class="flex items-center flex-1 min-w-0">
                                    <div class="w-12 h-12 flex items-center justify-center mr-4 relative">
                                        <?php if ($item['has_custom_icon']): ?>
                                            <img src="/Icons/<?php echo str_replace('/', '_', $itemFullSubPath); ?>_icon.png" class="custom-icon" alt="Custom Icon">
                                        <?php elseif ($fileInfo['type'] === 'image'): ?>
                                            <img src="<?php echo $itemUrlPath; ?>" class="thumbnail" alt=""> 
                                        <?php elseif ($fileInfo['type'] === 'video'): ?>
                                            <div class="w-12 h-12 bg-gray-900 rounded flex items-center justify-center overflow-hidden">
                                                <i class="fas fa-play text-red-500 text-xl absolute"></i>
                                                <i class="fas fa-video text-white text-3xl opacity-50"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-12 h-12 bg-<?php echo $fileInfo['color']; ?>-500 rounded flex items-center justify-center">
                                                <i class="fas <?php echo $fileInfo['icon']; ?> text-white text-xl"></i>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($item['is_pinned']): ?>
                                            <i class="fas fa-thumbtack text-amber-500 absolute top-1 left-1 transform -rotate-45 text-sm"></i>
                                        <?php endif; ?>
                                        <?php if ($item['view_name']): ?>
                                            <i class="fas fa-mask text-violet-500 absolute bottom-1 right-1 text-sm"></i>
                                        <?php endif; ?>
                                        <?php if ($item['is_locked']): ?>
                                            <i class="fas fa-lock lock-icon absolute -top-1 -right-1"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="text-base font-normal text-gray-900 truncate"><?php echo htmlspecialchars($item['view_name'] ?: $item['name']); ?></h3>
                                        <p class="text-sm text-gray-500"><?php echo formatSize($item['size']); ?> • <?php echo $item['date']; ?></p>
                                    </div>
                                </div>
                                </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if (empty($items) && is_dir($fullPath)): ?>
                    <div class="bg-white py-16 text-center">
                        <i class="fas fa-folder-open text-gray-300 text-6xl mb-4"></i>
                        <p class="text-gray-500 text-lg">This folder is empty</p>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="bg-white py-16 text-center">
                    <i class="fas fa-exclamation-triangle text-red-500 text-6xl mb-4"></i>
                    <p class="text-gray-700 text-xl font-semibold">Folder Not Found</p>
                    <p class="text-gray-500 text-lg mt-2">The requested folder does not exist or is inaccessible.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="storageBar" class="flex justify-between text-sm">
        Total storage: 5.00 GB Used: 1.83 MB Available: 5.00 GB
    </div>
    
    <div id="createModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4">Create New</h2>
            <div class="space-y-3">
                <button onclick="showFolderForm()" class="w-full btn-flat btn-yellow">
                    <i class="fas fa-folder mr-2"></i> Create Folder
                </button>
                <button onclick="showFileForm()" class="w-full btn-flat btn-blue">
                    <i class="fas fa-file mr-2"></i> Create File
                </button>
                <button onclick="showUploadForm()" class="w-full btn-flat btn-green">
                    <i class="fas fa-cloud-upload-alt mr-2"></i> Upload / Tools
                </button>
                <button onclick="hideCreateModal()" class="w-full btn-flat btn-gray">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <div id="folderModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4">Create Folder</h2>
            <form method="POST" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Creating folder')">
                <input type="hidden" name="action" value="create_folder">
                <input type="text" name="folder_name" placeholder="Folder name" class="w-full px-4 py-2 border rounded-lg mb-4" required>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 btn-flat btn-yellow">Create</button>
                    <button type="button" onclick="hideFolderModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="fileModal" class="hidden fixed inset-0 z-50 bg-white">
        <div class="h-full flex flex-col">
            <div id="fileModalHeader" class="bg-gray-800 text-white sticky top-0 z-10 shadow-lg border-b border-gray-700">
                <div class="flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-4 flex-1 min-w-0">
                        <button type="button" onclick="hideFileModal()" class="text-2xl hover:text-red-400" title="Back">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <h2 class="text-lg font-semibold truncate">Create New File</h2>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" form="createFileForm" class="text-2xl text-green-400 hover:text-green-300" title="Save">
                            <i class="fas fa-save"></i>
                        </button>
                    </div>
                </div>
            </div>
            <form method="POST" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Creating file')" class="flex flex-col flex-grow overflow-hidden" id="createFileForm">
                <input type="hidden" name="action" value="create_file">
                <div class="p-4 border-b border-gray-200 bg-gray-50">
                     <input type="text" name="file_name" placeholder="File name (e.g., index.php)" class="w-full px-4 py-2 border rounded-lg" required>
                </div>
                <div class="flex flex-grow overflow-hidden">
                    <div id="createLineNumbers" class="bg-gray-100 px-4 py-4 overflow-y-hidden text-right text-gray-500 font-mono text-sm select-none" style="width: 50px;"></div>
                    <textarea name="file_content" id="create_file_content" placeholder="File content..." class="flex-grow p-4 border-0 resize-none font-mono text-sm bg-white focus:ring-0 focus:border-0 overflow-y-auto" wrap="off" onscroll="syncCreateLineNumbers()" oninput="updateCreateLineNumbers()" onkeyup="updateCreateLineNumbers()"></textarea>
                </div>
                <div class="bg-gray-100 border-t border-gray-200 p-2 flex justify-end items-center sticky bottom-0 z-10">
                   </div>
            </form>
        </div>
    </div>
    
    <div id="uploadModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center gap-2">
                    <button id="uploadBackBtn" class="text-2xl hover:text-red-400 hidden" onclick="showUploadView('uploadMenu', 'File Tools')">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h2 class="text-2xl font-bold text-gray-800" id="uploadModalTitle">File Tools</h2>
                </div>
                <button onclick="hideUploadModal()" class="text-2xl hover:text-red-400">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="overflow-y-auto max-h-[70vh]">
                
                <div id="uploadMenu" class="space-y-3">
                    <button onclick="showUploadView('uploadSingleFormView', 'Upload Single File')" class="w-full btn-flat btn-green">
                        <i class="fas fa-upload mr-2"></i> Upload Single File
                    </button>
                    <button onclick="showUploadView('uploadMultiFormView', 'Upload Multiple Files')" class="w-full btn-flat bg-blue-500 hover:bg-blue-600">
                        <i class="fas fa-copy mr-2"></i> Upload Multiple Files
                    </button>
                    <button onclick="showUploadView('uploadFolderFormView', 'Upload Folder')" class="w-full btn-flat bg-purple-500 hover:bg-purple-600">
                        <i class="fas fa-folder mr-2"></i> Upload Folder
                    </button>
                    <button onclick="showUploadView('recordVoiceFormView', 'Record Voice')" class="w-full btn-flat bg-pink-500 hover:bg-pink-600">
                        <i class="fas fa-microphone mr-2"></i> Record Voice & Upload
                    </button>
                    <button onclick="showUploadView('createFolderFormView', 'Create Folder')" class="w-full btn-flat btn-yellow">
                        <i class="fas fa-folder-plus mr-2"></i> Create Folder
                    </button>
                    <button onclick="showUploadView('uploadLinkFormView', 'Upload by Link')" class="w-full btn-flat bg-indigo-500 hover:bg-indigo-600">
                        <i class="fas fa-link mr-2"></i> Upload by Link
                    </button>
                    <button onclick="showUploadView('capturePhotoFormView', 'Capture Photo')" class="w-full btn-flat bg-red-500 hover:bg-red-600">
                        <i class="fas fa-camera mr-2"></i> Capture Photo & Upload
                    </button>
                    <button onclick="showUploadView('captureVideoFormView', 'Record Video')" class="w-full btn-flat bg-red-500 hover:bg-red-600">
                        <i class="fas fa-video mr-2"></i> Record Video & Upload
                    </button>
                    <button onclick="showUploadView('uploadExtractFormView', 'Upload & Extract ZIP')" class="w-full btn-flat bg-orange-500 hover:bg-orange-600">
                        <i class="fas fa-box-open mr-2"></i> Upload & Extract ZIP
                    </button>
                </div>

                <div id="uploadSingleFormView" class="hidden">
                    <form method="POST" enctype="multipart/form-data" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Uploading file')">
                        <input type="hidden" name="action" value="upload">
                        <div class="p-4 border rounded-lg shadow-sm bg-gray-50 mb-4">
                            <input type="file" name="file" id="uploadFileInput" class="w-full" required>
                        </div>
                        <button type="submit" class="w-full btn-flat btn-green">Start Upload</button>
                    </form>
                </div>

                <div id="uploadMultiFormView" class="hidden">
                    <form method="POST" enctype="multipart/form-data" action="<?php echo $redirectUrl; ?>" onsubmit="event.preventDefault(); uploadFormWithProgress(this);">
                        <input type="hidden" name="action" value="upload_multiple">
                        <input type="hidden" name="ajax" value="true">
                        <div class="p-4 border rounded-lg shadow-sm bg-gray-50 mb-4">
                            <input type="file" name="multiple_files[]" multiple id="multiUploadFileInput" class="w-full" required>
                        </div>
                        <button type="submit" class="w-full btn-flat bg-blue-500 hover:bg-blue-600">Start Multi Upload</button>
                    </form>
                </div>

                <div id="uploadFolderFormView" class="hidden">
                    <form method="POST" enctype="multipart/form-data" action="<?php echo $redirectUrl; ?>" onsubmit="event.preventDefault(); uploadFormWithProgress(this);">
                        <input type="hidden" name="action" value="upload_folder">
                        <input type="hidden" name="ajax" value="true">
                        <div class="p-4 border rounded-lg shadow-sm bg-gray-50 mb-4">
                            <input type="file" name="folder_files[]" webkitdirectory directory multiple class="w-full" required>
                        </div>
                        <button type="submit" class="w-full btn-flat bg-purple-500 hover:bg-purple-600">Start Folder Upload</button>
                    </form>
                </div>

                <div id="recordVoiceFormView" class="hidden">
                    <div class="text-center">
                        <button id="startRecord" class="btn-flat bg-green-500 text-white hover:bg-green-600 mb-4">
                            <i class="fas fa-microphone"></i> Start Recording
                        </button>
                        <button id="stopRecord" class="btn-flat bg-red-500 text-white hover:bg-red-600 mb-4 hidden">
                            <i class="fas fa-stop"></i> Stop Recording
                        </button>
                        <audio id="audioPreview" controls class="w-full hidden"></audio>
                        <button id="uploadRecord" class="btn-flat bg-blue-500 text-white hover:bg-blue-600 mt-4 hidden">
                            <i class="fas fa-upload"></i> Upload Recording
                        </button>
                    </div>
                </div>

                <div id="createFolderFormView" class="hidden">
                    <form method="POST" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Creating folder')">
                        <input type="hidden" name="action" value="create_folder">
                        <div class="p-4 border rounded-lg shadow-sm bg-gray-50 mb-4">
                            <input type="text" name="folder_name" placeholder="New folder name" class="w-full px-4 py-2 border rounded-lg" required>
                        </div>
                        <button type="submit" class="w-full btn-flat btn-yellow">Create Folder</button>
                    </form>
                </div>
                
                <div id="uploadLinkFormView" class="hidden">
                    <form method="POST" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Downloading from link')">
                        <input type="hidden" name="action" value="upload_from_link">
                        <div class="p-4 border rounded-lg shadow-sm bg-gray-50 mb-4 space-y-3">
                            <input type="url" name="file_link" placeholder="http://example.com/file.zip" class="w-full px-4 py-2 border rounded-lg" required>
                            <input type="text" name="custom_name" placeholder="Save as file name (optional)" class="w-full px-4 py-2 border rounded-lg">
                        </div>
                        <button type="submit" class="w-full btn-flat bg-indigo-500 hover:bg-indigo-600">Download & Save</button>
                    </form>
                </div>

                <div id="capturePhotoFormView" class="hidden">
                    <form method="POST" enctype="multipart/form-data" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Uploading photo')" id="photoUploadForm">
                        <input type="hidden" name="action" value="upload">
                        <div class="p-4 border rounded-lg shadow-sm bg-gray-50 mb-4">
                            <input type="file" name="file" accept="image/*" capture="camera" class="w-full" required>
                        </div>
                        <button type="submit" class="w-full btn-flat bg-red-500 hover:bg-red-600">Capture Photo & Upload</button>
                    </form>
                </div>
                <div id="captureVideoFormView" class="hidden">
                    <form method="POST" enctype="multipart/form-data" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Uploading video')" id="videoUploadForm">
                        <input type="hidden" name="action" value="upload">
                        <div class="p-4 border rounded-lg shadow-sm bg-gray-50 mb-4">
                            <input type="file" name="file" accept="video/*" capture="camera" class="w-full" required>
                        </div>
                        <button type="submit" class="w-full btn-flat bg-red-500 hover:bg-red-600">Record Video & Upload</button>
                    </form>
                </div>

                <div id="uploadExtractFormView" class="hidden">
                     <form method="POST" enctype="multipart/form-data" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Uploading & extracting ZIP')" id="uploadExtractForm">
                        <input type="hidden" name="action" value="upload_and_extract">
                        <div class="p-4 border rounded-lg shadow-sm bg-gray-50 mb-4 space-y-3">
                            <input type="file" name="file" accept=".zip" class="w-full" required>
                            <input type="text" name="extract_to" placeholder="Extract to folder (optional)" class="w-full px-4 py-2 border rounded-lg">
                        </div>
                        <button type="submit" class="w-full btn-flat bg-orange-500 hover:bg-orange-600">Upload & Extract</button>
                    </form>
                </div>

            </div>
        </div>
    </div>
    <div id="iconUploadModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4" id="icon_upload_title">Upload Custom Icon</h2>
            <form method="POST" enctype="multipart/form-data" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Uploading icon')" id="iconUploadForm">
                <input type="hidden" name="action" value="upload_icon">
                <input type="hidden" name="item_name" id="icon_item_name">
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center mb-4">
                    <i class="fas fa-image text-5xl text-gray-400 mb-4"></i>
                    <input type="file" name="icon" id="iconFileInput" accept="image/*" class="w-full" required>
                </div>
                <p class="text-sm text-gray-500 mb-4 text-center">Upload a PNG image for custom icon (48x48px recommended).</p>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 btn-flat bg-purple-500 text-white hover:bg-purple-600">Upload Icon</button>
                    <button type="button" onclick="hideIconUploadModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editModal" class="hidden fixed inset-0 z-50 bg-white">
        <div class="h-full flex flex-col">
            <div id="editModalHeader" class="bg-gray-800 text-white sticky top-0 z-10 shadow-lg">
                <div class="flex items-center justify-between px-4 py-3">
                    
                    <div class="flex items-center gap-4 flex-1 min-w-0">
                        <button type="button" onclick="hideEditModal()" class="text-2xl hover:text-red-400" title="Back">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <h2 class="text-lg font-semibold truncate max-w-full sm:max-w-xs md:max-w-md lg:max-w-lg" id="edit_file_display">Edit File</h2>
                        </div>
                    
                    <div class="flex items-center gap-3">
                        <button type="button" onclick="showShareModal(document.getElementById('edit_file_name').value, false)" class="text-2xl hover:text-red-400" title="Share Link">
                            <i class="fas fa-share-alt"></i>
                        </button>
                        </div>
                </div>
            </div>
            <form method="POST" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Saving file')" class="flex flex-col flex-grow overflow-hidden" id="editForm">
                <input type="hidden" name="action" value="edit_file">
                <input type="hidden" name="file_name" id="edit_file_name">
                
                <div class="flex flex-grow overflow-hidden">
                    <div id="lineNumbers" class="bg-gray-100 px-4 py-4 overflow-y-hidden text-right text-gray-500 font-mono text-sm select-none" style="width: 50px;"></div>
                    <textarea name="file_content" id="edit_file_content" class="flex-grow p-4 border-0 resize-none font-mono text-sm bg-gray-50 focus:ring-0 focus:border-0 overflow-y-auto" wrap="off" onscroll="syncLineNumbers()" oninput="updateLineNumbers()" onkeyup="updateLineNumbers()"></textarea>
                </div>
                
                <div class="bg-gray-100 border-t border-gray-200 p-2 flex justify-between items-center sticky bottom-0 z-10">
                    <div class="flex gap-2">
                        <button type="button" onclick="runFileInBrowser()" class="btn-flat bg-red-400 text-white hover:bg-red-500 w-10 h-10" title="Run File in Browser">
                            <i class="fas fa-play"></i>
                        </button>
                        <button type="button" onclick="document.execCommand('undo')" class="btn-flat bg-gray-400 text-white hover:bg-gray-500 w-10 h-10" title="Undo">
                            <i class="fas fa-undo"></i>
                        </button>
                        <button type="button" onclick="document.execCommand('redo')" class="btn-flat bg-gray-400 text-white hover:bg-gray-500 w-10 h-10" title="Redo">
                            <i class="fas fa-redo"></i>
                        </button>
                    </div>
                    <div class="flex gap-2">
                         <button type="button" onclick="copyEditContent()" class="btn-flat bg-gray-400 text-white hover:bg-gray-500 w-10 h-10" title="Copy All">
                            <i class="fas fa-copy"></i>
                        </button>
                        <button type="submit" form="editForm" class="btn-flat bg-green-500 text-white hover:bg-green-600 w-10 h-10" title="Save">
                            <i class="fas fa-save"></i>
                        </button>
                    </div>
                </div>
                </form>
        </div>
    </div>
    
    <div id="viewNameModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4">Set View Name</h2>
            <form method="POST" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Setting view name')">
                <input type="hidden" name="action" value="set_view_name">
                <input type="hidden" name="item_name" id="view_item_name">
                <input type="text" name="view_name" id="view_new_name" placeholder="Enter view name (leave empty to remove)" class="w-full px-4 py-2 border rounded-lg mb-4">
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 btn-flat btn-violet">Set</button>
                    <button type="button" onclick="hideViewNameModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <div id="renameModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4">Rename</h2>
            <form method="POST" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Renaming item')">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="old_name" id="rename_old_name">
                <input type="text" name="new_name" id="rename_new_name" placeholder="New name" class="w-full px-4 py-2 border rounded-lg mb-4" required>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 btn-flat btn-yellow">Rename</button>
                    <button type="button" onclick="hideRenameModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="shareModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl share-modal p-6">
            <h2 class="text-xl font-bold mb-4">Share <span id="share_item_name" class="font-normal text-gray-600 truncate"></span></h2>
            <div class="space-y-4">
                <div class="space-y-2 mt-4" id="shareOptions" style="display: none;">
                    <button onclick="shareDirectLink()" class="w-full btn-flat bg-green-500 text-white hover:bg-green-600">
                        <i class="fas fa-share-alt mr-2"></i> Share Direct Link
                    </button>
                    <button onclick="shareZipLink()" class="w-full btn-flat bg-green-500 text-white hover:bg-green-600">
                        <i class="fas fa-share-alt mr-2"></i> Share Download Link
                    </button>
                </div>
                <div class="bg-gray-100 p-3 rounded">
                    <p class="text-xs uppercase text-gray-500">Direct File Link</p>
                    <input type="text" id="share_link_input" class="w-full bg-transparent border-none text-sm font-mono focus:ring-0 p-0" readonly>
                    <button onclick="copyShareLink()" class="mt-2 text-blue-500 hover:text-blue-700 text-sm">
                        <i class="fas fa-copy mr-1"></i> Copy Link
                    </button>
                </div>
                
                <div class="bg-gray-100 p-3 rounded">
                    <p class="text-xs uppercase text-gray-500">Download Link (Zipped)</p>
                    <input type="text" id="share_zip_link" class="w-full bg-transparent border-none text-sm font-mono focus:ring-0 p-0" readonly>
                    <button onclick="copyShareZipLink()" id="copy_zip_btn" class="mt-2 text-blue-500 hover:text-blue-700 text-sm">
                        <i class="fas fa-copy mr-1"></i> Copy Download Link
                    </button>
                </div>
                
                <button onclick="hideShareModal()" class="w-full btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400 transition">
                    Close
                </button>
            </div>
        </div>
    </div>

    <div id="singleDownloadAsModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4" id="single_download_modal_title">Download As</h2>
            <p class="text-sm text-gray-500 mb-4">Enter the name for the downloaded file or zip archive.</p>
            <form onsubmit="event.preventDefault(); initiateSingleDownload();">
                <input type="hidden" id="single_download_original_name">
                <input type="hidden" id="single_download_type_input">
                <input type="text" id="single_download_as_input" placeholder="New file name" class="w-full px-4 py-2 border rounded-lg mb-4" required>
                <input type="password" id="single_download_password" placeholder="Password (optional)" class="w-full px-4 py-2 border rounded-lg mb-4">
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 btn-flat bg-indigo-500 text-white hover:bg-indigo-600">Download</button>
                    <button type="button" onclick="hideSingleDownloadAsModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="multiDownloadAsModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4">Download Selected Items as ZIP</h2>
            <p class="text-sm text-gray-500 mb-4">Enter the name for the downloaded ZIP file.</p>
            <form onsubmit="event.preventDefault(); initiateMultiDownload();">
                <input type="text" id="multi_download_as_input" placeholder="Archive name (e.g., website_backup.zip)" class="w-full px-4 py-2 border rounded-lg mb-4" required>
                <input type="password" id="multi_download_password" placeholder="Password (optional)" class="w-full px-4 py-2 border rounded-lg mb-4">
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 btn-flat btn-green">Download ZIP</button>
                    <button type="button" onclick="hideMultiDownloadAsModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="mediaModal" onclick="hideMediaModal()" class="hidden fixed inset-0 z-50 blur-bg flex items-center justify-center p-4">
        <div class="max-w-full w-full h-full flex flex-col items-center justify-center">
            <div id="mediaContent" onclick="event.stopPropagation()" class="bg-black rounded-lg overflow-hidden max-w-4xl w-full flex justify-center items-center"></div>
        </div>
    </div>
    
    <div id="sideMenu">
        <div class="bg-gray-800 text-white sticky top-0 z-10 shadow-lg">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-4">
                    <h2 class="text-xl font-semibold">Menu</h2>
                </div>
                <button onclick="hideSideMenu()" class="text-xl hover:text-red-400">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="flex-grow overflow-y-auto p-4 space-y-4">
            <div class="mb-4">
                <h3 class="font-bold mb-2"><i class="fas fa-plus mr-2"></i> Create New</h3>
                <div class="space-y-2">
                    <button onclick="hideSideMenu(); showFolderForm()" class="w-full btn-flat btn-yellow">
                        <i class="fas fa-folder mr-2"></i> Create Folder
                    </button>
                    <button onclick="hideSideMenu(); showFileForm()" class="w-full btn-flat btn-blue">
                        <i class="fas fa-file mr-2"></i> Create File
                    </button>
                </div>
            </div>
            <div class="mb-4">
                <h3 class="font-bold mb-2"><i class="fas fa-upload mr-2"></i> Upload Tools</h3>
                <div class="space-y-2">
                    <button onclick="hideSideMenu(); showUploadForm(); showUploadView('uploadSingleFormView', 'Upload Single File')" class="w-full btn-flat btn-green">
                        <i class="fas fa-upload mr-2"></i> Upload Single File
                    </button>
                    <button onclick="hideSideMenu(); showUploadForm(); showUploadView('uploadMultiFormView', 'Upload Multiple Files')" class="w-full btn-flat bg-blue-500 hover:bg-blue-600">
                        <i class="fas fa-copy mr-2"></i> Upload Multiple Files
                    </button>
                    <button onclick="hideSideMenu(); showUploadForm(); showUploadView('uploadFolderFormView', 'Upload Folder')" class="w-full btn-flat bg-purple-500 hover:bg-purple-600">
                        <i class="fas fa-folder mr-2"></i> Upload Folder
                    </button>
                    <button onclick="hideSideMenu(); showUploadForm(); showUploadView('recordVoiceFormView', 'Record Voice')" class="w-full btn-flat bg-pink-500 hover:bg-pink-600">
                        <i class="fas fa-microphone mr-2"></i> Record Voice & Upload
                    </button>
                    <button onclick="hideSideMenu(); showUploadForm(); showUploadView('uploadLinkFormView', 'Upload by Link')" class="w-full btn-flat bg-indigo-500 hover:bg-indigo-600">
                        <i class="fas fa-link mr-2"></i> Upload by Link
                    </button>
                    <button onclick="hideSideMenu(); showUploadForm(); showUploadView('capturePhotoFormView', 'Capture Photo')" class="w-full btn-flat bg-red-500 hover:bg-red-600">
                        <i class="fas fa-camera mr-2"></i> Capture Photo & Upload
                    </button>
                    <button onclick="hideSideMenu(); showUploadForm(); showUploadView('captureVideoFormView', 'Record Video')" class="w-full btn-flat bg-red-500 hover:bg-red-600">
                        <i class="fas fa-video mr-2"></i> Record Video & Upload
                    </button>
                    <button onclick="hideSideMenu(); showUploadForm(); showUploadView('uploadExtractFormView', 'Upload & Extract ZIP')" class="w-full btn-flat bg-orange-500 hover:bg-orange-600">
                        <i class="fas fa-box-open mr-2"></i> Upload & Extract ZIP
                    </button>
                </div>
            </div>
            <div class="mb-4">
                <h3 class="font-bold mb-2"><i class="fas fa-history mr-2"></i> Activity History</h3>
                <div class="mt-2" id="activityContent">
                    <p class="text-center text-gray-500">Loading history...</p>
                </div>
            </div>
            <div class="mb-4">
                <h3 class="font-bold mb-2"><i class="fas fa-hdd mr-2"></i> Storage Information</h3>
                <div class="mt-2">
                    <div class="storage-text">Total storage: 5.00 GB Used: 1.83 MB Available: 5.00 GB</div>
                    <div id="storageBarProgress">
                        <div id="storageBarFill" style="width: <?php echo round($storage_percent, 2); ?>%;"></div>
                    </div>
                    <div class="storage-text mt-2">Assets - Total files: <?php echo $assets['files']; ?> Total folders: <?php echo $assets['folders']; ?></div>
                </div>
            </div>
            <div class="mb-4">
                <h3 class="font-bold mb-2"><i class="fas fa-cog mr-2"></i> Settings</h3>
                <div class="mt-2 space-y-4">
                    <div class="flex justify-between items-center">
                        <label>Always Show Control Bar</label>
                        <label class="switch">
                            <input type="checkbox" id="alwaysShowBar">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="flex justify-between items-center">
                        <label>Don't seclet on Click</label>
                        <label class="switch">
                            <input type="checkbox" id="openOnClick" checked>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <details>
                        <summary>Sort By</summary>
                        <div class="space-y-2 mt-2">
                            <button onclick="setSortBy('name')" class="w-full btn-flat bg-blue-500 text-white hover:bg-blue-600">Name</button>
                            <button onclick="setSortBy('date')" class="w-full btn-flat bg-blue-500 text-white hover:bg-blue-600">Date</button>
                            <button onclick="setSortBy('size')" class="w-full btn-flat bg-blue-500 text-white hover:bg-blue-600">Size</button>
                        </div>
                    </details>
                    <div class="flex justify-between items-center">
                        <label>Auto Refresh</label>
                        <label class="switch">
                            <input type="checkbox" id="autoRefresh">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <button onclick="showClearDataModal()" class="w-full btn-flat bg-red-500 text-white hover:bg-red-600">Clear All Data and Storage</button>
                </div>
            </div>
            <div class="mb-4">
                <h3 class="font-bold mb-2"><i class="fas fa-search mr-2"></i> Search Engine Manager</h3>
                <div class="mt-2 space-y-2">
                    <button id="googleBtn" onclick="setSearchEngine('google')" class="w-full btn-flat bg-green-500 text-white hover:bg-green-600 relative">
                        Google <i class="fas fa-check tick-icon absolute right-2 top-1/2 transform -translate-y-1/2 hidden"></i>
                    </button>
                    <button id="yahooBtn" onclick="setSearchEngine('yahoo')" class="w-full btn-flat bg-blue-500 text-white hover:bg-blue-600 relative">
                        Yahoo <i class="fas fa-check tick-icon absolute right-2 top-1/2 transform -translate-y-1/2 hidden"></i>
                    </button>
                    <button id="duckduckgoBtn" onclick="setSearchEngine('duckduckgo')" class="w-full btn-flat bg-orange-500 text-white hover:bg-orange-600 relative">
                        DuckDuckGo <i class="fas fa-check tick-icon absolute right-2 top-1/2 transform -translate-y-1/2 hidden"></i>
                    </button>
                    <button id="bingBtn" onclick="setSearchEngine('bing')" class="w-full btn-flat bg-purple-500 text-white hover:bg-purple-600 relative">
                        Bing <i class="fas fa-check tick-icon absolute right-2 top-1/2 transform -translate-y-1/2 hidden"></i>
                    </button>
                </div>
            </div>
            <p class="text-center text-gray-500 text-sm">Some text at the bottom of the menu bar</p>
        </div>
    </div>

    <div id="clearDataModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4">Clear All Data</h2>
            <form onsubmit="event.preventDefault(); handleClearData();">
                <input type="password" id="clearPassword" placeholder="Enter password" class="w-full px-4 py-2 border rounded-lg mb-4" required>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 btn-flat bg-red-500 text-white hover:bg-red-600">Clear</button>
                    <button type="button" onclick="hideClearDataModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="lockModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4">Set Lock Password</h2>
            <form method="POST" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Locking item')">
                <input type="hidden" name="action" value="lock_with_password">
                <input type="hidden" name="item_name" id="lock_item_name">
                <input type="password" name="password" placeholder="Enter password" class="w-full px-4 py-2 border rounded-lg mb-4" required>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 btn-flat bg-red-500 text-white hover:bg-red-600">Lock</button>
                    <button type="button" onclick="hideLockModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="unlockModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4">Unlock Item</h2>
            <form method="POST" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Unlocking item')" id="unlockFormModal">
                <input type="hidden" name="action" value="unlock_with_password">
                <input type="hidden" name="item_name" id="unlock_item_name">
                <input type="hidden" name="after_action" id="unlock_after_action">
                <input type="password" name="password" placeholder="Enter password" class="w-full px-4 py-2 border rounded-lg mb-4" required>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 btn-flat bg-green-500 text-white hover:bg-green-600">Unlock</button>
                    <button type="button" onclick="hideUnlockModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="detailsModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-blue-500 text-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4">Item Details</h2>
            <div id="detailsContent" class="space-y-2"></div>
            <button onclick="hideDetailsModal()" class="w-full btn-flat bg-white text-blue-500 hover:bg-gray-200 mt-4">Close</button>
        </div>
    </div>

    <div id="alertModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4 text-gray-800">Alert</h2>
            <p id="alertMessage" class="mb-6 text-gray-600"></p>
            <button onclick="hideAlertModal()" class="w-full btn-flat btn-blue">OK</button>
        </div>
    </div>

    <div id="confirmModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4 text-gray-800">Confirm</h2>
            <p id="confirmMessage" class="mb-6 text-gray-600"></p>
            <div class="flex gap-2">
                <button id="confirmYes" onclick="handleConfirmYes()" class="flex-1 btn-flat bg-red-500 text-white hover:bg-red-600">Yes</button>
                <button onclick="hideConfirmModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">No</button>
            </div>
        </div>
    </div>

    <div id="compressQualityModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4">Compress Image</h2>
            <p class="mb-4">Choose quality (1-100):</p>
            <input type="range" id="qualitySlider" min="1" max="100" value="75" class="w-full mb-4">
            <p id="qualityValue" class="text-center mb-4">75%</p>
            <form id="compressFormModal" method="POST" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Compressing image')">
                <input type="hidden" name="action" value="compress_image">
                <input type="hidden" name="file_name" id="compress_file_name_modal">
                <input type="hidden" name="quality" id="qualityInput">
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 btn-flat bg-teal-500 text-white hover:bg-teal-600">Compress</button>
                    <button type="button" onclick="hideCompressQualityModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="extractToModal" class="hidden fixed inset-0 z-50 blur-bg flex justify-center p-4 popup-modal">
        <div class="bg-white rounded-lg shadow-xl w-full p-6 popup-content">
            <h2 class="text-xl font-bold mb-4">Extract ZIP</h2>
            <p class="mb-4">Extract to folder:</p>
            <input type="text" id="extractToInput" placeholder="Folder name" class="w-full px-4 py-2 border rounded-lg mb-4" required>
            <form id="extractFormModal" method="POST" action="<?php echo $redirectUrl; ?>" onsubmit="showLoader('Extracting ZIP')">
                <input type="hidden" name="action" value="extract_zip">
                <input type="hidden" name="file_name" id="extract_file_name_modal">
                <input type="hidden" name="extract_to" id="extractToHidden">
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 btn-flat bg-orange-500 text-white hover:bg-orange-600">Extract</button>
                    <button type="button" onclick="hideExtractToModal()" class="flex-1 btn-flat bg-gray-300 text-gray-700 hover:bg-gray-400">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Use the scriptName variable from PHP to construct paths
        const scriptName = '<?php echo $scriptName; ?>';
        const currentSubPath = '<?php echo $currentSubPath; ?>';
        const currentPathPrefix = currentSubPath ? currentSubPath + '/' : '';
        
        const baseUrl = window.location.pathname.replace(scriptName, '');
        
        // --- Global State ---
        let currentItem = '';
        let currentIsDir = false;
        let currentType = '';
        let currentExt = '';
        let currentLocked = false;
        let currentSize = 0;
        let currentDate = '';
        let currentTotalAssets = 0;
        let currentPinned = false;
        const selectedItems = new Set();
        let isSearching = false;
        let totalItems = <?php echo count($items); ?>;
        let confirmCallback = null;
        let fakeLoaderInterval = null; // For the fake loader
        let fullActivityLog = '';
        let searchEngine = localStorage.getItem('searchEngine') || 'google';
        
        // --- Loader Functions ---
        
        /**
         * Shows a FAKE loader that animates to 95%.
         * Used for single, quick actions like create file, rename, etc.
         */
        function showLoader(text = 'Loading') {
            if (fakeLoaderInterval) clearInterval(fakeLoaderInterval);
            
            const container = document.getElementById('bottomLoaderContainer');
            const storageBar = document.getElementById('storageBar');
            const progress = document.getElementById('bottomLoaderProgress');
            const bar = document.getElementById('loaderBar');
            
            if (storageBar) storageBar.classList.add('hidden'); 
            
            container.style.display = 'block';
            let percent = 0;
            progress.textContent = `${text} 0.00%`;
            bar.style.width = '0%';
            
            fakeLoaderInterval = setInterval(() => {
                percent += Math.random() * 5; 
                if (percent > 95) percent = 95;
                progress.textContent = `${text} ${percent.toFixed(2)}%`;
                bar.style.width = percent + '%';
            }, 500);
            
            // Make completeLoader globally accessible
            window.completeLoader = function(finalText = 'Finished') {
                if (fakeLoaderInterval) clearInterval(fakeLoaderInterval);
                progress.textContent = `${finalText} 100%`;
                bar.style.width = '100%';
                setTimeout(() => {
                    hideLoader();
                    if (storageBar) storageBar.classList.remove('hidden');
                }, 500);
            };
        }

        /**
         * Shows the loader bar container without starting a fake interval.
         * Used for REAL progress tracking.
         */
        function showRealLoader(text = 'Loading') {
            if (fakeLoaderInterval) clearInterval(fakeLoaderInterval); // Stop any fake loaders
            
            const container = document.getElementById('bottomLoaderContainer');
            const storageBar = document.getElementById('storageBar');
            const progress = document.getElementById('bottomLoaderProgress');
            const bar = document.getElementById('loaderBar');
            
            if (storageBar) storageBar.classList.add('hidden'); 
            container.style.display = 'block';
            progress.textContent = text;
            bar.style.width = '0%';
        }

        /**
         * Updates the REAL loader bar with a specific percentage.
         */
        function updateRealLoader(percent, text) {
            const progress = document.getElementById('bottomLoaderProgress');
            const bar = document.getElementById('loaderBar');
            
            progress.textContent = text;
            bar.style.width = percent + '%';
        }

        function hideLoader() {
            if (fakeLoaderInterval) clearInterval(fakeLoaderInterval);
            const container = document.getElementById('bottomLoaderContainer');
            const storageBar = document.getElementById('storageBar');
            container.style.display = 'none';
             if (storageBar) storageBar.classList.remove('hidden');
        }

        function reloadPage() {
            const reloadBtn = document.getElementById('reloadBtn').querySelector('i');
            reloadBtn.classList.add('fa-spin');
            showLoader('Reloading');
            window.addEventListener('load', () => {
                if (window.completeLoader) window.completeLoader('Reloaded');
                reloadBtn.classList.remove('fa-spin');
            }, { once: true });
            window.location.reload();
        }

        // --- Custom Alert and Confirm ---
        function showAlert(message) {
            document.getElementById('alertMessage').textContent = message;
            const modal = document.getElementById('alertModal');
            modal.classList.remove('hidden');
        }

        function hideAlertModal() {
            const modal = document.getElementById('alertModal');
            modal.classList.add('hidden');
        }

        function showConfirm(message, callback) {
            document.getElementById('confirmMessage').textContent = message;
            confirmCallback = callback;
            const modal = document.getElementById('confirmModal');
            modal.classList.remove('hidden');
        }

        function hideConfirmModal() {
            const modal = document.getElementById('confirmModal');
            modal.classList.add('hidden');
            confirmCallback = null;
        }

        function handleConfirmYes() {
            if (confirmCallback) {
                confirmCallback();
            }
            hideConfirmModal();
        }
        // --- End Custom Alert and Confirm ---

        // Initial load
        document.addEventListener('DOMContentLoaded', function() {
            hideLoader();
            attachLongPressListeners();
            document.getElementById('storageBar').classList.remove('hidden');

            const errorMsg = document.getElementById('errorMsg');
            if (errorMsg) {
                setTimeout(() => {
                    errorMsg.style.display = 'none';
                }, 4000);
            }
            const successMsg = document.getElementById('successMsg');
            if (successMsg) {
                setTimeout(() => {
                    successMsg.style.display = 'none';
                }, 4000);
            }
            const urlParams = new URLSearchParams(window.location.search);
            const afterAction = urlParams.get('after_action');
            const afterItem = urlParams.get('after_item');
            if (afterAction && afterItem) {
                const itemRow = document.querySelector(`.item-row[data-name="${afterItem}"]`);
                if (itemRow) {
                    currentItem = afterItem;
                    currentIsDir = itemRow.dataset.isDir === 'true';
                    currentType = itemRow.dataset.type;
                    currentExt = itemRow.dataset.ext;
                    currentLocked = itemRow.dataset.locked === 'true'; 
                    currentSize = itemRow.dataset.size;
                    currentDate = itemRow.dataset.date;
                    currentTotalAssets = itemRow.dataset.totalAssets;
                    if (afterAction === 'edit') {
                        editItem();
                    } else if (afterAction === 'view') {
                        openOrViewItem();
                    }
                }
            }
            document.getElementById('qualitySlider').addEventListener('input', function() {
                document.getElementById('qualityValue').textContent = this.value + '%';
                document.getElementById('qualityInput').value = this.value;
            });
            initSettings();
            loadActivityHistory(true); // Load initial 5
            checkPermissions();
            updateSearchEngineUI();
        });
        
        // --- Sidebar Menu Functions ---
        function showSideMenu() {
            document.getElementById('sideMenu').classList.add('open');
            document.getElementById('menuOverlay').classList.add('open');
        }

        function hideSideMenu() {
            document.getElementById('sideMenu').classList.remove('open');
            document.getElementById('menuOverlay').classList.remove('open');
        }

        function loadActivityHistory(showLimited = true) {
            fetch(`${scriptName}?log=1`)
                .then(res => res.text())
                .then(text => {
                    fullActivityLog = text;
                    let lines = text.split('\n').filter(line => line.trim());
                    lines = lines.slice(-5);
                    const formattedLines = lines.map(line => {
                        const parts = line.split(' | ');
                        if (parts.length >= 3) {
                            const action = parts[1];
                            const details = parts[2];
                            let formatted = '';
                            switch (action) {
                                case 'edit_file':
                                    formatted = `Edited ${details}`;
                                    break;
                                case 'delete':
                                    formatted = `Deleted ${details}`;
                                    break;
                                case 'create_folder':
                                    formatted = `Created folder ${details}`;
                                    break;
                                case 'create_file':
                                    formatted = `Created file ${details}`;
                                    break;
                                case 'upload':
                                    formatted = `Uploaded ${details}`;
                                    break;
                                case 'rename':
                                    formatted = `Renamed ${details}`;
                                    break;
                                case 'lock':
                                    formatted = `Locked ${details}`;
                                    break;
                                case 'unlock':
                                    formatted = `Unlocked ${details}`;
                                    break;
                                default:
                                    formatted = line;
                            }
                            return formatted;
                        }
                        return line;
                    });
                    const reversedText = formattedLines.reverse().join('\n');
                    document.getElementById('activityContent').innerHTML = '<pre class="bg-gray-100 p-4 overflow-x-auto text-sm">' + reversedText + '</pre>';
                })
                .catch(err => {
                    document.getElementById('activityContent').innerHTML = '<p class="text-red-500">Error loading activity history.</p>';
                });
        }

        // --- Settings Functions ---
        function initSettings() {
            const alwaysShowBar = document.getElementById('alwaysShowBar');
            const autoRefresh = document.getElementById('autoRefresh');
            const openOnClick = document.getElementById('openOnClick');

            // Load from localStorage
            alwaysShowBar.checked = localStorage.getItem('alwaysShowBar') === 'true';
            toggleAlwaysShowBar();

            const savedSort = localStorage.getItem('sortBy') || 'name';
            // Apply sort if needed

            openOnClick.checked = localStorage.getItem('openOnClick') !== 'false';

            // Add listeners
            alwaysShowBar.addEventListener('change', (e) => {
                localStorage.setItem('alwaysShowBar', e.target.checked);
                toggleAlwaysShowBar();
            });

            autoRefresh.addEventListener('change', (e) => {
                localStorage.setItem('autoRefresh', e.target.checked);
                // Implement auto refresh if needed
            });

            openOnClick.addEventListener('change', (e) => {
                localStorage.setItem('openOnClick', e.target.checked);
            });
        }

        function setSortBy(sort) {
            localStorage.setItem('sortBy', sort);
            reloadPage();
        }

        function showClearDataModal() {
            document.getElementById('clearDataModal').classList.remove('hidden');
        }

        function hideClearDataModal() {
            document.getElementById('clearDataModal').classList.add('hidden');
        }

        function handleClearData() {
            const password = document.getElementById('clearPassword').value;
            if (password === 'mithu@123') {
                localStorage.clear();
                showAlert('All data and storage cleared!');
                hideClearDataModal();
                reloadPage();
            } else {
                showAlert('Incorrect password.');
            }
        }

        function toggleAlwaysShowBar() {
            const alwaysShow = document.getElementById('alwaysShowBar').checked;
            if (alwaysShow) {
                showSingleSelectBar();
            } else if (!alwaysShow) {
                if (selectedItems.size !== 1) hideSingleSelectBar();
            }
        }

        function toggleSearch() {
            const bar = document.getElementById('searchBar');
            bar.classList.toggle('hidden');
            if (!bar.classList.contains('hidden')) {
                document.getElementById('searchInput').focus();
            }
        }

        function filterItems() {
            const query = document.getElementById('searchInput').value.toLowerCase();
            const itemRows = document.querySelectorAll('.item-row');
            let visibleCount = 0;
            itemRows.forEach(row => {
                if (row.querySelector('h3').textContent.trim() === '..') return; 

                const name = row.querySelector('h3').textContent.toLowerCase();
                if (name.includes(query)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            isSearching = query.length > 0;
            const titleText = document.getElementById('titleText');
            if (isSearching) {
                titleText.textContent = `${visibleCount} results`;
                hideSingleSelectBar();
                clearSelection();
            } else {
                titleText.textContent = 'Admin Manager';
                if (selectedItems.size === 1) {
                    updateSingleSelectBar();
                } else {
                     hideSingleSelectBar();
                }
            }
        }

        function webSearch() {
            const query = document.getElementById('searchInput').value;
            if (query) {
                let url = '';
                switch (searchEngine) {
                    case 'google':
                        url = `https://www.google.com/search?q=${encodeURIComponent(query)}`;
                        break;
                    case 'yahoo':
                        url = `https://search.yahoo.com/search?p=${encodeURIComponent(query)}`;
                        break;
                    case 'duckduckgo':
                        url = `https://duckduckgo.com/?q=${encodeURIComponent(query)}`;
                        break;
                    case 'bing':
                        url = `https://www.bing.com/search?q=${encodeURIComponent(query)}`;
                        break;
                    default:
                        url = `https://www.google.com/search?q=${encodeURIComponent(query)}`;
                }
                window.open(url, '_blank');
            }
        }

        function setSearchEngine(engine) {
            searchEngine = engine;
            updateSearchEngineUI();
            localStorage.setItem('searchEngine', engine);
            showAlert(`Search engine set to ${engine.charAt(0).toUpperCase() + engine.slice(1)}`);
        }

        function updateSearchEngineUI() {
            const buttons = {
                google: document.getElementById('googleBtn'),
                yahoo: document.getElementById('yahooBtn'),
                duckduckgo: document.getElementById('duckduckgoBtn'),
                bing: document.getElementById('bingBtn')
            };
            Object.keys(buttons).forEach(key => {
                const tick = buttons[key].querySelector('.tick-icon');
                if (key === searchEngine) {
                    tick.classList.remove('hidden');
                } else {
                    tick.classList.add('hidden');
                }
            });
        }

        // --- Selection & Zip Functions ---
        function updateSelectionCount() {
            const count = selectedItems.size;
            const titleText = document.getElementById('titleText');
            // const selectedCountDisplay = document.getElementById('selectedCountDisplay'); // REQUEST 2: Removed
            
            // selectedCountDisplay.textContent = `${count} selected`; // REQUEST 2: Removed
            if (count > 0 && !isSearching) {
                titleText.textContent = `${count} selected`;
                normalTools.classList.add('hidden');
                selectionTools.classList.remove('hidden');
            } else if (isSearching) {
                // Handled in filterItems
            } else {
                titleText.textContent = 'Admin Manager';
                normalTools.classList.remove('hidden');
                selectionTools.classList.add('hidden');
                hideSingleSelectBar();
            }
            
            if (count === 1) {
                const singleItemName = Array.from(selectedItems)[0];
                const itemRow = document.querySelector(`.item-row[data-name="${singleItemName}"]`);
                if (itemRow) {
                    currentItem = itemRow.dataset.name;
                    currentIsDir = itemRow.dataset.isDir === 'true';
                    currentType = itemRow.dataset.type;
                    currentExt = itemRow.dataset.ext;
                    currentLocked = itemRow.dataset.locked === 'true';
                    currentSize = itemRow.dataset.size;
                    currentDate = itemRow.dataset.date;
                    currentTotalAssets = itemRow.dataset.totalAssets;
                    currentPinned = itemRow.dataset.isPinned === 'true';
                    updateSingleSelectBar();
                }
            } else {
                hideSingleSelectBar();
            }
            toggleAlwaysShowBar();
        }

        function showSingleSelectBar() {
            document.getElementById('singleSelectBar').classList.remove('hidden');
        }

        function hideSingleSelectBar() {
            document.getElementById('singleSelectBar').classList.add('hidden');
        }

        function updateSingleSelectBar() {
            const barEdit = document.getElementById('barEdit');
            barEdit.style.display = (!currentIsDir && currentType === 'code') ? 'flex' : 'none'; 

            const barView = document.getElementById('barView');
            if (currentIsDir) {
                barView.innerHTML = '<i class="fas fa-folder-open"></i>Open Folder';
            } else {
                const icon = (currentType === 'code' || currentType === 'image' || currentType === 'video' || currentType === 'audio' || currentType === 'document') ? 'eye' : 'file';
                barView.innerHTML = `<i class="fas fa-${icon}"></i>View/Open`;
            }

            const barDownload = document.getElementById('barDownload');
            barDownload.innerHTML = currentIsDir ? '<i class="fas fa-file-archive"></i>Download ZIP' : '<i class="fas fa-download"></i>Download';

            const barExtract = document.getElementById('barExtract');
            barExtract.style.display = (currentType === 'archive' && !currentIsDir) ? 'flex' : 'none'; 

            const barCompress = document.getElementById('barCompress');
            barCompress.style.display = (currentType === 'image' && !currentIsDir) ? 'flex' : 'none'; 

            const barLock = document.getElementById('barLock');
            barLock.innerHTML = currentLocked ? '<i class="fas fa-unlock"></i>Unlock' : '<i class="fas fa-lock"></i>Lock';

            const barPin = document.getElementById('barPin');
            barPin.innerHTML = `<i class="fas fa-thumbtack"></i>${currentPinned ? 'Unpin' : 'Pin'}`;

            showSingleSelectBar();
        }
        
        function toggleSelection(event, checkboxOrRow) {
            const checkbox = checkboxOrRow.tagName.toLowerCase() === 'input' ? checkboxOrRow : checkboxOrRow.querySelector('input[type="checkbox"]');
            const row = checkbox.closest('.item-selectable');
            const itemName = checkbox.dataset.itemName;
            
            const isChecked = checkbox.checked;

            if (isChecked) {
                selectedItems.add(itemName);
                row.classList.add('selected');
            } else {
                selectedItems.delete(itemName);
                row.classList.remove('selected');
            }
            
            updateSelectionCount();
        }

        function deleteSelectedMultiple() {
            if (selectedItems.size === 0) {
                showAlert('Please select items to delete.');
                return;
            }
            
            let lockedCount = 0;
            Array.from(selectedItems).forEach(itemName => {
                const itemRow = document.querySelector(`.item-row[data-name="${itemName}"]`);
                if (itemRow && itemRow.dataset.locked === 'true') {
                    lockedCount++;
                }
            });
            
            if (lockedCount > 0) {
                 showAlert(`${lockedCount} selected item${lockedCount > 1 ? 's' : ''} ${lockedCount > 1 ? 'are' : 'is'} locked. Please unlock them first.`);
                 return;
            }

            showConfirm(`Permanently delete ${selectedItems.size} selected item${selectedItems.size > 1 ? 's' : ''}? This cannot be undone.`, function() {
                showLoader('Deleting ' + selectedItems.size + ' items');
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?php echo $redirectUrl; ?>'; 
                
                const itemNames = Array.from(selectedItems).join('|');

                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_multiple">
                    <input type="hidden" name="items" value="${itemNames}">
                `;
                document.body.appendChild(form);
                form.submit();
            });
        }

        function clearSelection() {
            selectedItems.clear();
            document.querySelectorAll('.item-selectable').forEach(row => {
                row.classList.remove('selected');
                const checkbox = row.querySelector('input[type="checkbox"]');
                if (checkbox) checkbox.checked = false;
                delete row.dataset.longPressed;
            });
            updateSelectionCount();
        }

        function selectAllItems() {
            const checkboxes = document.querySelectorAll('.item-selectable:not([style*="display: none"]) input[type="checkbox"]');
            const totalVisibleItems = checkboxes.length;
            if (selectedItems.size === totalVisibleItems) {
                clearSelection();
            } else {
                checkboxes.forEach(checkbox => {
                    if (!checkbox.checked) {
                        checkbox.checked = true;
                        toggleSelection(null, checkbox);
                    }
                });
            }
        }

        function handleTopDownload() {
            if (selectedItems.size === 1) {
                showSingleDownloadAsModal();
            } else if (selectedItems.size > 1) {
                showMultiDownloadAsModal();
            } else {
                showAlert('Please select items to download.');
            }
        }

        function handleTopDelete() {
            deleteSelectedMultiple();
        }

        function extractItem() {
            if (selectedItems.size !== 1 || currentType !== 'archive') {
                showAlert('Please select a ZIP file to extract.');
                return;
            }
            if (currentLocked) {
                showAlert('Item is locked. Unlock first.');
                return;
            }
            const defaultFolder = currentItem.replace(/\.[^/.]+$/, "");
            document.getElementById('extractToInput').value = defaultFolder;
            document.getElementById('extract_file_name_modal').value = currentItem;
            document.getElementById('extractToHidden').value = defaultFolder;
            document.getElementById('extractToModal').classList.remove('hidden');
        }

        function hideExtractToModal() {
            document.getElementById('extractToModal').classList.add('hidden');
        }

        function compressItem() {
            if (selectedItems.size !== 1 || currentType !== 'image') {
                showAlert('Please select an image to compress.');
                return;
            }
            if (currentLocked) {
                showAlert('Item is locked. Unlock first.');
                return;
            }
            document.getElementById('compress_file_name_modal').value = currentItem;
            document.getElementById('qualitySlider').value = 75;
            document.getElementById('qualityValue').textContent = '75%';
            document.getElementById('qualityInput').value = 75;
            document.getElementById('compressQualityModal').classList.remove('hidden');
        }

        function hideCompressQualityModal() {
            document.getElementById('compressQualityModal').classList.add('hidden');
        }


        // --- Modal Control Functions (Modified for animations) ---
        
        function showCreateModal() {
            document.getElementById('createModal').classList.remove('hidden');
            hideSingleSelectBar();
            clearSelection();
        }
        
        function hideCreateModal() {
            document.getElementById('createModal').classList.add('hidden');
        }
        
        function showFolderForm() {
            hideCreateModal();
            document.getElementById('folderModal').classList.remove('hidden');
        }

        function hideFolderModal() {
            document.getElementById('folderModal').classList.add('hidden');
        }
        
        function showFileForm() {
            hideCreateModal();
            const form = document.getElementById('createFileForm');
            form.reset();
            updateCreateLineNumbers(); // Reset line numbers
            document.getElementById('fileModal').classList.remove('hidden');
            form.querySelector('input[name="file_name"]').focus();
        }
        
        function hideFileModal() {
            document.getElementById('fileModal').classList.add('hidden');
        }

        // --- START: Upload Modal Refactored Functions ---
        function showUploadForm() {
            hideCreateModal();
            showUploadView('uploadMenu', 'File Tools'); // Reset to main menu
            document.getElementById('uploadModal').classList.remove('hidden');
        }
        
        function hideUploadModal() {
            document.getElementById('uploadModal').classList.add('hidden');
        }
        
        /**
         * Switches the view inside the upload modal
         * @param {string} viewId The ID of the view to show (e.g., 'uploadMenu', 'uploadSingleFormView')
         * @param {string} title The new title for the modal
         */
        function showUploadView(viewId, title) {
            // Hide all views
            document.getElementById('uploadMenu').classList.add('hidden');
            document.getElementById('uploadSingleFormView').classList.add('hidden');
            document.getElementById('uploadMultiFormView').classList.add('hidden');
            document.getElementById('uploadFolderFormView').classList.add('hidden');
            document.getElementById('recordVoiceFormView').classList.add('hidden');
            document.getElementById('createFolderFormView').classList.add('hidden');
            document.getElementById('uploadLinkFormView').classList.add('hidden');
            document.getElementById('capturePhotoFormView').classList.add('hidden');
            document.getElementById('captureVideoFormView').classList.add('hidden');
            document.getElementById('uploadExtractFormView').classList.add('hidden');
            
            // Show the requested view
            document.getElementById(viewId).classList.remove('hidden');
            
            // Update title
            document.getElementById('uploadModalTitle').textContent = title;
            
            // Show/hide back button
            document.getElementById('uploadBackBtn').classList.toggle('hidden', viewId === 'uploadMenu');
        }
        // --- END: Upload Modal Refactored Functions ---


        function showIconUploadModal() {
            if (selectedItems.size !== 1) return;
            hideSingleSelectBar();
            document.getElementById('icon_item_name').value = currentItem;
            document.getElementById('icon_upload_title').textContent = 'Upload Custom Icon for ' + currentItem;
            document.getElementById('iconUploadModal').classList.remove('hidden');
        }

        function hideIconUploadModal() {
            document.getElementById('iconUploadModal').classList.add('hidden');
        }
        
        function hideEditModal() {
            document.getElementById('editModal').classList.add('hidden');
            document.getElementById('edit_file_content').value = ''; 
            clearSelection();
        }
        
        function hideRenameModal() {
            document.getElementById('renameModal').classList.add('hidden');
        }
        
        function hideMediaModal() {
            document.getElementById('mediaModal').classList.add('hidden');
            document.getElementById('mediaContent').innerHTML = '';
        }

        function hideLockModal() {
            document.getElementById('lockModal').classList.add('hidden');
        }

        function hideUnlockModal() {
            document.getElementById('unlockModal').classList.add('hidden');
        }

        function showDetails() {
            const content = document.getElementById('detailsContent');
            const itemRow = document.querySelector(`.item-row[data-name="${currentItem}"]`);
            const viewName = itemRow ? itemRow.dataset.viewName : '';
            const isPinned = currentPinned ? 'Yes' : 'No';
            
            const directFileUrl = `${window.location.origin}${baseUrl}${currentPathPrefix}${currentItem}`;
            const zipDownloadUrl = `${window.location.origin}${baseUrl}${scriptName}?zip=${encodeURIComponent(currentItem)}${currentSubPath ? '&path=' + encodeURIComponent(currentSubPath) : ''}`;

            content.innerHTML = `
                <p><strong>Name:</strong> ${currentItem}</p>
                <p><strong>Type:</strong> ${currentIsDir ? 'Folder' : 'File'}</p>
                <p><strong>File Type:</strong> ${currentIsDir ? 'N/A' : currentExt}</p>
                <p><strong>Size:</strong> ${currentIsDir ? currentTotalAssets + ' items' : formatSize(currentSize)}</p>
                <p><strong>Modified:</strong> ${currentDate}</p>
                <p><strong>Locked:</strong> ${currentLocked ? 'Yes' : 'No'}</p>
                <p><strong>Pinned:</strong> ${isPinned}</p>
                <p><strong>Real Name:</strong> ${currentItem}</p>
                <p><strong>View Name:</strong> ${viewName || 'None'}</p>
                <div class="mt-4 pt-2 border-t border-blue-400">
                    <p class="text-sm font-bold">Share Link:</p>
                    <p class="text-xs font-mono break-all">${directFileUrl.replace(/([^:]\/)\/+/g, '$1')}</p> <p class="text-sm font-bold mt-2">Download Link (ZIP):</p>
                    <p class="text-xs font-mono break-all">${zipDownloadUrl.replace(/([^:]\/)\/+/g, '$1')}</p> </div>
            `;
            document.getElementById('detailsModal').classList.remove('hidden');
        }

        function hideDetailsModal() {
            document.getElementById('detailsModal').classList.add('hidden');
        }

        function showUnlockForAction(action, name) {
            document.getElementById('unlock_item_name').value = name;
            document.getElementById('unlock_after_action').value = action;
            document.getElementById('unlockModal').classList.remove('hidden');
        }
        
        function showSingleDownloadAsModal() {
            if (selectedItems.size !== 1) {
                showAlert('Please select one item to download.');
                return;
            }
            
            hideSingleSelectBar();
            
            const fileName = currentItem;
            document.getElementById('single_download_original_name').value = fileName;
            
            let defaultDownloadName = fileName;
            let downloadType = currentIsDir ? 'folder' : 'file';

            if (currentIsDir) {
                if (!defaultDownloadName.toLowerCase().endsWith('.zip')) {
                     defaultDownloadName += '.zip';
                }
                document.getElementById('single_download_modal_title').textContent = 'Download Folder as ZIP';
            } else {
                 document.getElementById('single_download_modal_title').textContent = 'Download File As';
            }
            
            document.getElementById('single_download_as_input').value = defaultDownloadName;
            document.getElementById('single_download_type_input').value = downloadType;
            document.getElementById('single_download_password').value = '';
            document.getElementById('singleDownloadAsModal').classList.remove('hidden');
        }

        function hideSingleDownloadAsModal() {
            document.getElementById('singleDownloadAsModal').classList.add('hidden');
        }
        
        function initiateSingleDownload() {
            const originalName = document.getElementById('single_download_original_name').value;
            const newName = document.getElementById('single_download_as_input').value;
            const password = document.getElementById('single_download_password').value;

            hideSingleDownloadAsModal();
            clearSelection();
            
            const pathParam = currentSubPath ? `&path=${encodeURIComponent(currentSubPath)}` : '';
            const passwordParam = password ? `&password=${encodeURIComponent(password)}` : '';
            const downloadUrl = `${scriptName}?zip=${encodeURIComponent(originalName)}&name=${encodeURIComponent(newName)}${pathParam}${passwordParam}`;
            
            window.location.href = downloadUrl;
        }

        function showMultiDownloadAsModal() {
            if (selectedItems.size < 1) {
                showAlert('Please select items to download as ZIP.');
                return;
            }
            hideSingleSelectBar();
            
            let defaultZipName = currentSubPath ? currentSubPath.split('/').pop() : 'root';
            if (!defaultZipName.toLowerCase().endsWith('.zip')) {
                 defaultZipName += '.zip';
            }
            document.getElementById('multi_download_as_input').value = defaultZipName;
            document.getElementById('multi_download_password').value = '';
            document.getElementById('multiDownloadAsModal').classList.remove('hidden');
        }

        function hideMultiDownloadAsModal() {
            document.getElementById('multiDownloadAsModal').classList.add('hidden');
        }
        
        function initiateMultiDownload() {
            const newName = document.getElementById('multi_download_as_input').value;
            const password = document.getElementById('multi_download_password').value;
            
            hideMultiDownloadAsModal();
            
            const zipList = Array.from(selectedItems).join('|');
            const pathParam = currentSubPath ? `&path=${encodeURIComponent(currentSubPath)}` : '';
            const passwordParam = password ? `&password=${encodeURIComponent(password)}` : '';
            
            const downloadUrl = `${scriptName}?zip=${encodeURIComponent(zipList)}&name=${encodeURIComponent(newName)}${pathParam}${passwordParam}`;
            
            window.location.href = downloadUrl;
            
            clearSelection();
        }

        function toggleLock() {
            if (selectedItems.size !== 1) return;
            hideSingleSelectBar();
            if (currentLocked) {
                document.getElementById('unlock_item_name').value = currentItem;
                document.getElementById('unlock_after_action').value = 'delete'; 
                document.getElementById('unlockModal').classList.remove('hidden');
            } else {
                document.getElementById('lock_item_name').value = currentItem;
                document.getElementById('lockModal').classList.remove('hidden');
            }
        }

        function togglePin() {
            if (selectedItems.size !== 1) return;
            hideSingleSelectBar();
            showLoader('Toggling pin');
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo $redirectUrl; ?>';
            form.innerHTML = `
                <input type="hidden" name="action" value="toggle_pin">
                <input type="hidden" name="item_name" value="${currentItem}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function setViewName() {
            if (selectedItems.size !== 1) return;
            hideSingleSelectBar();
            const itemRow = document.querySelector(`.item-row[data-name="${currentItem}"]`);
            document.getElementById('view_item_name').value = currentItem;
            document.getElementById('view_new_name').value = itemRow.dataset.viewName || '';
            document.getElementById('viewNameModal').classList.remove('hidden');
        }

        function hideViewNameModal() {
            document.getElementById('viewNameModal').classList.add('hidden');
        }

        function showShareModal(name, isDir) {
            hideSingleSelectBar();
            
            const itemNameDisplay = document.getElementById('share_item_name');
            const linkInput = document.getElementById('share_link_input');
            const zipLinkInput = document.getElementById('share_zip_link');
            const zipCopyBtn = document.getElementById('copy_zip_btn');
            
            itemNameDisplay.textContent = name;
            
            const directFileUrl = `${window.location.origin}${baseUrl}${currentPathPrefix}${name}`;
            linkInput.value = directFileUrl.replace(/([^:]\/)\/+/g, '$1');
            
            const pathParam = currentSubPath ? `&path=${encodeURIComponent(currentSubPath)}` : '';
            const zipDownloadUrl = `${window.location.origin}${baseUrl}${scriptName}?zip=${encodeURIComponent(name)}${pathParam}`;
            
            zipLinkInput.value = zipDownloadUrl.replace(/([^:]\/)\/+/g, '$1');
            zipCopyBtn.classList.remove('hidden');
            document.getElementById('shareOptions').style.display = navigator.share ? 'block' : 'none';
            
            document.getElementById('shareModal').classList.remove('hidden');
        }
        
        function hideShareModal() {
            document.getElementById('shareModal').classList.add('hidden');
        }
        
        function copyShareLink() {
            const linkInput = document.getElementById('share_link_input');
            linkInput.select();
            document.execCommand('copy');
            showAlert('Direct link copied to clipboard!');
        }
        
        function copyShareZipLink() {
            const zipLinkInput = document.getElementById('share_zip_link');
            zipLinkInput.select();
            document.execCommand('copy');
            showAlert('Download link copied to clipboard!');
        }
        function shareDirectLink() {
            if (!navigator.share) return;
            const url = document.getElementById('share_link_input').value;
            navigator.share({
                title: currentItem,
                text: 'Check out this file:',
                url: url
            }).then(() => showAlert('Shared successfully!'))
              .catch(err => showAlert('Error sharing: ' + err));
        }

        function shareZipLink() {
            if (!navigator.share) return;
            const url = document.getElementById('share_zip_link').value;
            navigator.share({
                title: `${currentItem} (ZIP Download)`,
                text: 'Download this as ZIP:',
                url: url
            }).then(() => showAlert('Shared successfully!'))
              .catch(err => showAlert('Error sharing: ' + err));
        }
        
        // --- File Operation Functions (Called from Bar) ---

        function openFileOrEdit(name, type, isDir) {
            const openOnClick = localStorage.getItem('openOnClick') !== 'false';

            const itemRow = document.querySelector(`.item-row[data-name="${name}"]`);
            if (!itemRow) return;

            if (selectedItems.size > 0 || itemRow.dataset.longPressed === 'true') {
                 event.preventDefault(); 
                 
                 const checkbox = document.getElementById(`check-${name}`);
                 if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    toggleSelection(null, checkbox);
                 }
                 delete itemRow.dataset.longPressed;
                 return;
            }
            
            if (!openOnClick) {
                const checkbox = document.getElementById(`check-${name}`);
                 if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    toggleSelection(null, checkbox);
                 }
                 return;
            }

            if (itemRow.dataset.locked === 'true') {
                showAlert('This file is locked. Please unlock first');
                return;
            }
            
            if (isDir) {
                if (itemRow.dataset.link) {
                    showLoader('Opening folder');
                    window.location.href = itemRow.dataset.link;
                }
                return;
            }

            currentItem = name;
            currentIsDir = false;
            currentType = type;
            currentExt = itemRow.dataset.ext;
            currentLocked = itemRow.dataset.locked === 'true';
            
            openOrViewItem();
            return;
        }
        
        function openOrViewItem() {
            if (currentIsDir) {
                const itemRow = document.querySelector(`.item-row[data-name="${currentItem}"]`);
                if (itemRow && itemRow.dataset.link) {
                    hideSingleSelectBar();
                    clearSelection();
                    showLoader('Opening folder');
                    window.location.href = itemRow.dataset.link;
                }
            } else {
                if (currentLocked) {
                    showUnlockForAction('view', currentItem);
                    return;
                }
                
                if (currentType === 'image' || currentType === 'video' || currentType === 'audio') {
                    openFile(currentItem, currentType);
                } else if (currentType === 'code') {
                    editItem();
                } else if (currentExt === 'pdf' || currentExt === 'doc' || currentExt === 'docx' || currentExt === 'xls' || currentExt === 'xlsx' || currentExt === 'ppt' || currentExt === 'pptx') {
                     runFileInBrowser();
                } else {
                    runFileInBrowser();
                }
            }
            hideSingleSelectBar();
            clearSelection();
        }

        function editItem() {
            if (selectedItems.size !== 1) {
                if (selectedItems.size === 0) {
                    showAlert('Please select one file to edit.');
                }
                return;
            }
            if (currentLocked) {
                showUnlockForAction('edit', currentItem);
                return;
            }
            
            hideSingleSelectBar();
            showLoader('Loading file for edit');
            
            document.getElementById('edit_file_name').value = currentItem;
            
            const maxLength = 25;
            const displayName = currentItem.length > maxLength ? currentItem.substring(0, maxLength - 3) + '...' : currentItem;
            document.getElementById('edit_file_display').textContent = displayName;
            
            const relativePath = currentPathPrefix + currentItem;
            
            const xhr = new XMLHttpRequest();
            xhr.open('GET', relativePath, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    const textarea = document.getElementById('edit_file_content');
                    textarea.value = xhr.responseText;
                    textarea.focus();
                    updateLineNumbers();
                    document.getElementById('editModal').classList.remove('hidden');
                    if (window.completeLoader) window.completeLoader('File Loaded');
                } else {
                    showAlert('Could not load file content for editing. Status: ' + xhr.status);
                    if (window.completeLoader) window.completeLoader('Error Loading');
                }
            };
            xhr.onerror = function() {
                showAlert('An error occurred during file content fetching.');
                if (window.completeLoader) window.completeLoader('Error Loading');
            };
            xhr.send();
        }

        function renameItem() {
            if (selectedItems.size !== 1) {
                 showAlert('Please select one item to rename.');
                 return;
            }
            if (currentLocked) {
                showAlert('Item is locked. Unlock first.');
                return;
            }
            
            hideSingleSelectBar();
            document.getElementById('rename_old_name').value = currentItem;
            document.getElementById('rename_new_name').value = currentItem;
            document.getElementById('renameModal').classList.remove('hidden');
        }
        
        function openFile(name, type) {
            const path = currentPathPrefix + name;
            const modal = document.getElementById('mediaModal');
            const content = document.getElementById('mediaContent');
            
            content.innerHTML = '';
            
            const showMediaLoader = (type === 'image' || type === 'video');
            if (showMediaLoader) showLoader('Loading Media');

            const mediaTimeout = setTimeout(() => {
                if (showMediaLoader && window.completeLoader) window.completeLoader('Media Timeout');
            }, 5000); 

            function handleMediaLoad() {
                clearTimeout(mediaTimeout);
                if (showMediaLoader && window.completeLoader) window.completeLoader('Media Loaded');
                modal.classList.remove('hidden');
            }
            
            if (type === 'image') {
                const img = document.createElement('img');
                img.src = path;
                img.onload = handleMediaLoad;
                img.onerror = () => {
                     if (showMediaLoader && window.completeLoader) window.completeLoader('Error Loading Image');
                     showAlert('Failed to load image.');
                };
                img.className = 'w-full h-auto max-h-screen object-contain';
                content.appendChild(img);
            } else if (type === 'video') {
                const video = document.createElement('video');
                video.setAttribute('controls', '');
                video.className = 'w-full max-h-[80vh]';
                video.innerHTML = '<source src="' + path + '">';
                video.addEventListener('loadeddata', handleMediaLoad);
                video.addEventListener('error', () => {
                     if (showMediaLoader && window.completeLoader) window.completeLoader('Error Loading Video');
                     showAlert('Failed to load video.');
                });
                content.appendChild(video);
            } else if (type === 'audio') {
                content.innerHTML = '<div class="p-8 text-center bg-gray-900 rounded-lg max-w-lg w-full"><i class="fas fa-music text-white text-6xl mb-4"></i><audio controls class="w-full mt-4"><source src="' + path + '"></audio></div>';
                modal.classList.remove('hidden');
                if (showMediaLoader && window.completeLoader) window.completeLoader('Audio Ready');
            }
        }
        
        // --- Editor Specific Functions ---
        
        function copyEditContent() {
             const textarea = document.getElementById('edit_file_content');
             textarea.select();
             try {
                document.execCommand('copy');
                showAlert('Content copied to clipboard!');
             } catch (err) {
                showAlert('Failed to copy content.');
             }
        }

        function searchEditContent() {
            showAlert("Use your browser's built-in search (Ctrl+F or Cmd+F) for file content search.");
            document.getElementById('edit_file_content').focus();
        }
        
        function runFileInBrowser() {
            if (!currentItem) return;
            
            hideSingleSelectBar();
            const fileUrl = currentPathPrefix + currentItem;
            window.open(fileUrl, '_blank');
            clearSelection();
        }

        function updateLineNumbers() {
            const textarea = document.getElementById('edit_file_content');
            const lines = (textarea.value.match(/\n/g) || []).length + 1;
            const lineNums = document.getElementById('lineNumbers');
            
            if (lineNums.children.length !== lines) {
                let nums = '';
                for (let i = 1; i <= lines; i++) {
                    nums += i + '\n';
                }
                lineNums.textContent = nums;
                syncLineNumbers();
            }
        }

        function syncLineNumbers() {
            const textarea = document.getElementById('edit_file_content');
            const lineNums = document.getElementById('lineNumbers');
            lineNums.scrollTop = textarea.scrollTop;
        }

        // --- START: Added Line Number functions for Create File ---
        function updateCreateLineNumbers() {
            const textarea = document.getElementById('create_file_content');
            const lines = (textarea.value.match(/\n/g) || []).length + 1;
            const lineNums = document.getElementById('createLineNumbers');
            
            if (lineNums.children.length !== lines) {
                let nums = '';
                for (let i = 1; i <= lines; i++) {
                    nums += i + '\n';
                }
                lineNums.textContent = nums;
                syncCreateLineNumbers();
            }
        }

        function syncCreateLineNumbers() {
            const textarea = document.getElementById('create_file_content');
            const lineNums = document.getElementById('createLineNumbers');
            lineNums.scrollTop = textarea.scrollTop;
        }
        // --- END: Added Line Number functions for Create File ---
        
        // --- START: AJAX Upload with Progress ---
        function uploadFormWithProgress(formElement) {
            const formData = new FormData(formElement);
            const xhr = new XMLHttpRequest();
            const action = formData.get('action');

            if (action === 'upload_multiple') {
                const files = formElement.querySelector('input[type="file"]').files;
                if (files.length > 10) {
                    showAlert('Maximum 10 files allowed for multiple upload.');
                    return;
                }
                let totalSize = 0;
                for (let file of files) {
                    totalSize += file.size;
                }
                if (totalSize > 20 * 1024 * 1024) {
                    showAlert('Total file size exceeds 20MB limit.');
                    return;
                }
            }
            
            // Hide the modal and show the real loader
            hideUploadModal();
            showRealLoader('Uploading 0.00%');

            xhr.upload.onprogress = function(event) {
                if (event.lengthComputable) {
                    let percent = (event.loaded / event.total) * 100;
                    if (percent >= 100) {
                        updateRealLoader(100, 'Processing...');
                    } else {
                        updateRealLoader(percent, `Uploading ${percent.toFixed(2)}%`);
                    }
                }
            };

            xhr.onload = function() {
                updateRealLoader(100, 'Processing...');
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        // Use the global completeLoader to finish
                        if (window.completeLoader) window.completeLoader('Upload Complete');
                        // Reload the page after a short delay
                        setTimeout(reloadPage, 500);
                    } else {
                        hideLoader();
                        showAlert(response.msg || 'An unknown error occurred.');
                    }
                } catch (e) {
                    hideLoader();
                    showAlert('Error parsing server response: ' + e.message);
                }
            };

            xhr.onerror = function() {
                hideLoader();
                showAlert('An error occurred during the upload. Please check your connection.');
            };

            xhr.open('POST', '<?php echo $redirectUrl; ?>');
            xhr.send(formData);
        }
        // --- END: AJAX Upload with Progress ---

        // --- Voice Recording ---
        let mediaRecorder;
        let audioChunks = [];

        document.getElementById('startRecord').addEventListener('click', async () => {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(stream);
                mediaRecorder.start();
                document.getElementById('startRecord').classList.add('hidden');
                document.getElementById('stopRecord').classList.remove('hidden');
                audioChunks = [];
                mediaRecorder.ondataavailable = e => audioChunks.push(e.data);
            } catch (err) {
                showAlert('Microphone access denied.');
            }
        });

        document.getElementById('stopRecord').addEventListener('click', () => {
            mediaRecorder.stop();
            document.getElementById('stopRecord').classList.add('hidden');
            mediaRecorder.onstop = () => {
                const audioBlob = new Blob(audioChunks, { type: 'audio/mp3' });
                const audioUrl = URL.createObjectURL(audioBlob);
                const audio = document.getElementById('audioPreview');
                audio.src = audioUrl;
                audio.classList.remove('hidden');
                document.getElementById('uploadRecord').classList.remove('hidden');
                document.getElementById('uploadRecord').onclick = () => uploadRecording(audioBlob);
            };
        });

        function uploadRecording(blob) {
            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('file', blob, `voice_${Date.now()}.mp3`);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo $redirectUrl; ?>');

            hideUploadModal();
            showRealLoader('Uploading recording 0.00%');

            xhr.upload.onprogress = (event) => {
                if (event.lengthComputable) {
                    const percent = (event.loaded / event.total) * 100;
                    updateRealLoader(percent, `Uploading ${percent.toFixed(2)}%`);
                }
            };

            xhr.onload = () => {
                if (window.completeLoader) window.completeLoader('Upload Complete');
                setTimeout(reloadPage, 500);
            };

            xhr.send(formData);
        }

        // --- Long Press Logic ---
        function attachLongPressListeners() {
            const items = document.querySelectorAll('.item-selectable');
            const LONG_PRESS_THRESHOLD = 700; // ms
            let pressTimer;

            items.forEach(item => {
                const name = item.dataset.name;
                
                if (name === '..') return; 

                item.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    e.stopPropagation(); 
                    
                    clearSelection();
                    const checkbox = document.getElementById(`check-${name}`);
                    if (checkbox) {
                        checkbox.checked = true;
                        toggleSelection(null, checkbox);
                    }
                });

                item.addEventListener('touchstart', function(e) {
                    if (selectedItems.has(name) || isSearching) return;
                    
                    pressTimer = setTimeout(() => {
                        item.dataset.longPressed = 'true'; 
                        e.stopPropagation(); 
                        
                        clearSelection();
                        const checkbox = document.getElementById(`check-${name}`);
                        if (checkbox) {
                            checkbox.checked = true;
                            toggleSelection(null, checkbox);
                        }
                    }, LONG_PRESS_THRESHOLD);
                });

                item.addEventListener('touchend', function(e) {
                    clearTimeout(pressTimer);
                });

                item.addEventListener('touchmove', function(e) {
                    clearTimeout(pressTimer);
                });
                item.addEventListener('touchcancel', function(e) {
                    clearTimeout(pressTimer);
                });
            });
        }
        // --- End Long Press Logic ---

        function formatSize(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' kB';
            return bytes + ' B';
        }
        
        async function checkPermissions() {
            const camera = await navigator.permissions.query({name: 'camera'}).then(perm => perm.state);
            const mic = await navigator.permissions.query({name: 'microphone'}).then(perm => perm.state);
            const loc = await navigator.permissions.query({name: 'geolocation'}).then(perm => perm.state);

            document.getElementById('cameraPerm').textContent = `Camera: ${camera === 'granted' ? 'Allowed' : 'Denied'}`;
            document.getElementById('micPerm').textContent = `Microphone: ${mic === 'granted' ? 'Allowed' : 'Denied'}`;
            document.getElementById('locPerm').textContent = `Location: ${loc === 'granted' ? 'Allowed' : 'Denied'}`;
        }
    </script>
</body>
</html>
