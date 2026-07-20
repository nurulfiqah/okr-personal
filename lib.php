<?php
/**
 * Shared OKR helpers: card query builders, formatting, and grade-based scope.
 * Included by backend.php (AJAX) and the server-rendered pages (index.php, view.php).
 * Requires $conn (mysqli) to already be set up by the includer.
 */

// Statuses settable via the Timeline card's Status field. Suspended is
// deliberately excluded — it's only ever set via the dedicated Suspend/
// Unsuspend CEO actions, which have their own restore-previous-status logic.
$OKR_TIMELINE_STATUSES = ['Draft', 'Active', 'Complete', 'Complete with Excellence', 'Extend', 'Fail'];

require_once __DIR__ . '/nas_config.php';

// Attachments only ever touch local disk transiently (uploads/tmp/) on their
// way to the NAS (CURLFile needs a real filesystem path); the permanent copy
// lives on the NAS under CORP_NAS_FOLDER, not in uploads/.
$OKR_UPLOAD_TMP_DIR  = __DIR__ . '/uploads/tmp/';
$OKR_ALLOWED_EXT     = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
$OKR_MAX_FILE_SIZE   = 10 * 1024 * 1024;

function okrCardSelectSql($where, $include_deleted = false) {
    $deleted_clause = $include_deleted ? '' : 'c.deleted_at IS NULL AND ';
    return "SELECT c.*, ow.nama_staff AS owner_name, ow.department AS owner_department,
                   ow2.nama_staff AS owner2_name, ow2.department AS owner2_department,
                   iss.nama_staff AS issuer_name, iss.department AS issuer_department,
                   lv.label AS level_label, lv.base_rm AS level_rm,
                   os.value AS status_value, ir.code AS incentive_rule_code, ir.label AS incentive_rule_label,
                   inc.nama_staff AS incentivised_owner_name,
                   lb.nama_staff AS locked_by_name, ub.nama_staff AS unlocked_by_name
            FROM okr_cards c
            LEFT JOIN staff ow  ON c.owner_staff_id  = ow.id
            LEFT JOIN staff ow2 ON c.owner2_staff_id = ow2.id
            LEFT JOIN staff iss ON c.issuer_staff_id = iss.id
            LEFT JOIN staff inc ON c.incentivised_owner_staff_id = inc.id
            LEFT JOIN staff lb  ON c.locked_by = lb.id
            LEFT JOIN staff ub  ON c.unlocked_by = ub.id
            LEFT JOIN okr_levels lv ON c.difficulty_level = lv.level
            LEFT JOIN okr_statuses os ON c.result_status = os.id
            LEFT JOIN okr_incentive_rules ir ON c.incentive_rule = ir.id
            WHERE $deleted_clause$where";
}

function okrFormatCard($row) {
    return [
        'id'                => (int)$row['id'],
        'objective'         => $row['objective'],
        'key_results'       => $row['key_results'],
        'okr_type'          => $row['okr_type'],
        'difficulty_level'  => (int)$row['difficulty_level'],
        'level_label'       => $row['level_label'],
        'level_rm'          => (float)$row['level_rm'],
        'owner_staff_id'    => (int)$row['owner_staff_id'],
        'owner_name'        => $row['owner_name'],
        'owner_department'  => $row['owner_department'],
        'owner2_staff_id'   => $row['owner2_staff_id'] !== null ? (int)$row['owner2_staff_id'] : null,
        'owner2_name'       => $row['owner2_name'],
        'owner2_department' => $row['owner2_department'],
        'owner2_purpose'    => $row['owner2_purpose'],
        'incentive_rule'         => (int)$row['incentive_rule'],
        'incentive_rule_code'    => $row['incentive_rule_code'],
        'incentive_rule_label'   => $row['incentive_rule_label'],
        'incentivised_owner_staff_id' => $row['incentivised_owner_staff_id'] !== null ? (int)$row['incentivised_owner_staff_id'] : null,
        'incentivised_owner_name'    => $row['incentivised_owner_name'],
        'issuer_staff_id'   => (int)$row['issuer_staff_id'],
        'issuer_name'       => $row['issuer_name'],
        'issuer_department' => $row['issuer_department'],
        'dept_scope'        => $row['dept_scope'],
        'start_date'        => $row['start_date'],
        'end_date'          => $row['end_date'],
        'extended'          => (bool)$row['extended'],
        'extended_date'     => $row['extended_date'],
        'remarks'           => $row['remarks'],
        // Final Due Date never mirrors the Extended Date target — it's
        // End Date until the OKR is actually resolved (closed_at set),
        // at which point it follows that closure date.
        'final_due_date'    => (!empty($row['extended']) && $row['closed_at'])
            ? substr($row['closed_at'], 0, 10)
            : $row['end_date'],
        'closure_date'      => $row['closed_at'] ? substr($row['closed_at'], 0, 10) : null,
        'result_status_id'  => (int)$row['result_status'],
        'result_status'     => $row['status_value'],
        'incentive_locked'  => (bool)$row['incentive_locked'],
        'payout_remark'     => $row['payout_remark'] ?? null,
        'locked_by_name'    => $row['locked_by_name'] ?? null,
        'locked_at'         => $row['locked_at'] ?? null,
        'unlocked_by_name'  => $row['unlocked_by_name'] ?? null,
        'unlocked_at'       => $row['unlocked_at'] ?? null,
        'closed_at'         => $row['closed_at'],
        'created_at'        => $row['created_at'],
        'deleted_at'        => $row['deleted_at'] ?? null,
    ];
}

