<?php

use OCP\Util;

style('s3_api', []);
// No external CSS needed, we use inline styles for simplicity

?>

<div id="s3api-settings" class="section">
	<h2>S3 API</h2>
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
				<label><?php p($l->t('Access Key:')); ?></label>
				<input type="text" id="s3api-modal-access-key" readonly style="width:100%; font-family:monospace;" />
			</div>
			<div style="margin:12px 0;">
				<label><?php p($l->t('Secret Key:')); ?></label>
				<input type="text" id="s3api-modal-secret-key" readonly style="width:100%; font-family:monospace;" />
			</div>
			<button id="s3api-modal-close" class="primary"><?php p($l->t('I have saved these credentials')); ?></button>
		</div>
	</div>
</div>

<script nonce="<?php p(\OC::$server->getContentSecurityPolicyNonceManager()->getNonce()); ?>">
document.addEventListener('DOMContentLoaded', function() {
	'use strict';

	var baseUrl = OC.generateUrl('/apps/s3_api/api/v1');

	function escapeHtml(text) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(text));
		return div.innerHTML;
	}

	function loadBuckets() {
		fetch(baseUrl + '/buckets', {
			headers: { 'requesttoken': OC.requestToken }
		})
		.then(function(r) { return r.json(); })
		.then(function(buckets) {
			var html = '';
			if (buckets.length === 0) {
				html = '<p><em><?php p($l->t('No buckets configured yet.')); ?></em></p>';
			}
			buckets.forEach(function(bucket) {
				html += '<div class="s3api-bucket" data-id="' + bucket.id + '" style="border:1px solid var(--color-border, #ccc); border-radius:8px; padding:12px; margin:8px 0;">';
				html += '<div style="display:flex; justify-content:space-between; align-items:center;">';
				html += '<div><strong>' + escapeHtml(bucket.bucketName) + '</strong> &rarr; <code>' + escapeHtml(bucket.folderPath) + '</code></div>';
				html += '<button class="s3api-delete-bucket error" data-id="' + bucket.id + '"><?php p($l->t('Delete')); ?></button>';
				html += '</div>';
				html += '<div class="s3api-keys" data-bucket-id="' + bucket.id + '" style="margin-top:8px;">';
				html += '<em><?php p($l->t('Loading keys...')); ?></em>';
				html += '</div>';
				html += '<div style="margin-top:8px;">';
				html += '<select class="s3api-key-permission" style="width:auto;">';
				html += '<option value="readonly"><?php p($l->t('Read Only')); ?></option>';
				html += '<option value="readwrite"><?php p($l->t('Read/Write')); ?></option>';
				html += '</select>';
				html += '<input type="text" class="s3api-key-label" placeholder="<?php p($l->t('Label (optional)')); ?>" style="width:150px;" />';
				html += '<button class="s3api-add-key" data-bucket-id="' + bucket.id + '"><?php p($l->t('Create API Key')); ?></button>';
				html += '</div>';
				html += '</div>';
			});
			document.getElementById('s3api-bucket-list').innerHTML = html;

			// Load keys for each bucket
			buckets.forEach(function(bucket) {
				loadKeys(bucket.id);
			});
		});
	}

	function loadKeys(bucketId) {
		fetch(baseUrl + '/buckets/' + bucketId + '/keys', {
			headers: { 'requesttoken': OC.requestToken }
		})
		.then(function(r) { return r.json(); })
		.then(function(keys) {
			var container = document.querySelector('.s3api-keys[data-bucket-id="' + bucketId + '"]');
			if (!container) return;

			if (keys.length === 0) {
				container.innerHTML = '<em style="color:var(--color-text-lighter, #999);"><?php p($l->t('No API keys yet.')); ?></em>';
				return;
			}

			var html = '<table style="width:100%; font-size:0.9em;"><thead><tr>';
			html += '<th><?php p($l->t('Access Key')); ?></th>';
			html += '<th><?php p($l->t('Permission')); ?></th>';
			html += '<th><?php p($l->t('Label')); ?></th>';
			html += '<th><?php p($l->t('Created')); ?></th>';
			html += '<th></th>';
			html += '</tr></thead><tbody>';
			keys.forEach(function(key) {
				html += '<tr>';
				html += '<td><code>' + escapeHtml(key.accessKey) + '</code></td>';
				html += '<td>' + escapeHtml(key.permission) + '</td>';
				html += '<td>' + escapeHtml(key.label || '') + '</td>';
				html += '<td>' + escapeHtml(key.createdAt ? key.createdAt.substring(0, 10) : '') + '</td>';
				html += '<td><button class="s3api-delete-key error" data-bucket-id="' + bucketId + '" data-key-id="' + key.id + '"><?php p($l->t('Delete')); ?></button></td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			container.innerHTML = html;
		});
	}

	// Add bucket
	document.getElementById('s3api-add-bucket-btn').addEventListener('click', function() {
		var folderPath = document.getElementById('s3api-folder-path').value.trim();
		var bucketName = document.getElementById('s3api-bucket-name').value.trim();
		if (!folderPath || !bucketName) {
			OC.Notification.showTemporary('<?php p($l->t('Please fill in both folder path and bucket name.')); ?>');
			return;
		}
		fetch(baseUrl + '/buckets', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'requesttoken': OC.requestToken,
			},
			body: JSON.stringify({ folderPath: folderPath, bucketName: bucketName }),
		})
		.then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
		.then(function(res) {
			if (res.ok) {
				document.getElementById('s3api-folder-path').value = '';
				document.getElementById('s3api-bucket-name').value = '';
				loadBuckets();
			} else {
				OC.Notification.showTemporary(res.data.error || '<?php p($l->t('Failed to create bucket.')); ?>');
			}
		});
	});

	// Event delegation for dynamic elements
	document.getElementById('s3api-settings').addEventListener('click', function(e) {
		// Delete bucket
		if (e.target.classList.contains('s3api-delete-bucket')) {
			var id = e.target.getAttribute('data-id');
			if (!confirm('<?php p($l->t('Delete this bucket and all its API keys?')); ?>')) return;
			fetch(baseUrl + '/buckets/' + id, {
				method: 'DELETE',
				headers: { 'requesttoken': OC.requestToken },
			}).then(function() { loadBuckets(); });
		}

		// Create key
		if (e.target.classList.contains('s3api-add-key')) {
			var bucketId = e.target.getAttribute('data-bucket-id');
			var container = e.target.parentElement;
			var permission = container.querySelector('.s3api-key-permission').value;
			var label = container.querySelector('.s3api-key-label').value.trim();

			fetch(baseUrl + '/buckets/' + bucketId + '/keys', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'requesttoken': OC.requestToken,
				},
				body: JSON.stringify({ permission: permission, label: label }),
			})
			.then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
			.then(function(res) {
				if (res.ok) {
					// Show secret key modal
					document.getElementById('s3api-modal-access-key').value = res.data.accessKey;
					document.getElementById('s3api-modal-secret-key').value = res.data.secretKey;
					document.getElementById('s3api-secret-modal').style.display = 'flex';
					container.querySelector('.s3api-key-label').value = '';
					loadKeys(bucketId);
				} else {
					OC.Notification.showTemporary(res.data.error || '<?php p($l->t('Failed to create key.')); ?>');
				}
			});
		}

		// Delete key
		if (e.target.classList.contains('s3api-delete-key')) {
			var bucketId = e.target.getAttribute('data-bucket-id');
			var keyId = e.target.getAttribute('data-key-id');
			if (!confirm('<?php p($l->t('Delete this API key?')); ?>')) return;
			fetch(baseUrl + '/buckets/' + bucketId + '/keys/' + keyId, {
				method: 'DELETE',
				headers: { 'requesttoken': OC.requestToken },
			}).then(function() { loadKeys(bucketId); });
		}
	});

	// Close modal
	document.getElementById('s3api-modal-close').addEventListener('click', function() {
		document.getElementById('s3api-secret-modal').style.display = 'none';
	});

	// Initial load
	loadBuckets();
});
</script>
