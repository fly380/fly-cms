<?php
/**
 * data/HomeService.php — Сервіс для головної сторінки
 *
 * Використовує глобальний PDO singleton fly_db() з config.php
 * замість власного new PDO() — одне з'єднання на весь запит.
 *
 * index.php підключає config.php раніше, тому fly_db() вже доступна.
 */

class HomeService
{
    private PDO $db;

    public function __construct()
    {
        // fly_db() повертає singleton з config.php (WAL + busy_timeout вже налаштовані)
        $this->db = fly_db();
    }

    /**
     * Повертає HTML-вміст блоку main_page.
     * При помилці — порожній рядок (сайт не падає).
     */
    public function getMainContent(): string
    {
        try {
            $stmt = $this->db->query("SELECT content FROM main_page WHERE id = 1");
            $row  = $stmt->fetch();
            return $row ? (string)($row['content'] ?? '') : '';
        } catch (Exception $e) {
            error_log('HomeService::getMainContent — ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Повертає опубліковані записи для головної сторінки.
     *
     * @param  string|int|null $categoryId  Фільтр по категорії ('' або null = без фільтру)
     * @param  int             $limit
     * @return array<int, array<string, mixed>>
     */
    public function getPosts(mixed $categoryId, int $limit): array
    {
        try {
            $sql    = "SELECT p.* FROM posts p WHERE p.draft = 0 AND p.show_on_main = 1";
            $params = [];

            if ($categoryId !== '' && $categoryId !== null) {
                $sql     .= " AND p.id IN (SELECT post_id FROM post_categories WHERE category_id = ?)";
                $params[] = $categoryId;
            }

            $sql     .= " ORDER BY p.created_at DESC LIMIT ?";
            $params[] = $limit;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();

        } catch (Exception $e) {
            error_log('HomeService::getPosts — ' . $e->getMessage());
            return [];
        }
    }
}