function okrFetchTypes($conn, $include_recycled = true) {
    $where = $include_recycled ? '' : 'WHERE recycle = 0';
    $types = [];
    $result = mysqli_query($conn, "SELECT id, value, recycle FROM okr_types $where ORDER BY id ASC");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $types[] = ['id' => (int)$row['id'], 'value' => $row['value'], 'recycle' => (int)$row['recycle']];
        }
    }
    return $types;
}

function okrTypeValues($conn, $include_recycled = true) {
    return array_column(okrFetchTypes($conn, $include_recycled), 'value');
}

// Parses the Performance page's filter inputs (Year/Month/Quarter on
// start_date, Department, Closure Date range on closed_at) into a single
// $filter_sql fragment shared by staffPerformanceList/lockPayoutCards/
// unlockPayoutCards/export_performance - keeps all four call sites in sync
// instead of re-implementing the same parsing four times. Grade/struct are
// returned separately since they filter on the owner, not the card (see
// okrStaffPerformanceRows).
function okrPerformanceFilterSql($input) {
    $filter_year         = (int)($input['filter_year'] ?? 0);
    $filter_month        = (int)($input['filter_month'] ?? 0);
    $filter_quarter      = (int)($input['filter_quarter'] ?? 0);
    $filter_dept_id      = (int)($input['filter_dept_id'] ?? 0);
    $filter_grade        = (int)($input['filter_grade'] ?? 0);
    $filter_struct       = (int)($input['filter_struct'] ?? 0);
    $filter_closure_from = trim($input['filter_closure_from'] ?? '');
    $filter_closure_to   = trim($input['filter_closure_to'] ?? '');

    $filter_sql = '';
    if ($filter_year > 0)  { $filter_sql .= " AND YEAR(c.start_date) = $filter_year"; }
    if ($filter_month > 0) { $filter_sql .= " AND MONTH(c.start_date) = $filter_month"; }
    elseif ($filter_quarter > 0) { $filter_sql .= " AND QUARTER(c.start_date) = $filter_quarter"; }
    if ($filter_dept_id > 0) {
        $filter_sql .= " AND (FIND_IN_SET($filter_dept_id, c.dept_scope) OR FIND_IN_SET($filter_dept_id, iss.department))";
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_closure_from)) {
        $filter_sql .= " AND c.closed_at >= '$filter_closure_from 00:00:00'";
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_closure_to)) {
        $filter_sql .= " AND c.closed_at <= '$filter_closure_to 23:59:59'";
    }

    return ['filter_sql' => $filter_sql, 'filter_grade' => $filter_grade, 'filter_struct' => $filter_struct];
}

