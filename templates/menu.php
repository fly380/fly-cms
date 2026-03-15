<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $db = fly_db();
    
    // Поточна роль користувача
    $role = $_SESSION['role'] ?? 'guest';
    $loggedIn = $_SESSION['loggedin'] ?? false;

    // Отримуємо всі пункти меню
    $stmt = $db->query("SELECT * FROM menu_items ORDER BY position ASC");
    $menuItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Режим: вертикальний (мобільний) чи горизонтальний (ПК)
    $isVertical = $vertical ?? false;

    if (!function_exists('buildMenuTree')) {
        function buildMenuTree($items, $parentId = null) {
            $tree = [];
            foreach ($items as $item) {
                if ((int)$item['parent_id'] === (int)$parentId) {
                    $item['children'] = buildMenuTree($items, $item['id']);
                    $tree[] = $item;
                }
            }
            return $tree;
        }
    }

    if (!function_exists('renderMenu')) {
        function renderMenu($items, $isVertical, $role, $loggedIn) {
            foreach ($items as $item) {
                if (!$item['visible']) continue;

                $type = $item['type'] ?? 'link';
                
                $visibilityRole = $item['visibility_role'] ?? 'all';
                $show = false;
                
                if ($visibilityRole === 'all') {
                    $show = true;
                } elseif ($visibilityRole === 'auth') {
                    $show = $loggedIn;
                } elseif ($visibilityRole === 'editor_admin') {
                    $show = in_array($role, ['admin', 'redaktor', 'superadmin']);
                } elseif ($visibilityRole === 'admin') {
                    $show = in_array($role, ['admin', 'superadmin']);
                } else {
                    $show = ($visibilityRole === $role);
                }

                if (!$show || ($item['auth_only'] && !$loggedIn)) continue;

                $hasChildren = !empty($item['children']);

                if ($isVertical) {
                    echo '<li class="nav-item">';
                    if ($type === 'login_logout') {
                        if ($loggedIn) {
                            echo '<a href="/templates/logout.php" class="nav-link text-danger" data-translate="yes">Вийти</a>';
                        } else {
                            echo '<a href="/templates/login.php" class="nav-link text-success" data-translate="yes">Увійти</a>';
                        }
                    } elseif ($type === 'language_switcher') {
                        echo '<div id="lang-switcher-mount" class="ms-2"></div>';
                    } else {
                        echo '<a class="nav-link" href="' . htmlspecialchars($item['url']) . '" data-translate="yes">' . htmlspecialchars($item['title']) . '</a>';
                        if ($hasChildren) {
                            echo '<ul class="nav flex-column ms-3">';
                            renderMenu($item['children'], true, $role, $loggedIn);
                            echo '</ul>';
                        }
                    }
                    echo '</li>';
                } else {
                    if ($type === 'login_logout') {
                        if ($loggedIn) {
                            echo '<a href="/templates/logout.php" class="btn btn-outline-danger" data-translate="yes">Вийти</a>';
                        } else {
                            echo '<a href="/templates/login.php" class="btn btn-outline-success" data-translate="yes">Увійти</a>';
                        }
                    } elseif ($type === 'language_switcher') {
                        echo '<div id="lang-switcher-mount"></div>';
                    } elseif ($hasChildren) {
                        echo '<div class="btn-group dropdown dropdown-hover" style="position: relative;">';
                        echo '<a href="' . htmlspecialchars($item['url']) . '" class="btn btn-outline-light" data-translate="yes">' . htmlspecialchars($item['title']) . '</a>';
                        echo '<button type="button" class="btn btn-outline-light dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">';
                        echo '<span class="visually-hidden" data-translate="yes">Розгорнути підменю</span>';
                        echo '</button>';
                        echo '<ul class="dropdown-menu">';
                        foreach ($item['children'] as $child) {
                            echo '<li><a class="dropdown-item" href="' . htmlspecialchars($child['url']) . '" data-translate="yes">' . htmlspecialchars($child['title']) . '</a></li>';
                        }
                        echo '</ul></div>';
                    } else {
                        echo '<a href="' . htmlspecialchars($item['url']) . '" class="btn btn-outline-light" data-translate="yes">' . htmlspecialchars($item['title']) . '</a>';
                    }
                }
            }
        }
    }

    // Побудова дерева та вивід
    $menuTree = buildMenuTree($menuItems);
    echo $isVertical ? '<ul class="nav flex-column">' : '<nav class="d-flex flex-wrap gap-2 mb-3">';
    renderMenu($menuTree, $isVertical, $role, $loggedIn);
    echo $isVertical ? '</ul>' : '</nav>';
    
} catch (PDOException $e) {
    error_log('Menu error: ' . $e->getMessage());
    echo $isVertical ? '<ul class="nav flex-column"></ul>' : '<nav class="d-flex flex-wrap gap-2 mb-3"></nav>';
}
?>