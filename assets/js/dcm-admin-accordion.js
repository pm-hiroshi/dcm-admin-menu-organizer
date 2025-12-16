(() => {
	const data = window.dcmAdminMenuAccordionData;
	const configHash = (data && data.config_hash) ? data.config_hash : '';

	// PHP側でセパレーター・メニューにグループクラスを付与しているため、
	// JSはDOMからグループ情報を取得する（href突合やgroups配列は不要）。
	const separators = Array.from(
		document.querySelectorAll('#adminmenu > li.dcm-accordion-separator[id^="separator-group-"]')
	);
	if (separators.length === 0) {
		return;
	}

	// セパレーター直前のメニュー（サブメニュー展開時に余白が出やすい）へクラス付与
	separators.forEach((sep) => {
		const prev = sep.previousElementSibling;
		if (prev && prev.matches && prev.matches('li.menu-top')) {
			prev.classList.add('dcm-menu-before-separator');
		}
	});

	document.body.classList.add('dcm-accordion-loading');

	const storageKey = 'dcm_accordion_state';

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
	 * 現在地グループのセパレーターIDを取得
	 */
	function getCurrentSeparatorId() {
		// WP標準の current 判定（存在すれば最優先）
		let currentTop =
			document.querySelector('#adminmenu > li.menu-top.wp-has-current-submenu') ||
			document.querySelector('#adminmenu > li.menu-top.current');

		// サブメニュー側の current からトップを辿るフォールバック
		if (!currentTop) {
			const current = document.querySelector('#adminmenu li.current');
			if (current && current.closest) {
				const top = current.closest('li.menu-top');
				if (top) {
					currentTop = top;
				}
			}
		}

		if (!currentTop) {
			return '';
		}

		// まずはグループクラスから特定（最短）
		const groupClass = Array.from(currentTop.classList).find((c) => c.startsWith('dcm-accordion-group-separator-group-'));
		if (groupClass) {
			const sid = groupClass.replace('dcm-accordion-group-', '');
			if (sid && document.getElementById(sid)) {
				return sid;
			}
		}

		// フォールバック: 直前のセパレーターを探す
		let prev = currentTop.previousElementSibling;
		while (prev) {
			if (
				prev instanceof HTMLElement &&
				prev.id &&
				prev.id.startsWith('separator-group-') &&
				prev.classList.contains('dcm-accordion-separator')
			) {
				return prev.id;
			}
			prev = prev.previousElementSibling;
		}

		return '';
	}

	/**
	 * アコーディオン初期化: 保存状態を適用、クリックで開閉。
	 */
	function initAccordion() {
		let state = getAccordionState();
		const currentSeparatorId = getCurrentSeparatorId();

		separators.forEach((separatorLi) => {
			const separatorId = separatorLi.id;
			const groupClass = 'dcm-accordion-group-' + separatorId;

			const menuItems = Array.from(document.querySelectorAll('#adminmenu > li.' + groupClass));
			if (menuItems.length === 0) {
				console.warn('DCM Accordion: No menu items found for group:', separatorId);
				return;
			}

			const hasCurrentInGroup = menuItems.some((item) => {
				return (
					item.classList.contains('current') ||
					item.classList.contains('wp-has-current-submenu') ||
					!!item.querySelector('.current') ||
					!!item.querySelector('.wp-has-current-submenu')
				);
			});

			// 現在地を含むグループは「初期表示では必ず展開」
			const mustStartExpanded = (currentSeparatorId && separatorId === currentSeparatorId) || hasCurrentInGroup;
			if (mustStartExpanded) {
				state[separatorId] = 'expanded';
				saveAccordionState(state);
			}

			const isCollapsed = !mustStartExpanded && state[separatorId] === 'collapsed';

			// WPコアのセパレーターは aria-hidden="true" になりがちなので、アクセシビリティ警告を避ける
			separatorLi.removeAttribute('aria-hidden');
			separatorLi.setAttribute('aria-hidden', 'false');
			try {
				const content = getComputedStyle(separatorLi, '::after').content;
				if (content && content !== 'none') {
					const label = content.replace(/^['"]|['"]$/g, '');
					if (label) {
						separatorLi.setAttribute('aria-label', label);
					}
				}
			} catch (e) {
				// ignore
			}

			// セパレーターにARIA属性を付与（現在地グループも閉じられる）
			separatorLi.setAttribute('tabindex', '0');
			separatorLi.setAttribute('role', 'button');
			separatorLi.setAttribute('aria-expanded', (!isCollapsed).toString());

			// PHP側で付与済みでも、JS側でもクラスを揃えておく
			menuItems.forEach((item) => item.classList.add('dcm-accordion-menu-item'));

			const updateState = () => {
				const expanded = !separatorLi.classList.contains('dcm-collapsed');
				separatorLi.setAttribute('aria-expanded', expanded.toString());
				state[separatorId] = expanded ? 'expanded' : 'collapsed';
				saveAccordionState(state);
			};

			let isToggling = false;
			if (isCollapsed) {
				separatorLi.classList.add('dcm-collapsed');
				menuItems.forEach((item) => item.classList.add('dcm-hidden'));
			} else {
				separatorLi.classList.remove('dcm-collapsed');
				menuItems.forEach((item) => item.classList.remove('dcm-hidden'));
			}

			const toggle = (e) => {
				e.preventDefault();
				e.stopPropagation();

				if (isToggling) {
					return;
				}
				isToggling = true;

				separatorLi.classList.toggle('dcm-collapsed');
				menuItems.forEach((item) => item.classList.toggle('dcm-hidden'));

				triggerResize();
				updateState();

				// トグルガード（連続クリック防止）
				requestAnimationFrame(() => {
					isToggling = false;
				});
			};

			separatorLi.addEventListener('click', toggle);
			separatorLi.addEventListener('keydown', (e) => {
				if (e.key === 'Enter' || e.key === ' ') {
					toggle(e);
				}
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
