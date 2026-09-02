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
	const iconVer = (window.brikpanelNavCustomizer && window.brikpanelNavCustomizer.iconVer) || '';
	const iconStyle = (window.brikpanelNavCustomizer && window.brikpanelNavCustomizer.iconStyle) || 'solid';
	const lineIconSlugs = (window.brikpanelNavCustomizer && window.brikpanelNavCustomizer.lineIconSlugs) || [];
	// Build a versioned built-in icon URL (cache-bust + style-aware, mirrors PHP's helper).
	function iconUrl(slug) {
		slug = slug || 'default';
		const sub = (iconStyle === 'line' && lineIconSlugs.indexOf(slug) !== -1) ? 'line/' : '';
		return iconsBase + sub + slug + '.svg' + (iconVer ? '?ver=' + iconVer : '');
	}
	const roles = (window.brikpanelNavCustomizer && window.brikpanelNavCustomizer.roles) || {};

	/**
	 * HTML-attribute escaper. Defined at the IIFE scope (not inside the
	 * DOMContentLoaded callback) so the markup builders below — audienceSelectHTML
	 * / audienceRolesHTML, which also run from inside the callback — can reach it.
	 * Keeping it nested previously threw "escapeAttr is not defined" the moment a
	 * custom link was added, silently aborting createCustomRow.
	 */
	function escapeAttr(s) {
		return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	/**
	 * Read the audience rule from a row owner. For a top-level item the owner is
	 * the <li.brikpanel-navc-item>; for a submenu row it is the
	 * <li.brikpanel-navc-submenu-item>. The audience <select> lives in the row
	 * and the role checklist is a sibling `.brikpanel-navc-roles` block. Returns
	 * an object to merge into the serialized entry:
	 *   {} (everyone) | { audience:'admins' } | { audience:'roles', hide_roles:[…] }
	 */
	function readAudience(owner, isSub) {
		if (!owner) return {};
		const sel = isSub
			? owner.querySelector('[data-navc-audience]')
			: owner.querySelector(':scope > .brikpanel-navc-row [data-navc-audience]'); // i18n-ignore: CSS selector
		if (!sel) return {};
		const audience = sel.value || 'all';
		if (audience === 'admins') return { audience: 'admins' };
		if (audience === 'roles') {
			const panel = owner.querySelector(':scope > .brikpanel-navc-roles'); // i18n-ignore: CSS selector
			const hideRoles = [];
			if (panel) {
				panel.querySelectorAll('[data-navc-role]').forEach(function (cb) {
					if (cb.checked) hideRoles.push(cb.value);
				});
			}
			if (hideRoles.length) return { audience: 'roles', hide_roles: hideRoles };
			return {};
		}
		return {};
	}

	/** Inline audience <select> markup for client-created rows. */
	function audienceSelectHTML() {
		return (
			'<select class="brikpanel-navc-audience" data-navc-audience aria-label="' + escapeAttr(i18n.audienceLabel || 'Who can see this item') + '">' +
				'<option value="all">' + escapeAttr(i18n.audienceAll || 'Everyone') + '</option>' +
				'<option value="admins">' + escapeAttr(i18n.audienceAdmins || 'Admins only') + '</option>' +
				'<option value="roles">' + escapeAttr(i18n.audienceRoles || 'Specific roles') + '</option>' +
			'</select>'
		);
	}

	/** Role checklist block markup for client-created rows (hidden by default). */
	function audienceRolesHTML() {
		let rolesHtml = '';
		Object.keys(roles).forEach(function (slug) {
			rolesHtml +=
				'<label class="brikpanel-navc-role">' +
					'<input type="checkbox" data-navc-role value="' + escapeAttr(slug) + '">' +
					'<span>' + escapeAttr(roles[slug]) + '</span>' +
				'</label>';
		});
		return (
			'<div class="brikpanel-navc-roles" data-navc-roles hidden>' +
				'<span class="brikpanel-navc-roles-title">' + escapeAttr(i18n.hideFromRoles || 'Hide from these roles') + '</span>' +
				'<div class="brikpanel-navc-roles-grid">' + rolesHtml + '</div>' +
			'</div>'
		);
	}

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
			icon_svg: root.querySelector('[data-navc-field="icon_svg"]'),
			new_tab: root.querySelector('[data-navc-field="new_tab"]'),
		};
		const svgPreview = root.querySelector('.brikpanel-navc-svg-preview');
		const dialogElement = root.querySelector('.brikpanel-navc-dialog');
		const dialogSaveBtn = root.querySelector('[data-navc-action="dialog-save"]');
		// dialogContext.mode: 'add' | 'edit' | 'change-icon'
		let dialogContext = null;

		// ---------------------------------------------------------------------
		// State sync: read DOM → JSON, write into hidden input.
		// ---------------------------------------------------------------------
		const spacingSelect = root.querySelector('[data-navc-spacing]');
		const sitemgmtLabelInput = root.querySelector('[data-navc-sitemgmt-label]');

		// Visual only: mark every row that follows a heading (up to the next
		// heading) so the editor shows at a glance which group it belongs to.
		// Grouping itself is positional and lives in the serialized order, so
		// this class is never read back on save.
		function markGroupMembers() {
			lists.forEach(function (ul) {
				let inGroup = false;
				ul.querySelectorAll(':scope > .brikpanel-navc-item').forEach(function (li) { // i18n-ignore: CSS selector
					if (li.getAttribute('data-type') === 'heading') {
						inGroup = true;
						li.classList.remove('is-in-group');
						return;
					}
					li.classList.toggle('is-in-group', inGroup);
				});
			});
		}

		function serialize() {
			const items = [];
			lists.forEach(function (ul) {
				const section = ul.getAttribute('data-section') || 'store';
				ul.querySelectorAll(':scope > .brikpanel-navc-item').forEach(function (li) {
					const type = li.getAttribute('data-type');
					const hidden = li.classList.contains('is-hidden');
					if (type === 'spacer') {
						items.push({
							type: 'spacer',
							id: li.getAttribute('data-id') || '',
							variant: li.getAttribute('data-variant') === 'line' ? 'line' : 'space',
							section: section,
						});
						return;
					}
					if (type === 'heading') {
						const labelInput = li.querySelector('[data-navc-heading-label]'); // i18n-ignore: CSS selector
						items.push({
							type: 'heading',
							id: li.getAttribute('data-id') || '',
							label: labelInput ? labelInput.value.trim().slice(0, 60) : '',
							section: section,
						});
						return;
					}
					if (type === 'system') {
						const entry = {
							type: 'system',
							slug: li.getAttribute('data-slug') || '',
							section: section,
							hidden: hidden,
						};
						Object.assign(entry, readAudience(li, false));
						const labelOverride = li.getAttribute('data-label-override') || '';
						if (labelOverride) {
							entry.label_override = labelOverride;
						}
						const iconSvg = li.getAttribute('data-icon-svg') || '';
						if (iconSvg) {
							entry.icon_svg = iconSvg;
						}
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
								const sub = {
									slug: subSlug,
									hidden: subLi.classList.contains('is-hidden'),
								};
								Object.assign(sub, readAudience(subLi, true));
								subs.push(sub);
							});
							if (subs.length) entry.submenus = subs;
						}
						items.push(entry);
					} else if (type === 'custom') {
						const entry = {
							type: 'custom',
							id: li.getAttribute('data-id') || '',
							label: li.getAttribute('data-label') || '',
							url: li.getAttribute('data-url') || '',
							icon: li.getAttribute('data-icon') || 'default',
							new_tab: li.getAttribute('data-new-tab') === '1',
							section: section,
							hidden: hidden,
						};
						const customSvg = li.getAttribute('data-icon-svg') || '';
						if (customSvg) {
							entry.icon_svg = customSvg;
						}
						Object.assign(entry, readAudience(li, false));
						items.push(entry);
					}
				});
			});
			const spacing = spacingSelect && ['compact', 'comfortable', 'spacious'].indexOf(spacingSelect.value) !== -1
				? spacingSelect.value
				: 'comfortable';
			const sitemgmtLabel = sitemgmtLabelInput ? sitemgmtLabelInput.value.trim().slice(0, 60) : '';
			hiddenInput.value = JSON.stringify({ version: 1, items: items, spacing: spacing, sitemgmt_label: sitemgmtLabel });
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
				update: function () { markGroupMembers(); serialize(); },
			}).disableSelection();
		}

		// ---------------------------------------------------------------------
		// Top-level visibility toggle (system + custom row main switch).
		// ---------------------------------------------------------------------
		// Text label field re-serializes on every keystroke so the pending value
		// is always captured even if the user hits Save without blurring first.
		if (sitemgmtLabelInput) {
			sitemgmtLabelInput.addEventListener('input', serialize);
		}

		// Same for every group-heading label: capture each keystroke so a save
		// without blurring still stores the typed name.
		root.addEventListener('input', function (e) {
			if (e.target.closest('[data-navc-heading-label]')) {
				serialize();
			}
		});

		root.addEventListener('change', function (e) {
			const spacerVariant = e.target.closest('[data-navc-spacer-variant]');
			if (spacerVariant) {
				const li = spacerVariant.closest('.brikpanel-navc-item');
				if (li) li.setAttribute('data-variant', spacerVariant.value === 'line' ? 'line' : 'space');
				serialize();
				return;
			}
			if (e.target.closest('[data-navc-spacing]')) {
				serialize();
				return;
			}
			if (e.target.closest('[data-navc-sitemgmt-label]')) {
				serialize();
				return;
			}
			const audienceSel = e.target.closest('[data-navc-audience]');
			if (audienceSel) {
				const owner = audienceSel.closest('.brikpanel-navc-submenu-item')
					|| audienceSel.closest('.brikpanel-navc-item');
				const panel = owner ? owner.querySelector(':scope > .brikpanel-navc-roles') : null; // i18n-ignore: CSS selector
				if (panel) {
					if (audienceSel.value === 'roles') {
						panel.removeAttribute('hidden');
					} else {
						panel.setAttribute('hidden', '');
					}
				}
				serialize();
				return;
			}
			const roleCb = e.target.closest('[data-navc-role]');
			if (roleCb) {
				serialize();
				return;
			}
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
			if (action === 'add-spacer') {
				e.preventDefault();
				const section = actionEl.closest('.brikpanel-navc-section').getAttribute('data-section');
				const ul = root.querySelector('.brikpanel-navc-list[data-section="' + section + '"]');
				if (!ul) return;
				const li = createSpacerRow({ id: 's' + Math.random().toString(36).slice(2, 12), variant: 'space' });
				ul.appendChild(li);
				serialize();
				return;
			}
			if (action === 'add-heading') {
				e.preventDefault();
				const section = actionEl.closest('.brikpanel-navc-section').getAttribute('data-section');
				const ul = root.querySelector('.brikpanel-navc-list[data-section="' + section + '"]'); // i18n-ignore: CSS selector
				if (!ul) return;
				const li = createHeadingRow({
					id: 'h' + Math.random().toString(36).slice(2, 12),
					label: i18n.headingDefault || 'New section',
				});
				ul.appendChild(li);
				markGroupMembers();
				serialize();
				// Drop the user straight into the label so naming the new section
				// is one keystroke away instead of a second click.
				const input = li.querySelector('[data-navc-heading-label]'); // i18n-ignore: CSS selector
				if (input) {
					input.focus();
					input.select();
				}
				return;
			}
			if (action === 'delete') {
				e.preventDefault();
				const li = actionEl.closest('.brikpanel-navc-item');
				const rowType = li ? li.getAttribute('data-type') : '';
				// Spacers and headings are decorative — removing one moves no menu
				// item, so skip the confirm prompt for both.
				const isDecorative = rowType === 'spacer' || rowType === 'heading';
				// Stale system rows (slug has no live menu counterpart) get their own
				// wording: nothing on screen disappears, only the leftover setting.
				const isMissing = li && li.getAttribute('data-unavailable') === '1';
				const confirmText = isMissing ? i18n.confirmDeleteMissing : i18n.confirmDelete;
				if (!isDecorative && confirmText && !window.confirm(confirmText)) return;
				if (li && li.parentNode) {
					li.parentNode.removeChild(li);
					markGroupMembers();
					serialize();
				}
				return;
			}
			if (action === 'edit-system' || action === 'change-icon') {
				e.preventDefault();
				const li = actionEl.closest('.brikpanel-navc-item');
				openDialog({ mode: 'edit-system', element: li });
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
				if (!dialogContext || dialogContext.mode !== 'edit-system' || !dialogContext.element) return;
				dialogContext.element.setAttribute('data-icon-override', '');
				dialogContext.element.setAttribute('data-icon-svg', '');
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
				if (spacingSelect) spacingSelect.value = 'comfortable';
				if (sitemgmtLabelInput) sitemgmtLabelInput.value = '';
				lists.forEach(function (ul) {
					// Stale rows go too: a reset that left them in the DOM would see
					// them re-serialized into the config on the next interaction.
					ul.querySelectorAll('.brikpanel-navc-item.is-custom, .brikpanel-navc-item.is-spacer, .brikpanel-navc-item.is-heading, .brikpanel-navc-item.is-unavailable').forEach(function (li) { li.parentNode.removeChild(li); });
					ul.querySelectorAll('.brikpanel-navc-item.is-hidden').forEach(function (li) {
						li.classList.remove('is-hidden');
						const cb = li.querySelector(':scope > .brikpanel-navc-row > [data-navc-toggle]');
						if (cb) cb.checked = true;
					});
					ul.querySelectorAll('.brikpanel-navc-item.is-system').forEach(function (li) {
						li.setAttribute('data-icon-override', '');
						li.setAttribute('data-icon-svg', '');
						li.setAttribute('data-label-override', '');
						updateSystemRowIcon(li);
						updateSystemRowLabel(li);
					});
					ul.querySelectorAll('.brikpanel-navc-submenu-item.is-hidden').forEach(function (subLi) {
						subLi.classList.remove('is-hidden');
						const cb = subLi.querySelector('[data-navc-sub-toggle]');
						if (cb) cb.checked = true;
					});
					// Reset every audience rule back to "Everyone".
					ul.querySelectorAll('[data-navc-audience]').forEach(function (sel) {
						sel.value = 'all';
					});
					ul.querySelectorAll('[data-navc-role]').forEach(function (cb) {
						cb.checked = false;
					});
					ul.querySelectorAll('[data-navc-roles]').forEach(function (panel) {
						panel.setAttribute('hidden', '');
					});
				});
				// Every heading was just removed, so no row is inside a group any
				// more — drop the indent markers with them.
				markGroupMembers();
				return;
			}
		});

		function updateSystemRowIcon(li) {
			if (!li) return;
			const iconWrap = li.querySelector(':scope > .brikpanel-navc-row > .brikpanel-navc-icon');
			if (!iconWrap) return;
			const svg = li.getAttribute('data-icon-svg') || '';
			const override = li.getAttribute('data-icon-override') || '';
			if (svg) {
				iconWrap.innerHTML = '<img src="' + escapeAttr(svg) + '" alt="" width="14" height="14">';
			} else if (override) {
				iconWrap.innerHTML = '<img src="' + escapeAttr(iconUrl(override)) + '" alt="" width="14" height="14">';
			} else {
				// Reset to neutral placeholder (matches the PHP-render fallback).
				iconWrap.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/></svg>';
			}
		}

		// Reflect a system item's label override (or its original title when the
		// override is cleared) into the visible row label.
		function updateSystemRowLabel(li) {
			if (!li) return;
			const textEl = li.querySelector(':scope > .brikpanel-navc-row .brikpanel-navc-label-text');
			if (!textEl) return;
			const override = li.getAttribute('data-label-override') || '';
			textEl.textContent = override || (li.getAttribute('data-orig-title') || '');
		}

		// Normalize pasted icon input (raw SVG markup or a data URI) into a
		// data:image/svg+xml;base64 URI usable as an <img> src. Returns '' when
		// the input doesn't look like an SVG. The server re-sanitizes on save.
		function normalizeSvgInput(value) {
			value = (value || '').trim();
			if (!value) return '';
			if (/^data:image\/svg\+xml/i.test(value)) return value;
			if (/<svg[\s\S]*<\/svg>/i.test(value)) {
				try {
					return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(value)));
				} catch (err) {
					return '';
				}
			}
			return '';
		}

		// Live preview of the SVG textarea contents inside the dialog.
		function updateSvgPreview() {
			if (!svgPreview) return;
			const uri = normalizeSvgInput(dialogFields.icon_svg ? dialogFields.icon_svg.value : '');
			svgPreview.innerHTML = uri ? '<img src="' + escapeAttr(uri) + '" alt="" width="20" height="20">' : '';
		}

		// Picking a built-in icon from the dropdown clears any pasted SVG, so the
		// chosen icon takes effect (last action wins). Pasting an SVG afterwards
		// overrides the dropdown again.
		function clearSvgOnIconPick() {
			if (dialogFields.icon_svg) dialogFields.icon_svg.value = '';
			if (svgPreview) svgPreview.innerHTML = '';
		}

		// Set the icon dropdown + SVG textarea from a stored icon slug and svg
		// data URI. The SVG textarea is always visible; a non-empty value wins
		// over the dropdown at save time.
		function loadIconFields(iconSlug, iconSvg) {
			dialogFields.icon.value = iconSlug || 'default';
			if (dialogFields.icon_svg) dialogFields.icon_svg.value = iconSvg || '';
			updateSvgPreview();
		}

		function openDialog(ctx) {
			dialogContext = ctx;
			// Visibility of dialog field rows depends on the mode.
			const labelField = dialogFields.label && dialogFields.label.closest('.brikpanel-navc-field');
			const urlField   = dialogFields.url && dialogFields.url.closest('.brikpanel-navc-field');
			const newTabField = dialogFields.new_tab && dialogFields.new_tab.closest('.brikpanel-navc-field');
			const clearBtn   = root.querySelector('[data-navc-action="dialog-clear-icon"]');

			if (ctx.mode === 'edit-system' && ctx.element) {
				// Rename + icon for a built-in system item. URL / new-tab don't apply.
				dialogTitle.textContent = i18n.editItem || 'Edit menu item';
				if (labelField) labelField.hidden = false;
				if (urlField) urlField.hidden = true;
				if (newTabField) newTabField.hidden = true;
				if (clearBtn) clearBtn.hidden = false;
				const override = ctx.element.getAttribute('data-label-override') || '';
				dialogFields.label.value = override || (ctx.element.getAttribute('data-orig-title') || '');
				loadIconFields(ctx.element.getAttribute('data-icon-override') || 'default', ctx.element.getAttribute('data-icon-svg') || '');
			} else if (ctx.mode === 'edit' && ctx.element) {
				dialogTitle.textContent = i18n.editLink || 'Edit custom link';
				if (labelField) labelField.hidden = false;
				if (urlField) urlField.hidden = false;
				if (newTabField) newTabField.hidden = false;
				if (clearBtn) clearBtn.hidden = true;
				dialogFields.label.value = ctx.element.getAttribute('data-label') || '';
				dialogFields.url.value = ctx.element.getAttribute('data-url') || '';
				dialogFields.new_tab.checked = ctx.element.getAttribute('data-new-tab') === '1';
				loadIconFields(ctx.element.getAttribute('data-icon') || 'default', ctx.element.getAttribute('data-icon-svg') || '');
			} else {
				dialogTitle.textContent = i18n.addLink || 'Add custom link';
				if (labelField) labelField.hidden = false;
				if (urlField) urlField.hidden = false;
				if (newTabField) newTabField.hidden = false;
				if (clearBtn) clearBtn.hidden = true;
				dialogFields.label.value = '';
				dialogFields.url.value = '';
				dialogFields.new_tab.checked = false;
				loadIconFields('default', '');
			}
			dialogBackdrop.hidden = false;
			setTimeout(function () {
				dialogFields.label.focus();
			}, 30);
		}

		function closeDialog() {
			dialogBackdrop.hidden = true;
			dialogContext = null;
		}

		// Resolve the icon picker into { icon, iconSvg }. Returns null (after
		// alerting) when "Custom SVG…" is chosen but the input isn't a valid SVG.
		function resolveDialogIcon() {
			const rawSvg = dialogFields.icon_svg ? dialogFields.icon_svg.value.trim() : '';
			const sel = dialogFields.icon.value || 'default';
			// A non-empty SVG textarea always wins over the dropdown.
			if (rawSvg !== '') {
				const svg = normalizeSvgInput(rawSvg);
				if (!svg) {
					alert(i18n.invalidSvg || 'That SVG could not be read.');
					if (dialogFields.icon_svg) dialogFields.icon_svg.focus();
					return null;
				}
				return { icon: 'default', iconSvg: svg };
			}
			return { icon: sel, iconSvg: '' };
		}

		function saveDialog() {
			// Rename / re-icon a built-in system item.
			if (dialogContext && dialogContext.mode === 'edit-system' && dialogContext.element) {
				const li = dialogContext.element;
				const label = (dialogFields.label.value || '').trim();
				if (!label) { alert(i18n.invalidLabel || 'Please enter a label.'); dialogFields.label.focus(); return; }
				const resolved = resolveDialogIcon();
				if (!resolved) return;
				const origTitle = li.getAttribute('data-orig-title') || '';
				// Store an override only when the label actually differs from the
				// original — keeps the saved config minimal.
				li.setAttribute('data-label-override', label === origTitle ? '' : label);
				// A custom SVG wins; otherwise treat "Default link" as "use original".
				if (resolved.iconSvg) {
					li.setAttribute('data-icon-svg', resolved.iconSvg);
					li.setAttribute('data-icon-override', '');
				} else {
					li.setAttribute('data-icon-svg', '');
					li.setAttribute('data-icon-override', resolved.icon === 'default' ? '' : resolved.icon);
				}
				updateSystemRowLabel(li);
				updateSystemRowIcon(li);
				serialize();
				closeDialog();
				return;
			}

			const label = (dialogFields.label.value || '').trim();
			const url = (dialogFields.url.value || '').trim();
			const newTab = !!dialogFields.new_tab.checked;

			if (!label) { alert(i18n.invalidLabel || 'Please enter a label.'); dialogFields.label.focus(); return; }
			if (!url || !isValidUrl(url)) { alert(i18n.invalidUrl || 'Please enter a valid URL.'); dialogFields.url.focus(); return; }
			const resolved = resolveDialogIcon();
			if (!resolved) return;
			const icon = resolved.icon;
			const iconSvg = resolved.iconSvg;

			if (dialogContext.mode === 'edit' && dialogContext.element) {
				const li = dialogContext.element;
				li.setAttribute('data-label', label);
				li.setAttribute('data-url', url);
				li.setAttribute('data-icon', icon);
				li.setAttribute('data-icon-svg', iconSvg);
				li.setAttribute('data-new-tab', newTab ? '1' : '0');
				const labelText = li.querySelector('.brikpanel-navc-label-text');
				if (labelText) labelText.textContent = label;
				const labelMeta = li.querySelector('.brikpanel-navc-label-meta');
				if (labelMeta) labelMeta.textContent = url;
				const iconImg = li.querySelector('.brikpanel-navc-icon img');
				if (iconImg) iconImg.src = iconSvg ? iconSvg : iconUrl(icon);
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
					icon_svg: iconSvg,
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
			li.setAttribute('data-icon-svg', data.icon_svg || '');
			li.setAttribute('data-new-tab', data.new_tab ? '1' : '0');
			const iconSrc = data.icon_svg ? data.icon_svg : iconUrl(data.icon);
			li.innerHTML =
				'<div class="brikpanel-navc-row">' +
					'<span class="brikpanel-navc-drag" aria-hidden="true">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>' +
					'</span>' +
					'<span class="brikpanel-navc-icon">' +
						'<img src="' + escapeAttr(iconSrc) + '" alt="" width="14" height="14">' +
					'</span>' +
					'<span class="brikpanel-navc-label">' +
						'<span class="brikpanel-navc-label-text"></span>' +
						'<span class="brikpanel-navc-label-meta"></span>' +
					'</span>' +
					'<label class="brikpanel-navc-toggle">' +
						'<input type="checkbox" data-navc-toggle' + (data.hidden ? '' : ' checked') + '>' +
						'<span class="brikpanel-navc-toggle-track" aria-hidden="true"><span class="brikpanel-navc-toggle-thumb"></span></span>' +
					'</label>' +
					audienceSelectHTML() +
					'<button type="button" class="brikpanel-navc-iconbtn" data-navc-action="edit" aria-label="' + escapeAttr(i18n.edit || 'Edit') + '">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>' +
					'</button>' +
					'<button type="button" class="brikpanel-navc-iconbtn brikpanel-navc-iconbtn-danger" data-navc-action="delete" aria-label="' + escapeAttr(i18n.delete || 'Delete') + '">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>' +
					'</button>' +
				'</div>' +
				audienceRolesHTML();
			li.querySelector('.brikpanel-navc-label-text').textContent = data.label;
			li.querySelector('.brikpanel-navc-label-meta').textContent = data.url;
			return li;
		}

		// Build a spacer row (decorative gap / divider). Mirrors the PHP-rendered
		// markup so it serializes identically.
		function createSpacerRow(data) {
			const variant = data.variant === 'line' ? 'line' : 'space';
			const li = document.createElement('li');
			li.className = 'brikpanel-navc-item is-spacer';
			li.setAttribute('data-type', 'spacer');
			li.setAttribute('data-id', data.id);
			li.setAttribute('data-variant', variant);
			li.innerHTML =
				'<div class="brikpanel-navc-row">' +
					'<span class="brikpanel-navc-drag" aria-hidden="true">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>' +
					'</span>' +
					'<span class="brikpanel-navc-spacer-badge">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="12" x2="20" y2="12"/><polyline points="8 8 4 12 8 16"/><polyline points="16 8 20 12 16 16"/></svg>' +
						'<span>' + escapeAttr(i18n.spacer || 'Spacer') + '</span>' +
					'</span>' +
					'<select class="brikpanel-navc-audience brikpanel-navc-spacer-variant" data-navc-spacer-variant aria-label="' + escapeAttr(i18n.spacerStyle || 'Spacer style') + '">' +
						'<option value="space"' + (variant === 'space' ? ' selected' : '') + '>' + escapeAttr(i18n.spacerSpace || 'Blank space') + '</option>' +
						'<option value="line"' + (variant === 'line' ? ' selected' : '') + '>' + escapeAttr(i18n.spacerLine || 'Divider line') + '</option>' +
					'</select>' +
					'<button type="button" class="brikpanel-navc-iconbtn brikpanel-navc-iconbtn-danger" data-navc-action="delete" aria-label="' + escapeAttr(i18n.removeSpacer || 'Remove spacer') + '">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>' +
					'</button>' +
				'</div>';
			return li;
		}

		// Build a group-heading row. Mirrors the PHP-rendered markup so it
		// serializes identically. The label lives in an inline text input; the
		// rows that follow it in the same list become its collapsible group in
		// the live sidebar.
		function createHeadingRow(data) {
			const li = document.createElement('li');
			li.className = 'brikpanel-navc-item is-heading';
			li.setAttribute('data-type', 'heading');
			li.setAttribute('data-id', data.id);
			li.innerHTML =
				'<div class="brikpanel-navc-row">' +
					'<span class="brikpanel-navc-drag" aria-hidden="true">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>' +
					'</span>' +
					'<span class="brikpanel-navc-heading-badge">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="13" y2="12"/><line x1="4" y1="18" x2="13" y2="18"/></svg>' +
						'<span>' + escapeAttr(i18n.heading || 'Heading') + '</span>' +
					'</span>' +
					'<input type="text" class="brikpanel-navc-heading-input" data-navc-heading-label maxlength="60"' +
						' value="' + escapeAttr(data.label || '') + '"' +
						' placeholder="' + escapeAttr(i18n.headingExample || 'e.g. Site content') + '"' +
						' aria-label="' + escapeAttr(i18n.headingText || 'Heading text') + '">' +
					'<button type="button" class="brikpanel-navc-iconbtn brikpanel-navc-iconbtn-danger" data-navc-action="delete" aria-label="' + escapeAttr(i18n.removeHeading || 'Remove heading') + '">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>' +
					'</button>' +
				'</div>';
			return li;
		}

		function isValidUrl(value) {
			if (!value) return false;
			// Accept absolute http(s)/mailto, or admin-relative paths.
			if (/^(https?:\/\/|mailto:)/i.test(value)) return true;
			if (value.indexOf('/') === 0) return true;
			return false;
		}

		// Choosing a built-in icon clears any pasted SVG so the icon wins.
		if (dialogFields.icon) {
			dialogFields.icon.addEventListener('change', clearSvgOnIconPick);
		}
		// Live-preview the pasted SVG.
		if (dialogFields.icon_svg) {
			dialogFields.icon_svg.addEventListener('input', updateSvgPreview);
		}

		// Close dialog on backdrop click + Escape.
		dialogBackdrop.addEventListener('click', function (e) {
			if (e.target === dialogBackdrop) closeDialog();
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !dialogBackdrop.hidden) closeDialog();
		});

		// Initial serialize so the hidden field reflects DOM state from PHP-render.
		markGroupMembers();
		serialize();
	});
})();
