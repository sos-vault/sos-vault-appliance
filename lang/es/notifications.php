<?php

return [
    'title' => 'Notificaciones',
    'no_notifications' => 'Sin notificaciones.',
    'mark_all_read' => 'Marcar todo como leído',

    // Notifications page (pages/notifications/index.blade.php)
    'page_heading' => 'Notificaciones',
    'page_description' => 'Tus notificaciones actuales.',
    'page_empty_heading' => 'No se encontraron notificaciones',
    'page_empty_description' => 'Todas las notificaciones se mostrarán aquí.',
    'page_mark_as_read' => 'Marcar como leída',
    'page_from' => 'De: :name',

    // Announcements page (pages/announcements/)
    'ann_heading' => 'Anuncios',
    'ann_description' => 'Comunicaciones generales del sistema.',
    'ann_empty_heading' => 'No se encontraron anuncios',
    'ann_empty_description' => 'Todos los anuncios generales se mostrarán aquí.',
    'ann_view_all' => 'Ver todos los anuncios',
    'ann_written_on' => 'Escrito el :date',

    // -------------------------------------------------------------------------
    // Mensajes de notificación al usuario (helper notifyUser)
    // -------------------------------------------------------------------------

    // Ciclo de vida de la bóveda — listener de redimensionado (ResizeVault)
    'vault_no_associated' => 'No hay bóveda asociada al usuario :username.',
    'vault_not_found' => 'No se encontró la bóveda para :username.',
    'vault_not_open' => 'La bóveda no está abierta para :username.',
    'vault_expand_failed' => 'La expansión de la bóveda a :size falló.',
    'vault_expand_success' => 'La bóveda se expandió correctamente a :size.',
    'vault_shrink_failed' => 'La reducción de la bóveda a :size falló.',
    'vault_shrink_success' => 'La bóveda se redujo correctamente a :size.',
    'vault_adjust_failed' => 'El ajuste de la bóveda falló.',
    'vault_adjust_success' => 'El ajuste de la bóveda fue exitoso.',
    'vault_busy' => 'Su bóveda está ocupada actualmente. Por favor, inténtelo de nuevo en unos minutos.',
    'vault_busy_stale' => 'Por favor, inténtelo de nuevo.',
    'vault_reopen_failed' => ' No se pudo reabrir su bóveda.',
    'vault_size_changed' => ' El tamaño de la bóveda cambió de :old a :new.',
    'vault_tokens_adjusted' => ' Saldo de tokens ajustado de :old a :new.',
    'vault_resize_unexpected' => 'El redimensionamiento de la bóveda falló inesperadamente. Por favor, contacte al soporte.',

    // Acciones de administrador sobre vaults (panel Filament — UserResource)
    'admin_vault_expand_success' => 'Su bóveda fue expandida exitosamente en :size MB por el administrador del sistema.',
    'admin_vault_expand_failed' => 'No fue posible expandir su bóveda en :size MB por el administrador del sistema.',
    'admin_vault_shrink_success' => 'Su bóveda fue reducida exitosamente en :size MB por el administrador del sistema.',
    'admin_vault_shrink_failed' => 'No fue posible reducir su bóveda en :size MB por el administrador del sistema.',

    // Programador — reducción programada
    'scheduler_shrink_no_space' => 'La reducción programada de la bóveda de :size no pudo ejecutarse: el espacio libre (:free) está por debajo del mínimo requerido (:required). Por favor, libere espacio y la reducción se reintentará automáticamente.',

    // Carga y descompresión (VaultTools)
    'decrypt_complete' => 'Descifrado completo. Nombre del archivo tras el descifrado: :filename',
    'extract_complete' => 'Extracción completa. Eliminando archivo original: :filename',
    'case_create_failed' => 'No se pudo crear el caso asociado.',
    'case_created' => 'Caso asociado :case serial :serial creado correctamente.',

    // Mensajes de error de desempaquetado (doDecryptAndExtract / decrypt / xtract)
    'unpack_file_not_found' => 'No se encontró el archivo. No es posible continuar.',
    'unpack_decrypt_failed' => 'El descifrado falló. Por favor, verifique que la contraseña sea correcta.',
    'unpack_decrypt_pass_error' => 'No se pudo descifrar la contraseña de descifrado.',
    'unpack_decrypt_pass_empty' => 'La contraseña de descifrado está vacía.',
    'unpack_file_decrypt_failed' => 'El archivo no pudo ser descifrado.',
    'unpack_size_calc_failed' => 'No se pudo calcular el tamaño de extracción esperado.',
    'unpack_count_calc_failed' => 'No se pudo calcular el número de archivos esperado.',
    'unpack_no_space' => 'No hay suficiente espacio en la bóveda para extraer el archivo.',
    'unpack_dir_unknown' => 'No se pudo determinar el directorio destino de la extracción.',
    'unpack_extract_failed' => 'El comando de extracción falló.',
    'unpack_extract_unknown_dir' => 'La extracción se realizó en un directorio inesperado.',
    'unpack_dir_exists' => 'El directorio destino ya existe. Se cancela la extracción.',
    'unpack_dir_not_found' => 'No se encontró el directorio tras la creación del caso.',
    'unpack_dir_not_readable' => 'No se pudo leer el directorio tras la creación del caso.',
    'unpack_extraction_complete' => 'Extracción del archivo completada.',
    'unpack_kept_in_vault' => ':reason El archivo se conserva en la bóveda.',
    'unpack_decrypt_no_key' => 'El descifrado del archivo falló. No es posible continuar.',

    // Carga y descompresión vía API (AuthController)
    'upload_auto_failed' => 'La carga automática falló porque: :reason',
    'upload_file_success' => 'Archivo cargado correctamente: :filename',
    'upload_auto_success' => ':message :filename',
    'unpack_auto_failed' => 'La descompresión automática falló porque: :reason',
    'upload_success_unpack_failed' => 'El archivo se cargó correctamente pero la descompresión automática falló porque: :reason',
    'file_upload_unpack_failed' => 'El archivo :filename se cargó correctamente pero la descompresión automática falló: :reason. El archivo está disponible en la bóveda.',
    'upload_auto_complete' => 'Carga automática exitosa. :details',

    // Integración JIRA (JIRADownloader)
    'jira_download_failed' => 'La descarga del adjunto :filename del asunto :issueid falló. :error',
    'jira_download_success' => 'La descarga del adjunto :filename del asunto :issueid fue exitosa.',

    // Facturación y suscripciones (SubscriptionController)
    'subscription_product_error' => 'Error al localizar ese ID de producto de suscripción. Por favor, contáctenos si cree que esto es incorrecto.',
    'transaction_error' => 'Error al procesar la transacción. Por favor, inténtelo de nuevo.',
    'wrong_plan_disk_expand' => 'Tipo de plan incorrecto para la expansión de disco.',
    'wrong_plan_disk_cancel' => 'Tipo de plan incorrecto para la cancelación de disco.',
    'wrong_plan_disk_schedule' => 'Tipo de plan incorrecto para la cancelación programada de disco.',
    'wrong_plan_tokens' => 'Tipo de plan incorrecto para la compra de tokens.',
    'vault_expand_requested' => 'Se solicitó la expansión de la bóveda de :size. Esta operación puede tardar varios minutos.',
    'vault_shrink_requested' => 'Se solicitó la reducción de la bóveda de :size. Esta operación puede tardar varios minutos.',
    'vault_shrink_scheduled' => 'Reducción de la bóveda de :size programada para :date.',
    'vault_shrink_no_disk' => 'La solicitud de reducción de la bóveda falló. No se encontró ninguna expansión de disco activa.',
    'tokens_purchase_success' => 'La compra de tokens fue exitosa. Su nuevo saldo de tokens es :tokens.',

    // Webhooks de Paddle (WebhookController)
    'subscription_payment_received' => 'Se recibió el pago de su suscripción. Nuevo saldo de tokens: :tokens.',
    'subscription_cancelled' => 'Su suscripción ha sido cancelada.',
    'disk_expansion_cancelled' => 'Su suscripción de expansión de disco ha sido cancelada.',

    // Cambio de plan (Checkout::switchPlan)
    'plan_upgraded' => 'Su plan ha sido actualizado a :plan. Su bóveda y tokens están siendo ajustados.',
    'plan_downgraded_scheduled' => 'Su plan ha sido cambiado a :plan. Su bóveda será reducida el :date.',
    'plan_downgrade_blocked' => 'No es posible bajar de plan mientras tenga expansiones de disco activas. Por favor, cancélelas primero.',
    'plan_switch_error' => 'Hubo un error al cambiar su plan. Por favor, inténtelo de nuevo.',
];
