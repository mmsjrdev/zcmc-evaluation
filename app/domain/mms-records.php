<?php
/**
 * app/domain/mms-records.php
 *
 * Step 11: everything the MMS record pages need from the database.
 *
 *   mms_records_filters($_GET)       clean the search / filter values
 *   mms_records_count / _fetch / _statement   the list (also used by the CSV export)
 *   mms_status_counts(), mms_waiting_to_receive()   the MMS dashboard
 *   mms_record_extra(), mms_record_history()        the record page
 *   mms_mark_received()              completed -> received (through workflow.php)
 *
 * The list never selects evaluator content (no Findings, Sections B or C) and
 * never selects signature images.
 *
 * Forms that are still a supplier_draft are left out everywhere: the supplier
 * has not submitted them yet.
 */

require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/supplier-conforme.php';   // conforme_load_form(), conforme_form_title()

/** How many forms one page of the list shows. */
const MMS_RECORDS_PAGE_SIZE = 20;

/** A form with no status change for this many days counts as "stuck". */
const MMS_STUCK_DAYS = 7;

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

/** Returns 'YYYY-MM-DD' if $value is a real date, otherwise ''. */
function mms_clean_date(string $value): string
{
    $date = DateTime::createFromFormat('Y-m-d', trim($value));
    return ($date && $date->format('Y-m-d') === trim($value)) ? trim($value) : '';
}

/**
 * Turns the raw query string ($_GET) into safe, complete filter values.
 * Anything unknown or invalid becomes an empty value.
 */
function mms_records_filters(array $src): array
{
    $q = trim((string) preg_replace('/\s+/u', ' ', (string) ($src['q'] ?? '')));
    $q = mb_substr($q, 0, 100);

    $status = (string) ($src['status'] ?? '');
    if ($status === 'supplier_draft' || !isset(status_definitions()[$status])) {
        $status = '';
    }

    $from = mms_clean_date((string) ($src['from'] ?? ''));
    $to   = mms_clean_date((string) ($src['to'] ?? ''));
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];   // dates typed in the wrong order
    }

    return [
        'q'          => $q,
        'status'     => $status,
        'from'       => $from,
        'to'         => $to,
        'department' => max(0, (int) ($src['department'] ?? 0)),
        'mode'       => max(0, (int) ($src['mode'] ?? 0)),
        'stuck'      => ((string) ($src['stuck'] ?? '') === '1') ? 1 : 0,
        'page'       => max(1, (int) ($src['page'] ?? 1)),
    ];
}

/** The FROM part shared by every list query. */
function mms_records_from_sql(): string
{
    return ' FROM evaluations e
             LEFT JOIN users s             ON s.id = e.supplier_id
             LEFT JOIN departments d       ON d.id = e.department_id
             LEFT JOIN procurement_modes m ON m.id = e.mode_id
             LEFT JOIN (SELECT evaluation_id, MAX(created_at) AS last_at
                          FROM status_history
                         GROUP BY evaluation_id) h ON h.evaluation_id = e.id ';
}

/**
 * The WHERE part and its values for a set of filters.
 * Returns [ sql, params ]. Every value goes through a prepared-statement
 * placeholder; the % and _ typed by the user are escaped so they are plain text.
 */
function mms_records_where(array $f): array
{
    $where  = ["e.status <> 'supplier_draft'"];
    $params = [];

    if ($f['q'] !== '') {
        // Escape \ first, then % and _, so they are searched as normal characters.
        $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']);
        $like = '%' . $safe . '%';
        $where[] = '(e.ref_no LIKE :q1 OR e.bid_ref_no LIKE :q2 OR e.bidder LIKE :q3
                     OR s.full_name LIKE :q4 OR s.business_name LIKE :q5 OR d.name LIKE :q6)';
        for ($i = 1; $i <= 6; $i++) {
            $params[':q' . $i] = $like;
        }
    }

    if ($f['status'] !== '') {
        $where[]            = 'e.status = :status';
        $params[':status']  = $f['status'];
    }

    // The date range is on the day the supplier submitted the form.
    if ($f['from'] !== '') {
        $where[]          = 'e.submitted_at >= :from_date';
        $params[':from_date'] = $f['from'] . ' 00:00:00';
    }
    if ($f['to'] !== '') {
        $where[]          = 'e.submitted_at < :to_date';
        $params[':to_date'] = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00';
    }

    if ($f['department'] > 0) {
        $where[]           = 'e.department_id = :dept';
        $params[':dept']   = $f['department'];
    }
    if ($f['mode'] > 0) {
        $where[]           = 'e.mode_id = :mode';
        $params[':mode']   = $f['mode'];
    }

    // Stuck: still moving through the flow, and the last status change (or, if
    // there is none, the last update of the row) is older than MMS_STUCK_DAYS.
    if ($f['stuck'] === 1) {
        $where[] = "e.status NOT IN ('completed', 'received', 'cancelled')
                    AND COALESCE(h.last_at, e.updated_at) < DATE_SUB(NOW(), INTERVAL " . (int) MMS_STUCK_DAYS . ' DAY)';
    }

    return [' WHERE ' . implode(' AND ', $where), $params];
}

