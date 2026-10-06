<?php

return [
    // Fleet list page
    'table_title' => 'Flotte',
    'table_description' => 'Alle Systeme, die sos-Reports hochgeladen haben, gruppiert nach Host-Identität (machine-id).',
    'col_hostname' => 'Hostname',
    'col_machine_id' => 'Machine-ID',
    'col_os' => 'Betriebssystem',
    'col_reports' => 'Reports',
    'col_first_seen' => 'Zuerst gesehen',
    'col_last_seen' => 'Zuletzt gesehen',
    'machine_id_unknown' => 'unbekannt',
    'empty_heading' => 'Noch keine Systeme',
    'empty_description' => 'Laden Sie einen sos-Report hoch, dann erscheint der Host hier.',

    // Host timeline page
    'host_title' => 'Systemhistorie — :host',
    'host_description_machine_id' => 'machine-id :machine_id',
    'host_description_no_machine_id' => 'Gruppiert nach dem aus dem Dateinamen abgeleiteten Host — in diesen Reports wurde keine machine-id gefunden.',
    'col_date' => 'Datum',
    'col_case' => 'Fall',
    'col_label' => 'Bezeichnung',
    'col_sos_version' => 'sos-Version',
    'col_status' => 'Status',
    'col_sha256' => 'SHA-256',
    'host_empty_heading' => 'Keine Reports für dieses System',
    'host_empty_description' => 'Keine sichtbaren Reports entsprechen dieser Host-Identität.',
    'action_browse' => 'Report durchsuchen',
    'action_summary' => 'Zusammenfassung',
    'action_compare' => 'Vergleichen',
    'back_to_fleet' => 'Zurück zur Flotte',
];
