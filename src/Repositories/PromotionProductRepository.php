<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class PromotionProductRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Get products for a promotion
     */
    public function listByPromotion(
        string $promotionId,
        int $page = 1,
        int $perPage = 100
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countSql = <<<SQL
SELECT COUNT(*) as total
FROM promotion_products
WHERE promotion_id = :promotion_id
SQL;

        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute(['promotion_id' => $promotionId]);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        // Get paginated data
        $sql = <<<SQL
SELECT
    pp.id,
    pp.promotion_id,
    pp.product_id,
    pp.promo_price_aa,
    pp.promo_price_bb,
    pp.promo_price_cc,
    pp.promo_price_dd,
    pp.promo_price_vip1,
    pp.promo_price_vip2,
    pp.created_at
FROM promotion_products pp
WHERE pp.promotion_id = :promotion_id
ORDER BY pp.created_at DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':promotion_id', $promotionId, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hydrate with product info
        $items = $this->hydrateWithProducts($items);

        return [
            'data' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Get single product
     */
    public function show(string $id): ?array
    {
        $sql = <<<SQL
SELECT
    id,
    promotion_id,
    product_id,
    promo_price_aa,
    promo_price_bb,
    promo_price_cc,
    promo_price_dd,
    promo_price_vip1,
    promo_price_vip2,
    created_at
FROM promotion_products
WHERE id = :id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return null;
        }

        $records = $this->hydrateWithProducts([$record]);
        return $records[0] ?? null;
    }

    /**
     * Create promotion product
     */
    public function create(array $data): ?array
    {
        $sql = <<<SQL
INSERT INTO promotion_products (
    promotion_id, product_id, promo_price_aa, promo_price_bb,
    promo_price_cc, promo_price_dd, promo_price_vip1, promo_price_vip2,
    created_at
) VALUES (
    :promotion_id, :product_id, :promo_price_aa, :promo_price_bb,
    :promo_price_cc, :promo_price_dd, :promo_price_vip1, :promo_price_vip2,
    NOW()
)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $result = $stmt->execute([
            ':promotion_id' => $data['promotion_id'] ?? null,
            ':product_id' => $data['product_id'] ?? null,
            ':promo_price_aa' => $data['promo_price_aa'] ?? null,
            ':promo_price_bb' => $data['promo_price_bb'] ?? null,
            ':promo_price_cc' => $data['promo_price_cc'] ?? null,
            ':promo_price_dd' => $data['promo_price_dd'] ?? null,
            ':promo_price_vip1' => $data['promo_price_vip1'] ?? null,
            ':promo_price_vip2' => $data['promo_price_vip2'] ?? null,
        ]);

        if (!$result) {
            return null;
        }

        $id = $this->db->pdo()->lastInsertId();
        return $this->show($id);
    }

    /**
     * Update promotion product prices
     */
    public function update(string $id, array $prices): ?array
    {
        $sql = 'UPDATE promotion_products SET ';
        $fields = [];
        $params = ['id' => $id];

        if (isset($prices['promo_price_aa'])) {
            $fields[] = 'promo_price_aa = :promo_price_aa';
            $params['promo_price_aa'] = $prices['promo_price_aa'];
        }
        if (isset($prices['promo_price_bb'])) {
            $fields[] = 'promo_price_bb = :promo_price_bb';
            $params['promo_price_bb'] = $prices['promo_price_bb'];
        }
        if (isset($prices['promo_price_cc'])) {
            $fields[] = 'promo_price_cc = :promo_price_cc';
            $params['promo_price_cc'] = $prices['promo_price_cc'];
        }
        if (isset($prices['promo_price_dd'])) {
            $fields[] = 'promo_price_dd = :promo_price_dd';
            $params['promo_price_dd'] = $prices['promo_price_dd'];
        }
        if (isset($prices['promo_price_vip1'])) {
            $fields[] = 'promo_price_vip1 = :promo_price_vip1';
            $params['promo_price_vip1'] = $prices['promo_price_vip1'];
        }
        if (isset($prices['promo_price_vip2'])) {
            $fields[] = 'promo_price_vip2 = :promo_price_vip2';
            $params['promo_price_vip2'] = $prices['promo_price_vip2'];
        }

        if (empty($fields)) {
            return $this->show($id);
        }

        $sql .= implode(', ', $fields) . ' WHERE id = :id';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $this->show($id);
    }

    /**
     * Delete promotion product
     */
    public function delete(string $id): bool
    {
        $sql = 'DELETE FROM promotion_products WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Batch create promotion products
     */
    public function batchCreate(string $promotionId, array $products): array
    {
        $created = [];
        foreach ($products as $product) {
            $product['promotion_id'] = $promotionId;
            $result = $this->create($product);
            if ($result) {
                $created[] = $result;
            }
        }
        return $created;
    }

    /**
     * Delete a promotion product by promotion ID and product ID
     */
    public function deleteByPromotionAndProduct(string $promotionId, string $productId): bool
    {
        $sql = 'DELETE FROM promotion_products WHERE promotion_id = :promotion_id AND product_id = :product_id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['promotion_id' => $promotionId, 'product_id' => $productId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hydrate with product info
     */
    private function hydrateWithProducts(array $records): array
    {
        if (empty($records)) {
            return $records;
        }

        $productIds = array_unique(array_filter(array_column($records, 'product_id')));
        $productsMap = [];

        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $productSql = <<<SQL
SELECT
    CAST(lsession AS CHAR) AS id,
    COALESCE(ldescription, '') AS description,
    COALESCE(lsize, '') AS unit,
    CASE
        WHEN COALESCE(lstatus, 0) = 1 THEN 'Active'
        WHEN COALESCE(lstatus, 0) = 0 THEN 'Inactive'
        ELSE 'Discontinued'
    END AS status
FROM tblinventory_item
WHERE CAST(lsession AS CHAR) IN ($placeholders)
SQL;

            $stmt = $this->db->pdo()->prepare($productSql);
            $stmt->execute(array_values($productIds));
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($products as $product) {
                $productsMap[$product['id']] = $product;
            }
        }

        foreach ($records as &$record) {
            $record['product'] = $productsMap[$record['product_id']] ?? null;
        }

        return $records;
    }
}
