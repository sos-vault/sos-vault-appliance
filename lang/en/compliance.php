<?php

return [
    'tool_name' => 'Compliance',
    'tool_title' => 'Compliance & Exposure',
    'tool_tooltip' => 'Open Compliance & Exposure Tab',
    'tool_description' => 'CIS, DOD STIG, Docker Bench, and exposure findings assessed automatically on every sosreport upload, with remediation guidance for each failing check.',

    'table_empty_heading' => 'No compliance analysis yet',
    'table_empty_description' => 'Run an analysis to assess this case against CIS, STIG, Docker Bench, and exposure checks.',

    'ruleset_cis' => 'CIS',
    'ruleset_stig' => 'STIG',
    'ruleset_docker_bench' => 'Docker Bench',
    'ruleset_exposure' => 'Exposure',

    'column_rule_id' => 'Rule',
    'column_title' => 'Title',
    'column_category' => 'Category',
    'column_severity' => 'Severity',
    'column_status' => 'Status',
    'column_matched_path' => 'Matched path',

    'filter_ruleset' => 'Ruleset',
    'filter_severity' => 'Severity',
    'filter_status' => 'Status',

    'severity_INFO' => 'Info',
    'severity_LOW' => 'Low',
    'severity_MEDIUM' => 'Medium',
    'severity_HIGH' => 'High',
    'severity_CRITICAL' => 'Critical',

    'status_pass' => 'Pass',
    'status_fail' => 'Fail',
    'status_not_applicable' => 'N/A',
    'status_error' => 'Error',

    'action_run_now' => 'Run analysis now',
    'run_now_success' => 'Compliance analysis complete.',
    'run_now_case_not_found' => 'Could not find this case to analyze.',

    'field_description' => 'Description',
    'field_remediation' => 'Remediation',
    'field_reference_url' => 'Reference',
];
