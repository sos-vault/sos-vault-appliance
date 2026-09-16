<?php

return [
    'tool_name' => 'Compliance',
    'tool_title' => 'Compliance & Exposition',
    'tool_tooltip' => 'Registerkarte Compliance & Exposition öffnen',
    'tool_description' => 'CIS-, DOD-STIG-, Docker-Bench- und Expositionsbefunde, die bei jedem Upload eines sos-Reports automatisch bewertet werden, mit Behebungshinweisen für jede fehlgeschlagene Prüfung.',

    'table_empty_heading' => 'Noch keine Compliance-Analyse',
    'table_empty_description' => 'Führen Sie eine Analyse aus, um diesen Fall anhand von CIS-, STIG-, Docker-Bench- und Expositionsprüfungen zu bewerten.',

    'ruleset_cis' => 'CIS',
    'ruleset_stig' => 'STIG',
    'ruleset_docker_bench' => 'Docker Bench',
    'ruleset_exposure' => 'Exposition',

    'column_rule_id' => 'Regel',
    'column_title' => 'Titel',
    'column_category' => 'Kategorie',
    'column_severity' => 'Schweregrad',
    'column_status' => 'Status',
    'column_matched_path' => 'Übereinstimmender Pfad',

    'filter_ruleset' => 'Regelwerk',
    'filter_severity' => 'Schweregrad',
    'filter_status' => 'Status',

    'severity_INFO' => 'Info',
    'severity_LOW' => 'Niedrig',
    'severity_MEDIUM' => 'Mittel',
    'severity_HIGH' => 'Hoch',
    'severity_CRITICAL' => 'Kritisch',

    'status_pass' => 'Bestanden',
    'status_fail' => 'Fehlgeschlagen',
    'status_not_applicable' => 'N/A',
    'status_error' => 'Fehler',

    'action_run_now' => 'Analyse jetzt ausführen',
    'run_now_success' => 'Compliance-Analyse abgeschlossen.',
    'run_now_case_not_found' => 'Dieser Fall konnte zur Analyse nicht gefunden werden.',

    'field_description' => 'Beschreibung',
    'field_remediation' => 'Behebung',
    'field_reference_url' => 'Referenz',
];
