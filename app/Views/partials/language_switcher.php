<?php
/**
 * Language switcher (🇫🇷 Français ▾ / 🇬🇧 English) — a Bootstrap dropdown.
 * Each choice is a plain link to GET /language/{locale}?return=<this page>,
 * so it works without JavaScript; dashboard.js listens to
 * [data-locale-switch] clicks to keep its filters across the reload.
 *
 * @var string $variant 'navbar' (main layout) | 'floating' (login page)
 */
$variant = $variant ?? 'navbar';
$active  = current_locale();
$flags   = ['fr' => 'fr', 'en' => 'gb'];
$flag    = static fn (string $locale): string => base_url('assets/img/flags/' . ($flags[$locale] ?? $locale) . '.svg');
?>
<div class="dropdown bscd-lang bscd-lang--<?= esc($variant, 'attr') ?>">
    <button type="button" class="bscd-lang__toggle dropdown-toggle" data-toggle="dropdown"
            aria-haspopup="true" aria-expanded="false" aria-label="<?= esc(lang('App.language.choose'), 'attr') ?>"
            title="<?= esc(lang('App.language.label'), 'attr') ?>">
        <img class="bscd-lang__flag" src="<?= $flag($active) ?>" alt="" width="18" height="12">
        <span class="bscd-lang__name"><?= esc(lang('App.language.names.' . $active)) ?></span>
        <span class="bscd-lang__code" aria-hidden="true"><?= esc(strtoupper($active)) ?></span>
    </button>
    <div class="dropdown-menu dropdown-menu-right bscd-lang__menu">
        <?php foreach (supported_locales() as $locale): ?>
            <a class="dropdown-item bscd-lang__item<?= $locale === $active ? ' is-active' : '' ?>"
               href="<?= esc(locale_switch_url($locale), 'attr') ?>" hreflang="<?= esc($locale, 'attr') ?>" lang="<?= esc($locale, 'attr') ?>"
               data-locale-switch="<?= esc($locale, 'attr') ?>"<?= $locale === $active ? ' aria-current="true"' : '' ?>>
                <img class="bscd-lang__flag" src="<?= $flag($locale) ?>" alt="" width="18" height="12">
                <span><?= esc(lang('App.language.names.' . $locale)) ?></span>
                <?php if ($locale === $active): ?><i class="fas fa-check bscd-lang__check" aria-hidden="true"></i><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