// Per-owner performance breakdown (mirrors ATEM's staff performance scorecard,
// computed directly since OKR has no separate aggregation service). A card
// with a 2nd owner splits its RM per incentive_rule: RULE1 (id 1) pays the
// incentivised owner 100%, everything else (RULE2) splits 50/50 - same
// branching as view.php's Incentive box. $filter_sql is appended as-is to the
// WHERE clause (same convention as backend.php's dashboardStats filters).
// $filter_grade/$filter_struct filter on the owner's own staff.grade/struct
// (not the issuer's) - applied after aggregation since a card's two owners
// can have different grades/structs, so it can't be a single card-level WHERE.
function okrStaffPerformanceRows($conn, $filter_sql, $filter_grade = 0, $filter_struct = 0) {
    $query = "SELECT c.owner_staff_id, c.owner2_staff_id, c.incentive_rule, c.incentivised_owner_staff_id,
                     c.incentive_locked, os.value AS result_status, lv.base_rm AS level_rm
              FROM okr_cards c
              LEFT JOIN okr_levels lv ON c.difficulty_level = lv.level
              LEFT JOIN okr_statuses os ON c.result_status = os.id
              LEFT JOIN staff iss ON c.issuer_staff_id = iss.id
              WHERE c.deleted_at IS NULL $filter_sql";
    $result = mysqli_query($conn, $query);

    $by_staff = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $status    = $row['result_status'];
            $rm        = (float)$row['level_rm'];
            $is_paid   = ($status === 'Complete' || $status === 'Complete with Excellence');
            $owner_id  = (int)$row['owner_staff_id'];
            $owner2_id = $row['owner2_staff_id'] !== null ? (int)$row['owner2_staff_id'] : 0;

            $shares = [];
            if ($owner2_id > 0) {
                if ((int)$row['incentive_rule'] === 1) {
                    $incentivised_id = (int)$row['incentivised_owner_staff_id'];
                    $other_id = ($incentivised_id === $owner_id) ? $owner2_id : $owner_id;
                    $shares[$incentivised_id] = $rm;
                    $shares[$other_id] = 0.0;
                } else {
                    $shares[$owner_id]  = $rm / 2;
                    $shares[$owner2_id] = $rm / 2;
                }
            } else {
                $shares[$owner_id] = $rm;
            }

            foreach ([$owner_id, $owner2_id] as $sid) {
                if ($sid <= 0) { continue; }
                if (!isset($by_staff[$sid])) {
                    $by_staff[$sid] = [
                        'staff_id' => $sid, 'name' => '', 'department' => '-', 'grade' => '-', 'struct' => '-',
                        'total' => 0, 'complete' => 0, 'excellence' => 0,
                        'active' => 0, 'extend' => 0, 'fail' => 0, 'forecast_rm' => 0.0,
                        'locked' => 0,
                    ];
                }
                $by_staff[$sid]['total']++;
                if ($status === 'Complete') { $by_staff[$sid]['complete']++; }
                if ($status === 'Complete with Excellence') { $by_staff[$sid]['excellence']++; }
                if ($status === 'Active') { $by_staff[$sid]['active']++; }
                if ($status === 'Extend') { $by_staff[$sid]['extend']++; }
                if ($status === 'Fail') { $by_staff[$sid]['fail']++; }
                if ($is_paid) { $by_staff[$sid]['forecast_rm'] += $shares[$sid] ?? 0.0; }
                if ($is_paid && (int)$row['incentive_locked'] === 1) { $by_staff[$sid]['locked']++; }
            }
        }
    }

    if (!empty($by_staff)) {
        $dept_names = [];
        $dept_res = mysqli_query($conn, 'SELECT id, depart_name FROM staff_department');
        if ($dept_res) {
            while ($d = mysqli_fetch_assoc($dept_res)) { $dept_names[(int)$d['id']] = $d['depart_name']; }
        }

        $grade_labels = [];
        $gr = mysqli_query($conn, 'SELECT id, grade_name FROM staff_grade');
        if ($gr) { while ($g = mysqli_fetch_assoc($gr)) { $grade_labels[(int)$g['id']] = $g['grade_name']; } }

        $struct_labels = [];
        $sr = mysqli_query($conn, 'SELECT id, struct_name FROM staff_struct');
        if ($sr) { while ($s = mysqli_fetch_assoc($sr)) { $struct_labels[(int)$s['id']] = $s['struct_name']; } }

        $ids = implode(',', array_keys($by_staff));
        $staff_res = mysqli_query($conn, "SELECT id, nama_staff, department, grade, struct FROM staff WHERE id IN ($ids)");
        if ($staff_res) {
            while ($srow = mysqli_fetch_assoc($staff_res)) {
                $sid = (int)$srow['id'];
                $by_staff[$sid]['name'] = $srow['nama_staff'];
                $dept_ids = okrDeptIdsFromCsv($srow['department']);
                $by_staff[$sid]['department'] = (!empty($dept_ids) && isset($dept_names[$dept_ids[0]]))
                    ? $dept_names[$dept_ids[0]] : '-';
                $by_staff[$sid]['grade'] = isset($grade_labels[(int)$srow['grade']]) ? $grade_labels[(int)$srow['grade']] : '-';
                $by_staff[$sid]['struct'] = isset($struct_labels[(int)$srow['struct']]) ? $struct_labels[(int)$srow['struct']] : '-';
                if ($filter_grade > 0 && (int)$srow['grade'] !== $filter_grade) {
                    unset($by_staff[$sid]);
                    continue;
                }
                if ($filter_struct > 0 && (int)$srow['struct'] !== $filter_struct) {
                    unset($by_staff[$sid]);
                }
            }
        }
    }

    foreach ($by_staff as &$s) {
        $payable = $s['complete'] + $s['excellence'];
        $s['is_locked'] = ($payable > 0 && $s['locked'] === $payable);
        $s['complete_total'] = $payable;
    }
    unset($s);

    usort($by_staff, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    return array_values($by_staff);
}

