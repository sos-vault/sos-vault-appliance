<?php

return [
    'page_title' => 'Alerts',
    'page_description' => 'Manage alert rules for this vault. Rules apply to every case uploaded by any member and are evaluated automatically on each new upload.',

    'file_add_alert_label' => 'Add an Alert',
    'file_add_alert_tooltip' => 'Create an alert rule based on this file',

    'table_empty_heading' => 'No alerts yet',
    'table_empty_description' => 'Create an alert rule to be notified when a file, metric, or reference-case diff matches a condition on future uploads.',

    'column_name' => 'Name',
    'column_severity' => 'Severity',
    'column_type' => 'Type',
    'column_enabled' => 'Enabled',
    'column_creator' => 'Created by',
    'column_delivery_notification' => 'Notification',
    'column_delivery_email' => 'Email',
    'column_delivery_event' => 'Event / SIEM',
    'column_delivery_contact_point' => 'Contact point',
    'column_created_at' => 'Created',

    'action_create' => 'New alert',
    'action_edit' => 'Edit',
    'action_delete' => 'Delete',

    'field_name' => 'Name',
    'placeholder_name' => 'e.g. High CPU usage on web servers',
    'field_description' => 'Description',
    'placeholder_description' => 'Optional notes about what this alert rule detects',
    'field_severity' => 'Severity',
    'field_type' => 'Alert type',

    'type_grep' => 'File pattern match',
    'type_levels' => 'Metric threshold',
    'type_diff' => 'Reference case diff',

    'field_grep_path' => 'File path',
    'placeholder_grep_path' => '/var/log/messages',
    'field_grep_regex' => 'Regular expression',
    'placeholder_grep_regex' => 'error|failed|panic',
    'field_grep_match_mode' => 'Trigger when',
    'grep_match_found' => 'Pattern is found',
    'grep_match_not_found' => 'Pattern is NOT found',

    'field_levels_metric' => 'Metric',
    'field_levels_threshold' => 'Threshold',
    'placeholder_levels_threshold' => '80',
    'field_levels_mount_path' => 'Mount path',
    'placeholder_levels_mount_path' => '/',

    'metric_cpu' => 'CPU',
    'metric_disk' => 'Disk',
    'metric_inodes' => 'Inodes',
    'metric_memory' => 'Memory',
    'metric_swap' => 'Swap',
    'metric_processes' => 'Processes',
    'metric_open_files' => 'Open files',
    'metric_connections' => 'Connections',

    'field_diff_path' => 'File path',
    'placeholder_diff_path' => '/etc/passwd',
    'field_diff_reference_case' => 'Reference case',

    'section_delivery' => 'Delivery',
    'field_delivery_notification' => 'Notification',
    'field_delivery_email' => 'Email',
    'field_email_addresses' => 'Email addresses (comma separated)',
    'placeholder_email_addresses' => 'user@example.com, user2@example.com',
    'field_delivery_event' => 'Event (Sysevent / SIEM)',
    'field_delivery_contact_point' => 'Contact point',
    'field_contact_point_destination' => 'Destination',
    'field_contact_point_webhook_url' => 'Webhook URL',
    'placeholder_contact_point_webhook_url' => 'https://hooks.slack.com/services/...',

    'destination_slack' => 'Slack',
    'destination_pagerduty' => 'PagerDuty (coming soon)',
    'destination_msteams' => 'MS Teams (coming soon)',
    'destination_opsgenie' => 'OpsGenie (coming soon)',
    'destination_googlechat' => 'Google Chat (coming soon)',
    'destination_generic_api' => 'Generic API (coming soon)',
    'destination_snmp_trap' => 'SNMP Trap (coming soon)',

    'contact_point_coming_soon' => 'Only Slack is available today; the other destinations are under construction.',
    'contact_point_license_required' => 'The contact point channel requires an active appliance license.',
    'contact_point_test' => 'Test',
    'contact_point_test_success' => 'Test message sent to Slack.',
    'contact_point_test_failure' => 'Could not reach Slack with that webhook URL.',

    'severity_INFO' => 'Info',
    'severity_WARNING' => 'Warning',
    'severity_ERROR' => 'Error',
    'severity_CRITICAL' => 'Critical',
    'severity_FATAL' => 'Fatal',

    'validation_email_too_many' => 'Enter at most 10 email addresses.',
    'validation_email_invalid' => 'Not a valid email address: :address',
];
