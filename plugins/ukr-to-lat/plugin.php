<?php
/**
 * plugins/ukr-to-lat/plugin.php
 * Транслітерація кирилиці в латиницю для slug постів і сторінок.
 */

fly_register_plugin([
    'slug'        => 'ukr-to-lat',
    'name'        => 'Транслітерація slug',
    'version'     => '1.0.0',
]);

// ── Функція транслітерації ─────────────────────────────────────────
function ukr_to_lat_transliterate(string $text): string {
    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ye',
        'ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'yi','й'=>'y','к'=>'k','л'=>'l',
        'м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
        'ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ь'=>'',
        'ю'=>'yu','я'=>'ya','ъ'=>'',
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'H','Ґ'=>'G','Д'=>'D','Е'=>'E','Є'=>'Ye',
        'Ж'=>'Zh','З'=>'Z','И'=>'Y','І'=>'I','Ї'=>'Yi','Й'=>'Y','К'=>'K','Л'=>'L',
        'М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U',
        'Ф'=>'F','Х'=>'Kh','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Shch','Ь'=>'',
        'Ю'=>'Yu','Я'=>'Ya',
        // Російська
        'э'=>'e','ё'=>'yo','ы'=>'y','ъ'=>'',
        'Э'=>'E','Ё'=>'Yo','Ы'=>'Y',
    ];
    return strtr($text, $map);
}

// ── Підписатись на фільтри slug ────────────────────────────────────
fly_add_filter('cms.post.slug', function(string $slug, string $title): string {
    // Якщо slug вже латиниця — не чіпаємо
    if (!preg_match('/[а-яА-ЯіІїЇєЄґҐ]/u', $slug)) return $slug;
    $transliterated = ukr_to_lat_transliterate($slug);
    return strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $transliterated), '-'));
}, 5); // priority 5 — до основної обробки

fly_add_filter('cms.page.slug', function(string $slug, string $title): string {
    if (!preg_match('/[а-яА-ЯіІїЇєЄґҐ]/u', $slug)) return $slug;
    $transliterated = ukr_to_lat_transliterate($slug);
    return strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $transliterated), '-'));
}, 5);