// Detailed export rows: one row per (staff, card) pair - a card with two
// owners produces two rows, one per role - mirroring ATEM's bulk export
// (one row per ARCI role per ATEM). Pass $staff_id to restrict to a single
// staff's rows (for the per-staff export), or $staff_id_list for a
// multi-staff "Export Selected"; leave both empty for the full bulk export.
function okrExportRows($conn, $filter_sql, $staff_id = 0, $staff_id_list = [], $filter_grade = 0, $filter_struct = 0) {
    if ($staff_id > 0) {
        $staff_condition = " AND (c.owner_staff_id = $staff_id OR c.owner2_staff_id = $staff_id)";
    } elseif (!empty($staff_id_list)) {
        $csv = implode(',', array_map('intval', $staff_id_list));
        $staff_condition = " AND (c.owner_staff_id IN ($csv) OR c.owner2_staff_id IN ($csv))";
    } else {
        $staff_condition = '';
    }
    $query = "SELECT c.id, c.objective, c.okr_type, lv.label AS level_label, lv.base_rm AS level_rm,
                     c.start_date, c.end_date, os.value AS result_status,
                     c.owner_staff_id, c.owner2_staff_id, c.incentive_rule, c.incentivised_owner_staff_id,
                     iss.nama_staff AS issuer_name, ow2.nama_staff AS owner2_name, ir.label AS incentive_rule_label
              FROM okr_cards c
              LEFT JOIN okr_levels lv ON c.difficulty_level = lv.level
              LEFT JOIN okr_statuses os ON c.result_status = os.id
              LEFT JOIN staff iss ON c.issuer_staff_id = iss.id
              LEFT JOIN staff ow2 ON c.owner2_staff_id = ow2.id
              LEFT JOIN okr_incentive_rules ir ON c.incentive_rule = ir.id
              WHERE c.deleted_at IS NULL $filter_sql$staff_condition
              ORDER BY c.start_date DESC";
    $result = mysqli_query($conn, $query);

    $entries = [];
    $involved_staff_ids = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $status    = $row['result_status'];
            $rm        = (float)$row['level_rm'];
            $is_paid   = ($status === 'Complete' || $status === 'Complete with Excellence');
            $owner_id  = (int)$row['owner_staff_id'];
            $owner2_id = $row['owner2_staff_id'] !== null ? (int)$row['owner2_staff_id'] : 0;

            $shares = [];
            if ($owner2_id > 0) {
                if ((int)$row['incentive_rule'] === 1) {
                    $incentivised_id = (int)$row['incentivised_owner_staff_id'];
                    $other_id = ($incentivised_id === $owner_id) ? $owner2_id : $owner_id;
                    $shares[$incentivised_id] = $rm;
                    $shares[$other_id] = 0.0;
                } else {
                    $shares[$owner_id]  = $rm / 2;
                    $shares[$owner2_id] = $rm / 2;
                }
            } else {
                $shares[$owner_id] = $rm;
            }

            $participants = [[$owner_id, 'Owner']];
            if ($owner2_id > 0) { $participants[] = [$owner2_id, '2nd Owner']; }

            foreach ($participants as $p) {
                $sid = $p[0];
                $role = $p[1];
                if ($sid <= 0) { continue; }
                if ($staff_id > 0 && $sid !== $staff_id) { continue; }
                if (!empty($staff_id_list) && !in_array($sid, $staff_id_list, true)) { continue; }
                $involved_staff_ids[$sid] = true;
                $entries[] = [
                    'staff_id'    => $sid,
                    'year'        => date('Y', strtotime($row['start_date'])),
                    'month'       => date('F', strtotime($row['start_date'])),
                    'okr_id'      => 'OKR' . $row['id'],
                    'title'       => $row['objective'],
                    'okr_type'    => $row['okr_type'],
                    'level'       => $row['level_label'],
                    'start_date'  => $row['start_date'],
                    'end_date'    => $row['end_date'],
                    'issuer'      => $row['issuer_name'],
                    'owner2_name' => $owner2_id > 0 ? $row['owner2_name'] : '',
                    'role'        => $role,
                    'status'      => $status,
                    'incentive_rule_label' => $owner2_id > 0 ? $row['incentive_rule_label'] : '',
                    'reward'      => $is_paid ? ($shares[$sid] ?? 0.0) : 0.0,
                ];
            }
        }
    }

    $staff_info = [];
    if (!empty($involved_staff_ids)) {
        $dept_names = [];
        $dr = mysqli_query($conn, 'SELECT id, depart_name FROM staff_department');
        if ($dr) { while ($d = mysqli_fetch_assoc($dr)) { $dept_names[(int)$d['id']] = $d['depart_name']; } }

        $grade_labels = [];
        $gr = mysqli_query($conn, 'SELECT id, grade_name FROM staff_grade');
        if ($gr) { while ($g = mysqli_fetch_assoc($gr)) { $grade_labels[(int)$g['id']] = $g['grade_name']; } }

        $struct_labels = [];
        $sr = mysqli_query($conn, 'SELECT id, struct_name FROM staff_struct');
        if ($sr) { while ($s = mysqli_fetch_assoc($sr)) { $struct_labels[(int)$s['id']] = $s['struct_name']; } }

        $ids = implode(',', array_keys($involved_staff_ids));
        $staff_res = mysqli_query($conn, "SELECT id, nama_staff, department, grade, struct FROM staff WHERE id IN ($ids)");
        if ($staff_res) {
            while ($srow = mysqli_fetch_assoc($staff_res)) {
                $sid = (int)$srow['id'];
                $dept_ids = okrDeptIdsFromCsv($srow['department']);
                $staff_info[$sid] = [
                    'name'       => $srow['nama_staff'],
                    'department' => (!empty($dept_ids) && isset($dept_names[$dept_ids[0]])) ? $dept_names[$dept_ids[0]] : '-',
                    'grade'      => isset($grade_labels[(int)$srow['grade']]) ? $grade_labels[(int)$srow['grade']] : '-',
                    'struct'     => isset($struct_labels[(int)$srow['struct']]) ? $struct_labels[(int)$srow['struct']] : '-',
                    'grade_id'   => (int)$srow['grade'],
                    'struct_id'  => (int)$srow['struct'],
                ];
            }
        }
    }

    foreach ($entries as &$e) {
        $info = isset($staff_info[$e['staff_id']]) ? $staff_info[$e['staff_id']] : ['name' => '-', 'department' => '-', 'grade' => '-', 'struct' => '-', 'grade_id' => 0, 'struct_id' => 0];
        $e['name']       = $info['name'];
        $e['department'] = $info['department'];
        $e['grade']      = $info['grade'];
        $e['struct']     = $info['struct'];
        $e['grade_id']   = $info['grade_id'];
        $e['struct_id']  = $info['struct_id'];
    }
    unset($e);

    if ($filter_grade > 0) {
        $entries = array_filter($entries, function ($e) use ($filter_grade) { return $e['grade_id'] === $filter_grade; });
    }
    if ($filter_struct > 0) {
        $entries = array_filter($entries, function ($e) use ($filter_struct) { return $e['struct_id'] === $filter_struct; });
    }
    $entries = array_values($entries);

    usort($entries, function ($a, $b) {
        $cmp = strcasecmp($a['name'], $b['name']);
        return $cmp !== 0 ? $cmp : strcmp($b['start_date'], $a['start_date']);
    });
    return $entries;
}

