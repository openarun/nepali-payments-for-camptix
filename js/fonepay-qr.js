/**
 * Fonepay QR Payment Script
 *
 * Renders the Fonepay QR and listens on the Fonepay WebSocket for real-time
 * payment updates. A manual "Check Status" button performs a single
 * (non-polling) server-side status check. The customer is only sent to the
 * finalize step when the payment is actually successful; other states show a
 * message and leave the order unchanged.
 */
(function () {
	if (typeof camptixFonepayData === 'undefined' || !camptixFonepayData.qrString) {
		return;
	}

	var data = camptixFonepayData;
	var i18n = data.i18n || {};
	var settled = false;
	var socket = null;
	var enableTimer = null;

	var statusEl = document.getElementById('camptix-fonepay-status');
	var verifyBtn = document.getElementById('camptix-fonepay-verify');
	var cancelLink = document.getElementById('camptix-fonepay-cancel');

	var checkStatusLabel = i18n.checkStatus || (verifyBtn ? verifyBtn.textContent : 'Check Status');
	var checkStatusInTpl = i18n.checkStatusIn || 'Check Status (%ds)';
	var initialDelay = Math.max(0, parseInt(data.checkStatusDelay, 10) || 30);
	var cooldownDelay = Math.max(0, parseInt(data.checkStatusCooldown, 10) || 15);

	function setStatus(message) {
		if (statusEl) {
			statusEl.textContent = message;
		}
	}

	function formatCheckStatusLabel(seconds) {
		return checkStatusInTpl.replace('%d', String(seconds));
	}

	function clearEnableTimer() {
		if (enableTimer) {
			window.clearInterval(enableTimer);
			enableTimer = null;
		}
	}

	/**
	 * Keep Check Status disabled until the countdown finishes.
	 *
	 * @param {number} seconds Seconds until the button is enabled.
	 */
	function startCheckStatusCountdown(seconds) {
		if (!verifyBtn) {
			return;
		}

		clearEnableTimer();
		verifyBtn.disabled = true;

		var remaining = Math.max(0, Math.floor(seconds));
		if (remaining <= 0) {
			verifyBtn.disabled = false;
			verifyBtn.textContent = checkStatusLabel;
			return;
		}

		verifyBtn.textContent = formatCheckStatusLabel(remaining);

		enableTimer = window.setInterval(function () {
			remaining -= 1;
			if (remaining <= 0) {
				clearEnableTimer();
				if (!settled) {
					verifyBtn.disabled = false;
					verifyBtn.textContent = checkStatusLabel;
				}
				return;
			}
			verifyBtn.textContent = formatCheckStatusLabel(remaining);
		}, 1000);
	}

	function renderQr() {
		var container = document.getElementById('camptix-fonepay-qrcode');
		if (!container || typeof QRCode === 'undefined') {
			return;
		}

		new QRCode(container, {
			text: data.qrString,
			width: 200,
			height: 200,
			correctLevel: QRCode.CorrectLevel.L,
		});
	}

	function closeSocket() {
		if (socket) {
			try {
				socket.close();
			} catch (e) {
				// ignore
			}
			socket = null;
		}
	}

	function goToReturn(message) {
		if (settled) {
			return;
		}
		settled = true;
		clearEnableTimer();
		closeSocket();
		if (verifyBtn) {
			verifyBtn.disabled = true;
		}
		setStatus(message);
		window.location.href = data.returnUrl;
	}

	function handlePaymentComplete() {
		goToReturn(i18n.paymentConfirmed || 'Payment confirmed. Redirecting...');
	}

	function handlePaymentFailed() {
		goToReturn(i18n.paymentFailedReturn || 'Payment failed or was cancelled. Redirecting...');
	}

	function connectWebSocket() {
		if (!data.websocketId) {
			return;
		}
		try {
			socket = new WebSocket(data.websocketId);
		} catch (e) {
			// ws:// may be blocked as mixed content over HTTPS; use the button instead.
			return;
		}

		socket.onmessage = function (event) {
			if (settled) {
				return;
			}
			var payload;
			try {
				payload = JSON.parse(event.data);
			} catch (e) {
				return;
			}

			var transaction = payload.transactionStatus;
			if (typeof transaction === 'string') {
				try {
					transaction = JSON.parse(transaction);
				} catch (e) {
					transaction = null;
				}
			}

			if (transaction && transaction.paymentSuccess === true) {
				handlePaymentComplete();
			} else if (transaction && transaction.success === false) {
				handlePaymentFailed();
			}
		};

		socket.onerror = function () {
			// Silent; the manual verify button is available as a fallback.
		};
	}

	function checkStatusOnce() {
		if (settled || !data.statusUrl || (verifyBtn && verifyBtn.disabled)) {
			return;
		}

		clearEnableTimer();
		if (verifyBtn) {
			verifyBtn.disabled = true;
			verifyBtn.textContent = checkStatusLabel;
		}
		setStatus(i18n.checkingStatus || 'Checking payment status...');

		var xhr = new XMLHttpRequest();
		xhr.open('GET', data.statusUrl, true);
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4 || settled) {
				return;
			}

			var status = 'unknown';
			if (xhr.status >= 200 && xhr.status < 300) {
				try {
					status = JSON.parse(xhr.responseText).status;
				} catch (e) {
					status = 'unknown';
				}
			}

			if (status === 'success') {
				handlePaymentComplete();
				return;
			}

			if (status === 'failed') {
				setStatus(i18n.paymentFailedStay || 'Payment failed or was cancelled. Please start a new payment.');
			} else {
				// timeout / pending / not_found / unknown — treat as not paid yet.
				setStatus(i18n.paymentNotReceived || "We haven't received your payment yet. Complete the payment in your app, then click the button again.");
			}

			startCheckStatusCountdown(cooldownDelay);
		};
		xhr.send();
	}

	if (verifyBtn) {
		verifyBtn.addEventListener('click', checkStatusOnce);
		startCheckStatusCountdown(initialDelay);
	}

	if (cancelLink) {
		cancelLink.addEventListener('click', function (event) {
			if (settled) {
				event.preventDefault();
				return;
			}
			settled = true;
			clearEnableTimer();
			closeSocket();
			// Prefer the CampTix payment_cancel URL from localized data.
			if (data.cancelUrl) {
				event.preventDefault();
				window.location.href = data.cancelUrl;
			}
		});
	}

	renderQr();
	connectWebSocket();
})();
