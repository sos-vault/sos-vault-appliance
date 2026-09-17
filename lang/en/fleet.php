<?php

return [
    // Fleet list page
    'table_title' => 'Fleet',
    'table_description' => 'All systems that have uploaded sos reports, grouped by host identity (machine-id).',
    'col_hostname' => 'Hostname',
    'col_machine_id' => 'Machine ID',
    'col_os' => 'OS',
    'col_reports' => 'Reports',
    'col_first_seen' => 'First seen',
    'col_last_seen' => 'Last seen',
    'machine_id_unknown' => 'unknown',
    'empty_heading' => 'No systems yet',
    'empty_description' => 'Upload a sos report and its host will appear here.',

    // Host timeline page
    'host_title' => 'System history — :host',
    'host_description_machine_id' => 'machine-id :machine_id',
    'host_description_no_machine_id' => 'Grouped by the filename-derived host — no machine-id was found in these reports.',
    'col_date' => 'Date',
    'col_case' => 'Case',
    'col_label' => 'Label',
    'col_sos_version' => 'sos version',
    'col_status' => 'Status',
    'col_sha256' => 'SHA-256',
    'host_empty_heading' => 'No reports for this system',
    'host_empty_description' => 'No visible reports match this host identity.',
    'action_browse' => 'Browse report',
    'action_summary' => 'Summary',
    'action_compare' => 'Compare',
    'back_to_fleet' => 'Back to fleet',
];
