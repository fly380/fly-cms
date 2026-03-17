/**
 * lang-widget.js — Автоперекладач fly-CMS v5.1
 */
(function() {
    'use strict';

    const CFG = Object.assign({
        endpoint:    '/templates/translate.php',
        languages:   ['en', 'pl', 'de'],
        position:    'navbar',
        style:       'flag_name',
        cache_hours: 24,
        chunk_size:  25,
        delay_ms:    50,
        config_hash: '',
    }, window.LT_CONFIG || {});

    const CACHE_TIMEOUT = CFG.cache_hours * 60 * 60 * 1000;

    const ALL_LANGS = {
        uk: { flag: '🇺🇦', name: 'Українська', short: 'UA' },
        en: { flag: '🇬🇧', name: 'English',     short: 'EN' },
        pl: { flag: '🇵🇱', name: 'Polski',      short: 'PL' },
        de: { flag: '🇩🇪', name: 'Deutsch',     short: 'DE' },
        fr: { flag: '🇫🇷', name: 'Français',    short: 'FR' },
        es: { flag: '🇪🇸', name: 'Español',     short: 'ES' },
        it: { flag: '🇮🇹', name: 'Italiano',    short: 'IT' },
        cs: { flag: '🇨🇿', name: 'Čeština',     short: 'CS' },
        sk: { flag: '🇸🇰', name: 'Slovenčina',  short: 'SK' },
        ro: { flag: '🇷🇴', name: 'Română',      short: 'RO' },
        hu: { flag: '🇭🇺', name: 'Magyar',      short: 'HU' },
    };

    const LANGS = { uk: ALL_LANGS.uk };
    (CFG.languages || []).forEach(code => {
        if (ALL_LANGS[code]) LANGS[code] = ALL_LANGS[code];
    });

    let currentLang      = 'uk';
    let originalContents = new Map();
    let translationCache = new Map();
    let isTranslating    = false;

    // Інвалідація кешу при зміні конфігу
    try {
        const newHash = CFG.config_hash || '';
        const oldHash = localStorage.getItem('lt_config_hash') || '';
        if (newHash && newHash !== oldHash) {
            localStorage.removeItem('translation_cache');
            localStorage.removeItem('site_language');
            localStorage.setItem('lt_config_hash', newHash);
        }
        const saved = localStorage.getItem('site_language');
        if (saved && LANGS[saved]) currentLang = saved;
        const cached = localStorage.getItem('translation_cache');
        if (cached) Object.entries(JSON.parse(cached)).forEach(([k,v]) => translationCache.set(k,v));
    } catch(e) {}

    // ── CSS ──────────────────────────────────────────────────────────────────
    const style = document.createElement('style');
    style.textContent = `
        .lang-switcher { position:relative; display:inline-flex; align-items:center; }
        .lang-btn {
            background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.3);
            color:white; padding:5px 11px; border-radius:6px; cursor:pointer;
            font-size:14px; display:flex; align-items:center; gap:5px;
            transition:background .2s; white-space:nowrap; line-height:1.4;
        }
        .lang-btn:hover { background:rgba(255,255,255,0.28); }
        .lang-btn .lt-arrow { font-size:9px; opacity:.7; }
        .lang-dropdown {
            position:fixed; background:white; border-radius:10px;
            box-shadow:0 6px 24px rgba(0,0,0,.18);
            display:none; z-index:99999; min-width:170px; overflow:hidden;
        }
        .lang-dropdown.show { display:block; }
        .lang-option {
            padding:9px 14px; cursor:pointer; color:#222;
            display:flex; align-items:center; gap:10px;
            transition:background .1s; font-size:14px;
        }
        .lang-option:hover { background:#f4f6fb; }
        .lang-option.active { background:#eef2ff; color:#1e40af; }
        .lang-option .lt-check { margin-left:auto; opacity:0; font-size:12px; }
        .lang-option.active .lt-check { opacity:1; }
        .lang-translating .lang-btn { opacity:.6; pointer-events:none; }
        /* Прогрес-бар вгорі сторінки */
        #lt-progress {
            position:fixed; top:0; left:0; height:3px; width:0%;
            background:linear-gradient(90deg,#2E5FA3,#5b8dee);
            z-index:999999; transition:width .2s ease;
            box-shadow:0 0 8px rgba(46,95,163,.6);
        }
        #lt-progress.done { width:100% !important; opacity:0; transition:width .1s,opacity .4s .1s; }
        .lt-float-mount {
            position:fixed; bottom:24px; right:24px; z-index:9998;
        }
        .lt-float-mount .lang-btn {
            background:#1e3a6e; border-color:#2E5FA3; color:#fff;
            box-shadow:0 3px 14px rgba(0,0,0,.3); padding:8px 14px;
            border-radius:8px; font-size:15px;
        }
        .lt-float-mount .lang-btn:hover { background:#2E5FA3; }
        @media (max-width:768px) { .lang-btn { padding:4px 8px; font-size:13px; } }
    `;
    document.head.appendChild(style);

    function btnLabel(lang) {
        const l = LANGS[lang] || ALL_LANGS[lang] || { flag:'🌐', short:lang.toUpperCase(), name: lang };
        if (CFG.style === 'flag')     return `<span>${l.flag}</span>`;
        if (CFG.style === 'dropdown') return `<span>${l.name}</span>`;
        return `<span>${l.flag}</span><span>${l.short}</span>`;
    }

    function getMountEl() {
        const pos = CFG.position || 'navbar';

        // Floating — завжди створюємо fixed div
        if (pos === 'floating') {
            let m = document.getElementById('lang-switcher-mount');
            if (!m) {
                m = document.createElement('div');
                m.id = 'lang-switcher-mount';
                document.body.appendChild(m);
            }
            m.className = 'lt-float-mount';
            m.style.cssText = '';
            return m;
        }

        // Footer — знаходимо або створюємо в footer
        if (pos === 'footer') {
            let m = document.getElementById('lang-switcher-mount');
            if (!m) {
                m = document.createElement('div');
                m.id = 'lang-switcher-mount';
                const footer = document.querySelector('footer .container') || document.querySelector('footer');
                if (footer) footer.appendChild(m);
                else document.body.appendChild(m);
            }
            m.style.cssText = 'display:inline-block;margin-top:6px';
            return m;
        }

        // Navbar — mount вже є в шаблоні
        return document.getElementById('lang-switcher-mount');
    }

    function positionDropdown(btn, dd) {
        const rect = btn.getBoundingClientRect();
        const ddH  = Object.keys(LANGS).length * 38 + 12;
        const spaceBelow = window.innerHeight - rect.bottom;
        const openUp = spaceBelow < ddH + 10;

        dd.style.minWidth = Math.max(rect.width, 170) + 'px';

        if (openUp) {
            dd.style.top    = '';
            dd.style.bottom = (window.innerHeight - rect.top + 6) + 'px';
        } else {
            dd.style.top    = (rect.bottom + 6) + 'px';
            dd.style.bottom = '';
        }

        // Горизонтальне вирівнювання — не виходити за правий край
        const right = window.innerWidth - rect.right;
        dd.style.right = right + 'px';
        dd.style.left  = '';

        const arrow = btn.querySelector('.lt-arrow');
        if (arrow) arrow.textContent = openUp ? '▲' : '▼';
    }

    function createSwitcher() {
        const mount = getMountEl();
        if (!mount) return;

        const options = Object.entries(LANGS).map(([code,l]) => `
            <div class="lang-option ${code===currentLang?'active':''}" data-lang="${code}">
                <span>${l.flag}</span><span>${l.name}</span>
                <span class="lt-check">✓</span>
            </div>`).join('');

        mount.innerHTML = `
            <div class="lang-switcher" id="lt-switcher">
                <button class="lang-btn" id="ltBtn" aria-haspopup="true" aria-expanded="false">
                    ${btnLabel(currentLang)}<span class="lt-arrow">▼</span>
                </button>
            </div>`;

        // Dropdown — поза switcher, прямо в body щоб не обрізався overflow
        let dd = document.getElementById('ltDropdown');
        if (dd) dd.remove();
        dd = document.createElement('div');
        dd.className = 'lang-dropdown';
        dd.id = 'ltDropdown';
        dd.innerHTML = options;
        document.body.appendChild(dd);

        const btn = document.getElementById('ltBtn');

        btn.onclick = e => {
            e.stopPropagation();
            if (dd.classList.contains('show')) {
                dd.classList.remove('show');
                btn.setAttribute('aria-expanded', 'false');
            } else {
                positionDropdown(btn, dd);
                dd.classList.add('show');
                btn.setAttribute('aria-expanded', 'true');
            }
        };

        document.addEventListener('click', e => {
            if (!dd.contains(e.target) && e.target !== btn) {
                dd.classList.remove('show');
                btn.setAttribute('aria-expanded', 'false');
            }
        });

        dd.querySelectorAll('.lang-option').forEach(opt => {
            opt.onclick = () => {
                dd.classList.remove('show');
                btn.setAttribute('aria-expanded', 'false');
                if (opt.dataset.lang !== currentLang && !isTranslating) {
                    switchLanguage(opt.dataset.lang);
                }
            };
        });
    }

    function updateButton(lang) {
        const btn = document.getElementById('ltBtn');
        if (!btn) return;
        const arrow = document.getElementById('ltDropdown')?.classList.contains('show') ? '▲' : '▼';
        btn.innerHTML = btnLabel(lang) + `<span class="lt-arrow">${arrow}</span>`;
        document.querySelectorAll('#ltDropdown .lang-option').forEach(o =>
            o.classList.toggle('active', o.dataset.lang === lang));
    }

    // ── Прогрес-бар ──────────────────────────────────────────────────────────
    let progressEl = null;

    function progressCreate() {
        if (!progressEl) {
            progressEl = document.createElement('div');
            progressEl.id = 'lt-progress';
            document.body.appendChild(progressEl);
        }
        progressEl.className = '';
        progressEl.style.width = '5%';
        progressEl.style.opacity = '1';
    }

    function progressSet(pct) {
        if (progressEl) progressEl.style.width = Math.min(95, pct) + '%';
    }

    function progressDone() {
        if (progressEl) {
            progressEl.style.width = '100%';
            progressEl.classList.add('done');
            setTimeout(() => { if (progressEl) progressEl.style.width = '0%'; }, 600);
        }
    }

    function setTranslating(on) {
        isTranslating = on;
        const sw = document.getElementById('lt-switcher');
        if (sw) sw.classList.toggle('lang-translating', on);
        if (on) progressCreate();
        else progressDone();
    }

    function cacheKey(lang) { return lang + '_' + window.location.pathname; }

    function saveCache(lang, texts, translations) {
        try {
            translationCache.set(cacheKey(lang), { ts:Date.now(), texts, translations });
            const obj = {}; translationCache.forEach((v,k) => obj[k]=v);
            localStorage.setItem('translation_cache', JSON.stringify(obj));
        } catch(e) {}
    }

    function loadCache(lang, count) {
        const c = translationCache.get(cacheKey(lang));
        if (c && (Date.now()-c.ts) < CACHE_TIMEOUT && c.translations.length === count) return c.translations;
        return null;
    }

    async function fetchTranslations(lang, texts) {
        const cached = loadCache(lang, texts.length);
        if (cached) return cached;

        // Розбиваємо на чанки
        const chunks = [];
        for (let i = 0; i < texts.length; i += CFG.chunk_size) {
            chunks.push({ idx: chunks.length, texts: texts.slice(i, i + CFG.chunk_size) });
        }

        const all = new Array(texts.length);
        let done = 0;

        // Паралельні запити — не більше 3 одночасно
        const PARALLEL = 3;
        for (let i = 0; i < chunks.length; i += PARALLEL) {
            const batch = chunks.slice(i, i + PARALLEL);
            await Promise.all(batch.map(async chunk => {
                try {
                    const r = await fetch(CFG.endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ lang, texts: chunk.texts })
                    });
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    const d = await r.json();
                    const results = Array.isArray(d.translations) && d.translations.length === chunk.texts.length
                        ? d.translations : chunk.texts;
                    results.forEach((t, j) => { all[chunk.idx * CFG.chunk_size + j] = t; });
                } catch(err) {
                    console.warn('[lang-widget]', err);
                    chunk.texts.forEach((t, j) => { all[chunk.idx * CFG.chunk_size + j] = t; });
                }
                done++;
                progressSet(10 + (done / chunks.length) * 85);
            }));
        }

        const result = texts.map((t, i) => all[i] ?? t);
        saveCache(lang, texts, result);
        return result;
    }

    const SKIP_TAGS = new Set(['SCRIPT','STYLE','CODE','PRE','KBD','SAMP','VAR','MATH','SVG',
        'CANVAS','IFRAME','NOSCRIPT','INPUT','TEXTAREA','SELECT','OPTION','BUTTON','IMG',
        'VIDEO','AUDIO','PICTURE','FIGURE']);

    function shouldSkip(el) {
        if (SKIP_TAGS.has(el.tagName)) return true;
        if (el.classList.contains('no-translate') || el.classList.contains('lang-switcher')) return true;
        return el.isContentEditable;
    }

    function collectTexts() {
        const nodes = [];
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                let p = node.parentElement;
                while (p && p !== document.body) {
                    if (shouldSkip(p)) return NodeFilter.FILTER_REJECT;
                    p = p.parentElement;
                }
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        let node;
        while ((node = walker.nextNode())) {
            nodes.push(node);
            if (!originalContents.has(node)) originalContents.set(node, node.nodeValue);
        }
        return { nodes, texts: nodes.map(n => n.nodeValue.trim()) };
    }

    async function translatePage(lang) {
        if (lang === 'uk') {
            originalContents.forEach((orig, node) => { node.nodeValue = orig; });
            currentLang = 'uk'; updateButton('uk');
            try { localStorage.setItem('site_language','uk'); } catch(e) {}
            return;
        }
        const { nodes, texts } = collectTexts();
        if (!nodes.length) return;
        const translations = await fetchTranslations(lang, texts);
        nodes.forEach((node, i) => { if (translations[i]) node.nodeValue = translations[i]; });
        currentLang = lang; updateButton(lang);
        try { localStorage.setItem('site_language', lang); } catch(e) {}
    }

    async function switchLanguage(lang) {
        if (lang === currentLang || isTranslating) return;
        setTranslating(true);
        await translatePage(lang);
        setTranslating(false);
    }

    async function init() {
        createSwitcher();
        const showMore = document.getElementById('showMoreBtn');
        if (showMore) showMore.addEventListener('click', () => {
            if (currentLang !== 'uk') setTimeout(() => switchLanguage(currentLang), 150);
        });
        if (currentLang !== 'uk') {
            setTimeout(async () => {
                const { nodes, texts } = collectTexts();
                if (!nodes.length) return;
                const cached = loadCache(currentLang, texts.length);
                if (cached) { nodes.forEach((n,i) => { if (cached[i]) n.nodeValue=cached[i]; }); updateButton(currentLang); }
                else { setTranslating(true); await translatePage(currentLang); setTranslating(false); }
            }, 400);
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

})();