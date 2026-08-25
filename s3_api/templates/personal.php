<?php

declare(strict_types=1);

use OCP\Util;

// Loaded as a real script file: no inline <script>, therefore no CSP nonce
// needed (\OC::$server->getContentSecurityPolicyNonceManager() was removed
// from the server in Nextcloud 32+).
Util::addScript('s3_api', 'personal');

?>

<div id="s3api-settings" class="section">
	<h2><?php p($l->t('S3 API')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Expose Nextcloud folders as S3-compatible buckets.')); ?></p>

	<h3><?php p($l->t('Buckets')); ?></h3>

	<div id="s3api-bucket-list"></div>

	<div id="s3api-add-bucket" style="margin-top: 12px;">
		<input type="text" id="s3api-folder-path" placeholder="<?php p($l->t('Folder path (e.g. Documents/shared)')); ?>" style="width: 250px;" />
		<input type="text" id="s3api-bucket-name" placeholder="<?php p($l->t('Bucket name (e.g. my-docs)')); ?>" style="width: 200px;" />
		<button id="s3api-add-bucket-btn" class="primary"><?php p($l->t('Add Bucket')); ?></button>
	</div>

	<div id="s3api-secret-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
		<div style="background:var(--color-main-background, #fff); padding:24px; border-radius:8px; max-width:600px; width:90%; margin:auto; margin-top:15vh;">
			<h3><?php p($l->t('New API Key Created')); ?></h3>
			<p><strong><?php p($l->t('Save these credentials now. The secret key will not be shown again!')); ?></strong></p>
			<div style="margin:12px 0;">
				<label for="s3api-modal-access-key"><?php p($l->t('Access Key:')); ?></label>
				<input type="text" id="s3api-modal-access-key" readonly style="width:100%; font-family:monospace;" />
			</div>
			<div style="margin:12px 0;">
				<label for="s3api-modal-secret-key"><?php p($l->t('Secret Key:')); ?></label>
				<input type="text" id="s3api-modal-secret-key" readonly style="width:100%; font-family:monospace;" />
			</div>
			<button id="s3api-modal-close" class="primary"><?php p($l->t('I have saved these credentials')); ?></button>
		</div>
	</div>
</div>
