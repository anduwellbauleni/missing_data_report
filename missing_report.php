<?php
/** @var \uamsichi\MissingDataReport\MissingDataReport $module */

$project_id = isset($_GET['pid'])
    ? (int) $_GET['pid']
    : (isset($_POST['pid']) ? (int) $_POST['pid'] : null);

$error = null;

try {
    $filter = [
        'forms'   => !empty($_POST['filter_forms'])
            ? array_map('trim', explode(',', $_POST['filter_forms']))
            : [],
        'events'  => !empty($_POST['filter_events'])
            ? array_map('trim', explode(',', $_POST['filter_events']))
            : [],
        'records' => !empty($_POST['filter_records'])
            ? array_map('trim', explode(',', $_POST['filter_records']))
            : []
    ];

    $report_data = $module->runReport($project_id, $filter);
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

$rows    = $report_data['rows'] ?? [];
$summary = $report_data['summary'] ?? [];
$forms   = $report_data['forms'] ?? [];
$page_url = $module->getUrl('missing_report.php');

/* ============================================================
   CSV EXPORT
   ============================================================ */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="missing_data_report.csv"');

    $out = fopen('php://output', 'w');

    fputcsv(
        $out,
        [
            'Record ID',
            'Event',
            'Arm',
            'Repeat Instrument',
            'Repeat Instance',
            'Form',
            'Miss Count',
            'Missing Variables'
        ],
        ',',
        '"',
        '"'
    );

    foreach ($rows as $r) {
        fputcsv(
            $out,
            [
                $r['record_id'],
                $r['event_name'],
                $r['arm_name'],
                $r['repeat_instrument'] ?? 'N/A',
                $r['repeat_instance'] ?? '0',
                $r['form'],
                $r['miss_count'],
                $r['miss_vars']
            ],
            ',',
            '"',
            '"'
        );
    }

    fclose($out);
    exit;
}
?>

<style>
tr.low    { background-color:#f8f9fa; }
tr.medium { background-color:#fff3cd; }
tr.high   { background-color:#f8d7da; }
.badge-filter { cursor:pointer; }
</style>

<h2 class="mb-3">📋 Missing Data Report</h2>

<p class="text-muted">
Counts only fields that are truly applicable (branching logic respected).
</p>

<?php if ($error): ?>
<div class="alert alert-danger">
    <?= $module->escape($error) ?>
</div>
<?php endif; ?>

<!-- =========================
     FILTER PANEL
     ========================= -->
<div class="card mb-4">
  <div class="card-header bg-primary text-white">
    <strong>🔍 Filter Report</strong>
  </div>
  <div class="card-body">
    <form method="post" class="row g-3">

      <div class="col-md-4">
        <label class="form-label">Forms</label>
        <input type="text"
               class="form-control"
               name="filter_forms"
               placeholder="demographics, labs"
               value="<?= $module->escape($_POST['filter_forms'] ?? '') ?>">
        <small class="text-muted">Comma‑separated form names</small>
      </div>

      <div class="col-md-4">
        <label class="form-label">Events</label>
        <input type="text"
               class="form-control"
               name="filter_events"
               placeholder="baseline_arm_1"
               value="<?= $module->escape($_POST['filter_events'] ?? '') ?>">
        <small class="text-muted">Use unique event names</small>
      </div>

      <div class="col-md-4">
        <label class="form-label">Record IDs</label>
        <input type="text"
               class="form-control"
               name="filter_records"
               placeholder="101, 102"
               value="<?= $module->escape($_POST['filter_records'] ?? '') ?>">
        <small class="text-muted">Leave blank for all records</small>
      </div>

      <div class="col-12 text-end">
        <button class="btn btn-success" type="submit">
          ▶ Run Report
        </button>
      </div>
    </form>
  </div>
</div>

<!-- =========================
     QUICK FILTERS
     ========================= -->
<?php if (!empty($forms)): ?>
<div class="mb-3">
  <strong>Quick Filter by Form:</strong><br>
  <?php foreach ($forms as $f): ?>
    <span class="badge bg-secondary me-1 mb-1 badge-filter"
          onclick="document.querySelector('[name=filter_forms]').value='<?= $module->escape($f) ?>'; this.closest('form').submit();">
      <?= $module->escape($f) ?>
    </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- =========================
     SUMMARY STATS
     ========================= -->
<?php if (!empty($rows)): ?>
<div class="row mb-4">
  <div class="col-md-4">
    <div class="alert alert-info">
      <strong><?= count($rows) ?></strong><br>
      Rows with Missing Data
    </div>
  </div>
  <div class="col-md-4">
    <div class="alert alert-warning">
      <strong><?= array_sum(array_column($rows, 'miss_count')) ?></strong><br>
      Total Missing Fields
    </div>
  </div>
  <div class="col-md-4">
    <div class="alert alert-secondary">
      <strong><?= count($summary) ?></strong><br>
      Forms Affected
    </div>
  </div>
</div>
<?php endif; ?>

<!-- =========================
     EXPORT BUTTON
     ========================= -->
<?php if (!empty($rows)): ?>
<?php
$query = http_build_query([
    'pid'    => $project_id,
    'export' => 'csv'
]);
?>
<div class="d-flex justify-content-end mb-2">
  <a class="btn btn-outline-primary"
     href="<?= $module->escape($page_url . '?' . $query) ?>">
    ⬇ Export CSV
  </a>
</div>
<?php endif; ?>

<!-- =========================
     RESULTS TABLE
     ========================= -->
<?php if (!empty($rows)): ?>
<div class="table-responsive">
<table class="table table-bordered table-sm">
  <thead class="table-light">
    <tr>
      <th>Record</th>
      <th>Event</th>
      <th>Arm</th>
      <th>Repeat Instrument</th>
      <th>Repeat Instance</th>
      <th>Form</th>
      <th>Missing</th>
      <th>Fields</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
<?php
$cnt = (int)$r['miss_count'];
$cls = $cnt >= 5 ? 'high' : ($cnt >= 2 ? 'medium' : 'low');
?>
<tr class="<?= $cls ?>">
  <td><?= $module->escape($r['record_id']) ?></td>
  <td><?= $module->escape($r['event_name']) ?></td>
  <td><?= $module->escape($r['arm_name']) ?></td>
  <td><?= $module->escape($r['repeat_instrument'] ?? 'N/A') ?></td>
  <td><?= $module->escape($r['repeat_instance'] ?? '0') ?></td>
  <td><?= $module->escape($r['form']) ?></td>
  <td><?= $module->escape($cnt) ?></td>
  <td><?= $module->escape($r['miss_vars']) ?></td>
</tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>

<?php else: ?>

<p class="alert alert-success mt-4">
✅ All applicable fields are complete for the selected filters.
</p>

<?php endif; ?>