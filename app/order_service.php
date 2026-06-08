<?php
// =====================================================
// 作業指示サービス
// 目的: 作業指示の作成・更新・取得
// 接続テーブル: manufacturing_orders, manufacturing_order_processes
// 呼び出し元: orders.php, order_form.php
// =====================================================
require_once __DIR__ . '/chair_type_service.php';
require_once __DIR__ . '/standard_time_service.php';

/**
 * 作業指示を新規作成する
 * 椅子タイプのスナップショットを保存し、工程標準時間を自動セット
 *
 * @param array $data ['chair_type_id', 'quantity', 'due_date', 'priority', 'memo', 'customer_name', 'project_name', 'order_date']
 * @return int 作成された作業指示ID
 */
function createOrder(array $data): int {
    $chairTypeId = (int)$data['chair_type_id'];
    $quantity    = (int)$data['quantity'];

    // 椅子タイプのスナップショットを保存
    $snapshot = buildChairTypeSnapshot($chairTypeId);

    // 作業指示番号を自動生成
    $orderNo = generateOrderNo();

    $orderId = (int)dbExecute(
        "INSERT INTO manufacturing_orders
            (order_no, customer_name, project_name, order_date,
             chair_type_id, quantity, due_date, priority, memo,
             chair_type_snapshot, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $orderNo,
            $data['customer_name'] ?? null,
            $data['project_name']  ?? null,
            $data['order_date']    ?: null,
            $chairTypeId,
            $quantity,
            $data['due_date']  ?: null,
            $data['priority']  ?? 'normal',
            $data['memo']      ?? '',
            json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            $_SESSION['user_id'] ?? null,
        ]
    );

    // 工程標準時間を計算して manufacturing_order_processes に保存
    saveOrderProcessStandards($orderId, $chairTypeId, $quantity);

    auditLog('create', 'manufacturing_orders', $orderId, null, $data);

    return $orderId;
}

/**
 * 作業指示一覧を取得（フィルタ対応）
 */
function getOrderList(array $filters = []): array {
    $sql = "SELECT mo.*, ct.chair_type_code, ct.chair_type_name,
                   (SELECT COUNT(*) FROM manufacturing_order_processes mop
                    WHERE mop.manufacturing_order_id = mo.id) AS total_processes,
                   (SELECT COUNT(*) FROM manufacturing_order_processes mop
                    WHERE mop.manufacturing_order_id = mo.id AND mop.status = 'completed') AS done_processes,
                   (SELECT SUM(delay_minutes) FROM manufacturing_order_processes mop
                    WHERE mop.manufacturing_order_id = mo.id AND delay_minutes > 0) AS total_delay
            FROM manufacturing_orders mo
            JOIN chair_types ct ON mo.chair_type_id = ct.id
            WHERE 1=1";
    $params = [];

    if (!empty($filters['status'])) {
        $sql .= " AND mo.status = ?";
        $params[] = $filters['status'];
    }
    if (!empty($filters['priority'])) {
        $sql .= " AND mo.priority = ?";
        $params[] = $filters['priority'];
    }
    if (!empty($filters['due_from'])) {
        $sql .= " AND mo.due_date >= ?";
        $params[] = $filters['due_from'];
    }
    if (!empty($filters['due_to'])) {
        $sql .= " AND mo.due_date <= ?";
        $params[] = $filters['due_to'];
    }

    $sql .= " ORDER BY FIELD(mo.priority,'urgent','high','normal'), mo.due_date, mo.id";
    return dbFetchAll($sql, $params);
}

/**
 * 作業指示1件の詳細（工程進捗付き）を取得
 */
function getOrderDetail(int $orderId): array|false {
    $order = dbFetchOne(
        "SELECT mo.*, ct.chair_type_code, ct.chair_type_name
         FROM manufacturing_orders mo
         JOIN chair_types ct ON mo.chair_type_id = ct.id
         WHERE mo.id = ?",
        [$orderId]
    );
    if (!$order) return false;

    $order['processes'] = dbFetchAll(
        "SELECT mop.*, p.process_name, p.process_code
         FROM manufacturing_order_processes mop
         JOIN processes p ON mop.process_id = p.id
         WHERE mop.manufacturing_order_id = ?
         ORDER BY mop.process_sequence, p.display_order",
        [$orderId]
    );

    return $order;
}

/**
 * 作業指示のステータスを更新する
 */
function updateOrderStatus(int $orderId, string $status): void {
    $before = dbFetchOne("SELECT status FROM manufacturing_orders WHERE id = ?", [$orderId]);
    dbExecute("UPDATE manufacturing_orders SET status = ? WHERE id = ?", [$status, $orderId]);
    auditLog('update_status', 'manufacturing_orders', $orderId, $before, ['status' => $status]);
}
