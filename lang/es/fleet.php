<?php

return [
    // Fleet list page
    'table_title' => 'Flota',
    'table_description' => 'Todos los sistemas que han subido informes sos, agrupados por identidad del host (machine-id).',
    'col_hostname' => 'Nombre del host',
    'col_machine_id' => 'Machine ID',
    'col_os' => 'SO',
    'col_reports' => 'Informes',
    'col_first_seen' => 'Visto por primera vez',
    'col_last_seen' => 'Visto por última vez',
    'machine_id_unknown' => 'desconocido',
    'empty_heading' => 'Aún no hay sistemas',
    'empty_description' => 'Suba un informe sos y su host aparecerá aquí.',

    // Host timeline page
    'host_title' => 'Historial del sistema — :host',
    'host_description_machine_id' => 'machine-id :machine_id',
    'host_description_no_machine_id' => 'Agrupado por el host derivado del nombre de archivo — no se encontró machine-id en estos informes.',
    'col_date' => 'Fecha',
    'col_case' => 'Caso',
    'col_label' => 'Etiqueta',
    'col_sos_version' => 'Versión de sos',
    'col_status' => 'Estado',
    'col_sha256' => 'SHA-256',
    'host_empty_heading' => 'No hay informes para este sistema',
    'host_empty_description' => 'Ningún informe visible coincide con esta identidad de host.',
    'action_browse' => 'Explorar informe',
    'action_summary' => 'Resumen',
    'action_compare' => 'Comparar',
    'back_to_fleet' => 'Volver a la flota',
];
