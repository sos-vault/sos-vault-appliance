<?php

/**
 * Open-core licensing strings (español).
 */
return [

    'status' => [
        'active' => 'ACTIVA',
        'expired' => 'EXPIRADA',
        'revoked' => 'REVOCADA',
        'none' => 'NINGUNA',
    ],

    'banner' => [
        'unlicensed_summary' => 'Funcionando en modo open-core básico: un solo administrador, directorio de almacén sin cifrar. Instale una licencia para habilitar múltiples usuarios, grupos, módulos, integración ITSM, almacenes cifrados y registro de eventos.',
        'expired_summary' => 'La licencia ha caducado. Las funciones de pago están temporalmente deshabilitadas. Renueve para restaurar el acceso.',
        'cta_install' => 'Instalar licencia',
    ],

    'request' => [
        'section_heading' => 'Solicitar una licencia',
        'section_description' => 'Genera una clave corta vinculada al hardware de este servidor. Cópiala y pégala en sos-vault.com (Verificar solicitud de licencia) para adquirir una licencia válida exclusivamente para este servidor.',
        'button_generate' => 'Generar solicitud de licencia',
        'button_copy' => 'Copiar clave',
        'button_copied' => 'Copiada',
        'key_heading' => 'Tu clave de solicitud de licencia',
        'key_helper' => 'Copia esta clave y pégala en sos-vault.com en «Verificar solicitud de licencia». Es seguro compartirla — solo contiene la huella de hardware de este servidor.',
        'notif_key_ready' => 'Clave de solicitud de licencia lista',
        'notif_key_ready_body' => 'Copia la clave de abajo y pégala en sos-vault.com en «Verificar solicitud de licencia».',
        'notif_failed' => 'No se pudo generar la solicitud de licencia',
    ],

    'expired_non_admin_blocked' => 'Este equipo no tiene una licencia activa. Solo el administrador puede iniciar sesión. Pida a su operador que renueve o instale una licencia.',

    'user_creating_single_admin' => 'El modo open-core permite un único usuario administrador. Instale una licencia para añadir más usuarios.',

    'modules_unavailable' => 'La instalación de módulos requiere una licencia activa.',
    'event_log_unavailable' => 'El registro de eventos solo está disponible con una licencia activa.',

    'disk_manager' => [
        'unlicensed_title' => 'Directorio del almacén',
        'vault_dir_label' => 'Ruta al directorio de la bóveda',
        'vault_dir_helper' => 'El modo open-core utiliza un directorio sin cifrar como bóveda. Predeterminado: /vault. Debe ser una ruta absoluta.',
        'save_button' => 'Guardar',
        'save_notif' => 'Directorio del almacén guardado.',
    ],

    'dashboard' => [
        'unlicensed_title' => 'Licencia',
        'unlicensed_value' => 'OPEN-CORE',
        'unlicensed_callout' => 'Instale una licencia para habilitar múltiples usuarios, grupos, módulos, ITSM, almacenes cifrados y registro de eventos.',
    ],

    'event' => [
        'request_generated' => 'Solicitud de licencia generada',
        'license_installed' => 'Licencia instalada',
        'license_expired' => 'Licencia caducada',
        'login_blocked' => 'Inicio de sesión bloqueado (sin licencia)',
    ],

];
