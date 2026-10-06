<?php

declare(strict_types=1);

use NoBuildCMS\App;
use NoBuildCMS\Auth;
use NoBuildCMS\Ds;

// Serve existing static files (assets) directly under the built-in server.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . '/' . ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    if (is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

session_start();

$app = new App(dirname(__DIR__));
$auth = new Auth($app->store);

// Assign a stable visitor identity.
if (empty($_SESSION['visitor'])) {
    $_SESSION['visitor'] = ['name' => 'Guest ' . random_int(1000, 9999), 'id' => bin2hex(random_bytes(4))];
}
$app->setVisitor($_SESSION['visitor']);

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

/** Render a public content record inside the site layout. */
$renderPublic = function (?array $record) use ($app): string {
    if (!$record) {
        http_response_code(404);
        $record = ['type' => 'page', 'title' => '404', 'body' => "<section class='mx-auto max-w-3xl px-5 py-24 text-center'><h1 class='text-4xl font-bold text-slate-900'>404</h1><p class='mt-2 text-slate-500'>Page not found.</p></section>"];
    }
    $html = $app->renderBody($record);

    return $app->render('layouts/site.html.twig', [
        'page' => $record,
        'content' => $html,
        'nav' => $app->fnCollection('pages', ['limit' => 20]),
    ]);
};

$redirect = function (string $to) {
    header('Location: ' . $to);
    exit;
};

// ---- Live-reload version (polled by the site; non-blocking) ---------------
if ($path === '/api/version') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['v' => $app->store->version()]);
    exit;
}

// ---- SSE live-reload (for production servers with concurrency) -------------
if ($path === '/sse/reload') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    echo "retry: 1000\n\n";
    for ($i = 0; $i < 30 && !connection_aborted(); $i++) {
        echo 'event: version' . "\n";
        echo 'data: ' . $app->store->version() . "\n\n";
        @ob_flush();
        @flush();
        sleep(1);
    }
    exit;
}

// ---- Chat (public lobby) --------------------------------------------------
if ($path === '/chat/poll' || $path === '/chat/send') {
    if ($path === '/chat/send' && $method === 'POST') {
        $body = trim((string) (Ds::signals()['draft'] ?? ''));
        if ($body !== '') {
            $staff = ($_GET['staff'] ?? '') === '1' && $auth->check();
            $app->store->save('chat', [
                'name' => $staff ? ($auth->user()['name'] ?? 'Staff') : ($app->visitor['name'] ?? 'Guest'),
                'role' => $staff ? 'staff' : 'guest',
                'body' => mb_substr($body, 0, 500),
            ]);
        }
    }
    $messages = array_slice($app->store->all('chat'), -50);
    Ds::patchElements(
        $app->render('chat-messages.html.twig', ['messages' => $messages]),
        ['selector' => '#chat-messages', 'mode' => 'inner']
    );
    if ($path === '/chat/send') {
        Ds::patchSignals(['draft' => '']);
    }
    exit;
}

// ---- Admin ----------------------------------------------------------------
if ($path === '/admin/login') {
    if ($method === 'POST') {
        if ($auth->attempt($_POST['email'] ?? '', $_POST['password'] ?? '')) {
            $redirect('/admin');
        }
        echo $app->render('admin/login.html.twig', ['error' => 'Invalid email or password.']);
        exit;
    }
    echo $app->render('admin/login.html.twig', []);
    exit;
}

if ($path === '/admin/logout') {
    $auth->logout();
    $redirect('/admin/login');
}