// Locks (incentive_locked = 1) every not-yet-locked Complete/Complete with
// Excellence card matching $filter_sql (+ optional single $staff_id, or a
// $staff_id_list for "Lock Selected"), stamping locked_by/locked_at/
// payout_remark (mirrors ATEM's payout audit fields on atems) and logging one
// audit entry per card. Shared by backend.php's lockPayoutCards action and
// export_performance.php (People Management's export auto-locks the same set
// it just downloaded). Returns the count of cards locked.
function okrLockPayoutCards($conn, $actor_id, $filter_sql, $staff_id = 0, $staff_id_list = [], $remark = '', $filter_grade = 0, $filter_struct = 0) {
    if ($staff_id > 0) {
        $filter_sql .= " AND (c.owner_staff_id = $staff_id OR c.owner2_staff_id = $staff_id)";
    } elseif (!empty($staff_id_list)) {
        $csv = implode(',', array_map('intval', $staff_id_list));
        $filter_sql .= " AND (c.owner_staff_id IN ($csv) OR c.owner2_staff_id IN ($csv))";
    }
    // Grade/struct describe an owner, not the card, so match if either owner
    // qualifies (a 2-owner card can have owners in different grades/structs).
    if ($filter_grade > 0) {
        $filter_sql .= " AND EXISTS (SELECT 1 FROM staff s WHERE s.id IN (c.owner_staff_id, c.owner2_staff_id) AND s.grade = $filter_grade)";
    }
    if ($filter_struct > 0) {
        $filter_sql .= " AND EXISTS (SELECT 1 FROM staff s WHERE s.id IN (c.owner_staff_id, c.owner2_staff_id) AND s.struct = $filter_struct)";
    }

    $complete_id   = okrStatusIdByValue($conn, 'Complete');
    $excellence_id = okrStatusIdByValue($conn, 'Complete with Excellence');

    $select_sql = "SELECT c.id FROM okr_cards c
                   LEFT JOIN staff iss ON c.issuer_staff_id = iss.id
                   WHERE c.deleted_at IS NULL AND c.incentive_locked = 0
                     AND c.result_status IN ($complete_id, $excellence_id)
                     $filter_sql";
    $ids_result = mysqli_query($conn, $select_sql);
    $ids = [];
    if ($ids_result) {
        while ($r = mysqli_fetch_assoc($ids_result)) { $ids[] = (int)$r['id']; }
    }

    if (!empty($ids)) {
        $ids_csv = implode(',', $ids);
        $remark_sql = "'" . mysqli_real_escape_string($conn, $remark) . "'";
        mysqli_query($conn, "UPDATE okr_cards
            SET incentive_locked = 1, locked_by = " . (int)$actor_id . ", locked_at = NOW(), payout_remark = $remark_sql
            WHERE id IN ($ids_csv)");
        foreach ($ids as $cid) {
            okrLogAudit($conn, $cid, $actor_id, 'incentive_locked', null, 'Incentive locked for payout by People Management.');
        }
    }

    return count($ids);
}

// Reverses okrLockPayoutCards: unlocks every currently-locked card matching
// $filter_sql (+ optional single $staff_id, or $staff_id_list for
// "Unlock Selected"), stamping unlocked_by/unlocked_at and logging one audit
// entry per card. Returns the count of cards unlocked.
function okrUnlockPayoutCards($conn, $actor_id, $filter_sql, $staff_id = 0, $staff_id_list = [], $filter_grade = 0, $filter_struct = 0) {
    if ($staff_id > 0) {
        $filter_sql .= " AND (c.owner_staff_id = $staff_id OR c.owner2_staff_id = $staff_id)";
    } elseif (!empty($staff_id_list)) {
        $csv = implode(',', array_map('intval', $staff_id_list));
        $filter_sql .= " AND (c.owner_staff_id IN ($csv) OR c.owner2_staff_id IN ($csv))";
    }
    if ($filter_grade > 0) {
        $filter_sql .= " AND EXISTS (SELECT 1 FROM staff s WHERE s.id IN (c.owner_staff_id, c.owner2_staff_id) AND s.grade = $filter_grade)";
    }
    if ($filter_struct > 0) {
        $filter_sql .= " AND EXISTS (SELECT 1 FROM staff s WHERE s.id IN (c.owner_staff_id, c.owner2_staff_id) AND s.struct = $filter_struct)";
    }

    $select_sql = "SELECT c.id FROM okr_cards c
                   LEFT JOIN staff iss ON c.issuer_staff_id = iss.id
                   WHERE c.deleted_at IS NULL AND c.incentive_locked = 1
                     $filter_sql";
    $ids_result = mysqli_query($conn, $select_sql);
    $ids = [];
    if ($ids_result) {
        while ($r = mysqli_fetch_assoc($ids_result)) { $ids[] = (int)$r['id']; }
    }

    if (!empty($ids)) {
        $ids_csv = implode(',', $ids);
        mysqli_query($conn, "UPDATE okr_cards
            SET incentive_locked = 0, unlocked_by = " . (int)$actor_id . ", unlocked_at = NOW()
            WHERE id IN ($ids_csv)");
        foreach ($ids as $cid) {
            okrLogAudit($conn, $cid, $actor_id, 'incentive_unlocked', null, 'Incentive unlocked by People Management.');
        }
    }

    return count($ids);
}

function okrStatusIdByValue($conn, $value) {
    $value_e = mysqli_real_escape_string($conn, $value);
    $result = mysqli_query($conn, "SELECT id FROM okr_statuses WHERE value = '$value_e'");
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        return (int)$row['id'];
    }
    return 0;
}

function okrFetchReferenceLinks($conn, $card_id) {
    $links = [];
    $result = mysqli_query($conn, "SELECT id, name, url FROM okr_reference_links
                                    WHERE card_id = " . (int)$card_id . " ORDER BY created_at ASC");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $links[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'url' => $row['url']];
        }
    }
    return $links;
}

