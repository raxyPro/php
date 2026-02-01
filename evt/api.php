<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/db_registry.php';
require_once __DIR__ . '/lib/db_folder.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'folders.list': {
            json_response(['ok'=>true, 'folders'=>registry_list_folders()]);
        }

        case 'folders.create': {
            require_post();
            $name = (string)($_POST['name'] ?? '');
            $folder = registry_create_folder($name);
            json_response(['ok'=>true, 'folder'=>$folder]);
        }

        case 'folders.remove': {
            require_post();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('Invalid folder id');
            registry_remove_folder($id);
            json_response(['ok'=>true]);
        }

        case 'tags.list': {
            $folderId = (int)($_GET['folder_id'] ?? 0);
            if ($folderId <= 0) throw new InvalidArgumentException('folder_id required');
            json_response(['ok'=>true, 'tags'=>folder_list_tags($folderId)]);
        }

        case 'tags.create': {
            require_post();
            $folderId = (int)($_POST['folder_id'] ?? 0);
            $name = (string)($_POST['name'] ?? '');
            if ($folderId <= 0) throw new InvalidArgumentException('folder_id required');
            $tag = folder_create_tag($folderId, $name);
            json_response(['ok'=>true, 'tag'=>$tag]);
        }

        case 'events.list': {
            $folderId = (int)($_GET['folder_id'] ?? 0);
            if ($folderId <= 0) throw new InvalidArgumentException('folder_id required');
            $q = isset($_GET['q']) ? (string)$_GET['q'] : null;
            $tagId = isset($_GET['tag_id']) && $_GET['tag_id'] !== '' ? (int)$_GET['tag_id'] : null;
            $events = folder_list_events($folderId, $q, $tagId);
            json_response(['ok'=>true, 'events'=>$events]);
        }

        case 'events.get': {
            $folderId = (int)($_GET['folder_id'] ?? 0);
            $eventId = (int)($_GET['event_id'] ?? 0);
            if ($folderId<=0 || $eventId<=0) throw new InvalidArgumentException('folder_id and event_id required');
            $ev = folder_get_event($folderId, $eventId);
            json_response(['ok'=>true, 'event'=>$ev]);
        }

        case 'events.create': {
            require_post();
            $folderId = (int)($_POST['folder_id'] ?? 0);
            if ($folderId <= 0) throw new InvalidArgumentException('folder_id required');
            $data = [
                'event_date' => (string)($_POST['event_date'] ?? ''),
                'name' => (string)($_POST['name'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'remark' => (string)($_POST['remark'] ?? ''),
            ];
            $ev = folder_create_event($folderId, $data);
            $tagsCsv = (string)($_POST['tags_csv'] ?? '');
            $tagNames = array_filter(array_map('trim', explode(',', $tagsCsv)));
            folder_set_event_tags($folderId, (int)$ev['id'], $tagNames);
            $ev = folder_get_event($folderId, (int)$ev['id']);
            json_response(['ok'=>true, 'event'=>$ev]);
        }

        case 'events.update': {
            require_post();
            $folderId = (int)($_POST['folder_id'] ?? 0);
            $eventId = (int)($_POST['event_id'] ?? 0);
            if ($folderId<=0 || $eventId<=0) throw new InvalidArgumentException('folder_id and event_id required');
            $data = [
                'event_date' => (string)($_POST['event_date'] ?? ''),
                'name' => (string)($_POST['name'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'remark' => (string)($_POST['remark'] ?? ''),
            ];
            $ev = folder_update_event($folderId, $eventId, $data);
            $tagsCsv = (string)($_POST['tags_csv'] ?? '');
            $tagNames = array_filter(array_map('trim', explode(',', $tagsCsv)));
            folder_set_event_tags($folderId, $eventId, $tagNames);
            $ev = folder_get_event($folderId, $eventId);
            json_response(['ok'=>true, 'event'=>$ev]);
        }

        case 'events.link_file': {
            require_post();
            $folderId = (int)($_POST['folder_id'] ?? 0);
            $eventId  = (int)($_POST['event_id'] ?? 0);
            if ($folderId<=0 || $eventId<=0) throw new InvalidArgumentException('folder_id and event_id required');

            $fileUrl = (string)($_POST['file_url'] ?? '');
            $localPath = (string)($_POST['local_path'] ?? '');
            $display = (string)($_POST['display_name'] ?? '');
            $fileType = (string)($_POST['file_type'] ?? '');

            folder_link_file_to_event($folderId, $eventId, [
                'file_url' => $fileUrl,
                'local_path' => $localPath !== '' ? $localPath : null,
                'display_name' => $display !== '' ? $display : null,
                'file_type' => $fileType !== '' ? $fileType : null,
            ]);

            $ev = folder_get_event($folderId, $eventId);
            json_response(['ok'=>true, 'event'=>$ev]);
        }

        case 'events.remove_file': {
            require_post();
            $folderId = (int)($_POST['folder_id'] ?? 0);
            $eventFileId = (int)($_POST['event_file_id'] ?? 0);
            if ($folderId<=0 || $eventFileId<=0) throw new InvalidArgumentException('folder_id and event_file_id required');
            folder_remove_event_file($folderId, $eventFileId);
            json_response(['ok'=>true]);
        }

        case 'inbox.list': {
            $folderId = (int)($_GET['folder_id'] ?? 0);
            if ($folderId<=0) throw new InvalidArgumentException('folder_id required');
            $files = folder_list_inbox_files($folderId);
            json_response(['ok'=>true, 'files'=>$files]);
        }

        case 'inbox.upload': {
            require_post();
            $folderId = (int)($_POST['folder_id'] ?? 0);
            if ($folderId<=0) throw new InvalidArgumentException('folder_id required');
            if (!isset($_FILES['files'])) throw new InvalidArgumentException('files[] required');
            $saved = folder_save_uploads_to_inbox($folderId, $_FILES['files']);
            json_response(['ok'=>true, 'saved'=>$saved]);
        }

        // Serve uploaded files (local-only)
        // URL: api.php?action=download&folder_id=1&name=filename.ext
        case 'download': {
            $folderId = (int)($_GET['folder_id'] ?? 0);
            $name = (string)($_GET['name'] ?? '');
            if ($folderId<=0 || $name==='') { http_response_code(400); exit; }

            $f = registry_get_folder($folderId);
            if (!$f) { http_response_code(404); exit; }

            $folderPath = (string)$f['path'];
            folder_init_storage($folderPath);
            $dir = folder_files_dir($folderPath);

            // prevent traversal
            $name = basename($name);
            $full = safe_join($dir, $name);
            if (!is_file($full)) { http_response_code(404); exit; }

            $mime = mime_content_type($full) ?: 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($full));
            header('Content-Disposition: inline; filename="' . $name . '"');
            readfile($full);
            exit;
        }

        default:
            json_response(['ok'=>false, 'error'=>'Unknown action'], 400);
    }
} catch (Throwable $e) {
    json_response(['ok'=>false, 'error'=>$e->getMessage()], 400);
}
