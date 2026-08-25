/**
 * S3 API personal settings.
 *
 * Loaded via Util::addScript(), so no inline <script> and therefore no CSP
 * nonce is required.
 */
(function () {
	'use strict';

	var APP = 's3_api';

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('s3api-settings');
		if (!root) {
			return;
		}

		var baseUrl = OC.generateUrl('/apps/s3_api/api/v1');

		function notify(message) {
			if (window.OC && OC.Notification && OC.Notification.showTemporary) {
				OC.Notification.showTemporary(message);
			} else {
				window.alert(message);
			}
		}

		function headers(extra) {
			var h = { requesttoken: OC.requestToken };
			if (extra) {
				Object.keys(extra).forEach(function (k) {
					h[k] = extra[k];
				});
			}
			return h;
		}

		/** Build an element with text content set safely (no HTML injection). */
		function el(tag, opts) {
			var node = document.createElement(tag);
			opts = opts || {};
			if (opts.text !== undefined) {
				node.textContent = opts.text;
			}
			if (opts.className) {
				node.className = opts.className;
			}
			if (opts.style) {
				node.setAttribute('style', opts.style);
			}
			if (opts.attrs) {
				Object.keys(opts.attrs).forEach(function (k) {
					node.setAttribute(k, opts.attrs[k]);
				});
			}
			(opts.children || []).forEach(function (c) {
				node.appendChild(c);
			});
			return node;
		}

		function loadBuckets() {
			fetch(baseUrl + '/buckets', { headers: headers() })
				.then(function (r) {
					return r.json();
				})
				.then(function (buckets) {
					var list = document.getElementById('s3api-bucket-list');
					list.textContent = '';

					if (!buckets.length) {
						list.appendChild(
							el('p', {
								children: [
									el('em', {
										text: t(APP, 'No buckets configured yet.'),
									}),
								],
							})
						);
						return;
					}

					buckets.forEach(function (bucket) {
						list.appendChild(renderBucket(bucket));
						loadKeys(bucket.id);
					});
				})
				.catch(function () {
					notify(t(APP, 'Failed to load buckets.'));
				});
		}

		function renderBucket(bucket) {
			var header = el('div', {
				style: 'display:flex; justify-content:space-between; align-items:center;',
				children: [
					el('div', {
						children: [
							el('strong', { text: bucket.bucketName }),
							document.createTextNode(' \u2192 '),
							el('code', { text: bucket.folderPath }),
						],
					}),
					el('button', {
						text: t(APP, 'Delete'),
						className: 's3api-delete-bucket error',
						attrs: { 'data-id': bucket.id },
					}),
				],
			});

			var keys = el('div', {
				className: 's3api-keys',
				style: 'margin-top:8px;',
				attrs: { 'data-bucket-id': bucket.id },
				children: [el('em', { text: t(APP, 'Loading keys\u2026') })],
			});

			var permission = el('select', {
				className: 's3api-key-permission',
				style: 'width:auto;',
				children: [
					el('option', {
						text: t(APP, 'Read Only'),
						attrs: { value: 'readonly' },
					}),
					el('option', {
						text: t(APP, 'Read/Write'),
						attrs: { value: 'readwrite' },
					}),
				],
			});

			var controls = el('div', {
				style: 'margin-top:8px;',
				children: [
					permission,
					el('input', {
						className: 's3api-key-label',
						style: 'width:150px;',
						attrs: {
							type: 'text',
							placeholder: t(APP, 'Label (optional)'),
						},
					}),
					el('button', {
						text: t(APP, 'Create API Key'),
						className: 's3api-add-key',
						attrs: { 'data-bucket-id': bucket.id },
					}),
				],
			});

			return el('div', {
				className: 's3api-bucket',
				style:
					'border:1px solid var(--color-border, #ccc); border-radius:8px; padding:12px; margin:8px 0;',
				attrs: { 'data-id': bucket.id },
				children: [header, keys, controls],
			});
		}

		function loadKeys(bucketId) {
			fetch(baseUrl + '/buckets/' + bucketId + '/keys', { headers: headers() })
				.then(function (r) {
					return r.json();
				})
				.then(function (keys) {
					var container = document.querySelector(
						'.s3api-keys[data-bucket-id="' + bucketId + '"]'
					);
					if (!container) {
						return;
					}
					container.textContent = '';

					if (!keys.length) {
						container.appendChild(
							el('em', {
								text: t(APP, 'No API keys yet.'),
								style: 'color:var(--color-text-lighter, #999);',
							})
						);
						return;
					}

					var headRow = el('tr', {
						children: [
							t(APP, 'Access Key'),
							t(APP, 'Permission'),
							t(APP, 'Label'),
							t(APP, 'Created'),
							'',
						].map(function (label) {
							return el('th', { text: label });
						}),
					});

					var body = el(
						'tbody',
						{
							children: keys.map(function (key) {
								return el('tr', {
									children: [
										el('td', {
											children: [el('code', { text: key.accessKey })],
										}),
										el('td', { text: key.permission }),
										el('td', { text: key.label || '' }),
										el('td', {
											text: key.createdAt
												? key.createdAt.substring(0, 10)
												: '',
										}),
										el('td', {
											children: [
												el('button', {
													text: t(APP, 'Delete'),
													className: 's3api-delete-key error',
													attrs: {
														'data-bucket-id': bucketId,
														'data-key-id': key.id,
													},
												}),
											],
										}),
									],
								});
							}),
						}
					);

					container.appendChild(
						el('table', {
							style: 'width:100%; font-size:0.9em;',
							children: [el('thead', { children: [headRow] }), body],
						})
					);
				})
				.catch(function () {
					notify(t(APP, 'Failed to load API keys.'));
				});
		}

		// ---- add bucket ------------------------------------------------

		document
			.getElementById('s3api-add-bucket-btn')
			.addEventListener('click', function () {
				var folderPath = document
					.getElementById('s3api-folder-path')
					.value.trim();
				var bucketName = document
					.getElementById('s3api-bucket-name')
					.value.trim();

				if (!folderPath || !bucketName) {
					notify(t(APP, 'Please fill in both folder path and bucket name.'));
					return;
				}

				fetch(baseUrl + '/buckets', {
					method: 'POST',
					headers: headers({ 'Content-Type': 'application/json' }),
					body: JSON.stringify({
						folderPath: folderPath,
						bucketName: bucketName,
					}),
				})
					.then(function (r) {
						return r.json().then(function (d) {
							return { ok: r.ok, data: d };
						});
					})
					.then(function (res) {
						if (res.ok) {
							document.getElementById('s3api-folder-path').value = '';
							document.getElementById('s3api-bucket-name').value = '';
							loadBuckets();
						} else {
							notify(
								(res.data && res.data.error) ||
									t(APP, 'Failed to create bucket.')
							);
						}
					})
					.catch(function () {
						notify(t(APP, 'Failed to create bucket.'));
					});
			});

		// ---- delegated handlers ----------------------------------------

		root.addEventListener('click', function (e) {
			var target = e.target;

			if (target.classList.contains('s3api-delete-bucket')) {
				var bucketId = target.getAttribute('data-id');
				if (!window.confirm(t(APP, 'Delete this bucket and all its API keys?'))) {
					return;
				}
				fetch(baseUrl + '/buckets/' + bucketId, {
					method: 'DELETE',
					headers: headers(),
				}).then(loadBuckets);
				return;
			}

			if (target.classList.contains('s3api-add-key')) {
				var addBucketId = target.getAttribute('data-bucket-id');
				var container = target.parentElement;
				var permission = container.querySelector(
					'.s3api-key-permission'
				).value;
				var labelInput = container.querySelector('.s3api-key-label');

				fetch(baseUrl + '/buckets/' + addBucketId + '/keys', {
					method: 'POST',
					headers: headers({ 'Content-Type': 'application/json' }),
					body: JSON.stringify({
						permission: permission,
						label: labelInput.value.trim(),
					}),
				})
					.then(function (r) {
						return r.json().then(function (d) {
							return { ok: r.ok, data: d };
						});
					})
					.then(function (res) {
						if (res.ok) {
							document.getElementById('s3api-modal-access-key').value =
								res.data.accessKey;
							document.getElementById('s3api-modal-secret-key').value =
								res.data.secretKey;
							document.getElementById('s3api-secret-modal').style.display =
								'flex';
							labelInput.value = '';
							loadKeys(addBucketId);
						} else {
							notify(
								(res.data && res.data.error) || t(APP, 'Failed to create key.')
							);
						}
					})
					.catch(function () {
						notify(t(APP, 'Failed to create key.'));
					});
				return;
			}

			if (target.classList.contains('s3api-delete-key')) {
				var keyBucketId = target.getAttribute('data-bucket-id');
				var keyId = target.getAttribute('data-key-id');
				if (!window.confirm(t(APP, 'Delete this API key?'))) {
					return;
				}
				fetch(baseUrl + '/buckets/' + keyBucketId + '/keys/' + keyId, {
					method: 'DELETE',
					headers: headers(),
				}).then(function () {
					loadKeys(keyBucketId);
				});
			}
		});

		document
			.getElementById('s3api-modal-close')
			.addEventListener('click', function () {
				document.getElementById('s3api-secret-modal').style.display = 'none';
			});

		loadBuckets();
	});
})();