function okrCountReferenceLinks($conn, $card_id) {
    $result = mysqli_query($conn, "SELECT COUNT(*) AS n FROM okr_reference_links WHERE card_id = " . (int)$card_id);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? (int)$row['n'] : 0;
}

// Stages a reference link in the session so it can be linked once createCard
// succeeds (mirrors the attachment staging flow below, for the create form).
function okrStageReferenceLink($name, $url) {
    $token = uniqid('reflink_', true);
    $_SESSION['okr_draft_reflinks'] ??= [];
    $_SESSION['okr_draft_reflinks'][$token] = ['name' => $name, 'url' => $url];
    return $token;
}

function okrRemoveStagedReferenceLink($token) {
    if (!isset($_SESSION['okr_draft_reflinks'][$token])) {
        return false;
    }
    unset($_SESSION['okr_draft_reflinks'][$token]);
    return true;
}

function okrFinalizeStagedReferenceLinks($conn, $card_id, $added_by) {
    if (empty($_SESSION['okr_draft_reflinks'])) {
        return;
    }
    foreach ($_SESSION['okr_draft_reflinks'] as $link) {
        $name_e = mysqli_real_escape_string($conn, $link['name']);
        $url_e  = mysqli_real_escape_string($conn, $link['url']);
        mysqli_query($conn, "INSERT INTO okr_reference_links (card_id, name, url, added_by)
                              VALUES ($card_id, '$name_e', '$url_e', $added_by)");
    }
    $_SESSION['okr_draft_reflinks'] = [];
}

// Writes one immutable audit trail row (mirrors ATEM's atem_audit_logs).
// $changes is an associative array of field => [old, new], or null.
function okrLogAudit($conn, $card_id, $actor_id, $event, $changes, $summary) {
    $event_e   = mysqli_real_escape_string($conn, $event);
    $summary_e = mysqli_real_escape_string($conn, (string)$summary);
    $changes_sql = 'NULL';
    if ($changes !== null) {
        $changes_sql = "'" . mysqli_real_escape_string($conn, json_encode($changes)) . "'";
    }
    mysqli_query($conn, "INSERT INTO okr_audit_logs (card_id, event, actor_staff_id, changes, summary)
                          VALUES (" . (int)$card_id . ", '$event_e', " . (int)$actor_id . ", $changes_sql, '$summary_e')");
}

function okrFetchAuditLogs($conn, $card_id) {
    $logs = [];
    $result = mysqli_query($conn, "SELECT a.event, a.summary, a.created_at, s.nama_staff AS actor_name
                                    FROM okr_audit_logs a
                                    LEFT JOIN staff s ON a.actor_staff_id = s.id
                                    WHERE a.card_id = " . (int)$card_id . "
                                    ORDER BY a.created_at DESC");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $logs[] = [
                'event'      => $row['event'],
                'summary'    => $row['summary'],
                'actor_name' => $row['actor_name'],
                'created_at' => $row['created_at'],
            ];
        }
    }
    return $logs;
}

