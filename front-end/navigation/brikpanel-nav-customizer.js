/**
 * BrikPanel — Sidebar Navigation Customizer (settings page UI)
 *
 * Responsibilities:
 *   - Make all section lists drag-drop sortable (jquery-ui-sortable).
 *   - Sync DOM state into the hidden `brikpanel_nav_config_json` input on
 *     every change so the WC settings save handler can read the latest
 *     ordering, visibility, custom-link metadata, per-submenu visibility,
 *     and per-system-item icon overrides.
 *   - Add / edit / delete custom links via the inline dialog.
 *   - Change the icon of system items (drop-down picker dialog).
 *   - Toggle visibility of individual submenu items inside a system row.
 *   - "Reset to defaults" clears the config option (the server side handles
 *     empty config by falling back to natural $menu order).
 */
(function () {
	'use strict';

	const i18n = (window.brikpanelNavCustomizer && window.brikpanelNavCustomizer.i18n) || {};
	const iconOptions = (window.brikpanelNavCustomizer && window.brikpanelNavCustomizer.iconOptions) || {};
	const iconsBase = (window.brikpanelNavCustomizer && window.brikpanelNavCustomizer.iconsBase) || '';

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); return; }
		document.addEventListener('DOMContentLoaded', fn);
	}

	ready(function () {
		const root = document.querySelector('.brikpanel-nav-customizer');
		if (!root) return;
		const hiddenInput = root.querySelector('#brikpanel_nav_config_json');
		const lists = root.querySelectorAll('.brikpanel-navc-list');
		const dialogBackdrop = root.querySelector('.brikpanel-navc-dialog-backdrop');
		const dialogTitle = root.querySelector('#brikpanel-navc-dialog-title');
		const dialogFields = {
			label: root.querySelector('[data-navc-field="label"]'),
			url: root.querySelector('[data-navc-field="url"]'),
			icon: root.querySelector('[data-navc-field="icon"]'),
			new_tab: root.querySelector('[data-navc-field="new_tab"]'),
		};
		const dialogElement = root.querySelector('.brikpanel-navc-dialog');
		const dialogSaveBtn = root.querySelector('[data-navc-action="dialog-save"]');
		// dialogContext.mode: 'add' | 'edit' | 'change-icon'
		let dialogContext = null;

		// ---------------------------------------------------------------------
		// State sync: read DOM → JSON, write into hidden input.
		// ---------------------------------------------------------------------
		function serialize() {
			const items = [];
			lists.forEach(function (ul) {
				const section = ul.getAttribute('data-section') || 'store';
				ul.querySelectorAll(':scope > .brikpanel-navc-item').forEach(function (li) {
					const type = li.getAttribute('data-type');
					const hidden = li.classList.contains('is-hidden');
					if (type === 'system') {
						const entry = {
							type: 'system',
							slug: li.getAttribute('data-slug') || '',
							section: section,
							hidden: hidden,
						};
						const iconOverride = li.getAttribute('data-icon-override') || '';
						if (iconOverride) {
							entry.icon_override = iconOverride;
						}
						const submenuItems = li.querySelectorAll(':scope .brikpanel-navc-submenu-item'); // i18n-ignore: CSS selector
						if (submenuItems.length) {
							const subs = [];
							submenuItems.forEach(function (subLi) {
								const subSlug = subLi.getAttribute('data-sub-slug') || '';
								if (!subSlug) return;
								subs.push({
									slug: subSlug,
									hidden: subLi.classList.contains('is-hidden'),
								});
							});
							if (subs.length) entry.submenus = subs;
						}
						items.push(entry);
					} else if (type === 'custom') {
						items.push({
							type: 'custom',
							id: li.getAttribute('data-id') || '',
							label: li.getAttribute('data-label') || '',
							url: li.getAttribute('data-url') || '',
							icon: li.getAttribute('data-icon') || 'default',
							new_tab: li.getAttribute('data-new-tab') === '1',
							section: section,
							hidden: hidden,
						});
					}
				});
			});
			hiddenInput.value = JSON.stringify({ version: 1, items: items });
		}

		// ---------------------------------------------------------------------
		// jQuery UI sortable across all lists. connectWith lets the user drag
		// items between sections, which automatically reassigns their section
		// when serialized.
		// ---------------------------------------------------------------------
		if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sortable) {
			window.jQuery(lists).sortable({
				connectWith: '.brikpanel-navc-list',
				handle: '.brikpanel-navc-drag',
				placeholder: 'brikpanel-navc-placeholder',
				items: '> .brikpanel-navc-item',
				tolerance: 'pointer',
				cursor: 'grabbing',
				forcePlaceholderSize: true,
				update: function () { serialize(); },
			}).disableSelection();
		}

		// ---------------------------------------------------------------------
		// Top-level visibility toggle (system + custom row main switch).
		// ---------------------------------------------------------------------
		root.addEventListener('change', function (e) {
			const subToggle = e.target.closest('[data-navc-sub-toggle]');
			if (subToggle) {
				const subLi = subToggle.closest('.brikpanel-navc-submenu-item');
				if (!subLi) return;
				if (subToggle.checked) {
					subLi.classList.remove('is-hidden');
				} else {
					subLi.classList.add('is-hidden');
				}
				serialize();
				return;
			}
			const cb = e.target.closest('[data-navc-toggle]');
			if (!cb) return;
			const li = cb.closest('.brikpanel-navc-item');
			if (!li) return;
			if (cb.checked) {
				li.classList.remove('is-hidden');
			} else {
				li.classList.add('is-hidden');
			}
			serialize();
		});

		// ---------------------------------------------------------------------
		// Click delegation: dialog open/close/save, custom row CRUD, submenu
		// chevron toggle, system row "Change icon", reset.
		// ---------------------------------------------------------------------
		root.addEventListener('click', function (e) {
			const actionEl = e.target.closest('[data-navc-action]');
			if (!actionEl) return;
			const action = actionEl.getAttribute('data-navc-action');

			if (action === 'add') {
				e.preventDefault();
				const section = actionEl.closest('.brikpanel-navc-section').getAttribute('data-section');
				openDialog({ mode: 'add', section: section });
				return;
			}
			if (action === 'edit') {
				e.preventDefault();
				const li = actionEl.closest('.brikpanel-navc-item');
				openDialog({ mode: 'edit', element: li, section: li.closest('.brikpanel-navc-list').getAttribute('data-section') });
				return;
			}
			if (action === 'delete') {
				e.preventDefault();
				if (i18n.confirmDelete && !window.confirm(i18n.confirmDelete)) return;
				const li = actionEl.closest('.brikpanel-navc-item');
				if (li && li.parentNode) {
					li.parentNode.removeChild(li);
					serialize();
				}
				return;
			}
			if (action === 'change-icon') {
				e.preventDefault();
				const li = actionEl.closest('.brikpanel-navc-item');
				openDialog({ mode: 'change-icon', element: li });
				return;
			}
			if (action === 'toggle-submenus') {
				e.preventDefault();
				const li = actionEl.closest('.brikpanel-navc-item');
				if (!li) return;
				const panel = li.querySelector(':scope > .brikpanel-navc-submenus');
				if (!panel) return;
				const isHidden = panel.hasAttribute('hidden');
				if (isHidden) {
					panel.removeAttribute('hidden');
					actionEl.setAttribute('aria-expanded', 'true');
					actionEl.classList.add('is-open');
				} else {
					panel.setAttribute('hidden', '');
					actionEl.setAttribute('aria-expanded', 'false');
					actionEl.classList.remove('is-open');
				}
				return;
			}
			if (action === 'dialog-close' || action === 'dialog-cancel') {
				e.preventDefault();
				closeDialog();
				return;
			}
			if (action === 'dialog-save') {
				e.preventDefault();
				saveDialog();
				return;
			}
			if (action === 'dialog-clear-icon') {
				e.preventDefault();
				if (!dialogContext || dialogContext.mode !== 'change-icon' || !dialogContext.element) return;
				dialogContext.element.setAttribute('data-icon-override', '');
				updateSystemRowIcon(dialogContext.element);
				serialize();
				closeDialog();
				return;
			}
			if (action === 'reset') {
				e.preventDefault();
				if (i18n.confirmReset && !window.confirm(i18n.confirmReset)) return;
				// Empty config tells the server to use natural $menu order.
				hiddenInput.value = JSON.stringify({ version: 1, items: [] });
				// Also clear the UI immediately so the user gets feedback that the
				// pending submission will reset everything. We re-mark every item
				// as visible, remove custom items, clear icon overrides, restore
				// submenu visibility.
				lists.forEach(function (ul) {
					ul.querySelectorAll('.brikpanel-navc-item.is-custom').forEach(function (li) { li.parentNode.removeChild(li); });
					ul.querySelectorAll('.brikpanel-navc-item.is-hidden').forEach(function (li) {
						li.classList.remove('is-hidden');
						const cb = li.querySelector(':scope > .brikpanel-navc-row > [data-navc-toggle]');
						if (cb) cb.checked = true;
					});
					ul.querySelectorAll('.brikpanel-navc-item.is-system').forEach(function (li) {
						li.setAttribute('data-icon-override', '');
						updateSystemRowIcon(li);
					});
					ul.querySelectorAll('.brikpanel-navc-submenu-item.is-hidden').forEach(function (subLi) {
						subLi.classList.remove('is-hidden');
						const cb = subLi.querySelector('[data-navc-sub-toggle]');
						if (cb) cb.checked = true;
					});
				});
				return;
			}
		});

		function updateSystemRowIcon(li) {
			if (!li) return;
			const iconWrap = li.querySelector(':scope > .brikpanel-navc-row > .brikpanel-navc-icon');
			if (!iconWrap) return;
			const override = li.getAttribute('data-icon-override') || '';
			if (override) {
				iconWrap.innerHTML = '<img src="' + escapeAttr(iconsBase + override + '.svg') + '" alt="" width="14" height="14">';
			} else {
				// Reset to neutral placeholder (matches the PHP-render fallback).
				iconWrap.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/></svg>';
			}
		}

		function openDialog(ctx) {
			dialogContext = ctx;
			// Visibility of dialog field rows depends on the mode.
			const labelField = dialogFields.label && dialogFields.label.closest('.brikpanel-navc-field');
			const urlField   = dialogFields.url && dialogFields.url.closest('.brikpanel-navc-field');
			const newTabField = dialogFields.new_tab && dialogFields.new_tab.closest('.brikpanel-navc-field');
			const clearBtn   = root.querySelector('[data-navc-action="dialog-clear-icon"]');

			if (ctx.mode === 'change-icon' && ctx.element) {
				dialogTitle.textContent = i18n.changeIcon || 'Change icon';
				if (labelField) labelField.hidden = true;
				if (urlField) urlField.hidden = true;
				if (newTabField) newTabField.hidden = true;
				if (clearBtn) clearBtn.hidden = false;
				dialogFields.icon.value = ctx.element.getAttribute('data-icon-override') || 'default';
			} else if (ctx.mode === 'edit' && ctx.element) {
				dialogTitle.textContent = i18n.editLink || 'Edit custom link';
				if (labelField) labelField.hidden = false;
				if (urlField) urlField.hidden = false;
				if (newTabField) newTabField.hidden = false;
				if (clearBtn) clearBtn.hidden = true;
				dialogFields.label.value = ctx.element.getAttribute('data-label') || '';
				dialogFields.url.value = ctx.element.getAttribute('data-url') || '';
				dialogFields.icon.value = ctx.element.getAttribute('data-icon') || 'default';
				dialogFields.new_tab.checked = ctx.element.getAttribute('data-new-tab') === '1';
			} else {
				dialogTitle.textContent = i18n.addLink || 'Add custom link';
				if (labelField) labelField.hidden = false;
				if (urlField) urlField.hidden = false;
				if (newTabField) newTabField.hidden = false;
				if (clearBtn) clearBtn.hidden = true;
				dialogFields.label.value = '';
				dialogFields.url.value = '';
				dialogFields.icon.value = 'default';
				dialogFields.new_tab.checked = false;
			}
			dialogBackdrop.hidden = false;
			setTimeout(function () {
				if (ctx.mode === 'change-icon') {
					dialogFields.icon.focus();
				} else {
					dialogFields.label.focus();
				}
			}, 30);
		}

		function closeDialog() {
			dialogBackdrop.hidden = true;
			dialogContext = null;
		}

		function saveDialog() {
			if (dialogContext && dialogContext.mode === 'change-icon' && dialogContext.element) {
				const icon = dialogFields.icon.value || '';
				dialogContext.element.setAttribute('data-icon-override', icon);
				updateSystemRowIcon(dialogContext.element);
				serialize();
				closeDialog();
				return;
			}

			const label = (dialogFields.label.value || '').trim();
			const url = (dialogFields.url.value || '').trim();
			const icon = dialogFields.icon.value || 'default';
			const newTab = !!dialogFields.new_tab.checked;

			if (!label) { alert(i18n.invalidLabel || 'Please enter a label.'); dialogFields.label.focus(); return; }
			if (!url || !isValidUrl(url)) { alert(i18n.invalidUrl || 'Please enter a valid URL.'); dialogFields.url.focus(); return; }

			if (dialogContext.mode === 'edit' && dialogContext.element) {
				const li = dialogContext.element;
				li.setAttribute('data-label', label);
				li.setAttribute('data-url', url);
				li.setAttribute('data-icon', icon);
				li.setAttribute('data-new-tab', newTab ? '1' : '0');
				const labelText = li.querySelector('.brikpanel-navc-label-text');
				if (labelText) labelText.textContent = label;
				const labelMeta = li.querySelector('.brikpanel-navc-label-meta');
				if (labelMeta) labelMeta.textContent = url;
				const iconImg = li.querySelector('.brikpanel-navc-icon img');
				if (iconImg) iconImg.src = iconsBase + icon + '.svg';
			} else {
				// Add a new custom row to the matching section.
				const section = dialogContext.section || 'store';
				const ul = root.querySelector('.brikpanel-navc-list[data-section="' + section + '"]');
				if (!ul) return;
				const newId = 'c' + Math.random().toString(36).slice(2, 12);
				const li = createCustomRow({
					id: newId,
					label: label,
					url: url,
					icon: icon,
					new_tab: newTab,
					hidden: false,
				});
				ul.appendChild(li);
			}
			serialize();
			closeDialog();
		}

		function createCustomRow(data) {
			const li = document.createElement('li');
			li.className = 'brikpanel-navc-item is-custom';
			li.setAttribute('data-type', 'custom');
			li.setAttribute('data-id', data.id);
			li.setAttribute('data-label', data.label);
			li.setAttribute('data-url', data.url);
			li.setAttribute('data-icon', data.icon || 'default');
			li.setAttribute('data-new-tab', data.new_tab ? '1' : '0');
			li.innerHTML =
				'<div class="brikpanel-navc-row">' +
					'<span class="brikpanel-navc-drag" aria-hidden="true">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>' +
					'</span>' +
					'<span class="brikpanel-navc-icon">' +
						'<img src="' + escapeAttr(iconsBase + (data.icon || 'default') + '.svg') + '" alt="" width="14" height="14">' +
					'</span>' +
					'<span class="brikpanel-navc-label">' +
						'<span class="brikpanel-navc-label-text"></span>' +
						'<span class="brikpanel-navc-label-meta"></span>' +
					'</span>' +
					'<label class="brikpanel-navc-toggle">' +
						'<input type="checkbox" data-navc-toggle' + (data.hidden ? '' : ' checked') + '>' +
						'<span class="brikpanel-navc-toggle-track" aria-hidden="true"><span class="brikpanel-navc-toggle-thumb"></span></span>' +
					'</label>' +
					'<button type="button" class="brikpanel-navc-iconbtn" data-navc-action="edit" aria-label="' + escapeAttr(i18n.edit || 'Edit') + '">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>' +
					'</button>' +
					'<button type="button" class="brikpanel-navc-iconbtn brikpanel-navc-iconbtn-danger" data-navc-action="delete" aria-label="' + escapeAttr(i18n.delete || 'Delete') + '">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>' +
					'</button>' +
				'</div>';
			li.querySelector('.brikpanel-navc-label-text').textContent = data.label;
			li.querySelector('.brikpanel-navc-label-meta').textContent = data.url;
			return li;
		}

		function isValidUrl(value) {
			if (!value) return false;
			// Accept absolute http(s)/mailto, or admin-relative paths.
			if (/^(https?:\/\/|mailto:)/i.test(value)) return true;
			if (value.indexOf('/') === 0) return true;
			return false;
		}

		function escapeAttr(s) {
			return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
		}

		// Close dialog on backdrop click + Escape.
		dialogBackdrop.addEventListener('click', function (e) {
			if (e.target === dialogBackdrop) closeDialog();
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !dialogBackdrop.hidden) closeDialog();
		});

		// Initial serialize so the hidden field reflects DOM state from PHP-render.
		serialize();
	});
})();
