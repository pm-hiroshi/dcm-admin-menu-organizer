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
	 * アコーディオン初期化: 保存状態を適用、クリックで開閉。
	 */
	function initAccordion() {
		let state = getAccordionState();

		separators.forEach(separatorLi => {
			const separatorId = separatorLi.id;
			const groupClass = 'dcm-accordion-group-' + separatorId;

			const menuItems = Array.from(document.querySelectorAll('#adminmenu > li.' + groupClass));

			// PHP側で現在地グループのセパレーターにロッククラスを付与しているため、
			// JS側はロッククラスの有無だけを見て「閉じられない」ようにする。
			if (separatorLi.classList.contains('dcm-accordion-locked')) {
				separatorLi.classList.add('dcm-accordion-locked');
				separatorLi.classList.remove('dcm-collapsed');
				menuItems.forEach((item) => item.classList.remove('dcm-hidden'));

				separatorLi.setAttribute('aria-expanded', 'true');
				separatorLi.setAttribute('aria-disabled', 'true');
				separatorLi.removeAttribute('tabindex');
				separatorLi.removeAttribute('role');

				state[separatorId] = 'expanded';
				saveAccordionState(state);
				return;
			}

			// セパレーターにクラスとARIA属性を付与
			const isCollapsed = state[separatorId] === 'collapsed';
			separatorLi.setAttribute('tabindex', '0');
			separatorLi.setAttribute('role', 'button');
			separatorLi.setAttribute('aria-expanded', (!isCollapsed).toString());

			if (menuItems.length === 0) {
				console.warn('DCM Accordion: No menu items found for group:', separatorId);
				return;
			}

			// PHP側で付与済みでも、JS側でもクラスを揃えておく
			menuItems.forEach(item => item.classList.add('dcm-accordion-menu-item'));

			const updateState = () => {
				const expanded = !separatorLi.classList.contains('dcm-collapsed');
				separatorLi.setAttribute('aria-expanded', expanded.toString());
				state[separatorId] = expanded ? 'expanded' : 'collapsed';
				saveAccordionState(state);
			};

			let isToggling = false;

			if (isCollapsed) {
				separatorLi.classList.add('dcm-collapsed');
				menuItems.forEach(item => item.classList.add('dcm-hidden'));
			}

			const toggle = e => {
				e.preventDefault();
				e.stopPropagation();

				if (separatorLi.classList.contains('dcm-accordion-locked')) {
					return;
				}

				if (isToggling) {
					return;
				}
				isToggling = true;

				const nowCollapsed = separatorLi.classList.toggle('dcm-collapsed');
				menuItems.forEach(item => item.classList.toggle('dcm-hidden'));

				triggerResize();

				updateState();

				// トグルガード（連続クリック防止）
				requestAnimationFrame(() => { isToggling = false; });
			};

			separatorLi.addEventListener('click', toggle);
			separatorLi.addEventListener('keydown', e => {
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
