<?php

return [
    'page_title' => 'Alertas',
    'page_description' => 'Gestione las reglas de alerta de esta bóveda. Las reglas se aplican a cada caso subido por cualquier miembro y se evalúan automáticamente en cada nueva carga.',

    'file_add_alert_label' => 'Añadir alerta',
    'file_add_alert_tooltip' => 'Crear una regla de alerta basada en este archivo',

    'table_empty_heading' => 'Aún no hay alertas',
    'table_empty_description' => 'Cree una regla de alerta para recibir un aviso cuando un archivo, una métrica o una diferencia con un caso de referencia cumpla una condición en futuras cargas.',

    'column_name' => 'Nombre',
    'column_severity' => 'Severidad',
    'column_type' => 'Tipo',
    'column_enabled' => 'Activa',
    'column_creator' => 'Creada por',
    'column_delivery_notification' => 'Notificación',
    'column_delivery_email' => 'Correo',
    'column_delivery_event' => 'Evento / SIEM',
    'column_delivery_contact_point' => 'Punto de contacto',
    'column_created_at' => 'Creada',

    'action_create' => 'Nueva alerta',
    'action_edit' => 'Editar',
    'action_delete' => 'Eliminar',

    'field_name' => 'Nombre',
    'placeholder_name' => 'p. ej. Uso elevado de CPU en servidores web',
    'field_description' => 'Descripción',
    'placeholder_description' => 'Notas opcionales sobre lo que detecta esta regla de alerta',
    'field_severity' => 'Severidad',
    'field_type' => 'Tipo de alerta',

    'type_grep' => 'Coincidencia de patrón en archivo',
    'type_levels' => 'Umbral de métrica',
    'type_diff' => 'Diferencia con caso de referencia',

    'field_grep_path' => 'Ruta del archivo',
    'placeholder_grep_path' => '/var/log/messages',
    'field_grep_regex' => 'Expresión regular',
    'placeholder_grep_regex' => 'error|failed|panic',
    'field_grep_match_mode' => 'Disparar cuando',
    'grep_match_found' => 'Se encuentra el patrón',
    'grep_match_not_found' => 'NO se encuentra el patrón',

    'field_levels_metric' => 'Métrica',
    'field_levels_threshold' => 'Umbral',
    'placeholder_levels_threshold' => '80',
    'field_levels_mount_path' => 'Punto de montaje',
    'placeholder_levels_mount_path' => '/',

    'metric_cpu' => 'CPU',
    'metric_disk' => 'Disco',
    'metric_inodes' => 'Inodos',
    'metric_memory' => 'Memoria',
    'metric_swap' => 'Swap',
    'metric_processes' => 'Procesos',
    'metric_open_files' => 'Archivos abiertos',
    'metric_connections' => 'Conexiones',

    'field_diff_path' => 'Ruta del archivo',
    'placeholder_diff_path' => '/etc/passwd',
    'field_diff_reference_case' => 'Caso de referencia',

    'section_delivery' => 'Entrega',
    'field_delivery_notification' => 'Notificación',
    'field_delivery_email' => 'Correo',
    'field_email_addresses' => 'Direcciones de correo (separadas por comas)',
    'placeholder_email_addresses' => 'usuario@ejemplo.com, usuario2@ejemplo.com',
    'field_delivery_event' => 'Evento (Sysevent / SIEM)',
    'field_delivery_contact_point' => 'Punto de contacto',
    'field_contact_point_destination' => 'Destino',
    'field_contact_point_webhook_url' => 'URL del webhook',
    'placeholder_contact_point_webhook_url' => 'https://hooks.slack.com/services/...',

    'destination_slack' => 'Slack',
    'destination_pagerduty' => 'PagerDuty (próximamente)',
    'destination_msteams' => 'MS Teams (próximamente)',
    'destination_opsgenie' => 'OpsGenie (próximamente)',
    'destination_googlechat' => 'Google Chat (próximamente)',
    'destination_generic_api' => 'API genérica (próximamente)',
    'destination_snmp_trap' => 'SNMP Trap (próximamente)',

    'contact_point_coming_soon' => 'Solo Slack está disponible por ahora; los demás destinos están en construcción.',
    'contact_point_license_required' => 'El canal de punto de contacto requiere una licencia de appliance activa.',
    'contact_point_test' => 'Probar',
    'contact_point_test_success' => 'Mensaje de prueba enviado a Slack.',
    'contact_point_test_failure' => 'No se pudo contactar con Slack usando esa URL de webhook.',

    'severity_INFO' => 'Info',
    'severity_WARNING' => 'Advertencia',
    'severity_ERROR' => 'Error',
    'severity_CRITICAL' => 'Crítico',
    'severity_FATAL' => 'Fatal',

    'validation_email_too_many' => 'Introduzca como máximo 10 direcciones de correo.',
    'validation_email_invalid' => 'Dirección de correo no válida: :address',
];
