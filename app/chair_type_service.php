<?php
// =====================================================
// 椅子タイプサービス
// 目的: 椅子タイプの検索・取得・スナップショット生成を担う
// 接続テーブル: chair_types, chair_type_groups,
//              chair_type_process_standards, chair_type_process_adjustments,
//              chair_type_media, chair_type_keywords
// 呼び出し元: chair_types.php, chair_type_form.php, order_form.php
// =====================================================

/**
 * 椅子タイプ一覧を取得（検索・フィルタ対応）
 * @param array $filters ['keyword'=>string, 'group_id'=>int, 'is_base'=>0|1]
 */
function getChairTypeList(array $filters = []): array {
    $sql = "SELECT ct.*, g.group_code, g.group_name,
                   base.chair_type_code AS base_code, base.chair_type_name AS base_name
            FROM chair_types ct
            JOIN chair_type_groups g ON ct.chair_type_group_id = g.id
            LEFT JOIN chair_types base ON ct.base_chair_type_id = base.id
            WHERE ct.is_active = 1 AND g.is_active = 1";
    $params = [];

    if (!empty($filters['keyword'])) {
        $kw = '%' . $filters['keyword'] . '%';
        $sql .= " AND (ct.chair_type_code LIKE ? OR ct.chair_type_name LIKE ?
                   OR ct.shape_summary LIKE ? OR ct.difference_summary LIKE ?
                   OR ct.search_text LIKE ?
                   OR EXISTS (SELECT 1 FROM chair_type_keywords k WHERE k.chair_type_id=ct.id AND k.keyword LIKE ?))";
        $params = array_merge($params, [$kw, $kw, $kw, $kw, $kw, $kw]);
    }
    if (!empty($filters['group_id'])) {
        $sql .= " AND ct.chair_type_group_id = ?";
        $params[] = $filters['group_id'];
    }
    if (isset($filters['is_base']) && $filters['is_base'] !== '') {
        $sql .= " AND ct.is_base_type = ?";
        $params[] = (int)$filters['is_base'];
    }

    $sql .= " ORDER BY g.group_code, ct.version_no";
    return dbFetchAll($sql, $params);
}

/**
 * 椅子タイプ1件を詳細情報付きで取得
 */
function getChairTypeDetail(int $id): array|false {
    $ct = dbFetchOne(
        "SELECT ct.*, g.group_code, g.group_name
         FROM chair_types ct
         JOIN chair_type_groups g ON ct.chair_type_group_id = g.id
         WHERE ct.id = ? AND ct.is_active = 1",
        [$id]
    );
    if (!$ct) return false;

    $ct['process_standards'] = dbFetchAll(
        "SELECT s.*, p.process_name, p.process_code
         FROM chair_type_process_standards s
         JOIN processes p ON s.process_id = p.id
         WHERE s.chair_type_id = ? AND s.is_active = 1
         ORDER BY s.display_order",
        [$id]
    );
    $ct['adjustments'] = dbFetchAll(
        "SELECT a.*, p.process_name
         FROM chair_type_process_adjustments a
         LEFT JOIN processes p ON a.process_id = p.id
         WHERE a.chair_type_id = ? AND a.is_active = 1",
        [$id]
    );
    $ct['media'] = dbFetchAll(
        "SELECT * FROM chair_type_media WHERE chair_type_id = ? ORDER BY display_order",
        [$id]
    );
    $ct['keywords'] = dbFetchAll(
        "SELECT keyword FROM chair_type_keywords WHERE chair_type_id = ? ORDER BY keyword",
        [$id]
    );

    return $ct;
}

/**
 * 作業指示作成時に使うスナップショットを生成する
 * 後からマスターを変更しても過去の指示の標準時間を守るために使用
 */
function buildChairTypeSnapshot(int $chairTypeId): array {
    $detail = getChairTypeDetail($chairTypeId);
    if (!$detail) return [];

    return [
        'snapshot_at'       => date('Y-m-d H:i:s'),
        'chair_type_id'     => $detail['id'],
        'chair_type_code'   => $detail['chair_type_code'],
        'chair_type_name'   => $detail['chair_type_name'],
        'base_quantity'     => $detail['base_quantity'],
        'shape_summary'     => $detail['shape_summary'],
        'difference_summary'=> $detail['difference_summary'],
        'process_standards' => $detail['process_standards'],
        'adjustments'       => $detail['adjustments'],
    ];
}

/**
 * 椅子タイプグループ一覧を取得
 */
function getChairTypeGroups(): array {
    return dbFetchAll(
        "SELECT * FROM chair_type_groups WHERE is_active = 1 ORDER BY group_code"
    );
}

/**
 * 調整タイプの日本語ラベル
 */
function adjustmentTypeLabel(string $type): string {
    return match($type) {
        'add'          => '加算',
        'subtract'     => '減算',
        'replace'      => '置換',
        'add_process'  => '工程追加',
        'remove_process' => '工程削除',
        default        => $type,
    };
}

/**
 * 適用単位の日本語ラベル
 */
function appliesPerLabel(string $per): string {
    return match($per) {
        'unit'  => '本数単位',
        'part'  => 'パーツ単位',
        'meter' => 'ｍ単位',
        default => '指示全体',
    };
}
