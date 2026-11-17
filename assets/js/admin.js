/* Customs Invoice for WCPDF — order edit modal */
(function () {
	'use strict';

	var l10n = window.cifwL10n || {};

	function t(key, fallback, arg) {
		var s = l10n[key] || fallback;
		return arg !== undefined ? s.replace('%s', arg) : s;
	}

	/*
	 * Proportion mode — mirrors includes/cifw-amounts.php step by step, on
	 * integer cents, so the preview and the PDF always show the same figures.
	 */

	// half up, as cifw_scale_cents()
	function scaleCents(cents, percent) {
		return Math.floor((cents * percent + 50) / 100);
	}

	// largest remainder method, as cifw_scale_lines(); lines = [{id, cents}]
	function scaleLines(lines, percent) {
		var scaled = {};
		var remainders = [];
		var sum = 0;
		var sumFloor = 0;

		lines.forEach(function (line, position) {
			var exact = line.cents * percent;
			var floor = Math.floor(exact / 100);
			scaled[line.id] = floor;
			remainders.push([exact - 100 * floor, position, line.id]);
			sum += line.cents;
			sumFloor += floor;
		});

		remainders.sort(function (a, b) {
			return a[0] === b[0] ? a[1] - b[1] : b[0] - a[0];
		});

		var missing = scaleCents(sum, percent) - sumFloor;
		for (var i = 0; i < missing && i < remainders.length; i++) {
			scaled[remainders[i][2]] += 1;
		}
		return scaled;
	}

	// as cifw_compute_proportional_amounts(); includedIds in display order
	function computeProportional(base, includedIds, percent) {
		var lines = includedIds.map(function (id) {
			return { id: id, cents: base.items[id] ? base.items[id].amount : 0 };
		});
		var items = scaleLines(lines, percent);
		var allIncluded = includedIds.length === Object.keys(base.items).length;
		var shippingTaxes = base.shipping.taxes || {};

		var taxes = base.rates.map(function (rate) {
			var cents;
			if (allIncluded) {
				cents = rate.full;
			} else {
				cents = shippingTaxes[rate.id] || 0;
				includedIds.forEach(function (id) {
					var itemTaxes = (base.items[id] && base.items[id].taxes) || {};
					cents += itemTaxes[rate.id] || 0;
				});
			}
			return { label: rate.label, amount: scaleCents(cents, percent) };
		});

		return {
			items: items,
			shipping: scaleCents(base.shipping.amount, percent),
			taxes: taxes
		};
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
		var proportionSelect = document.getElementById('cifw-proportion');
		var proportionHint = document.getElementById('cifw-proportion-hint');
		var shippingInput = document.getElementById('cifw-shipping');
		var totalRow = modal.querySelector('.cifw-total-row');
		var base = null;

		try {
			base = JSON.parse(modal.dataset.base || 'null');
		} catch (e) {
			base = null;
		}
		if (!base) {
			// without the order amounts only custom values can work
			proportionSelect.value = 'custom';
			proportionSelect.disabled = true;
		}

		function money(cents) {
			return (cents / 100).toFixed(2);
		}

		function isCustom() {
			return proportionSelect.value === 'custom';
		}

		function open() {
			modal.style.display = 'flex';
			applyMode();
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

		function setTaxRows(rows) {
			modal.querySelectorAll('.cifw-tax-row').forEach(function (row) {
				row.remove();
			});
			rows.forEach(function (tax) {
				var tr = document.createElement('tr');
				tr.className = 'cifw-tax-row';
				var label = document.createElement('td');
				label.colSpan = 3;
				label.className = 'cifw-right';
				label.textContent = tax.label;
				var value = document.createElement('td');
				value.className = 'cifw-col-total';
				value.textContent = money(tax.amount) + ' ' + currency;
				tr.appendChild(label);
				tr.appendChild(value);
				totalRow.parentNode.insertBefore(tr, totalRow);
			});
		}

		function recalcProportional(percent) {
			var includedIds = [];
			modal.querySelectorAll('.cifw-item').forEach(function (row) {
				if (row.querySelector('[data-field="include"]').checked) {
					includedIds.push(row.dataset.itemId);
				}
			});

			var amounts = computeProportional(base, includedIds, percent);
			var total = amounts.shipping;

			modal.querySelectorAll('.cifw-item').forEach(function (row) {
				var input = row.querySelector('[data-field="total"]');
				if (Object.prototype.hasOwnProperty.call(amounts.items, row.dataset.itemId)) {
					input.value = money(amounts.items[row.dataset.itemId]);
					total += amounts.items[row.dataset.itemId];
				} else {
					// excluded line: show what it would be, it is not printed
					input.value = money(scaleCents(base.items[row.dataset.itemId] ? base.items[row.dataset.itemId].amount : 0, percent));
				}
			});
			shippingInput.value = money(amounts.shipping);

			var rows = [];
			if (base.singleTax) {
				var taxTotal = amounts.taxes.reduce(function (sum, tax) { return sum + tax.amount; }, 0);
				if (taxTotal !== 0) {
					rows.push({ label: base.taxLabel, amount: taxTotal });
				}
			} else {
				amounts.taxes.forEach(function (tax) {
					if (tax.amount !== 0) {
						rows.push(tax);
					}
				});
			}
			rows.forEach(function (tax) {
				total += tax.amount;
			});
			setTaxRows(rows);

			document.getElementById('cifw-total').textContent = money(total) + ' ' + currency;
		}

		// lock the amount fields while a percentage is selected
		function applyMode() {
			var custom = isCustom();
			modal.querySelectorAll('[data-field="total"]').forEach(function (input) {
				input.readOnly = !custom;
			});
			shippingInput.readOnly = !custom;
			modal.classList.toggle('cifw-mode-percent', !custom);
			proportionHint.textContent = custom
				? t('hintCustom', 'Amounts typed by hand. No tax rows are printed: total = lines + shipping.')
				: t('hintPercent', 'Every amount of the order (lines, shipping and taxes) is scaled to this percentage.');
		}

		function recalc() {
			if (!isCustom()) {
				recalcProportional(parseInt(proportionSelect.value, 10));
				return;
			}
			setTaxRows([]);
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
				proportion: proportionSelect.value,
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

		// switching to "Custom values" keeps the figures on screen (the scaled
		// ones) as the starting point for manual editing
		proportionSelect.addEventListener('change', applyMode);
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
