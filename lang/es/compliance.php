<?php

return [
    'tool_name' => 'Compliance',
    'tool_title' => 'Cumplimiento y Exposición',
    'tool_tooltip' => 'Abrir la pestaña de Cumplimiento y Exposición',
    'tool_description' => 'Hallazgos de CIS, DOD STIG, Docker Bench y exposición evaluados automáticamente en cada carga de un informe sos, con orientación de corrección para cada control fallido.',

    'table_empty_heading' => 'Aún no hay análisis de cumplimiento',
    'table_empty_description' => 'Ejecute un análisis para evaluar este caso frente a los controles CIS, STIG, Docker Bench y de exposición.',

    'ruleset_cis' => 'CIS',
    'ruleset_stig' => 'STIG',
    'ruleset_docker_bench' => 'Docker Bench',
    'ruleset_exposure' => 'Exposición',

    'column_rule_id' => 'Regla',
    'column_title' => 'Título',
    'column_category' => 'Categoría',
    'column_severity' => 'Severidad',
    'column_status' => 'Estado',
    'column_matched_path' => 'Ruta coincidente',

    'filter_ruleset' => 'Conjunto de reglas',
    'filter_severity' => 'Severidad',
    'filter_status' => 'Estado',

    'severity_INFO' => 'Info',
    'severity_LOW' => 'Baja',
    'severity_MEDIUM' => 'Media',
    'severity_HIGH' => 'Alta',
    'severity_CRITICAL' => 'Crítica',

    'status_pass' => 'Aprobado',
    'status_fail' => 'Fallido',
    'status_not_applicable' => 'N/A',
    'status_error' => 'Error',

    'action_run_now' => 'Ejecutar análisis ahora',
    'run_now_success' => 'Análisis de cumplimiento completado.',
    'run_now_case_not_found' => 'No se pudo encontrar este caso para analizarlo.',

    'field_description' => 'Descripción',
    'field_remediation' => 'Corrección',
    'field_reference_url' => 'Referencia',
];