function okrFetchAttachments($conn, $card_id) {
    $attachments = [];
    $result = mysqli_query($conn, "SELECT id, original_name, size, mime_type, created_at
                                    FROM okr_card_attachments WHERE card_id = " . (int)$card_id . "
                                    ORDER BY created_at ASC");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $attachments[] = [
                'id'            => (int)$row['id'],
                'original_name' => $row['original_name'],
                'size'          => (int)$row['size'],
                'mime_type'     => $row['mime_type'],
                'created_at'    => $row['created_at'],
            ];
        }
    }
    return $attachments;
}

// Validates an $_FILES[...] entry against the allowed extensions/size.
// Returns an error message string, or null if the file is acceptable.
function okrValidateUpload($file) {
    global $OKR_ALLOWED_EXT, $OKR_MAX_FILE_SIZE;

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return 'Upload failed.';
    }
    if ($file['size'] > $OKR_MAX_FILE_SIZE) {
        return 'File exceeds the 10MB limit.';
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $OKR_ALLOWED_EXT, true)) {
        return 'File type not allowed.';
    }
    return null;
}

// Stages an uploaded file in uploads/tmp and records it in the session so it
// can later be attached to a card once the card itself is saved (mirrors
// ATEM's session-draft attachment flow for the create form).
function okrStageAttachment($file) {
    global $OKR_UPLOAD_TMP_DIR;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $token = uniqid('okr_', true);
    $stored_name = $token . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $OKR_UPLOAD_TMP_DIR . $stored_name)) {
        return null;
    }

    $_SESSION['okr_draft_files'] ??= [];
    $_SESSION['okr_draft_files'][$token] = [
        'stored_name'   => $stored_name,
        'original_name' => $file['name'],
        'size'          => (int)$file['size'],
        'mime_type'     => $file['type'] ?? null,
    ];

    return $token;
}

function okrRemoveStagedAttachment($token) {
    global $OKR_UPLOAD_TMP_DIR;

    if (!isset($_SESSION['okr_draft_files'][$token])) {
        return false;
    }
    $path = $OKR_UPLOAD_TMP_DIR . $_SESSION['okr_draft_files'][$token]['stored_name'];
    if (is_file($path)) {
        unlink($path);
    }
    unset($_SESSION['okr_draft_files'][$token]);
    return true;
}

// Fully discards an in-progress create-form draft: removes any staged
// attachment tmp files, clears staged reference links, and clears the
// autosaved form-field state (okr_draft_state). Used by the clearDraftState
// action and after a successful createCard, mirrors ATEM's draft-clear.
function okrClearDraftSession() {
    if (!empty($_SESSION['okr_draft_files'])) {
        foreach (array_keys($_SESSION['okr_draft_files']) as $token) {
            okrRemoveStagedAttachment($token);
        }
    }
    $_SESSION['okr_draft_reflinks'] = [];
    unset($_SESSION['okr_draft_state']);
}

