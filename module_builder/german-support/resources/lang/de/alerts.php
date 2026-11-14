<?php

return [
    'page_title' => 'Warnmeldungen',
    'page_description' => 'Verwalten Sie die Alarmregeln für diesen Vault. Regeln gelten für jeden von einem Mitglied hochgeladenen Fall und werden bei jedem neuen Upload automatisch ausgewertet.',

    'file_add_alert_label' => 'Alarm hinzufügen',
    'file_add_alert_tooltip' => 'Eine Alarmregel basierend auf dieser Datei erstellen',

    'table_empty_heading' => 'Noch keine Alarme',
    'table_empty_description' => 'Erstellen Sie eine Alarmregel, um benachrichtigt zu werden, wenn eine Datei, eine Metrik oder ein Unterschied zu einem Referenzfall bei zukünftigen Uploads eine Bedingung erfüllt.',

    'column_name' => 'Name',
    'column_severity' => 'Schweregrad',
    'column_type' => 'Typ',
    'column_enabled' => 'Aktiv',
    'column_creator' => 'Erstellt von',
    'column_delivery_notification' => 'Benachrichtigung',
    'column_delivery_email' => 'E-Mail',
    'column_delivery_event' => 'Ereignis / SIEM',
    'column_delivery_contact_point' => 'Kontaktpunkt',
    'column_created_at' => 'Erstellt',

    'action_create' => 'Neuer Alarm',
    'action_edit' => 'Bearbeiten',
    'action_delete' => 'Löschen',

    'field_name' => 'Name',
    'placeholder_name' => 'z. B. Hohe CPU-Auslastung auf Webservern',
    'field_description' => 'Beschreibung',
    'placeholder_description' => 'Optionale Notizen dazu, was diese Alarmregel erkennt',
    'field_severity' => 'Schweregrad',
    'field_type' => 'Alarmtyp',

    'type_grep' => 'Dateimuster-Treffer',
    'type_levels' => 'Metrik-Schwellenwert',
    'type_diff' => 'Vergleich mit Referenzfall',

    'field_grep_path' => 'Dateipfad',
    'placeholder_grep_path' => '/var/log/messages',
    'field_grep_regex' => 'Regulärer Ausdruck',
    'placeholder_grep_regex' => 'error|failed|panic',
    'field_grep_match_mode' => 'Auslösen wenn',
    'grep_match_found' => 'Muster wird gefunden',
    'grep_match_not_found' => 'Muster wird NICHT gefunden',

    'field_levels_metric' => 'Metrik',
    'field_levels_threshold' => 'Schwellenwert',
    'placeholder_levels_threshold' => '80',
    'field_levels_mount_path' => 'Einhängepunkt',
    'placeholder_levels_mount_path' => '/',

    'metric_cpu' => 'CPU',
    'metric_disk' => 'Festplatte',
    'metric_inodes' => 'Inodes',
    'metric_memory' => 'Arbeitsspeicher',
    'metric_swap' => 'Swap',
    'metric_processes' => 'Prozesse',
    'metric_open_files' => 'Offene Dateien',
    'metric_connections' => 'Verbindungen',

    'field_diff_path' => 'Dateipfad',
    'placeholder_diff_path' => '/etc/passwd',
    'field_diff_reference_case' => 'Referenzfall',

    'section_delivery' => 'Zustellung',
    'field_delivery_notification' => 'Benachrichtigung',
    'field_delivery_email' => 'E-Mail',
    'field_email_addresses' => 'E-Mail-Adressen (durch Komma getrennt)',
    'placeholder_email_addresses' => 'user@example.com, user2@example.com',
    'field_delivery_event' => 'Ereignis (Sysevent / SIEM)',
    'field_delivery_contact_point' => 'Kontaktpunkt',
    'field_contact_point_destination' => 'Ziel',
    'field_contact_point_webhook_url' => 'Webhook-URL',
    'placeholder_contact_point_webhook_url' => 'https://hooks.slack.com/services/...',

    'destination_slack' => 'Slack',
    'destination_pagerduty' => 'PagerDuty (in Entwicklung)',
    'destination_msteams' => 'MS Teams (in Entwicklung)',
    'destination_opsgenie' => 'OpsGenie (in Entwicklung)',
    'destination_googlechat' => 'Google Chat (in Entwicklung)',
    'destination_generic_api' => 'Generische API (in Entwicklung)',
    'destination_snmp_trap' => 'SNMP-Trap (in Entwicklung)',

    'contact_point_coming_soon' => 'Derzeit ist nur Slack verfügbar; die anderen Ziele befinden sich in Entwicklung.',
    'contact_point_license_required' => 'Der Kontaktpunkt-Kanal erfordert eine aktive Appliance-Lizenz.',
    'contact_point_test' => 'Testen',
    'contact_point_test_success' => 'Testnachricht an Slack gesendet.',
    'contact_point_test_failure' => 'Slack konnte mit dieser Webhook-URL nicht erreicht werden.',

    'severity_INFO' => 'Info',
    'severity_WARNING' => 'Warnung',
    'severity_ERROR' => 'Fehler',
    'severity_CRITICAL' => 'Kritisch',
    'severity_FATAL' => 'Fatal',

    'validation_email_too_many' => 'Geben Sie höchstens 10 E-Mail-Adressen ein.',
    'validation_email_invalid' => 'Keine gültige E-Mail-Adresse: :address',
];
