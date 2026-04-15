<?php
/**
 * Missing Data Report - REDCap External Module
 * MissingDataReport.php  —  Module entry class
 *
 * Handles all data retrieval, branching-logic evaluation,
 * and missing-data computation for the report page.
 *
 * v0.0.2 changes:
 *   - Namespace updated to uamsichi\MissingDataReport
 *   - Full support for repeated instruments:
 *       REDCap getData() returns repeated rows under the key
 *       ['repeat_instances'][$event_name][$instrument][$instance_num]
 *       Non-repeated rows remain under [$event_name] as before.
 *   - Two new output columns added to every row:
 *       'repeat_instrument' — instrument name, or "N/A" for non-repeated
 *       'repeat_instance'   — instance number (int), or 0 for non-repeated
 */

namespace uamsichi\MissingDataReport;

use ExternalModules\AbstractExternalModule;

class MissingDataReport extends AbstractExternalModule
{

    // ─────────────────────────────────────────────────────────────────────────
    // CONSTANTS
    // ─────────────────────────────────────────────────────────────────────────

    /** Field types that are never user-entered and always skipped */
    const ALWAYS_SKIP_TYPES = ['descriptive', 'calc', 'file'];

    /** Annotations that mark auto-filled / hidden fields */
    const SKIP_ANNOTATIONS  = ['@CALCTEXT', '@HIDDEN', '@READONLY', '@BARCODE-APP'];

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API  —  called from missing_report.php
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run the full missing-data report for the current project.
     *
     * @param int    $project_id
     * @param array  $filters    Optional: ['forms'=>[], 'events'=>[], 'records'=>[]]
     * @return array  [
     *   'rows'     => [
     *       [
     *         'record_id', 'event_name', 'arm_name',
     *         'repeat_instrument',   // instrument name OR "N/A"
     *         'repeat_instance',     // int (1,2,…) OR 0 for non-repeated
     *         'form', 'miss_count', 'miss_vars'
     *       ], ...
     *   ],
     *   'summary'  => [ form => ['total_records','records_with_missing','total_missing'], ... ],
     *   'is_longitudinal' => bool,
     *   'has_repeating'   => bool,
     *   'forms'    => [form_name, ...],
     *   'events'   => [event_name, ...],
     *   'arms'     => [arm_name, ...],
     * ]
     */
    public function runReport(int $project_id, array $filters = []): array
    {
        // ── 1. Load project metadata ─────────────────────────────────────────
        $Proj            = new \Project($project_id);
        $is_longitudinal = (bool) $Proj->longitudinal;

        // ── 2. Build dictionary (variable → metadata) ────────────────────────
        $dictionary = $this->buildDictionary($Proj);

        // ── 3. Determine forms to process ────────────────────────────────────
        $exclude_forms = $this->getProjectSetting('exclude-forms') ?? '';
        $excluded      = array_filter(array_map('trim', explode(',', $exclude_forms)));

        $extra_skip_types = $this->getProjectSetting('exclude-field-types') ?? '';
        $extra_skip       = array_filter(array_map('trim', explode(',', $extra_skip_types)));
        $skip_types       = array_merge(self::ALWAYS_SKIP_TYPES, $extra_skip);

        // Filter dictionary to actionable fields only
        $dictionary = array_filter($dictionary, function($f) use ($skip_types, $excluded) {
            if (in_array($f['type'], $skip_types))          return false;
            if (in_array($f['form'], $excluded))            return false;
            if ($this->hasSkipAnnotation($f['annotation'])) return false;
            return true;
        });

        // Group variables by form
        $forms_dict = [];
        foreach ($dictionary as $var => $meta) {
            $forms_dict[$meta['form']][] = array_merge(['var' => $var], $meta);
        }

        $all_forms = array_keys($forms_dict);
        if (!empty($filters['forms'])) {
            $all_forms = array_intersect($all_forms, $filters['forms']);
        }

        // ── 4. Fetch data ────────────────────────────────────────────────────
        $all_vars   = array_keys($dictionary);
        $logic_vars = $this->extractLogicVarNames($dictionary);
        $fetch_vars = array_unique(array_merge($all_vars, $logic_vars));
        // REDCap adds these system fields automatically — do not request them
        $fetch_vars = array_diff($fetch_vars, [
            'record_id',
            'redcap_event_name',
            'redcap_repeat_instrument',
            'redcap_repeat_instance',
        ]);

        $records_filter = !empty($filters['records']) ? $filters['records'] : null;
        $events_filter  = !empty($filters['events'])  ? $filters['events']  : null;

        $data = \REDCap::getData([
            'project_id'            => $project_id,
            'return_format'         => 'array',
            'fields'                => $fetch_vars,
            'records'               => $records_filter,
            'events'                => $events_filter,
            'exportDataAccessGroups' => false,
        ]);

        // ── 5. Build event → arm map ─────────────────────────────────────────
        $event_arm_map = $this->buildEventArmMap($Proj);

        // ── 6. Determine display lists ───────────────────────────────────────
        if ($is_longitudinal) {
            $event_names = array_keys($Proj->eventInfo);
            $arm_names   = array_unique(array_values($event_arm_map));
        } else {
            $event_names = ['Event 1'];
            $arm_names   = ['Arm 1'];
        }

        // ── 7. Determine which forms are set up as repeating instruments ──────
        // $Proj->RepeatingFormsEvents is populated by REDCap core for projects
        // that have repeating instruments / events enabled.
        $repeating_forms = $this->getRepeatingForms($Proj);

        // ── 8. Evaluate missing data ─────────────────────────────────────────
        $rows         = [];
        $summary      = [];
        $has_repeating = false;

        foreach ($data as $record_id => $record_data) {

            // ── 8a. Non-repeated rows (classic key structure) ─────────────────
            foreach ($record_data as $event_key => $row) {

                // REDCap puts repeated data under 'repeat_instances' — skip here
                if ($event_key === 'repeat_instances') continue;

                [$event_label, $arm_label, $event_id_num] =
                    $this->resolveEventMeta($Proj, $event_key, $is_longitudinal, $event_arm_map);

                if (!empty($filters['events']) && !in_array($event_key, $filters['events'])) {
                    continue;
                }

                foreach ($all_forms as $form) {
                    if (!isset($forms_dict[$form])) continue;

                    // Only process non-repeating forms here
                    if (isset($repeating_forms[$event_key][$form])) continue;

                    [$miss_count, $miss_vars_str] = $this->evalFormMissing(
                        $forms_dict[$form], $row, $record_id, $event_id_num, $data
                    );

                    $this->accumulateSummary($summary, $form, $miss_count);

                    if ($miss_count > 0) {
                        $rows[] = [
                            'record_id'         => $record_id,
                            'event_name'        => $event_label,
                            'arm_name'          => $arm_label,
                            'repeat_instrument' => 'N/A',
                            'repeat_instance'   => 0,
                            'form'              => $form,
                            'miss_count'        => $miss_count,
                            'miss_vars'         => $miss_vars_str,
                        ];
                    }
                }
            }

            // ── 8b. Repeated instrument rows ──────────────────────────────────
            if (!isset($record_data['repeat_instances'])) continue;

            foreach ($record_data['repeat_instances'] as $event_key => $instruments) {

                [$event_label, $arm_label, $event_id_num] =
                    $this->resolveEventMeta($Proj, $event_key, $is_longitudinal, $event_arm_map);

                if (!empty($filters['events']) && !in_array($event_key, $filters['events'])) {
                    continue;
                }

                foreach ($instruments as $instrument_name => $instances) {

                    // Apply form filter
                    if (!in_array($instrument_name, $all_forms)) continue;
                    if (!isset($forms_dict[$instrument_name]))   continue;

                    $has_repeating = true;

                    foreach ($instances as $instance_num => $row) {

                        [$miss_count, $miss_vars_str] = $this->evalFormMissing(
                            $forms_dict[$instrument_name], $row, $record_id, $event_id_num, $data
                        );

                        $this->accumulateSummary($summary, $instrument_name, $miss_count);

                        if ($miss_count > 0) {
                            $rows[] = [
                                'record_id'         => $record_id,
                                'event_name'        => $event_label,
                                'arm_name'          => $arm_label,
                                'repeat_instrument' => $instrument_name,
                                'repeat_instance'   => (int)$instance_num,
                                'form'              => $instrument_name,
                                'miss_count'        => $miss_count,
                                'miss_vars'         => $miss_vars_str,
                            ];
                        }
                    }
                }
            }
        }

        // Sort rows: form → event → arm → repeat_instrument → instance → record
        usort($rows, function($a, $b) {
            return [
                $a['form'], $a['event_name'], $a['arm_name'],
                $a['repeat_instrument'], $a['repeat_instance'], $a['record_id']
            ] <=> [
                $b['form'], $b['event_name'], $b['arm_name'],
                $b['repeat_instrument'], $b['repeat_instance'], $b['record_id']
            ];
        });

        return [
            'rows'            => $rows,
            'summary'         => $summary,
            'is_longitudinal' => $is_longitudinal,
            'has_repeating'   => $has_repeating,
            'forms'           => array_values(array_unique(array_keys($forms_dict))),
            'events'          => $event_names,
            'arms'            => $arm_names,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REPEATED INSTRUMENTS HELPER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns a map of [ event_unique_name => [ form_name => true ] ]
     * for all forms configured as repeating instruments in this project.
     *
     * REDCap stores this in $Proj->RepeatingFormsEvents (array) when the
     * "Repeating Instruments and Events" feature is enabled.
     * Structure varies slightly by REDCap version; we handle both shapes.
     */
    private function getRepeatingForms(\Project $Proj): array
    {
        $map = [];

        // Shape A (most common): [ event_id => [ form_name => '' ], ... ]
        // Shape B (newer):       [ event_unique_name => [ form_name => '' ], ... ]
        if (empty($Proj->RepeatingFormsEvents)) {
            return $map;
        }

        foreach ($Proj->RepeatingFormsEvents as $event_key => $forms) {
            if (!is_array($forms)) continue;
            // Resolve numeric event_id → unique_event_name if needed
            $unique = $event_key;
            if (is_numeric($event_key) && isset($Proj->eventInfo[$event_key]['unique_event_name'])) {
                $unique = $Proj->eventInfo[$event_key]['unique_event_name'];
            }
            foreach ($forms as $form_name => $label) {
                $map[$unique][$form_name] = true;
            }
        }

        return $map;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EVALUATE MISSING FIELDS FOR ONE FORM / ONE ROW
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array [ $miss_count (int), $miss_vars_str (string) ]
     */
    private function evalFormMissing(
        array  $form_vars,
        array  $row,
        string $record_id,
        ?int   $event_id_num,
        array  $all_data
    ): array {
        $miss_vars = [];

        foreach ($form_vars as $fv) {
            $var_name = $fv['var'];
            $logic    = $fv['logic'];

            // Branching logic — skip hidden fields
            if (!empty($logic)) {
                $shown = $this->evaluateBranchingLogic(
                    $logic, $row, $record_id, $event_id_num, $all_data
                );
                if (!$shown) continue;
            }

            if ($fv['type'] === 'checkbox') {
                if (!$this->isCheckboxChecked($var_name, $row)) {
                    $miss_vars[] = $var_name;
                }
            } else {
                $val = $row[$var_name] ?? null;
                if ($this->isMissing($val)) {
                    $miss_vars[] = $var_name;
                }
            }
        }

        return [count($miss_vars), implode(', ', $miss_vars)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SUMMARY ACCUMULATOR
    // ─────────────────────────────────────────────────────────────────────────

    private function accumulateSummary(array &$summary, string $form, int $miss_count): void
    {
        if (!isset($summary[$form])) {
            $summary[$form] = ['total_records' => 0, 'records_with_missing' => 0, 'total_missing' => 0];
        }
        $summary[$form]['total_records']++;
        if ($miss_count > 0) {
            $summary[$form]['records_with_missing']++;
            $summary[$form]['total_missing'] += $miss_count;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DICTIONARY BUILDER
    // ─────────────────────────────────────────────────────────────────────────

    private function buildDictionary(\Project $Proj): array
    {
        $dict = [];
        foreach ($Proj->metadata as $var => $meta) {
            $dict[$var] = [
                'form'       => $meta['form_name'],
                'type'       => $meta['element_type'],
                'logic'      => $meta['branching_logic'] ?? '',
                'annotation' => $meta['field_annotation'] ?? '',
            ];
        }
        return $dict;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EVENT META RESOLVER
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveEventMeta(
        \Project $Proj,
        string   $event_key,
        bool     $is_longitudinal,
        array    $event_arm_map
    ): array {
        if ($is_longitudinal) {
            $event_label  = $Proj->eventInfo[$event_key]['name'] ?? $event_key;
            $arm_label    = $event_arm_map[$event_key] ?? 'Arm 1';
            $event_id_num = $Proj->eventInfo[$event_key]['event_id'] ?? null;
        } else {
            $event_label  = 'Event 1';
            $arm_label    = 'Arm 1';
            $event_id_num = null;
        }
        return [$event_label, $arm_label, $event_id_num];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BRANCHING LOGIC EVALUATOR
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Evaluate REDCap branching logic for a given data row.
     * Returns TRUE if the field SHOULD be shown (i.e., is applicable).
     */
    private function evaluateBranchingLogic(
        string $logic,
        array  $row,
        string $record_id,
        ?int   $event_id,
        array  $all_data
    ): bool {
        if (empty(trim($logic))) return true;

        $expr = preg_replace('/\s+/', ' ', trim($logic));

        // Cross-event references → conservatively TRUE
        $expr = preg_replace('/\[[a-z0-9_]+_arm_\d+\]\[[a-z0-9_]+\]/i', '1=1', $expr);

        // [event-id] comparisons
        $eid_str = (string)($event_id ?? '');
        $expr = preg_replace_callback(
            '/\[event-id\]\s*(>=|<=|<>|>|<|=)\s*\'([^\']*)\'/',
            function($m) use ($eid_str) {
                return $this->numericCompare((float)$eid_str, $m[1], (float)$m[2]) ? '1=1' : '1=0';
            },
            $expr
        );

        // [var(code)] = 1 / = 0  (checkbox sub-fields)
        $expr = preg_replace_callback(
            '/\[([a-z0-9_]+)\(([^)]+)\)\]\s*=\s*1/i',
            function($m) use ($row) {
                return (isset($row[$m[1].'___'.$m[2]]) && (int)$row[$m[1].'___'.$m[2]] === 1) ? '1=1' : '1=0';
            },
            $expr
        );
        $expr = preg_replace_callback(
            '/\[([a-z0-9_]+)\(([^)]+)\)\]\s*=\s*0/i',
            function($m) use ($row) {
                return (!isset($row[$m[1].'___'.$m[2]]) || (int)$row[$m[1].'___'.$m[2]] === 0) ? '1=1' : '1=0';
            },
            $expr
        );

        // Empty checks: [var] = "" or [var] = ''
        $expr = preg_replace_callback(
            '/\[([a-z0-9_]+)\]\s*(=|<>)\s*""/i',
            function($m) use ($row) {
                $empty = $this->isMissing($row[$m[1]] ?? null);
                return ($m[2] === '=') ? ($empty ? '1=1' : '1=0') : ($empty ? '1=0' : '1=1');
            },
            $expr
        );
        $expr = preg_replace_callback(
            "/\[([a-z0-9_]+)\]\s*(=|<>)\s*''/i",
            function($m) use ($row) {
                $empty = $this->isMissing($row[$m[1]] ?? null);
                return ($m[2] === '=') ? ($empty ? '1=1' : '1=0') : ($empty ? '1=0' : '1=1');
            },
            $expr
        );

        // [var] <> 'value'
        $expr = preg_replace_callback(
            "/\[([a-z0-9_]+)\]\s*<>\s*'([^']*)'/i",
            function($m) use ($row) {
                return (trim((string)($row[$m[1]] ?? '')) !== $m[2]) ? '1=1' : '1=0';
            },
            $expr
        );

        // [var] = 'value'
        $expr = preg_replace_callback(
            "/\[([a-z0-9_]+)\]\s*=\s*'([^']*)'/i",
            function($m) use ($row) {
                return (trim((string)($row[$m[1]] ?? '')) === $m[2]) ? '1=1' : '1=0';
            },
            $expr
        );

        // [var] >= / <= / > / < number
        $expr = preg_replace_callback(
            "/\[([a-z0-9_]+)\]\s*(>=|<=|>|<)\s*'?([0-9.]+)'?/i",
            function($m) use ($row) {
                return $this->numericCompare((float)($row[$m[1]] ?? 0), $m[2], (float)$m[3]) ? '1=1' : '1=0';
            },
            $expr
        );

        // [var] = number (unquoted)
        $expr = preg_replace_callback(
            '/\[([a-z0-9_]+)\]\s*=\s*([0-9.]+)/i',
            function($m) use ($row) {
                $val = trim((string)($row[$m[1]] ?? ''));
                return ($val === $m[2] || (float)$val === (float)$m[2]) ? '1=1' : '1=0';
            },
            $expr
        );

        $expr = preg_replace('/\band\b/i', '&&', $expr);
        $expr = preg_replace('/\bor\b/i',  '||', $expr);

        return $this->evalBoolExpr($expr);
    }

    private function evalBoolExpr(string $expr): bool
    {
        $expr = str_replace(['1=1', '1=0'], ['true', 'false'], $expr);
        if (!preg_match('/^[\struefals&|()!]+$/', $expr)) return true;
        $result = null;
        try { eval('$result = (bool)(' . $expr . ');'); }
        catch (\Throwable $e) { $result = true; }
        return (bool)$result;
    }

    private function numericCompare(float $left, string $op, float $right): bool
    {
        return match($op) {
            '>'  => $left >  $right,
            '<'  => $left <  $right,
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            '='  => $left == $right,
            '<>' => $left != $right,
            default => true,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function isMissing($val): bool
    {
        if ($val === null) return true;
        if ($val === '')   return true;
        if (is_string($val) && trim($val) === '') return true;
        return false;
    }

    private function isCheckboxChecked(string $var, array $row): bool
    {
        foreach ($row as $key => $val) {
            if (strpos($key, $var . '___') === 0 && (int)$val === 1) return true;
        }
        return false;
    }

    private function extractLogicVarNames(array $dictionary): array
    {
        $vars = [];
        foreach ($dictionary as $meta) {
            if (!empty($meta['logic'])) {
                preg_match_all('/\[([a-z0-9_]+)\]/i', $meta['logic'], $matches);
                foreach ($matches[1] as $v) {
                    if ($v !== 'event-id') $vars[] = $v;
                }
            }
        }
        return array_unique($vars);
    }

    private function buildEventArmMap(\Project $Proj): array
    {
        $map = [];
        if (isset($Proj->eventInfo)) {
            foreach ($Proj->eventInfo as $event_id => $info) {
                $arm_num  = $info['arm_num']  ?? 1;
                $arm_name = $info['arm_name'] ?? "Arm $arm_num";
                $unique   = $info['unique_event_name'] ?? $event_id;
                $map[$unique] = $arm_name;
            }
        }
        return $map;
    }

    private function hasSkipAnnotation(string $annotation): bool
    {
        foreach (self::SKIP_ANNOTATIONS as $tag) {
            if (stripos($annotation, $tag) !== false) return true;
        }
        return false;
    }
}
