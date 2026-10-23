<?php
// /finance/expense.php - manual biaya (docs §11.2). Owner (own outlets + 'pusat') or Mitra (own outlet).
//   GET    ?start_date&end_date   -> [{ expense_id, company_id, company_name, category, amount, expense_date, note, recurring_id }]
//   POST   { company_id, category, amount, expense_date, note?, repeat_monthly? }
//   PUT    { expense_id, company_id?, category, amount, expense_date, note, scope: "this"|"forward" }
//   DELETE ?expense_id&scope=this|forward
// A recurring biaya is a rule (finance_recurring) that is turned into one row per month on read.
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once 'lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

function expenseDate($v, string $f = 'expense_date'): string {
    $d = DateTime::createFromFormat('Y-m-d', (string)$v);
    if (!$d || $d->format('Y-m-d') !== $v) jsonResponse(400, "$f harus berformat YYYY-MM-DD");
    return $v;
}

function expenseFields(array $in): array {
    $category = trim((string)($in['category'] ?? ''));
    if (!in_array($category, FINANCE_CATEGORIES, true)) jsonResponse(400, 'category harus salah satu: ' . implode(', ', FINANCE_CATEGORIES));
    if (!isset($in['amount']) || !is_numeric($in['amount']) || (int)$in['amount'] <= 0) jsonResponse(400, 'amount harus lebih dari 0');
    $note = trim((string)($in['note'] ?? ''));
    return [$category, (int)$in['amount'], expenseDate($in['expense_date'] ?? null), $note === '' ? null : mb_substr($note, 0, 500)];
}

function expenseFind(mysqli $conn, string $schema, object $decoded, string $id): array {
    $stmt = $conn->prepare("SELECT * FROM {$schema}.finance_expense WHERE expense_id = ?");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) jsonResponse(404, 'Biaya tidak ditemukan');
    financeAssertScope($conn, $decoded, $row['company_id']);
    return $row;
}