/** The columns of the list: summary data only. */
function mms_records_columns(): string
{
    return 'e.id, e.ref_no, e.bid_ref_no, e.bidder, e.status, e.version,
            e.opening_date, e.eval_date, e.submitted_at,
            s.full_name AS supplier_name, s.business_name AS supplier_business,
            d.name AS department_name, m.name AS mode_name,
            COALESCE(h.last_at, e.updated_at) AS last_change_at';
}

/** How many forms match the filters (all pages). */
function mms_records_count(array $f): int
{
    [$where, $params] = mms_records_where($f);
    $stmt = db()->prepare('SELECT COUNT(*)' . mms_records_from_sql() . $where);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * One page of forms. $oldestFirst = true sorts by the oldest last change first
 * (the dashboard uses it for "waiting to be received").
 */
function mms_records_fetch(array $f, int $limit, int $offset, bool $oldestFirst = false): array
{
    [$where, $params] = mms_records_where($f);
    $order = $oldestFirst ? 'last_change_at ASC, e.id ASC' : 'e.id DESC';

    // LIMIT and OFFSET are whole numbers made here, never user text.
    $sql = 'SELECT ' . mms_records_columns() . mms_records_from_sql() . $where
         . ' ORDER BY ' . $order . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** An executed statement with ALL matching forms, for the CSV export (read with ->fetch()). */
function mms_records_statement(array $f): PDOStatement
{
    [$where, $params] = mms_records_where($f);
    $stmt = db()->prepare('SELECT ' . mms_records_columns() . mms_records_from_sql() . $where . ' ORDER BY e.id DESC');
    $stmt->execute($params);
    return $stmt;
}

/** Departments for the filter list: [ [id, name], ... ]. */
function mms_department_options(): array
{
    return db()->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
}

/** Modes of procurement for the filter list: [ [id, name], ... ]. */
function mms_mode_options(): array
{
    return db()->query('SELECT id, name FROM procurement_modes ORDER BY id')->fetchAll();
}

// ---------------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------------

/** Number of forms per status (drafts left out): [ 'submitted' => 3, ... ]. */
function mms_status_counts(): array
{
    $rows = db()->query(
        "SELECT status, COUNT(*) AS total FROM evaluations
          WHERE status <> 'supplier_draft'
          GROUP BY status"
    )->fetchAll();

    $counts = [];
    foreach ($rows as $r) {
        $counts[(string) $r['status']] = (int) $r['total'];
    }
    return $counts;
}

/** How many forms are stuck right now. */
function mms_stuck_count(): int
{
    return mms_records_count(mms_records_filters(['stuck' => '1']));
}

/** Completed forms waiting to be marked as received, longest waiting first. */
function mms_waiting_to_receive(int $limit = 20): array
{
    return mms_records_fetch(mms_records_filters(['status' => 'completed']), $limit, 0, true);
}

// ---------------------------------------------------------------------------
// One record
// ---------------------------------------------------------------------------

/** Names for the record page: supplier, department, mode, evaluator, who distributed. */
function mms_record_extra(int $id): array
{
    $stmt = db()->prepare(
        'SELECT s.full_name AS supplier_name, s.business_name AS supplier_business, s.email AS supplier_email,
                d.name AS department_name, m.name AS mode_name,
                ev.full_name AS evaluator_name, db.full_name AS distributed_by_name
           FROM evaluations e
           LEFT JOIN users s             ON s.id = e.supplier_id
           LEFT JOIN departments d       ON d.id = e.department_id
           LEFT JOIN procurement_modes m ON m.id = e.mode_id
           LEFT JOIN users ev            ON ev.id = e.evaluator_id
           LEFT JOIN users db            ON db.id = e.distributed_by
          WHERE e.id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: [];
}

/** Every status change of the form, oldest first, with the person's name. */
function mms_record_history(int $id): array
{
    $stmt = db()->prepare(
        'SELECT h.from_status, h.to_status, h.remarks, h.created_at, u.full_name AS actor_name
           FROM status_history h
           LEFT JOIN users u ON u.id = h.actor_id
          WHERE h.evaluation_id = :id
          ORDER BY h.created_at, h.id'
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Mark as received
// ---------------------------------------------------------------------------

/**
 * completed -> received. Only mm_staff (workflow.php action 'receive').
 * One transaction: lock the row, change the status through transition(),
 * notify the evaluator and the supplier, write the audit line.
 * Throws WorkflowException with a plain message when it is not allowed.
 */
function mms_mark_received(array $user, int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the row so two people cannot receive the same form at once.
        $lock = $pdo->prepare('SELECT id FROM evaluations WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $id]);
        if ($lock->fetchColumn() === false) {
            throw new WorkflowException('This form no longer exists.');
        }

        $form = conforme_load_form($id);
        if (!$form || !user_is_usable($user) || !can_transition($form, 'receive', $user)) {
            throw new WorkflowException('This form cannot be marked as received right now.');
        }

        transition($form, 'receive', $user);

        $title   = conforme_form_title($form);
        $message = 'Form ' . $title . ' was received by MMS.';
        if (!empty($form['evaluator_id'])) {
            notify((int) $form['evaluator_id'], $message, 'common/evaluation-view.php?id=' . $id);
        }
        notify((int) $form['supplier_id'], $message, 'supplier/form-view.php?id=' . $id);

        audit('form_received', 'Evaluation #' . $id . ' (' . $title . ') version ' . (int) $form['version'] . ' marked as received by user #' . (int) $user['id'] . '.');

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}