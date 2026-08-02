/**
 * BrikPanel — Cart Abandonment front-end capture.
 *
 * Two independent jobs, both configured via the localized `brikpanelCartAb`
 * object:
 *  1. Checkout email capture: a delegated `input` listener plus a low-cost
 *     poller watch every native email field on the checkout page — this
 *     covers the classic shortcode checkout (#billing_email), the Gutenberg
 *     block checkout (#email, a React-controlled input that still emits
 *     native input events), third-party checkouts, and browser autofill
 *     (which the poller catches even when no event fires).
 *  2. Signup popup with coupon: optional, disabled by default. Klaviyo-style
 *     lifecycle — closing the popup collapses it into a floating tab on the
 *     left edge (click to reopen); dismissing the tab hides everything for
 *     the cooldown period; a successful signup shows the personal coupon
 *     code, then auto-closes and never returns.
 *
 * Rendered entirely with textContent (no HTML injection from settings
 * values). All user-facing strings come from the localize payload (i18n rule).
 */
(function () {
	'use strict';

	var cfg = window.brikpanelCartAb;
	if (!cfg || !cfg.ajaxUrl) {
		return;
	}

	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
	// Dedup signature covers email AND the extra fields, so a name/phone
	// filled in after the email was captured still triggers one update ping.
	var lastSent = '';

	function signature(email, extra) {
		return email.toLowerCase() + '|' + JSON.stringify(extra || {});
	}

	function send(email, source, extra) {
		email = (email || '').trim();
		var sig = signature(email, extra);
		if (!EMAIL_RE.test(email) || sig === lastSent) {
			return Promise.reject(new Error('invalid'));
		}
		lastSent = sig;

		var body = new FormData();
		body.append('action', 'brikpanel_cartab_capture');
		body.append('email', email);
		body.append('source', source);
		if (extra) {
			Object.keys(extra).forEach(function (k) {
				if (extra[k]) {
					body.append(k, extra[k]);
				}
			});
		}
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					lastSent = ''; // allow retry on server-side rejection
					throw new Error('rejected');
				}
				if (json.data && json.data.throttled) {
					lastSent = ''; // rate-limited — the 3s poller will retry
				}
				return json;
			})
			.catch(function (err) {
				if (err && err.message !== 'invalid' && err.message !== 'rejected') {
					lastSent = ''; // network error — retry later
				}
				throw err;
			});
	}

	// Correct the email on a popup signup and re-deliver the coupon. Used by
	// the emailed-coupon "edit" affordance so a mistyped inbox is recoverable.
	function updateEmail(id, email) {
		var body = new FormData();
		body.append('action', 'brikpanel_cartab_popup_update_email');
		body.append('id', id);
		body.append('email', email);
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error('rejected');
				}
				return json;
			});
	}

	/* ------------------------------------------------------------------
	 * 1) Checkout capture
	 * ------------------------------------------------------------------ */
	// wp_localize_script stringifies scalars — "0" is truthy, so compare numerically.
	if (Number(cfg.isCheckout) === 1) {
		var debounceTimer = null;

		function fieldExtra() {
			// Best-effort name/phone from classic checkout fields; block
			// checkout ids covered too. Missing fields are simply skipped.
			function val(sel) {
				var el = document.querySelector(sel);
				return el && el.value ? el.value.trim() : '';
			}
			return {
				first_name: val('#billing_first_name') || val('#billing-first_name'),
				last_name: val('#billing_last_name') || val('#billing-last_name'),
				phone: val('#billing_phone') || val('#billing-phone')
			};
		}

		function isEmailField(el) {
			if (!el || el.tagName !== 'INPUT') {
				return false;
			}
			if (el.closest('.brikpanel-cartab-popup')) {
				return false; // the popup has its own submit path
			}
			return el.type === 'email' || el.id === 'billing_email' || /email/i.test(el.name || '');
		}

		function captureFrom(el, immediate) {
			if (!isEmailField(el) || !EMAIL_RE.test((el.value || '').trim())) {
				return;
			}
			window.clearTimeout(debounceTimer);
			debounceTimer = window.setTimeout(function () {
				send(el.value, 'checkout', fieldExtra()).catch(function () {});
			}, immediate ? 0 : 900);
		}

		document.addEventListener('input', function (e) { captureFrom(e.target, false); }, true);
		document.addEventListener('change', function (e) { captureFrom(e.target, true); }, true);
		document.addEventListener('focusout', function (e) { captureFrom(e.target, true); }, true);

		// Poller: catches prefilled values (saved customer, autofill,
		// programmatic fills from express-checkout plugins) that never fire
		// an input event. Cheap — a handful of DOM reads every 3s.
		function scanFields() {
			var fields = document.querySelectorAll('input[type="email"], #billing_email');
			for (var i = 0; i < fields.length; i++) {
				var value = (fields[i].value || '').trim();
				if (EMAIL_RE.test(value) && signature(value, fieldExtra()) !== lastSent) {
					send(value, 'checkout', fieldExtra()).catch(function () {});
					break;
				}
			}
		}
		window.setInterval(scanFields, 3000);

		// Logged-in customer: email already known server-side; register it
		// right away so even an immediate bounce is captured.
		if (cfg.knownEmail) {
			send(cfg.knownEmail, 'checkout', fieldExtra()).catch(function () {});
		} else {
			window.setTimeout(scanFields, 1200);
		}
	}

	/* ------------------------------------------------------------------
	 * 2) Signup popup + floating tab (teaser)
	 * ------------------------------------------------------------------ */
	if (cfg.popup && Number(cfg.popup.enabled) === 1) {
		var LS_DONE = 'brikpanel_cartab_done';
		var LS_DISMISS = 'brikpanel_cartab_dismissed';
		var LS_TEASER_HIDDEN = 'brikpanel_cartab_teaser_hidden';

		var storage;
		try {
			storage = window.localStorage;
			storage.setItem('brikpanel_cartab_probe', '1');
			storage.removeItem('brikpanel_cartab_probe');
		} catch (err) {
			storage = null;
		}

		var teaserEl = null;
		var overlayEl = null;
		var discount = Math.max(0, Number(cfg.popup.discount) || 0);
		var cooldownMs = Math.max(1, Number(cfg.popup.cooldown) || 7) * 86400000;

		function lsGet(key) {
			return storage ? storage.getItem(key) : null;
		}
		function lsSet(key, value) {
			if (storage) {
				storage.setItem(key, value);
			}
		}

		function isDone() {
			return !!lsGet(LS_DONE);
		}

		function teaserHidden() {
			var ts = parseInt(lsGet(LS_TEASER_HIDDEN) || '0', 10);
			if (!ts) {
				return false;
			}
			if (Date.now() - ts < cooldownMs) {
				return true;
			}
			if (storage) {
				// Cooldown over — forget both flags so the flow restarts fresh.
				storage.removeItem(LS_TEASER_HIDDEN);
				storage.removeItem(LS_DISMISS);
			}
			return false;
		}

		function wasDismissed() {
			return !!lsGet(LS_DISMISS);
		}

		/* ---------------- Floating tab ---------------- */

		function removeTeaser() {
			if (teaserEl && teaserEl.parentNode) {
				teaserEl.parentNode.removeChild(teaserEl);
			}
			teaserEl = null;
		}

		function showTeaser() {
			if (teaserEl || isDone() || teaserHidden()) {
				return;
			}
			teaserEl = document.createElement('div');
			teaserEl.className = 'brikpanel-cartab-teaser';

			var open = document.createElement('button');
			open.type = 'button';
			open.className = 'brikpanel-cartab-teaser-btn';
			open.setAttribute('aria-label', cfg.popup.title);

			if (discount > 0) {
				var mini = document.createElement('span');
				mini.className = 'brikpanel-cartab-teaser-ticket';
				mini.textContent = '%';
				open.appendChild(mini);
			}

			var label = document.createElement('span');
			label.className = 'brikpanel-cartab-teaser-label';
			label.textContent = cfg.popup.teaser;
			open.appendChild(label);

			var close = document.createElement('button');
			close.type = 'button';
			close.className = 'brikpanel-cartab-teaser-close';
			close.setAttribute('aria-label', cfg.i18n.close);
			close.textContent = '×';

			open.addEventListener('click', function () {
				openPopup();
			});
			close.addEventListener('click', function (e) {
				e.stopPropagation();
				lsSet(LS_TEASER_HIDDEN, String(Date.now()));
				removeTeaser();
			});

			teaserEl.appendChild(open);
			teaserEl.appendChild(close);
			document.body.appendChild(teaserEl);
			window.requestAnimationFrame(function () {
				teaserEl.classList.add('is-in');
			});
		}

		/* ---------------- Offer visual (animated discount badge) ---------------- */

		function el(tag, cls, text) {
			var node = document.createElement(tag);
			if (cls) {
				node.className = cls;
			}
			if (text !== undefined) {
				node.textContent = text;
			}
			return node;
		}

		function addStars(parent) {
			for (var i = 1; i <= 3; i++) {
				parent.appendChild(el('span', 'brikpanel-cartab-star brikpanel-cartab-star-' + i));
			}
		}

		// Builds the animated offer visual per the configured style. All styles
		// play a one-shot reveal sequence, then settle into a subtle idle loop.
		function buildOfferVisual() {
			var style = String(cfg.popup.style || 'pocket');
			var wrap = el('div', 'brikpanel-cartab-ticket-wrap brikpanel-cartab-ticket-wrap--' + style);
			var pctText = discount + '%';
			var i;

			if (style === 'classic') {
				var ticket = el('div', 'brikpanel-cartab-ticket');
				ticket.appendChild(el('span', 'brikpanel-cartab-ticket-pct', pctText));
				ticket.appendChild(el('span', 'brikpanel-cartab-ticket-off', cfg.i18n.offBadge));
				ticket.appendChild(el('span', 'brikpanel-cartab-ticket-notch brikpanel-cartab-ticket-notch-l'));
				ticket.appendChild(el('span', 'brikpanel-cartab-ticket-notch brikpanel-cartab-ticket-notch-r'));
				wrap.appendChild(ticket);
				for (i = 1; i <= 3; i++) {
					wrap.appendChild(el('span', 'brikpanel-cartab-spark brikpanel-cartab-spark-' + i));
				}
			} else if (style === 'scratch') {
				var box = el('div', 'brikpanel-cartab-scratch-box');
				var card = el('div', 'brikpanel-cartab-scratch');
				var win = el('span', 'brikpanel-cartab-scratch-win');
				win.appendChild(el('span', 'brikpanel-cartab-scratch-pct', pctText));
				win.appendChild(el('span', 'brikpanel-cartab-scratch-off', cfg.i18n.offBadge));
				var foil = el('span', 'brikpanel-cartab-scratch-foil');
				foil.appendChild(el('span', 'brikpanel-cartab-scratch-foil-label', cfg.i18n.scratchMe));
				card.appendChild(win);
				card.appendChild(foil);
				box.appendChild(card);
				box.appendChild(el('span', 'brikpanel-cartab-scratch-coin'));
				for (i = 1; i <= 3; i++) {
					box.appendChild(el('span', 'brikpanel-cartab-scratch-crumb brikpanel-cartab-scratch-crumb-' + i));
				}
				wrap.appendChild(box);
			} else if (style === 'slot') {
				var frame = el('div', 'brikpanel-cartab-slot');
				var chars = (String(discount) + '%').split('');
				var fillers = ['7', '3', '9', '2', '8', '4', '6', '5'];
				for (i = 0; i < chars.length; i++) {
					var reel = el('div', 'brikpanel-cartab-slot-reel brikpanel-cartab-slot-reel-' + Math.min(i + 1, 4));
					var strip = el('div', 'brikpanel-cartab-slot-strip');
					for (var f = 0; f < 8; f++) {
						strip.appendChild(el('span', '', fillers[(f + i * 3) % 8]));
					}
					strip.appendChild(el('span', '', chars[i]));
					reel.appendChild(strip);
					frame.appendChild(reel);
				}
				frame.appendChild(el('span', 'brikpanel-cartab-slot-stamp', cfg.i18n.offBadge));
				wrap.appendChild(frame);
			} else if (style === 'envelope') {
				var env = el('div', 'brikpanel-cartab-env');
				env.appendChild(el('div', 'brikpanel-cartab-env-back'));
				var envCard = el('div', 'brikpanel-cartab-env-card');
				var mini = el('span', 'brikpanel-cartab-env-mini');
				mini.appendChild(el('span', 'brikpanel-cartab-env-pct', pctText));
				mini.appendChild(el('span', 'brikpanel-cartab-env-off', cfg.i18n.offBadge));
				envCard.appendChild(mini);
				env.appendChild(envCard);
				env.appendChild(el('div', 'brikpanel-cartab-env-front'));
				env.appendChild(el('div', 'brikpanel-cartab-env-flap'));
				env.appendChild(el('div', 'brikpanel-cartab-env-wax', '%'));
				addStars(env);
				wrap.appendChild(env);
			} else if (style === 'assembly') {
				var asm = el('div', 'brikpanel-cartab-asm');
				var chip = el('div', 'brikpanel-cartab-asm-chip');
				chip.appendChild(el('span', 'brikpanel-cartab-asm-pct', pctText));
				chip.appendChild(el('span', 'brikpanel-cartab-asm-off', cfg.i18n.offBadge));
				asm.appendChild(chip);
				for (i = 1; i <= 6; i++) {
					asm.appendChild(el('span', 'brikpanel-cartab-asm-piece brikpanel-cartab-asm-piece-' + i));
				}
				asm.appendChild(el('span', 'brikpanel-cartab-asm-flash'));
				addStars(asm);
				wrap.appendChild(asm);
			} else { // pocket (default)
				var pocketWrap = el('div', 'brikpanel-cartab-pocketwrap');
				var pkCard = el('div', 'brikpanel-cartab-pk-card');
				pkCard.appendChild(el('span', 'brikpanel-cartab-pk-pct', pctText));
				pkCard.appendChild(el('span', 'brikpanel-cartab-pk-off', cfg.i18n.offBadge));
				pocketWrap.appendChild(pkCard);
				pocketWrap.appendChild(el('div', 'brikpanel-cartab-pocket'));
				addStars(pocketWrap);
				wrap.appendChild(pocketWrap);
			}
			return wrap;
		}

		/* ---------------- Popup ---------------- */

		function closePopup(permanent) {
			if (!overlayEl) {
				return;
			}
			var el = overlayEl;
			overlayEl = null;
			el.classList.remove('is-open');
			window.setTimeout(function () {
				if (el.parentNode) {
					el.parentNode.removeChild(el);
				}
			}, 250);
			if (permanent) {
				removeTeaser();
			} else {
				lsSet(LS_DISMISS, String(Date.now()));
				showTeaser(); // collapse into the floating tab, Klaviyo-style
			}
		}

		function openPopup() {
			if (overlayEl || isDone()) {
				return;
			}
			removeTeaser(); // no floating tab behind the open popup
			var overlay = document.createElement('div');
			overlay.className = 'brikpanel-cartab-popup-overlay';
			overlay.setAttribute('role', 'presentation');

			var modal = document.createElement('div');
			modal.className = 'brikpanel-cartab-popup';
			modal.setAttribute('role', 'dialog');
			modal.setAttribute('aria-modal', 'true');
			modal.setAttribute('aria-label', cfg.popup.title);

			var close = document.createElement('button');
			close.type = 'button';
			close.className = 'brikpanel-cartab-popup-close';
			close.setAttribute('aria-label', cfg.i18n.close);
			close.textContent = '×';
			modal.appendChild(close);

			// Animated offer visual — the reveal hook for the discount.
			if (discount > 0) {
				modal.appendChild(buildOfferVisual());
			}

			var title = document.createElement('h2');
			title.className = 'brikpanel-cartab-popup-title';
			title.textContent = cfg.popup.title;
			modal.appendChild(title);

			if (cfg.popup.message) {
				var message = document.createElement('p');
				message.className = 'brikpanel-cartab-popup-message';
				message.textContent = cfg.popup.message;
				modal.appendChild(message);
			}

			var form = document.createElement('form');
			form.className = 'brikpanel-cartab-popup-form';
			form.noValidate = true;

			var input = document.createElement('input');
			input.type = 'email';
			input.required = true;
			input.autocomplete = 'email';
			input.className = 'brikpanel-cartab-popup-input';
			input.placeholder = cfg.popup.placeholder;
			input.setAttribute('aria-label', cfg.i18n.emailLabel);

			var button = document.createElement('button');
			button.type = 'submit';
			button.className = 'brikpanel-cartab-popup-button';
			var buttonLabel = document.createElement('span');
			buttonLabel.className = 'brikpanel-cartab-popup-button-label';
			buttonLabel.textContent = cfg.popup.button;
			button.appendChild(buttonLabel);
			// Sliding arrow micro-interaction (static SVG, no user content).
			var arrowNs = 'http://www.w3.org/2000/svg';
			var arrow = document.createElementNS(arrowNs, 'svg');
			arrow.setAttribute('class', 'brikpanel-cartab-popup-button-arrow');
			arrow.setAttribute('viewBox', '0 0 24 24');
			arrow.setAttribute('width', '15');
			arrow.setAttribute('height', '15');
			arrow.setAttribute('aria-hidden', 'true');
			var arrowPath = document.createElementNS(arrowNs, 'path');
			arrowPath.setAttribute('d', 'M5 12h14M13 6l6 6-6 6');
			arrowPath.setAttribute('fill', 'none');
			arrowPath.setAttribute('stroke', 'currentColor');
			arrowPath.setAttribute('stroke-width', '2');
			arrowPath.setAttribute('stroke-linecap', 'round');
			arrowPath.setAttribute('stroke-linejoin', 'round');
			arrow.appendChild(arrowPath);
			button.appendChild(arrow);

			var note = document.createElement('div');
			note.className = 'brikpanel-cartab-popup-note';
			note.setAttribute('aria-live', 'polite');

			form.appendChild(input);
			form.appendChild(button);
			modal.appendChild(form);
			modal.appendChild(note);
			overlay.appendChild(modal);

			close.addEventListener('click', function () { closePopup(false); });
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					closePopup(false);
				}
			});
			document.addEventListener('keydown', function onKey(e) {
				if (e.key === 'Escape' && overlay.parentNode) {
					closePopup(false);
					document.removeEventListener('keydown', onKey);
				}
			});

			var autoClose = null;

			function showSuccess(data) {
				// Inline !important because the form's own display rule is
				// !important (theme hardening) and would beat [hidden].
				form.style.setProperty('display', 'none', 'important');
				note.textContent = cfg.popup.success;
				note.className = 'brikpanel-cartab-popup-note is-success';
				lsSet(LS_DONE, '1');
				removeTeaser();
				modal.classList.add('is-done');

				// Confetti burst — a dozen CSS-animated particles from the top
				// of the card; each nth-child gets its own trajectory in CSS.
				var confetti = document.createElement('div');
				confetti.className = 'brikpanel-cartab-confetti';
				confetti.setAttribute('aria-hidden', 'true');
				for (var ci = 0; ci < 12; ci++) {
					confetti.appendChild(document.createElement('span'));
				}
				modal.appendChild(confetti);
				window.setTimeout(function () {
					if (confetti.parentNode) {
						confetti.parentNode.removeChild(confetti);
					}
				}, 1800);

				// Companion-plugin mode: the coupon was deferred to email —
				// show the "check your inbox" state instead of the code.
				var emailedCoupon = data && data.coupon_emailed;
				if (emailedCoupon) {
					var emailedBox = document.createElement('div');
					emailedBox.className = 'brikpanel-cartab-coupon';

					var emailedIntro = document.createElement('div');
					emailedIntro.className = 'brikpanel-cartab-coupon-intro';
					emailedIntro.textContent = cfg.i18n.couponEmailed.replace('%s', data.email || '');

					var emailedHint = document.createElement('div');
					emailedHint.className = 'brikpanel-cartab-coupon-hint';
					emailedHint.textContent = cfg.i18n.couponEmailedHint;

					emailedBox.appendChild(emailedIntro);
					emailedBox.appendChild(emailedHint);

					// "Edit email" affordance: only meaningful when the code was
					// emailed (an inline code is already on screen, no inbox to
					// miss). Needs the entry id the server returned to authorize
					// the correction.
					if (data && data.id) {
						var editWrap = document.createElement('div');
						editWrap.className = 'brikpanel-cartab-editmail';

						var editToggle = document.createElement('button');
						editToggle.type = 'button';
						editToggle.className = 'brikpanel-cartab-editmail-toggle';
						editToggle.textContent = cfg.i18n.editEmail;

						var editForm = document.createElement('form');
						editForm.className = 'brikpanel-cartab-editmail-form';
						editForm.noValidate = true;
						editForm.hidden = true;

						var editInput = document.createElement('input');
						editInput.type = 'email';
						editInput.required = true;
						editInput.autocomplete = 'email';
						editInput.className = 'brikpanel-cartab-editmail-input';
						editInput.value = data.email || '';
						editInput.setAttribute('aria-label', cfg.i18n.emailLabel);

						var editSave = document.createElement('button');
						editSave.type = 'submit';
						editSave.className = 'brikpanel-cartab-editmail-save';
						editSave.textContent = cfg.i18n.editSave;

						var editCancel = document.createElement('button');
						editCancel.type = 'button';
						editCancel.className = 'brikpanel-cartab-editmail-cancel';
						editCancel.textContent = cfg.i18n.editCancel;

						var editNote = document.createElement('div');
						editNote.className = 'brikpanel-cartab-editmail-note';
						editNote.setAttribute('aria-live', 'polite');

						editForm.appendChild(editInput);
						editForm.appendChild(editSave);
						editForm.appendChild(editCancel);
						editWrap.appendChild(editToggle);
						editWrap.appendChild(editForm);
						editWrap.appendChild(editNote);
						emailedBox.appendChild(editWrap);

						function openEdit() {
							window.clearTimeout(autoClose); // don't auto-close mid-edit
							editToggle.hidden = true;
							editForm.hidden = false;
							editNote.textContent = '';
							editInput.focus({ preventScroll: true });
							editInput.select();
						}

						function closeEdit() {
							editForm.hidden = true;
							editToggle.hidden = false;
							editNote.textContent = '';
						}

						editToggle.addEventListener('click', openEdit);
						editCancel.addEventListener('click', closeEdit);

						editForm.addEventListener('submit', function (e) {
							e.preventDefault();
							var next = (editInput.value || '').trim();
							if (!EMAIL_RE.test(next)) {
								editNote.textContent = cfg.i18n.invalidEmail;
								editNote.className = 'brikpanel-cartab-editmail-note is-error';
								return;
							}
							editSave.disabled = true;
							editCancel.disabled = true;
							updateEmail(data.id, next).then(function (json) {
								var d = (json && json.data) ? json.data : {};
								var addr = d.email || next;
								editForm.hidden = true;
								editToggle.hidden = false;
								editToggle.disabled = true;
								editToggle.textContent = cfg.i18n.editDone;
								editNote.textContent = '';
								emailedIntro.textContent = cfg.i18n.couponEmailed.replace('%s', addr);
								// Give the reader time again after a correction.
								window.clearTimeout(autoClose);
								autoClose = window.setTimeout(function () { closePopup(true); }, 9000);
							}).catch(function () {
								editSave.disabled = false;
								editCancel.disabled = false;
								editNote.textContent = cfg.i18n.error;
								editNote.className = 'brikpanel-cartab-editmail-note is-error';
							});
						});
					}

					modal.appendChild(emailedBox);
				}

				var hasCoupon = data && data.coupon;
				if (hasCoupon) {
					var couponBox = document.createElement('div');
					couponBox.className = 'brikpanel-cartab-coupon';

					var intro = document.createElement('div');
					intro.className = 'brikpanel-cartab-coupon-intro';
					intro.textContent = cfg.i18n.couponIntro;

					var codeRow = document.createElement('div');
					codeRow.className = 'brikpanel-cartab-coupon-row';

					var code = document.createElement('span');
					code.className = 'brikpanel-cartab-coupon-code';
					code.textContent = data.coupon;

					var copy = document.createElement('button');
					copy.type = 'button';
					copy.className = 'brikpanel-cartab-coupon-copy';
					copy.textContent = cfg.i18n.copy;
					copy.addEventListener('click', function () {
						var value = data.coupon;
						var mark = function () {
							copy.textContent = cfg.i18n.copied;
							copy.classList.add('is-copied');
						};
						if (navigator.clipboard && navigator.clipboard.writeText) {
							navigator.clipboard.writeText(value).then(mark).catch(mark);
						} else {
							var tmp = document.createElement('textarea');
							tmp.value = value;
							document.body.appendChild(tmp);
							tmp.select();
							try { document.execCommand('copy'); } catch (e) { /* noop */ }
							document.body.removeChild(tmp);
							mark();
						}
					});

					var hint = document.createElement('div');
					hint.className = 'brikpanel-cartab-coupon-hint';
					hint.textContent = cfg.i18n.couponHint;

					codeRow.appendChild(code);
					codeRow.appendChild(copy);
					couponBox.appendChild(intro);
					couponBox.appendChild(codeRow);
					couponBox.appendChild(hint);
					modal.appendChild(couponBox);
				}

				// Auto-close: longer when a coupon (or the emailed-coupon
				// notice) is on screen so the visitor has time to read it.
				// Stored so the edit-email flow can defer it while correcting.
				autoClose = window.setTimeout(function () {
					closePopup(true);
				}, (hasCoupon || emailedCoupon) ? 9000 : 2500);
			}

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var email = (input.value || '').trim();
				if (!EMAIL_RE.test(email)) {
					note.textContent = cfg.i18n.invalidEmail;
					note.className = 'brikpanel-cartab-popup-note is-error';
					return;
				}
				button.disabled = true;
				lastSent = ''; // popup submit always goes through even if checkout sent earlier
				send(email, 'popup').then(function (json) {
					showSuccess(json && json.data ? json.data : null);
				}).catch(function () {
					button.disabled = false;
					note.textContent = cfg.i18n.error;
					note.className = 'brikpanel-cartab-popup-note is-error';
				});
			});

			overlayEl = overlay;
			document.body.appendChild(overlay);
			window.requestAnimationFrame(function () {
				overlay.classList.add('is-open');
				input.focus({ preventScroll: true });
			});
		}

		/* ---------------- Entry flow ---------------- */

		if (!isDone() && !teaserHidden()) {
			if (wasDismissed()) {
				// Popup was closed before — stay collapsed as the floating tab.
				showTeaser();
			} else {
				window.setTimeout(function () {
					if (!isDone() && !teaserHidden() && !wasDismissed()) {
						openPopup();
					}
				}, Math.max(0, Number(cfg.popup.delay) || 0) * 1000);
			}
		}
	}
})();