// Uploads every attachment staged in the session to the NAS and links it to
// the newly-created card. Called right after createCard succeeds.
function okrFinalizeStagedAttachments($conn, $card_id, $uploaded_by) {
    global $OKR_UPLOAD_TMP_DIR;

    if (empty($_SESSION['okr_draft_files'])) {
        return;
    }

    $nas = corpNasConnect();
    foreach ($_SESSION['okr_draft_files'] as $file) {
        $tmp_path = $OKR_UPLOAD_TMP_DIR . $file['stored_name'];
        if (!is_file($tmp_path)) {
            continue;
        }
        $nas_path = $nas->upload($tmp_path, CORP_NAS_FOLDER, $file['stored_name']);
        unlink($tmp_path);
        if ($nas_path === false) {
            continue;
        }
        $original_name_e = mysqli_real_escape_string($conn, $file['original_name']);
        $stored_name_e    = mysqli_real_escape_string($conn, $nas_path);
        $mime_type_e      = mysqli_real_escape_string($conn, (string)$file['mime_type']);
        mysqli_query($conn, "INSERT INTO okr_card_attachments
            (card_id, original_name, stored_name, size, mime_type, uploaded_by)
            VALUES ($card_id, '$original_name_e', '$stored_name_e', " . (int)$file['size'] . ", '$mime_type_e', $uploaded_by)");
    }

    $_SESSION['okr_draft_files'] = [];
}

// Admin toggle (okr_config.backdate_enabled) - when false, date pickers
// across the module reject past dates; when true, backdating is allowed.
function okrBackdateEnabled($conn) {
    $result = mysqli_query($conn, "SELECT setting_value FROM okr_config WHERE setting_key = 'backdate_enabled'");
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        return $row['setting_value'] === '1';
    }
    return false;
}

function okrDeptIdsFromCsv($csv) {
    $ids = [];
    foreach (explode(',', (string)$csv) as $_d) {
        $_d = (int)trim($_d);
        if ($_d > 0) {
            $ids[] = $_d;
        }
    }
    return $ids;
}

// Grade-based visibility scope, mirrors framework doc section 3:
// grade 1-2 see own (owned) cards only, grade 3 sees own department's cards
// (plus anything they issued), grade 4-5 see the whole company.
function okrScopeWhere($requester_id, $requester_grade, $requester_dept_ids, $is_admin = false) {
    if ($is_admin || $requester_grade >= 4) {
        return '1=1';
    }
    if ($requester_grade === 3) {
        if (empty($requester_dept_ids)) {
            return "c.issuer_staff_id = $requester_id";
        }
        $conds = [];
        foreach ($requester_dept_ids as $_did) {
            $conds[] = "FIND_IN_SET($_did, c.dept_scope)";
        }
        return '(' . implode(' OR ', $conds) . ") OR c.issuer_staff_id = $requester_id";
    }
    return "(c.owner_staff_id = $requester_id OR c.owner2_staff_id = $requester_id)";
}

// Once an OKR has gone through an extension, Complete/Fail read as
// "Completed with extension"/"Failed" everywhere, to make the extension
// visible in the outcome rather than just in the Extended? checkbox.
function okrStatusDisplayLabel($status, $extended) {
    if (!$extended) {
        return $status;
    }
    if ($status === 'Complete') {
        return 'Completed with extension';
    }
    if ($status === 'Fail') {
        return 'Failed';
    }
    return $status;
}

function okrPillClass($status) {
    $map = [
        'Draft'                    => 'okr-pill-draft',
        'Active'                   => 'okr-pill-active',
        'Complete'                 => 'okr-pill-complete',
        'Complete with Excellence' => 'okr-pill-complete-excellence',
        'Extend'                   => 'okr-pill-extend',
        'Suspended'                => 'okr-pill-suspended',
        'Fail'                     => 'okr-pill-fail',
    ];
    return isset($map[$status]) ? $map[$status] : 'okr-pill-active';
}

// Incentive tile color follows the OKR's lifecycle stage, not just paid/unpaid:
// Draft/Active = still in progress (blue), Extend = pending closure (yellow),
// Fail/Suspended = won't pay out (red), Complete(+Excellence) = paid (green, default).
function okrIncentiveTileClass($status) {
    $map = [
        'Draft'     => 'okr-incentive-tile--blue',
        'Active'    => 'okr-incentive-tile--blue',
        'Extend'    => 'okr-incentive-tile--yellow',
        'Fail'      => 'okr-incentive-tile--red',
        'Suspended' => 'okr-incentive-tile--red',
    ];
    return isset($map[$status]) ? $map[$status] : '';
}
