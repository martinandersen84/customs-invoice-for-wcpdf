/* Customs Invoice for WCPDF — order edit modal */
(function () {
	'use strict';

	var l10n = window.cifwL10n || {};

	function t(key, fallback, arg) {
		var s = l10n[key] || fallback;
		return arg !== undefined ? s.replace('%s', arg) : s;
	}

	function init() {
		var modal = document.getElementById('cifw-modal');
		var openBtn = document.querySelector('.cifw-open-modal');
		if (!modal || !openBtn) {
			return;
		}

		// escape the order form / metabox stacking context
		document.body.appendChild(modal);

		var ajaxurl = modal.dataset.ajaxurl;
		var orderId = modal.dataset.orderId;
		var nonce = modal.dataset.nonce;
		var currency = modal.dataset.currency || '';
		var feedback = document.getElementById('cifw-feedback');

		function open() {
			modal.style.display = 'flex';
			recalc();
		}

		function close() {
			modal.style.display = 'none';
			setFeedback('');
		}

		function setFeedback(msg, isError) {
			feedback.textContent = msg;
			feedback.classList.toggle('cifw-error', !!isError);
		}

		function recalc() {
			var total = 0;
			modal.querySelectorAll('.cifw-item').forEach(function (row) {
				var include = row.querySelector('[data-field="include"]').checked;
				var amount = parseFloat(row.querySelector('[data-field="total"]').value);
				if (include && !isNaN(amount)) {
					total += amount;
				}
			});
			var shipping = parseFloat(document.getElementById('cifw-shipping').value);
			if (!isNaN(shipping)) {
				total += shipping;
			}
			document.getElementById('cifw-total').textContent = total.toFixed(2) + ' ' + currency;
		}

		function collect() {
			var items = {};
			modal.querySelectorAll('.cifw-item').forEach(function (row) {
				items[row.dataset.itemId] = {
					include: row.querySelector('[data-field="include"]').checked ? 1 : 0,
					name: row.querySelector('[data-field="name"]').value,
					description: row.querySelector('[data-field="description"]').value,
					total: row.querySelector('[data-field="total"]').value
				};
			});
			return {
				document_title: document.getElementById('cifw-document-title').value,
				shipping: document.getElementById('cifw-shipping').value,
				items: items
			};
		}

		function post(action, withData) {
			var body = new FormData();
			body.append('action', action);
			body.append('order_id', orderId);
			body.append('nonce', nonce);
			if (withData) {
				body.append('data', JSON.stringify(collect()));
			}
			return fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json(); });
		}

		function busy(isBusy) {
			modal.querySelectorAll('.cifw-modal-footer .button').forEach(function (b) {
				b.disabled = isBusy;
			});
			modal.classList.toggle('cifw-busy', isBusy);
		}

		openBtn.addEventListener('click', open);
		modal.querySelector('.cifw-close').addEventListener('click', close);
		modal.querySelector('.cifw-cancel').addEventListener('click', close);
		modal.addEventListener('click', function (e) {
			if (e.target === modal) {
				close();
			}
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal.style.display !== 'none') {
				close();
			}
		});

		modal.addEventListener('input', recalc);
		modal.addEventListener('change', recalc);

		modal.querySelector('.cifw-save').addEventListener('click', function () {
			busy(true);
			setFeedback(t('saving', 'Saving…'));
			post('cifw_save', true).then(function (res) {
				busy(false);
				if (res.success) {
					setFeedback(res.data.message);
				} else {
					setFeedback((res.data && res.data.message) || t('saveError', 'Could not save.'), true);
				}
			}).catch(function () {
				busy(false);
				setFeedback(t('connError', 'Connection error.'), true);
			});
		});

		modal.querySelector('.cifw-generate').addEventListener('click', function () {
			busy(true);
			setFeedback(t('generating', 'Generating PDF…'));
			post('cifw_generate', true).then(function (res) {
				busy(false);
				if (res.success) {
					setFeedback(t('generated', 'PDF generated: %s', res.data.filename));
					// trigger the download without leaving the page
					var link = document.createElement('a');
					link.href = res.data.download_url;
					document.body.appendChild(link);
					link.click();
					link.remove();
					// refresh so the meta box shows the download link
					window.setTimeout(function () { window.location.reload(); }, 1500);
				} else {
					setFeedback((res.data && res.data.message) || t('generateError', 'Could not generate the PDF.'), true);
				}
			}).catch(function () {
				busy(false);
				setFeedback(t('connError', 'Connection error.'), true);
			});
		});

		modal.querySelector('.cifw-reset').addEventListener('click', function () {
			if (!window.confirm(t('resetConfirm', 'Reset the customs data to the original order values?'))) {
				return;
			}
			busy(true);
			post('cifw_reset', false).then(function (res) {
				busy(false);
				if (res.success) {
					window.location.reload();
				} else {
					setFeedback((res.data && res.data.message) || t('resetError', 'Could not reset.'), true);
				}
			}).catch(function () {
				busy(false);
				setFeedback(t('connError', 'Connection error.'), true);
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
