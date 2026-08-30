<?php

return [
    'title' => 'Notifications',
    'no_notifications' => 'No notifications.',
    'mark_all_read' => 'Mark all as read',

    // Notifications page (pages/notifications/index.blade.php)
    'page_heading' => 'Notifications',
    'page_description' => 'Your current notifications.',
    'page_empty_heading' => 'No notification found',
    'page_empty_description' => 'All notification will be shown here.',
    'page_mark_as_read' => 'Mark as read',
    'page_from' => 'From: :name',

    // Announcements page (pages/announcements/)
    'ann_heading' => 'Announcements',
    'ann_description' => 'General system communications.',
    'ann_empty_heading' => 'No announcements found',
    'ann_empty_description' => 'All general announcements will be shown here.',
    'ann_view_all' => 'View All Announcements',
    'ann_written_on' => 'Written on :date',

    // -------------------------------------------------------------------------
    // User notification messages (notifyUser helper)
    // -------------------------------------------------------------------------

    // Vault lifecycle — resize listener (ResizeVault)
    'vault_no_associated' => 'No vault associated to :username.',
    'vault_not_found' => 'No vault found for :username.',
    'vault_not_open' => 'Vault is not open for :username.',
    'vault_expand_failed' => 'Vault expansion to :size failed.',
    'vault_expand_success' => 'Vault successfully expanded to :size.',
    'vault_shrink_failed' => 'Vault shrink to :size failed.',
    'vault_shrink_success' => 'Vault successfully shrunk to :size.',
    'vault_adjust_failed' => 'Vault adjustment failed.',
    'vault_adjust_success' => 'Vault adjustment was successful.',
    'vault_busy' => 'Your vault is currently busy. Please try again in a couple of minutes.',
    'vault_busy_stale' => 'Please try again.',
    'vault_reopen_failed' => ' Could not re-open your vault.',
    'vault_size_changed' => ' Vault size changed from :old to :new.',
    'vault_tokens_adjusted' => ' Token balance adjusted from :old to :new.',
    'vault_resize_unexpected' => 'Vault resize operation failed unexpectedly. Please contact support.',

    // Admin vault actions (UserResource Filament panel)
    'admin_vault_expand_success' => 'Your vault was successfully expanded in :size MB by the system administrator.',
    'admin_vault_expand_failed' => 'Your vault could not be expanded in :size MB by the system administrator.',
    'admin_vault_shrink_success' => 'Your vault was successfully shrunk in :size MB by the system administrator.',
    'admin_vault_shrink_failed' => 'Your vault could not be shrunk in :size MB by the system administrator.',

    // Scheduler — scheduled shrink
    'scheduler_shrink_no_space' => 'Scheduled vault shrink of :size could not run: free space (:free) is below the required minimum (:required). Please free up space and the shrink will be retried automatically.',

    // Upload & unpack (VaultTools)
    'decrypt_complete' => 'Decryption complete. Filename after decryption: :filename',
    'extract_complete' => 'Extraction complete. Removing original file: :filename',
    'case_create_failed' => 'Could not create the associated case.',
    'case_created' => 'Associated case :case serial :serial created correctly.',

    // Unpack error messages (doDecryptAndExtract / decrypt / xtract)
    'unpack_file_not_found' => "Couldn't find file. Cannot continue.",
    'unpack_decrypt_failed' => 'Decryption failed. Please verify that the passphrase is correct.',
    'unpack_decrypt_pass_error' => 'Could not decrypt the decryption passphrase.',
    'unpack_decrypt_pass_empty' => 'Decryption passphrase is empty.',
    'unpack_file_decrypt_failed' => 'File could not be decrypted.',
    'unpack_size_calc_failed' => 'Could not calculate the expected extraction size.',
    'unpack_count_calc_failed' => 'Could not calculate the expected file count.',
    'unpack_no_space' => 'Not enough space in the vault to extract the file.',
    'unpack_dir_unknown' => 'Could not determine the extraction target directory.',
    'unpack_extract_failed' => 'Extraction command failed.',
    'unpack_extract_unknown_dir' => 'Extraction landed in an unexpected directory.',
    'unpack_dir_exists' => 'Target directory already exists. Aborting extraction.',
    'unpack_dir_not_found' => 'Directory not found after case creation.',
    'unpack_dir_not_readable' => 'Directory could not be read after case creation.',
    'unpack_extraction_complete' => 'File extraction complete.',
    'unpack_kept_in_vault' => ':reason File was kept in vault.',
    'unpack_decrypt_no_key' => 'File decryption failed. Cannot continue.',

    // API upload & unpack (AuthController)
    'upload_auto_failed' => 'The automatic upload failed because: :reason',
    'upload_file_success' => 'File upload success: :filename',
    'upload_auto_success' => ':message :filename',
    'unpack_auto_failed' => 'The automatic unpack failed because: :reason',
    'upload_success_unpack_failed' => 'File uploaded correctly but the automatic unpack failed because: :reason',
    'file_upload_unpack_failed' => 'File :filename was uploaded correctly but the automatic unpack failed: :reason. File is available in vault.',
    'upload_auto_complete' => 'Automatic file upload success. :details',

    // JIRA integration (JIRADownloader)
    'jira_download_failed' => 'Issue :issueid attachment :filename download failed. :error',
    'jira_download_success' => 'Issue :issueid attachment :filename download successful.',

    // Billing & subscription (SubscriptionController)
    'subscription_product_error' => 'Error locating that subscription product id. Please contact us if you think this is incorrect.',
    'transaction_error' => 'Error processing the transaction. Please try again.',
    'wrong_plan_disk_expand' => 'Wrong plan type for disk expansion.',
    'wrong_plan_disk_cancel' => 'Wrong plan type for disk cancellation.',
    'wrong_plan_disk_schedule' => 'Wrong plan type for scheduled disk cancellation.',
    'wrong_plan_tokens' => 'Wrong plan type for token purchase.',
    'vault_expand_requested' => 'Vault :size expansion requested. This operation can take several minutes.',
    'vault_shrink_requested' => 'Vault :size shrink requested. This operation can take several minutes.',
    'vault_shrink_scheduled' => 'Vault :size shrink scheduled for :date.',
    'vault_shrink_no_disk' => 'Vault shrink request failed. No active disk expansion found.',
    'tokens_purchase_success' => 'The token purchase was successful. Your new token balance is :tokens tokens.',

    // Paddle webhooks (WebhookController)
    'subscription_payment_received' => 'Your subscription payment was received. New token balance: :tokens.',
    'subscription_cancelled' => 'Your subscription has been cancelled.',
    'disk_expansion_cancelled' => 'Your disk expansion subscription has been cancelled.',

    // Plan switching (Checkout::switchPlan)
    'plan_upgraded' => 'Your plan has been upgraded to :plan. Your vault and tokens are being adjusted.',
    'plan_downgraded_scheduled' => 'Your plan has been switched to :plan. Your vault will be reduced on :date.',
    'plan_downgrade_blocked' => 'Cannot downgrade your plan while you have active disk expansions. Please cancel them first.',
    'plan_switch_error' => 'There was an error switching your plan. Please try again.',

    // Extra seat add-on
    'wrong_plan_seats' => 'Wrong plan type for seat purchase.',
    'seat_addon_requires_team' => 'Extra seats are only available for Team, Enterprise and Self-hosted plans.',
    'seat_addon_no_group' => 'No team group found for your account.',
    'seat_purchase_success' => ':qty extra seat(s) added. Your team can now have up to :total members.',
    'seat_cancelled' => ':qty seat(s) removed. :suspended account(s) have been suspended.',
];