function expenseStopRule(mysqli $conn, string $schema, string $recurringId, string $fromPeriod): void {
    // The rule now ends the month before $fromPeriod; its generated rows from $fromPeriod on are dropped.
    $prev = (new DateTime($fromPeriod . '-01'))->modify('-1 month')->format('Y-m');
    $stmt = $conn->prepare("UPDATE {$schema}.finance_recurring SET end_period = ? WHERE recurring_id = ?");
    $stmt->bind_param('ss', $prev, $recurringId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM {$schema}.finance_expense WHERE recurring_id = ? AND period >= ?");
    $stmt->bind_param('ss', $recurringId, $fromPeriod);
    $stmt->execute();
    $stmt->close();
}

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    $schema = DB_SCHEMA;
    $core = MOVIRA_CORE_SCHEMA;
    requireManager($decoded);
    $by = $decoded->username;

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            $start = expenseDate($_GET['start_date'] ?? date('Y-m-01'), 'start_date');
            $end = expenseDate($_GET['end_date'] ?? date('Y-m-d'), 'end_date');
            $scopes = financeScopes($conn, $decoded);
            financeMaterialize($conn, $schema, $scopes, financePeriod($start), financePeriod($end));
            [$in, $types] = accessInList($scopes);
            $stmt = $conn->prepare("SELECT E.expense_id, E.company_id, COALESCE(C.company_name, 'Pusat') AS company_name, E.category, E.amount, E.expense_date, E.note, E.recurring_id
                FROM {$schema}.finance_expense E LEFT JOIN {$core}.app_company C ON C.company_id = E.company_id
                WHERE E.company_id IN ($in) AND E.expense_date BETWEEN ? AND ? ORDER BY E.expense_date DESC, E.created_at DESC");
            $params = array_merge($scopes, [$start, $end]);
            $stmt->bind_param($types . 'ss', ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($rows as &$r) $r['amount'] = (int)$r['amount'];
            unset($r);
            jsonResponse(200, 'Expenses fetched', $rows);
            break;

        case 'POST':
            $in = json_decode(file_get_contents('php://input'), true);
            if (!is_array($in)) jsonResponse(400, 'Invalid JSON body');
            $companyId = financeAssertScope($conn, $decoded, $in['company_id'] ?? null);
            [$category, $amount, $date, $note] = expenseFields($in);
            $id = 'exp' . uniqid();
            $recurringId = null;
            $period = null;

            $conn->begin_transaction();
            try {
                if (filter_var($in['repeat_monthly'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $recurringId = 'rec' . uniqid();
                    $period = financePeriod($date);
                    $day = (int)substr($date, 8, 2);
                    $stmt = $conn->prepare("INSERT INTO {$schema}.finance_recurring (recurring_id, company_id, category, amount, day_of_month, note, start_period, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('sssiisss', $recurringId, $companyId, $category, $amount, $day, $note, $period, $by);
                    $stmt->execute();
                    $stmt->close();
                }
                $stmt = $conn->prepare("INSERT INTO {$schema}.finance_expense (expense_id, company_id, category, amount, expense_date, note, recurring_id, period, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssisssss', $id, $companyId, $category, $amount, $date, $note, $recurringId, $period, $by);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            jsonResponse(201, 'Biaya disimpan', ['expense_id' => $id, 'recurring_id' => $recurringId]);
            break;

        case 'PUT':
            $in = json_decode(file_get_contents('php://input'), true);
            if (!is_array($in)) jsonResponse(400, 'Invalid JSON body');
            $row = expenseFind($conn, $schema, $decoded, (string)($in['expense_id'] ?? ''));
            [$category, $amount, $date, $note] = expenseFields($in);
            $scope = ($in['scope'] ?? 'this') === 'forward' ? 'forward' : 'this';

            $conn->begin_transaction();
            try {
                $recurringId = $row['recurring_id'];
                $period = $row['period'];
                if ($scope === 'forward' && $recurringId !== null) {
                    // A price change from this month on: close the old rule the month before, start a new one here.
                    expenseStopRule($conn, $schema, $recurringId, $period);
                    $newRule = 'rec' . uniqid();
                    $day = (int)substr($date, 8, 2);
                    $stmt = $conn->prepare("INSERT INTO {$schema}.finance_recurring (recurring_id, company_id, category, amount, day_of_month, note, start_period, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('sssiisss', $newRule, $row['company_id'], $category, $amount, $day, $note, $period, $by);
                    $stmt->execute();
                    $stmt->close();
                    $recurringId = $newRule;
                    // stopRule dropped this month's row too; recreate it under the new rule.
                    $stmt = $conn->prepare("INSERT INTO {$schema}.finance_expense (expense_id, company_id, category, amount, expense_date, note, recurring_id, period, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('sssisssss', $row['expense_id'], $row['company_id'], $category, $amount, $date, $note, $recurringId, $period, $by);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("UPDATE {$schema}.finance_expense SET category = ?, amount = ?, expense_date = ?, note = ? WHERE expense_id = ?");
                    $stmt->bind_param('sisss', $category, $amount, $date, $note, $row['expense_id']);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            jsonResponse(200, 'Biaya diperbarui');
            break;

        case 'DELETE':
            $row = expenseFind($conn, $schema, $decoded, (string)($_GET['expense_id'] ?? ''));
            $scope = ($_GET['scope'] ?? 'this') === 'forward' ? 'forward' : 'this';
            $conn->begin_transaction();
            try {
                if ($row['recurring_id'] !== null && $scope === 'forward') {
                    expenseStopRule($conn, $schema, $row['recurring_id'], $row['period']);
                } else {
                    if ($row['recurring_id'] !== null) {
                        $stmt = $conn->prepare("INSERT IGNORE INTO {$schema}.finance_recurring_skip (recurring_id, period) VALUES (?, ?)");
                        $stmt->bind_param('ss', $row['recurring_id'], $row['period']);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $stmt = $conn->prepare("DELETE FROM {$schema}.finance_expense WHERE expense_id = ?");
                    $stmt->bind_param('s', $row['expense_id']);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            jsonResponse(200, 'Biaya dihapus');
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Throwable $e) {
    logApiError(DB::conn(), ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/finance/expense.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
