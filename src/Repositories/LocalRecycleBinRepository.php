<?php

declare(strict_types=1);
namespace App\Repositories;

use App\Database;
use App\Support\Exceptions\HttpException;
use PDO;
use Throwable;

final class LocalRecycleBinRepository
{
    private const CUSTOMER_TABLES = ['tblpatient' => 'lsessionid', 'tblcontact_person' => 'lrefno', 'tblpatient_terms' => 'lpatient', 'tblpatient_image' => 'lrefno'];
    public function __construct(private readonly Database $db) {}

    /** Called inside the source deletion transaction, before anything is changed. */
    public function capture(int $mainId, string $type, string $itemId): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Recovery capture requires the deletion transaction');
        $table = $type === 'contact' ? 'tblpatient' : 'tblinventory_item';
        $key = $type === 'contact' ? 'lsessionid' : 'lsession';
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE lmain_id = ? AND {$key} = ? FOR UPDATE");
        $stmt->execute([$mainId, $itemId]);
        $row = $stmt->fetch();
        if (!$row) throw new HttpException(404, 'Record not found');
        $snapshot = [$table => [$row]];
        if ($type === 'contact') {
            foreach (self::CUSTOMER_TABLES as $child => $foreignKey) {
                if ($child === 'tblpatient') continue;
                $stmt = $pdo->prepare("SELECT * FROM {$child} WHERE {$foreignKey} = ? FOR UPDATE");
                $stmt->execute([$itemId]);
                $snapshot[$child] = $stmt->fetchAll();
            }
        }
        $label = (string) ($row[$type === 'contact' ? 'lcompany' : 'litemcode'] ?? $itemId);
        // Repeated deletes must not overwrite the original recovery state.
        $stmt = $pdo->prepare('INSERT INTO local_recycle_bin (main_id,item_type,item_id,label,snapshot) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
        $stmt->execute([$mainId, $type, $itemId, $label, json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    }

    public function list(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id,item_type,item_id,label,deleted_at FROM local_recycle_bin WHERE main_id = ? ORDER BY deleted_at DESC,id DESC');
        $stmt->execute([$mainId]);
        // Never send raw customer snapshots (which contain legacy credential columns).
        return $stmt->fetchAll();
    }

    public function act(int $mainId, int $userId, string $id, bool $restore): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM local_recycle_bin WHERE main_id = ? AND id = ? FOR UPDATE');
            $stmt->execute([$mainId, $id]);
            $item = $stmt->fetch();
            if (!$item) throw new HttpException(404, 'Recovery entry not found');
            if ($restore) {
                $snapshot = json_decode($item['snapshot'], true, 512, JSON_THROW_ON_ERROR);
                if ($item['item_type'] === 'contact') {
                    foreach (self::CUSTOMER_TABLES as $table => $key) {
                        $check = $pdo->prepare("SELECT lid FROM {$table} WHERE {$key} = ? LIMIT 1 FOR UPDATE");
                        $check->execute([$item['item_id']]);
                        if ($check->fetch()) throw new HttpException(409, 'A record already uses this customer reference; restoration would overwrite data');
                        foreach ($snapshot[$table] ?? [] as $row) {
                            // Column names originate from a server-created snapshot, never a client payload.
                            $columns = array_keys($row);
                            if (array_filter($columns, fn($column) => !preg_match('/^[A-Za-z0-9_]+$/', $column))) throw new \RuntimeException('Invalid recovery schema');
                            $sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
                            $pdo->prepare($sql)->execute(array_values($row));
                        }
                    }
                } else {
                    $old = $snapshot['tblinventory_item'][0];
                    $stmt = $pdo->prepare('SELECT lid,lstatus,lnot_inventory FROM tblinventory_item WHERE lmain_id = ? AND lsession = ? FOR UPDATE');
                    $stmt->execute([$mainId, $item['item_id']]);
                    $current = $stmt->fetch();
                    if (!$current || (string) $current['lid'] !== (string) $old['lid'] || (int) $current['lstatus'] !== 0 || (int) $current['lnot_inventory'] !== 1) throw new HttpException(409, 'Product state changed; review it in Product Database');
                    $stmt = $pdo->prepare('UPDATE tblinventory_item SET lstatus = ?, lnot_inventory = ? WHERE lmain_id = ? AND lsession = ?');
                    $stmt->execute([$old['lstatus'], $old['lnot_inventory'], $mainId, $item['item_id']]);
                }
            }
            $pdo->prepare('DELETE FROM local_recycle_bin WHERE main_id = ? AND id = ?')->execute([$mainId, $id]);
            $pdo->prepare('INSERT INTO tblaudit_trail (lmain_id,luser_id,lpage,laction,lrefno,ldatetime) VALUES (?,?,?,?,?,NOW())')->execute([$mainId, $userId, 'Server Maintenance', $restore ? 'Restore' : 'Discard Recovery', $item['item_type'] . ':' . $item['item_id']]);
            $pdo->commit();
            return ['success' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
