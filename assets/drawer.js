/* ACSS Recipe Drawer — shadow-DOM drawer UI.
 * Self-invoking, no dependencies, no build step. Idempotent against
 * double-enqueue. Fetches recipes on first expand, not on page load.
 */
(function () {
	'use strict';

	var cfg = window.acssRecipeDrawer;
	if (!cfg || !cfg.ajaxUrl || !cfg.nonce) {
		return;
	}
	if (document.getElementById('acss-recipe-drawer-host')) {
		return; // already mounted
	}

	var strings = cfg.strings || {};
	var recipes = null; // fetched lazily
	var fetching = false;
	var activeIndex = -1;
	var matches = [];

	// --- Drag state ---
	var DRAG_THRESHOLD = 5; // px before a mousedown counts as a drag, not a click
	var dragging = false;
	var justDragged = false;
	var dragStartX = 0, dragStartY = 0;
	var dragOriginLeft = 0, dragOriginTop = 0;
	var STORAGE_KEY = 'ard-position';
	var WIDTH_KEY = 'ard-width';
	var WRAP_KEY = 'ard-wrap';
	var MIN_WIDTH = 420;
	var MAX_WIDTH = function () { return window.innerWidth - 32; };

	var host = document.createElement('div');
	host.id = 'acss-recipe-drawer-host';
	var shadow = host.attachShadow({ mode: 'closed' });

	var style = document.createElement('style');
	style.textContent = cfg.cssText || '';
	shadow.appendChild(style);

	var root = document.createElement('div');
	root.id = 'ard-host';
	shadow.appendChild(root);

	// --- Build the DOM. ---
	var tab = document.createElement('button');
	tab.id = 'ard-tab';
	tab.type = 'button';
	tab.textContent = strings.tab || 'ACSS Recipes';
	root.appendChild(tab);

	var panel = document.createElement('div');
	panel.id = 'ard-panel';
	root.appendChild(panel);

	// Header.
	var header = document.createElement('header');
	var title = document.createElement('span');
	title.className = 'ard-title';
	title.textContent = strings.tab || 'ACSS Recipes';
	var actionsTop = document.createElement('span');
	actionsTop.className = 'ard-actions-top';
	var refreshBtn = document.createElement('button');
	refreshBtn.type = 'button';
	refreshBtn.className = 'ard-btn';
	refreshBtn.textContent = strings.refresh || 'Refresh';
	actionsTop.appendChild(refreshBtn);
	header.appendChild(title);
	header.appendChild(actionsTop);
	panel.appendChild(header);

	// Search box.
	var searchWrap = document.createElement('div');
	searchWrap.id = 'ard-search-wrap';
	var input = document.createElement('input');
	input.id = 'ard-input';
	input.type = 'text';
	input.setAttribute('autocomplete', 'off');
	input.setAttribute('placeholder', strings.placeholder || 'Type a recipe name...');
	searchWrap.appendChild(input);

	var autocomplete = document.createElement('div');
	autocomplete.id = 'ard-autocomplete';
	searchWrap.appendChild(autocomplete);
	panel.appendChild(searchWrap);

	// Output box.
	var output = document.createElement('textarea');
	output.id = 'ard-output';
	output.setAttribute('readonly', 'readonly');
	output.setAttribute('placeholder', strings.empty || 'No recipes found.');
	panel.appendChild(output);

	// Status row.
	var status = document.createElement('div');
	status.id = 'ard-status';
	var statusText = document.createElement('span');
	statusText.className = 'ard-status-text';
	statusText.textContent = '';
	status.appendChild(statusText);
	var wrapBtn = document.createElement('button');
	wrapBtn.type = 'button';
	wrapBtn.className = 'ard-btn';
	wrapBtn.textContent = strings.wrap || 'Wrap';
	var copyBtn = document.createElement('button');
	copyBtn.type = 'button';
	copyBtn.className = 'ard-btn ard-primary';
	copyBtn.textContent = strings.copy || 'Copy';
	var clearBtn = document.createElement('button');
	clearBtn.type = 'button';
	clearBtn.className = 'ard-btn';
	clearBtn.textContent = strings.clear || 'Clear';
	status.appendChild(wrapBtn);
	status.appendChild(clearBtn);
	status.appendChild(copyBtn);
	panel.appendChild(status);

	// Right-edge resize handle for horizontal expand.
	var resizeHandle = document.createElement('div');
	resizeHandle.id = 'ard-resize';
	panel.appendChild(resizeHandle);

	document.body.appendChild(host);

	// --- Position persistence (sessionStorage) ---
	function savePosition(left, top) {
		try {
			sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ left: left, top: top }));
		} catch (e) {
			// sessionStorage can throw in restricted iframes — silently degrade
		}
	}

	function loadPosition() {
		try {
			var raw = sessionStorage.getItem(STORAGE_KEY);
			if (!raw) return null;
			var p = JSON.parse(raw);
			if (p && typeof p.left === 'number' && typeof p.top === 'number') {
				return p;
			}
		} catch (e) {
			// parse error or storage unavailable — silently degrade
		}
		return null;
	}

	function clampPosition(left, top) {
		var rect = root.getBoundingClientRect();
		var w = rect.width || root.offsetWidth || 420;
		var h = rect.height || root.offsetHeight || 60;
		var maxLeft = window.innerWidth - w;
		var maxTop = window.innerHeight - h;
		if (left < 0) left = 0;
		if (left > maxLeft) left = maxLeft;
		if (top < 0) top = 0;
		if (top > maxTop) top = maxTop;
		return { left: left, top: top };
	}

	function applyPosition(left, top) {
		root.style.left = left + 'px';
		root.style.top = top + 'px';
		root.style.right = 'auto';
		root.style.bottom = 'auto';
	}

	// Restore saved position on mount, otherwise CSS default (bottom-left) applies.
	(function restorePosition() {
		var saved = loadPosition();
		if (saved) {
			var clamped = clampPosition(saved.left, saved.top);
			applyPosition(clamped.left, clamped.top);
		}
	})();

	// --- Drag handling ---
	// Both the collapsed tab and the open panel header are drag handles.
	// The refresh button inside the header is excluded.
	function startDrag(e, handle) {
		// Ignore mousedown on the refresh button — it should click, not drag.
		if (e.target === refreshBtn) return;

		dragStartX = e.clientX;
		dragStartY = e.clientY;
		var rect = root.getBoundingClientRect();
		dragOriginLeft = rect.left;
		dragOriginTop = rect.top;
		dragging = false;

		function onMove(ev) {
			var dx = ev.clientX - dragStartX;
			var dy = ev.clientY - dragStartY;
			if (!dragging && Math.abs(dx) + Math.abs(dy) > DRAG_THRESHOLD) {
				dragging = true;
			}
			if (dragging) {
				ev.preventDefault();
				var newLeft = dragOriginLeft + dx;
				var newTop = dragOriginTop + dy;
				var clamped = clampPosition(newLeft, newTop);
				applyPosition(clamped.left, clamped.top);
			}
		}

		function onUp(ev) {
			document.removeEventListener('mousemove', onMove);
			document.removeEventListener('mouseup', onUp);
			if (dragging) {
				var rect = root.getBoundingClientRect();
				savePosition(rect.left, rect.top);
				justDragged = true;
				setTimeout(function () { justDragged = false; }, 50);
			}
			dragging = false;
		}

		document.addEventListener('mousemove', onMove);
		document.addEventListener('mouseup', onUp);
	}

	tab.addEventListener('mousedown', function (e) {
		startDrag(e, 'tab');
	});
	header.addEventListener('mousedown', function (e) {
		startDrag(e, 'header');
	});

	// --- Helpers. ---
	function setStatus(text) {
		statusText.textContent = text;
	}

	function flashCopied() {
		var prev = copyBtn.textContent;
		copyBtn.textContent = strings.copied || 'Copied!';
		copyBtn.classList.add('ard-flash');
		setTimeout(function () {
			copyBtn.textContent = prev;
			copyBtn.classList.remove('ard-flash');
		}, 1500);
	}

	function copyToClipboard(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(flashCopied).catch(function () {
				legacyCopy(text);
			});
		} else {
			legacyCopy(text);
		}
	}

	function legacyCopy(text) {
		try {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.focus();
			ta.select();
			var ok = document.execCommand('copy');
			document.body.removeChild(ta);
			if (ok) {
				flashCopied();
			}
		} catch (e) {
			// manual select-and-copy remains as the final fallback
		}
	}

	// --- Recipe fetching. ---
	function fetchRecipes(refresh, cb) {
		if (recipes && !refresh) {
			cb(null, recipes);
			return;
		}
		if (fetching) {
			return;
		}
		fetching = true;
		setStatus(strings.loading || 'Loading...');
		var body = new URLSearchParams();
		body.append('action', 'acss_recipe_drawer_get');
		body.append('nonce', cfg.nonce);
		if (refresh) {
			body.append('refresh', '1');
		}
		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				fetching = false;
				if (json && json.success && json.data && json.data.recipes) {
					recipes = json.data.recipes;
					setStatus((Object.keys(recipes).length) + ' recipes');
					cb(null, recipes);
				} else {
					setStatus(strings.error || 'Failed to load recipes.');
					cb(new Error('bad response'), null);
				}
			})
			.catch(function (err) {
				fetching = false;
				setStatus(strings.error || 'Failed to load recipes.');
				cb(err, null);
			});
	}

	// --- Autocomplete. ---
	function normalizeQuery(q) {
		// Tolerate a leading '?' and trailing ';' so ACSS Bricks/Gutenberg
		// muscle-memory syntax still resolves.
		return q.replace(/^\?/, '').replace(/;$/, '').trim().toLowerCase();
	}

	function filterRecipes(q) {
		if (!recipes) {
			return [];
		}
		var keys = Object.keys(recipes);
		var out = [];
		for (var i = 0; i < keys.length && out.length < 10; i++) {
			var name = keys[i];
			if (q === '' || name.toLowerCase().indexOf(q) !== -1) {
				out.push(name);
			}
		}
		return out;
	}

	function firstCssLine(css) {
		if (!css) {
			return '';
		}
		var lines = css.split(/\r?\n/);
		for (var i = 0; i < lines.length; i++) {
			var t = lines[i].trim();
			if (t) {
				return t.length > 80 ? t.slice(0, 80) + '...' : t;
			}
		}
		return '';
	}

	function renderAutocomplete() {
		autocomplete.innerHTML = '';
		activeIndex = -1;
		if (matches.length === 0) {
			autocomplete.classList.remove('ard-show');
			return;
		}
		for (var i = 0; i < matches.length; i++) {
			(function (idx, name) {
				var row = document.createElement('div');
				row.className = 'ard-ac-row';
				row.setAttribute('data-name', name);

				var nameWrap = document.createElement('div');
				nameWrap.className = 'ard-ac-name';
				var nameSpan = document.createElement('span');
				nameSpan.textContent = name; // textContent — custom names are user-supplied
				nameWrap.appendChild(nameSpan);
				if (recipes[name] && recipes[name].source === 'custom') {
					var badge = document.createElement('span');
					badge.className = 'ard-ac-badge';
					badge.textContent = strings.custom || 'custom';
					nameWrap.appendChild(badge);
				}
				row.appendChild(nameWrap);

				var preview = document.createElement('div');
				preview.className = 'ard-ac-preview';
				preview.textContent = firstCssLine(recipes[name] ? recipes[name].css : '');
				row.appendChild(preview);

				row.addEventListener('mousedown', function (e) {
					e.preventDefault(); // keep focus on input
					selectRecipe(name);
				});
				autocomplete.appendChild(row);
			})(i, matches[i]);
		}
		autocomplete.classList.add('ard-show');
	}

	function setActive(idx) {
		var rows = autocomplete.querySelectorAll('.ard-ac-row');
		if (rows.length === 0) {
			return;
		}
		for (var i = 0; i < rows.length; i++) {
			rows[i].classList.remove('ard-active');
		}
		if (idx < 0) {
			idx = rows.length - 1;
		}
		if (idx >= rows.length) {
			idx = 0;
		}
		activeIndex = idx;
		rows[idx].classList.add('ard-active');
		rows[idx].scrollIntoView({ block: 'nearest' });
	}

	function selectRecipe(name) {
		if (!recipes || !recipes[name]) {
			return;
		}
		var r = recipes[name];
		output.value = r.css || '';
		input.value = name;
		autocomplete.classList.remove('ard-show');
		matches = [];
		var lines = r.css ? r.css.split(/\r?\n/).length : 0;
		var badge = r.source === 'custom' ? ' (' + (strings.custom || 'custom') + ')' : '';
		setStatus(lines + ' lines' + badge);
	}

	// --- Event wiring. ---
	function onInput() {
		var q = normalizeQuery(input.value);
		if (!recipes) {
			fetchRecipes(false, function () {
				matches = filterRecipes(q);
				renderAutocomplete();
			});
			return;
		}
		matches = filterRecipes(q);
		renderAutocomplete();
	}

	input.addEventListener('input', onInput);

	input.addEventListener('keydown', function (e) {
		if (matches.length === 0) {
			return;
		}
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			setActive(activeIndex + 1);
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			setActive(activeIndex - 1);
		} else if (e.key === 'Enter') {
			e.preventDefault();
			var name;
			if (activeIndex >= 0 && activeIndex < matches.length) {
				name = matches[activeIndex];
			} else if (matches.length > 0) {
				name = matches[0];
			}
			if (name) {
				selectRecipe(name);
			}
		} else if (e.key === 'Escape') {
			autocomplete.classList.remove('ard-show');
			matches = [];
		}
	});

	// Close autocomplete on blur, after a short timeout so clicks land.
	input.addEventListener('blur', function () {
		setTimeout(function () {
			autocomplete.classList.remove('ard-show');
		}, 150);
	});
	input.addEventListener('focus', function () {
		if (matches.length > 0) {
			autocomplete.classList.add('ard-show');
		}
	});

	copyBtn.addEventListener('click', function () {
		if (output.value) {
			copyToClipboard(output.value);
		}
	});

	clearBtn.addEventListener('click', function () {
		output.value = '';
		input.value = '';
		setStatus('');
		matches = [];
		autocomplete.classList.remove('ard-show');
		input.focus();
	});

	refreshBtn.addEventListener('click', function () {
		fetchRecipes(true, function (err, data) {
			if (!err && data) {
				setStatus(Object.keys(data).length + ' recipes (refreshed)');
			}
		});
	});

	// --- Word wrap toggle ---
	function applyWrap(on) {
		if (on) {
			output.classList.add('ard-wrap');
		} else {
			output.classList.remove('ard-wrap');
		}
	}

	function setWrapBtn(on) {
		if (on) {
			wrapBtn.classList.add('ard-active');
		} else {
			wrapBtn.classList.remove('ard-active');
		}
	}

	function saveWrap(on) {
		try { sessionStorage.setItem(WRAP_KEY, on ? '1' : '0'); } catch (e) {}
	}

	function loadWrap() {
		try { return sessionStorage.getItem(WRAP_KEY) === '1'; } catch (e) { return false; }
	}

	var wrapped = loadWrap();
	applyWrap(wrapped);
	setWrapBtn(wrapped);

	wrapBtn.addEventListener('click', function () {
		wrapped = !wrapped;
		applyWrap(wrapped);
		setWrapBtn(wrapped);
		saveWrap(wrapped);
	});

	// --- Horizontal resize (right-edge handle) ---
	function applyWidth(w) {
		root.style.width = w + 'px';
	}

	function saveWidth(w) {
		try { sessionStorage.setItem(WIDTH_KEY, String(w)); } catch (e) {}
	}

	function loadWidth() {
		try {
			var raw = sessionStorage.getItem(WIDTH_KEY);
			return raw ? parseInt(raw, 10) : null;
		} catch (e) { return null; }
	}

	(function restoreWidth() {
		var saved = loadWidth();
		if (saved && saved >= MIN_WIDTH && saved <= MAX_WIDTH()) {
			applyWidth(saved);
		}
	})();

	resizeHandle.addEventListener('mousedown', function (e) {
		e.preventDefault();
		var startX = e.clientX;
		var startW = root.getBoundingClientRect().width || MIN_WIDTH;
		function move(ev) {
			var nw = startW + (ev.clientX - startX);
			if (nw < MIN_WIDTH) { nw = MIN_WIDTH; }
			if (nw > MAX_WIDTH()) { nw = MAX_WIDTH(); }
			applyWidth(nw);
		}
		function up() {
			document.removeEventListener('mousemove', move);
			document.removeEventListener('mouseup', up);
			saveWidth(root.getBoundingClientRect().width || MIN_WIDTH);
		}
		document.addEventListener('mousemove', move);
		document.addEventListener('mouseup', up);
	});

	tab.addEventListener('click', function (e) {
		// Suppress the click that follows a drag so the panel does not
		// accidentally toggle open/closed.
		if (justDragged) {
			e.preventDefault();
			e.stopPropagation();
			return;
		}
		var open = panel.classList.toggle('ard-open');
		if (open && !recipes && !fetching) {
			fetchRecipes(false, function () {});
		}
		if (open) {
			setTimeout(function () { input.focus(); }, 50);
		}
	});
})();