if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    if (!$auth->check()) {
        $redirect('/admin/login');
    }
    $user = $auth->user();
    $types = ['pages' => 'Pages', 'posts' => 'Articles', 'products' => 'Products'];

    // Dashboard summary
    if ($path === '/admin') {
        echo $app->render('admin/dashboard.html.twig', [
            'user' => $user,
            'types' => $types,
            'active' => 'home',
            'title' => 'Overview',
            'subtitle' => 'Site & content metrics',
            'counts' => [
                'pages' => count($app->store->all('pages')),
                'posts' => count($app->store->all('posts')),
                'products' => count($app->store->all('products')),
            ],
            'recent' => $app->fnCollection('posts', ['sort' => '-updated_at', 'limit' => 5, 'status' => '*']),
        ]);
        exit;
    }

    // Settings
    if ($path === '/admin/settings') {
        if ($method === 'POST') {
            if (!$auth->can('manage_settings')) {
                http_response_code(403);
                exit('Forbidden');
            }
            $s = $app->settings;
            foreach (['site_name', 'tagline', 'footer', 'currency', 'accent', 'whatsapp', 'telegram', 'email', 'address'] as $k) {
                if (isset($_POST[$k])) {
                    $s[$k] = $_POST[$k];
                }
            }
            $app->store->write('settings', $s);
            $redirect('/admin/settings');
        }
        echo $app->render('admin/settings.html.twig', ['user' => $user, 'types' => $types, 's' => $app->settings, 'active' => 'settings', 'title' => 'Settings', 'subtitle' => 'Stored in data/settings.json']);
        exit;
    }

    // Chat: staff view of the visitor lobby
    if ($path === '/admin/chat') {
        echo $app->render('admin/chat.html.twig', [
            'user' => $user, 'types' => $types, 'active' => 'chat',
            'title' => 'Chat', 'subtitle' => 'Visitor lobby — reply as staff',
            'messages' => array_slice($app->store->all('chat'), -50),
        ]);
        exit;
    }

    // Snippets: reusable Twig partials called via snippet('key')
    if (preg_match('#^/admin/snippets(?:/(edit|save|delete))?$#', $path, $m)) {
        $op = $m[1] ?? 'list';
        if ($op === 'save' && $method === 'POST') {
            if (!$auth->can('edit')) { http_response_code(403); exit('Forbidden'); }
            $app->store->save('snippets', [
                'id' => $_POST['id'] ?: null,
                'key' => trim($_POST['key'] ?? ''),
                'name' => trim($_POST['name'] ?? ''),
                'body' => $_POST['body'] ?? '',
            ]);
            $redirect('/admin/snippets');
        }
        if ($op === 'delete' && $method === 'POST') {
            if (!$auth->can('edit')) { http_response_code(403); exit('Forbidden'); }
            $app->store->delete('snippets', $_POST['id'] ?? '');
            $redirect('/admin/snippets');
        }
        if ($op === 'edit') {
            $record = ($_GET['id'] ?? '') ? $app->store->findById('snippets', $_GET['id']) : null;
            echo $app->render('admin/snippet-editor.html.twig', ['user' => $user, 'types' => $types, 'active' => 'snippets', 'title' => $record ? 'Edit snippet' : 'New snippet', 'subtitle' => $record['key'] ?? '', 'record' => $record]);
            exit;
        }
        echo $app->render('admin/snippets-list.html.twig', ['user' => $user, 'types' => $types, 'active' => 'snippets', 'title' => 'Snippets', 'subtitle' => 'Reusable Twig partials — snippet(\'key\')', 'rows' => $app->store->all('snippets')]);
        exit;
    }

    // Datasets: reusable JSON rows read via data('key')
    if (preg_match('#^/admin/datasets(?:/(edit|save|delete))?$#', $path, $m)) {
        $op = $m[1] ?? 'list';
        if ($op === 'save' && $method === 'POST') {
            if (!$auth->can('edit')) { http_response_code(403); exit('Forbidden'); }
            $rows = json_decode($_POST['rows'] ?? '[]', true);
            $app->store->save('datasets', [
                'id' => $_POST['id'] ?: null,
                'key' => trim($_POST['key'] ?? ''),
                'name' => trim($_POST['name'] ?? ''),
                'rows' => is_array($rows) ? $rows : [],
            ]);
            $redirect('/admin/datasets');
        }
        if ($op === 'delete' && $method === 'POST') {
            if (!$auth->can('edit')) { http_response_code(403); exit('Forbidden'); }
            $app->store->delete('datasets', $_POST['id'] ?? '');
            $redirect('/admin/datasets');
        }
        if ($op === 'edit') {
            $record = ($_GET['id'] ?? '') ? $app->store->findById('datasets', $_GET['id']) : null;
            echo $app->render('admin/dataset-editor.html.twig', ['user' => $user, 'types' => $types, 'active' => 'datasets', 'title' => $record ? 'Edit dataset' : 'New dataset', 'subtitle' => $record['key'] ?? '', 'record' => $record, 'rows_json' => $record ? json_encode($record['rows'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : "[\n    { \"name\": \"Example\" }\n]"]);
            exit;
        }
        echo $app->render('admin/datasets-list.html.twig', ['user' => $user, 'types' => $types, 'active' => 'datasets', 'title' => 'Datasets', 'subtitle' => 'Reusable JSON rows — data(\'key\')', 'rows' => $app->store->all('datasets')]);
        exit;
    }

    // Media: image uploads stored in public/uploads
    if ($path === '/admin/media' || $path === '/admin/media/delete') {
        $dir = dirname(__DIR__) . '/public/uploads';
        $error = null;
        if ($path === '/admin/media/delete' && $method === 'POST') {
            if (!$auth->can('edit')) { http_response_code(403); exit('Forbidden'); }
            $name = basename($_POST['name'] ?? '');
            if ($name && is_file($dir . '/' . $name)) {
                @unlink($dir . '/' . $name);
            }
            $redirect('/admin/media');
        }
        if ($path === '/admin/media' && $method === 'POST') {
            if (!$auth->can('edit')) { http_response_code(403); exit('Forbidden'); }
            $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
            $f = $_FILES['file'] ?? null;
            if ($f && is_uploaded_file($f['tmp_name'] ?? '')) {
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $error = 'Unsupported file type.';
                } elseif (($f['size'] ?? 0) > 2 * 1024 * 1024) {
                    $error = 'File is larger than 2 MB.';
                } else {
                    $base = preg_replace('/[^a-z0-9_-]+/i', '-', pathinfo($f['name'], PATHINFO_FILENAME));
                    $name = strtolower(trim($base, '-') ?: 'file') . '.' . $ext;
                    $target = $dir . '/' . $name;
                    $i = 1;
                    while (is_file($target)) {
                        $name = strtolower(trim($base, '-')) . '-' . $i++ . '.' . $ext;
                        $target = $dir . '/' . $name;
                    }
                    move_uploaded_file($f['tmp_name'], $target);
                }
            }
            if (!$error) {
                $redirect('/admin/media');
            }
        }
        $files = [];
        foreach (glob($dir . '/*.{png,jpg,jpeg,gif,webp,svg}', GLOB_BRACE) ?: [] as $p) {
            $files[] = ['name' => basename($p), 'size' => filesize($p), 'url' => '/uploads/' . basename($p)];
        }
        echo $app->render('admin/media.html.twig', ['user' => $user, 'types' => $types, 'active' => 'media', 'title' => 'Media', 'subtitle' => 'PNG, JPG, GIF, WebP, SVG · max 2 MB · stored in public/uploads', 'files' => $files, 'error' => $error]);
        exit;
    }

    // Content: /admin/content/{type}[/edit|/save|/delete|/inline|/rename]
    if (preg_match('#^/admin/content/(pages|posts|products)(?:/(edit|save|delete|inline|rename))?$#', $path, $m)) {
        $type = $m[1];
        $op = $m[2] ?? 'list';

        // Re-render the content table (used by Datastar list patches).
        $patchRows = function () use ($app, $type, $types) {
            $rows = array_map([$app, 'decorate'], $app->store->all($type));
            return $app->render('admin/content-table.html.twig', ['type' => $type, 'label' => $types[$type], 'rows' => $rows]);
        };

        if ($op === 'save' && $method === 'POST') {
            if (!$auth->can('edit')) {
                http_response_code(403);
                exit('Forbidden');
            }
            $rec = [
                'id' => $_POST['id'] ?: null,
                'type' => rtrim($type, 's') === 'page' ? 'page' : (rtrim($type, 's')),
                'title' => trim($_POST['title'] ?? ''),
                'slug' => trim($_POST['slug'] ?? ''),
                'status' => ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
                'layout' => $_POST['layout'] ?? 'full',
                'excerpt' => $_POST['excerpt'] ?? '',
                'body' => $_POST['body'] ?? '',
                'tags' => array_values(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')))),
            ];
            if ($type === 'products') {
                $rec['attrs'] = [
                    'category' => $_POST['category'] ?? '',
                    'price' => round((float) ($_POST['price'] ?? 0), 2),
                    'stock' => (int) ($_POST['stock'] ?? 0),
                    'emoji' => $_POST['emoji'] ?? '📦',
                    'featured' => isset($_POST['featured']),
                ];
            }
            $app->store->save($type, $rec);
            if (Ds::isRequest()) {
                Ds::start();
                Ds::patchElements($patchRows());
                Ds::patchSignals(['drawerOpen' => false]);
                exit;
            }
            $redirect('/admin/content/' . $type);
        }

        if ($op === 'delete' && $method === 'POST') {
            if (!$auth->can('edit')) {
                http_response_code(403);
                exit('Forbidden');
            }
            $app->store->delete($type, $_POST['id'] ?? '');
            $redirect('/admin/content/' . $type);
        }

        if ($op === 'edit') {
            $id = $_GET['id'] ?? '';
            $record = $id ? $app->store->findById($type, $id) : null;
            if ($record) {
                $record = $app->decorate($record);
            }
            if (Ds::isRequest()) {
                Ds::start();
                Ds::patchElements($app->render('admin/editor-fragment.html.twig', [
                    'type' => $type, 'label' => $types[$type], 'record' => $record,
                ]));
                exit;
            }
            echo $app->render('admin/editor.html.twig', [
                'user' => $user, 'types' => $types, 'type' => $type,
                'label' => $types[$type], 'record' => $record,
                'active' => $type, 'title' => ($record ? 'Edit ' : 'New: ') . $types[$type],
                'subtitle' => $record['slug'] ?? '',
            ]);
            exit;
        }

        if ($op === 'inline') {
            $id = $_GET['id'] ?? '';
            $rec = $id ? $app->store->findById($type, $id) : null;
            if ($rec) {
                $rec = $app->decorate($rec);
                Ds::start();
                $tpl = ($_GET['cancel'] ?? '') ? 'admin/content-title-cell.html.twig' : 'admin/content-title-edit.html.twig';
                Ds::patchElements($app->render($tpl, ['type' => $type, 'r' => $rec]));
            }
            exit;
        }

        if ($op === 'rename' && $method === 'POST') {
            if (!$auth->can('edit')) {
                http_response_code(403);
                exit('Forbidden');
            }
            $id = $_GET['id'] ?? '';
            $rec = $id ? $app->store->findById($type, $id) : null;
            if ($rec) {
                $title = trim($_POST['title'] ?? '') ?: $rec['title'];
                $app->store->save($type, ['id' => $id, 'title' => $title]);
                $rec = $app->decorate($app->store->findById($type, $id));
                Ds::start();
                Ds::patchElements($app->render('admin/content-title-cell.html.twig', ['type' => $type, 'r' => $rec]));
            }
            exit;
        }

        // list
        $rows = array_map([$app, 'decorate'], $app->store->all($type));
        echo $app->render('admin/content-list.html.twig', [
            'user' => $user, 'types' => $types, 'type' => $type,
            'label' => $types[$type], 'rows' => $rows,
            'active' => $type, 'title' => $types[$type],
            'subtitle' => count($rows) . ' items · edited here, live on the site instantly',
        ]);
        exit;
    }

    http_response_code(404);
    echo $app->render('admin/dashboard.html.twig', ['user' => $user, 'types' => $types, 'counts' => [], 'recent' => []]);
    exit;
}

// ---- Public site ----------------------------------------------------------
if ($path === '/') {
    echo $renderPublic($app->resolve('pages', 'home'));
    exit;
}
if (preg_match('#^/blog/([a-z0-9\-]+)$#', $path, $m)) {
    echo $renderPublic($app->resolve('posts', $m[1]));
    exit;
}
if (preg_match('#^/products/([a-z0-9\-]+)$#', $path, $m)) {
    echo $renderPublic($app->resolve('products', $m[1]));
    exit;
}
if (preg_match('#^/([a-z0-9\-]+)$#', $path, $m)) {
    echo $renderPublic($app->resolve('pages', $m[1]));
    exit;
}

http_response_code(404);
echo $renderPublic(null);
