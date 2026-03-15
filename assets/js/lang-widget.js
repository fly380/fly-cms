/**
 * lang-widget.js — Автоперекладач fly-CMS v4.5
 * Виправлено: пошук всіх елементів, включаючи меню
 */
(function() {
    'use strict';

    const CONFIG = {
        endpoint: '/templates/translate.php',
        chunkSize: 15,
        delayBetweenChunks: 50,
        cacheTimeout: 24 * 60 * 60 * 1000
    };

    const LANGS = {
        uk: { flag: '🇺🇦', name: 'Українська', short: 'UA' },
        en: { flag: '🇬🇧', name: 'English',     short: 'EN' },
        pl: { flag: '🇵🇱', name: 'Polski',      short: 'PL' },
        de: { flag: '🇩🇪', name: 'Deutsch',     short: 'DE' }
    };

    let currentLang      = 'uk';
    let originalContents = new Map();
    let translationCache = new Map();
    let isTranslating    = false;

    // Завантажуємо збережену мову та кеш
    try {
        const saved = localStorage.getItem('site_language');
        if (saved && LANGS[saved]) currentLang = saved;
        const cached = localStorage.getItem('translation_cache');
        if (cached) {
            Object.entries(JSON.parse(cached)).forEach(([k, v]) => translationCache.set(k, v));
        }
    } catch(e) {}

    // ── CSS ──────────────────────────────────────────────────────────────────
    const style = document.createElement('style');
    style.textContent = `
        .lang-switcher { position: relative; display: inline-block; margin-left: 15px; }
        .lang-btn { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3);
                    color: white; padding: 6px 12px; border-radius: 6px; cursor: pointer;
                    font-size: 14px; display: flex; align-items: center; gap: 6px;
                    transition: all 0.2s; min-width: 80px; }
        .lang-btn:hover { background: rgba(255,255,255,0.3); }
        .lang-btn .flag { font-size: 16px; }
        .lang-btn .arrow { font-size: 10px; margin-left: 2px; }
        .lang-dropdown { position: absolute; top: 100%; right: 0; margin-top: 5px;
                         background: white; border-radius: 8px;
                         box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                         display: none; z-index: 10000; min-width: 160px; overflow: hidden; }
        .lang-dropdown.show { display: block; }
        .lang-option { padding: 10px 15px; cursor: pointer; color: #333;
                       display: flex; align-items: center; gap: 10px; transition: background 0.1s; }
        .lang-option:hover { background: #f5f5f5; }
        .lang-option.active { background: #e8f0fe; color: #0066cc; }
        .lang-option .flag { font-size: 18px; }
        .lang-option .name { flex: 1; font-size: 14px; }
        .lang-option .check { color: #0066cc; font-weight: bold; opacity: 0; }
        .lang-option.active .check { opacity: 1; }

        @media (max-width: 768px) { .lang-btn { padding: 4px 8px; font-size: 13px; min-width: 60px; } }
    `;
    document.head.appendChild(style);

    // ── UI ───────────────────────────────────────────────────────────────────
    function createSwitcher() {
        const mount = document.getElementById('lang-switcher-mount');
        if (!mount) return;

        mount.innerHTML = `
            <div class="lang-switcher">
                <button class="lang-btn" id="langBtn">
                    <span class="flag">${LANGS[currentLang].flag}</span>
                    <span class="name">${LANGS[currentLang].short}</span>
                    <span class="arrow">▼</span>
                </button>
                <div class="lang-dropdown" id="langDropdown">
                    ${Object.entries(LANGS).map(([code, l]) => `
                        <div class="lang-option ${code === currentLang ? 'active' : ''}" data-lang="${code}">
                            <span class="flag">${l.flag}</span>
                            <span class="name">${l.name}</span>
                            <span class="check">✓</span>
                        </div>`).join('')}
                </div>
            </div>`;

        const btn      = document.getElementById('langBtn');
        const dropdown = document.getElementById('langDropdown');
        
        btn.onclick = e => { 
            e.stopPropagation(); 
            dropdown.classList.toggle('show'); 
        };
        
        document.addEventListener('click', () => dropdown.classList.remove('show'));
        
        document.querySelectorAll('.lang-option').forEach(opt => {
            opt.onclick = () => {
                dropdown.classList.remove('show');
                if (opt.dataset.lang !== currentLang && !isTranslating) {
                    switchLanguage(opt.dataset.lang);
                }
            };
        });
    }

    function updateButton(lang) {
        const btn = document.getElementById('langBtn');
        if (!btn) return;
        
        btn.querySelector('.flag').textContent = LANGS[lang].flag;
        btn.querySelector('.name').textContent = LANGS[lang].short;
        
        document.querySelectorAll('.lang-option').forEach(opt =>
            opt.classList.toggle('active', opt.dataset.lang === lang));
    }

    // ── Робота з текстом ────────────────────────────────────────────────────
    
    /**
     * Отримує тільки текст з елемента, зберігаючи HTML структуру
     */
    function getTextContent(element) {
        const temp = document.createElement('div');
        temp.innerHTML = element.innerHTML;
        
        const excludeSelectors = 'script, style, code, pre, .no-translate, img, svg, iframe, button, input, select';
        temp.querySelectorAll(excludeSelectors).forEach(el => el.remove());
        
        return temp.innerText || temp.textContent || '';
    }

    /**
     * Замінює текст в елементі, зберігаючи всі HTML теги
     */
    function setTextContent(element, newText) {
        if (element.children.length === 0) {
            element.innerText = newText;
            return;
        }

        const walker = document.createTreeWalker(
            element,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function(node) {
                    if (!node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                    
                    const parent = node.parentElement;
                    if (parent.closest('script, style, code, pre, .no-translate, img, svg, iframe, button, input, select')) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    
                    return NodeFilter.FILTER_ACCEPT;
                }
            }
        );

        const textNodes = [];
        let node;
        while (node = walker.nextNode()) {
            textNodes.push(node);
        }

        if (textNodes.length > 0) {
            textNodes[0].nodeValue = newText;
            for (let i = 1; i < textNodes.length; i++) {
                textNodes[i].nodeValue = '';
            }
        } else {
            element.innerText = newText;
        }
    }

    // ── Кеш ─────────────────────────────────────────────────────────────────
    function cacheKey(lang) { 
        return lang + '_' + window.location.pathname; 
    }

    function saveCache(lang, texts, translations) {
        try {
            translationCache.set(cacheKey(lang), { 
                ts: Date.now(), 
                texts, 
                translations 
            });
            
            const obj = {};
            translationCache.forEach((v, k) => obj[k] = v);
            localStorage.setItem('translation_cache', JSON.stringify(obj));
        } catch(e) {}
    }

    function loadCache(lang, expectedCount) {
        const cached = translationCache.get(cacheKey(lang));
        if (cached && 
            (Date.now() - cached.ts) < CONFIG.cacheTimeout && 
            cached.translations.length === expectedCount) {
            return cached.translations;
        }
        return null;
    }

    // ── API ──────────────────────────────────────────────────────────────────
    async function fetchTranslations(lang, texts) {
        const cached = loadCache(lang, texts.length);
        if (cached) return cached;

        const allTranslations = [];
        
        for (let i = 0; i < texts.length; i += CONFIG.chunkSize) {
            const chunk = texts.slice(i, i + CONFIG.chunkSize);
            
            try {
                const response = await fetch(CONFIG.endpoint, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ lang, texts: chunk }),
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                const data = await response.json();
                
                if (Array.isArray(data.translations) && data.translations.length === chunk.length) {
                    allTranslations.push(...data.translations);
                } else {
                    allTranslations.push(...chunk);
                }
            } catch(err) {
                console.error('[lang-widget] помилка частини:', err);
                allTranslations.push(...chunk);
            }
            
            if (i + CONFIG.chunkSize < texts.length) {
                await new Promise(r => setTimeout(r, CONFIG.delayBetweenChunks));
            }
        }

        if (allTranslations.length === texts.length) {
            saveCache(lang, texts, allTranslations);
        }
        
        return allTranslations;
    }

    // Теги, вміст яких НЕ перекладаємо
    const SKIP_TAGS = new Set([
        'SCRIPT', 'STYLE', 'CODE', 'PRE', 'KBD', 'SAMP', 'VAR',
        'MATH', 'SVG', 'CANVAS', 'IFRAME', 'NOSCRIPT',
        'INPUT', 'TEXTAREA', 'SELECT', 'OPTION', 'BUTTON',
        'IMG', 'VIDEO', 'AUDIO', 'PICTURE', 'FIGURE'
    ]);

    // Атрибути, які НЕ чіпаємо
    const SKIP_CLASSES = ['no-translate', 'lang-switcher'];

    function shouldSkipElement(el) {
        if (SKIP_TAGS.has(el.tagName)) return true;
        if (SKIP_CLASSES.some(c => el.classList.contains(c))) return true;
        if (el.isContentEditable) return true;
        return false;
    }

    // ── Збір всіх текстових вузлів зі сторінки ──────────────────────────────
    function collectAllTexts() {
        const textNodes = [];

        const walker = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode(node) {
                    const text = node.nodeValue;

                    // Пропускаємо порожні та лише пробільні вузли
                    if (!text || !text.trim()) return NodeFilter.FILTER_REJECT;

                    // Пропускаємо вузли всередині заборонених тегів
                    let parent = node.parentElement;
                    while (parent && parent !== document.body) {
                        if (shouldSkipElement(parent)) return NodeFilter.FILTER_REJECT;
                        parent = parent.parentElement;
                    }

                    return NodeFilter.FILTER_ACCEPT;
                }
            }
        );

        let node;
        while ((node = walker.nextNode())) {
            textNodes.push(node);
        }

        console.log(`[lang-widget] Знайдено ${textNodes.length} текстових вузлів для перекладу`);

        // Зберігаємо оригінальний текст кожного вузла
        textNodes.forEach(node => {
            if (!originalContents.has(node)) {
                originalContents.set(node, node.nodeValue);
            }
        });

        const texts = textNodes.map(node => node.nodeValue.trim());

        return { elements: textNodes, texts };
    }

    // ── Застосування перекладів ─────────────────────────────────────────────
    function applyTranslations(elements, translations, lang) {
        elements.forEach((node, i) => {
            if (translations[i]) {
                node._translatedValue = translations[i];
                node.nodeValue = translations[i];
            }
        });

        currentLang = lang;
        updateButton(lang);
        try { localStorage.setItem('site_language', lang); } catch(e) {}
    }

    // ── Патч для кнопки "Більше новин" ──────────────────────────────────────
    function patchShowMoreButton() {
        const btn = document.getElementById('showMoreBtn');
        if (!btn) return;

        btn.addEventListener('click', function() {
            // Після появи нових елементів — нічого додатково не треба,
            // нові текстові вузли ще не в originalContents і не перекладені.
            // Якщо потрібно — можна викликати повторний переклад:
            if (currentLang !== 'uk') {
                setTimeout(() => switchLanguage(currentLang), 100);
            }
        });
    }

    // ── Основний переклад ────────────────────────────────────────────────────
    async function translatePage(lang) {
        if (lang === 'uk') {
            originalContents.forEach((original, node) => {
                node.nodeValue = original;
            });
            currentLang = 'uk';
            updateButton('uk');
            try { localStorage.setItem('site_language', 'uk'); } catch(e) {}
            return;
        }

        const { elements, texts } = collectAllTexts();
        if (!elements.length) return;

        try {
            const translations = await fetchTranslations(lang, texts);
            applyTranslations(elements, translations, lang);
        } catch (err) {
            console.error('[lang-widget] помилка перекладу:', err);
        }
    }

    // ── Перемикання мови ─────────────────────────────────────────────────────
    async function switchLanguage(lang) {
        if (lang === currentLang || isTranslating) return;
        
        isTranslating = true;
        await translatePage(lang);
        isTranslating = false;
    }

    // ── Ініціалізація ──────────────────────────────────────────────────────
    async function init() {
        createSwitcher();
        patchShowMoreButton();

        // Чекаємо повного завантаження DOM, включаючи меню
        setTimeout(async () => {
            if (currentLang !== 'uk') {
                const { elements, texts } = collectAllTexts();
                if (!elements.length) return;
                
                const cached = loadCache(currentLang, texts.length);
                
                if (cached) {
                    applyTranslations(elements, cached, currentLang);
                } else {
                    try {
                        const translations = await fetchTranslations(currentLang, texts);
                        applyTranslations(elements, translations, currentLang);
                    } catch (err) {
                        console.error('[lang-widget] помилка ініціалізації:', err);
                    }
                }
            }
        }, 500);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();