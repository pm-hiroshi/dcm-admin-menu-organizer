(() => {
	const data = window.dcmAdminMenuAccordionData;
	if (!data || !Array.isArray(data.groups) || data.groups.length === 0) {
		return;
	}

	document.body.classList.add('dcm-accordion-loading');

	const accordionGroups = data.groups;
	const storageKey = 'dcm_accordion_state';
	const configHash = data.config_hash || '';

	/**
	 * 管理画面内の href を正規化し、/wp-admin/ 以降のパス+クエリをキーとして返す。
	 */
	function normalizeAdminHref(href) {
		try {
			const url = new URL(href, window.location.origin);
			const adminIndex = url.pathname.indexOf('/wp-admin/');
			let path;
			if (adminIndex >= 0) {
				path = url.pathname.slice(adminIndex + '/wp-admin/'.length);
			} else {
				// /wp-admin/が無い場合は警告してフルパスをキーに使う
				console.warn('DCM Accordion: /wp-admin/ not found in href, using full pathname as key:', href);
				path = url.pathname.replace(/^\/+/, '');
			}
			return (path || '') + (url.search || '');
		} catch (e) {
			return (href || '').trim();
		}
	}

	/**
	 * 管理メニュー(li.menu-top)のhrefをキーにしたインデックスを作成。
	 * hrefが重複する場合は最初を優先し、警告を出す。
	 */
	function buildMenuIndex() {
		const map = new Map();
		document.querySelectorAll('#adminmenu > li.menu-top').forEach(li => {
			const link = li.querySelector('a[href]');
			if (!link) {
				return;
			}
			const key = normalizeAdminHref(link.getAttribute('href'));
			if (!key) {
				return;
			}
			if (!map.has(key)) {
				map.set(key, li);
			} else {
				const existingLi = map.get(key);
				const getInfo = el => {
					if (!el) return '[unknown]';
					const id = el.id ? `#${el.id}` : '';
					const text = el.textContent ? el.textContent.trim().replace(/\s+/g, ' ') : '';
					return `${id} "${text}"`;
				};
				console.warn(
					'DCM Accordion: Duplicate menu href detected, keeping first item',
					key,
					'\n  Existing:', getInfo(existingLi),
					'\n  Duplicate:', getInfo(li)
				);
			}
		});
		return map;
	}

	/**
	 * 設定スラッグを正規化して、メニューインデックスから要素を取得。
	 */
	function findMenuItem(slug, menuIndex) {
		const key = normalizeAdminHref(slug);
		return menuIndex.get(key) || null;
	}

	/**
	 * localStorage から開閉状態を取得。ハッシュ不一致時はリセット。
	 */
	function getAccordionState() {
		try {
			const stored = localStorage.getItem(storageKey);
			if (!stored) {
				return {};
			}
			const parsed = JSON.parse(stored);
			if (parsed.config_hash !== configHash) {
				console.info('DCM Accordion: Configuration changed, resetting accordion state');
				return {};
			}
			return parsed.states || {};
		} catch (e) {
			return {};
		}
	}

	/**
	 * 開閉状態をlocalStorageへ保存（ハッシュ付き）。
	 */
	function saveAccordionState(states) {
		try {
			localStorage.setItem(
				storageKey,
				JSON.stringify({
					config_hash: configHash,
					states,
				})
			);
		} catch (e) {
			// ignore
		}
	}

	/**
	 * レイアウト再計算をワンショットで呼ぶ（WP側のメニュー崩れ防止）。
	 */
	const triggerResize = (() => {
		let resizeId = null;
		return () => {
			if (resizeId !== null) {
				cancelAnimationFrame(resizeId);
			}
			resizeId = requestAnimationFrame(() => {
				window.dispatchEvent(new Event('resize'));
				resizeId = null;
			});
		};
	})();

	/**
	 * アコーディオン初期化: メニュー索引を作成し、保存状態を適用、クリックで開閉。
	 */
	function initAccordion() {
		let state = getAccordionState();
		const menuIndex = buildMenuIndex();

		accordionGroups.forEach(group => {
			const separatorId = group.separator_id;
			const menuSlugs = group.menu_slugs;

			const separatorLi = document.getElementById(separatorId);
			if (!separatorLi) {
				console.warn('DCM Accordion: Separator not found:', separatorId);
				return;
			}

			separatorLi.classList.add('dcm-accordion-separator');

			const menuItems = [];
			menuSlugs.forEach(slug => {
				const menuLi = findMenuItem(slug, menuIndex);
				if (menuLi) {
					menuLi.classList.add('dcm-accordion-menu-item');
					menuLi.dataset.accordionGroup = separatorId;
					menuItems.push(menuLi);
				} else {
					console.warn('DCM Accordion: Menu item not found for slug:', slug, 'in group:', separatorId);
				}
			});

			if (menuItems.length === 0) {
				console.warn('DCM Accordion: No menu items found for group:', separatorId);
				return;
			}

			const updateState = () => {
				state[separatorId] = separatorLi.classList.contains('dcm-collapsed') ? 'collapsed' : 'expanded';
				saveAccordionState(state);
			};

			let isToggling = false;

			const isCollapsed = state[separatorId] === 'collapsed';
			if (isCollapsed) {
				separatorLi.classList.add('dcm-collapsed');
				menuItems.forEach(item => item.classList.add('dcm-hidden'));
			}

			const toggle = e => {
				e.preventDefault();
				e.stopPropagation();

				if (isToggling) {
					return;
				}
				isToggling = true;

				const nowCollapsed = separatorLi.classList.toggle('dcm-collapsed');
				menuItems.forEach(item => item.classList.toggle('dcm-hidden'));

				triggerResize();

				updateState();

				// 軽いデバウンス
				requestAnimationFrame(() => { isToggling = false; });
			};

			separatorLi.addEventListener('click', toggle);
			separatorLi.addEventListener('keydown', e => {
				if (e.key === 'Enter' || e.key === ' ') {
					toggle(e);
				}
			});
			separatorLi.setAttribute('tabindex', '0');
			separatorLi.setAttribute('role', 'button');
			separatorLi.setAttribute('aria-expanded', (!isCollapsed).toString());

			// aria-expanded 更新
			separatorLi.addEventListener('click', () => {
				const expanded = !separatorLi.classList.contains('dcm-collapsed');
				separatorLi.setAttribute('aria-expanded', expanded.toString());
			});
			separatorLi.addEventListener('keydown', () => {
				const expanded = !separatorLi.classList.contains('dcm-collapsed');
				separatorLi.setAttribute('aria-expanded', expanded.toString());
			});
		});

		document.body.classList.remove('dcm-accordion-loading');
		triggerResize();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAccordion);
	} else {
		initAccordion();
	}
})();
