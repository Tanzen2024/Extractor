<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Translation catalogs stay complete and consistent:
 *
 *   app/Language/{fr,en}/*.php        server-rendered text (lang())
 *   public/assets/i18n/{fr,en}.json   browser-rendered text (bscdI18n.t())
 *
 * - French and English define exactly the same keys;
 * - every key used in the code exists in both languages, and every key
 *   defined is used somewhere (no dead entries);
 * - the business glossary has one English term per French term, identical
 *   on both sides (PHP Glossary.php vs JSON "glossary").
 *
 * @internal
 */
final class I18nCatalogTest extends CIUnitTestCase
{
    /** Application catalogs (Errors / Pager / Validation in fr/ only translate CodeIgniter's own). */
    private const FILES = ['App', 'Admin', 'Auth', 'Dashboard', 'Extraction', 'Glossary'];

    /** JS files reading the browser catalog. */
    private const JS_FILES = ['dashboard.js', 'export-widget.js', 'export-progress.js', 'frdatepicker.js', 'app.js', 'i18n.js', 'sync-status.js'];

    /**
     * Not checked for "defined but never used": keys read through a computed
     * name (prefix + variable), and the glossary — the reference vocabulary,
     * kept whole even for the terms no screen quotes directly.
     */
    private const DYNAMIC_PHP = ['App.language.names.', 'Glossary.'];

    private const DYNAMIC_JS = [
        'dashboard.columns.', 'meta.', 'glossary.',
        'dashboard.sync.state.', 'dashboard.sync.status.', 'dashboard.sync.trigger.', 'dashboard.sync.reason.',
    ];

    public function testDashboardTitleIsMadeOfTheGlossaryTerms(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $glossary  = require APPPATH . "Language/{$locale}/Glossary.php";
            $dashboard = require APPPATH . "Language/{$locale}/Dashboard.php";

            $this->assertSame(
                $glossary['payerProfileSegmentation'] . ' | ' . $glossary['consumerProfileSegmentation'],
                $dashboard['title'],
                "{$locale}: Dashboard.title must use the glossary terms",
            );
        }
    }

    public function testPhpCatalogsHaveTheSameKeysInBothLanguages(): void
    {
        foreach (self::FILES as $file) {
            $fr = $this->flatten(require APPPATH . "Language/fr/{$file}.php");
            $en = $this->flatten(require APPPATH . "Language/en/{$file}.php");

            $this->assertSame([], array_values(array_diff(array_keys($fr), array_keys($en))), "{$file}: missing in en");
            $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($fr))), "{$file}: missing in fr");

            foreach ($en as $key => $value) {
                $this->assertNotSame('', trim((string) $value), "{$file}.{$key}: empty English text");
            }
        }
    }

    public function testJsCatalogsHaveTheSameKeysInBothLanguages(): void
    {
        $fr = $this->flatten($this->json('fr'), true);
        $en = $this->flatten($this->json('en'), true);

        $this->assertSame([], array_values(array_diff(array_keys($fr), array_keys($en))), 'json: missing in en');
        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($fr))), 'json: missing in fr');

        foreach ($fr as $key => $value) {
            if (is_array($value)) {
                $this->assertCount(count($value), $en[$key], "json {$key}: list length differs");
            }
        }
    }

    public function testGlossaryIsTheSameOnServerAndBrowser(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $php  = require APPPATH . "Language/{$locale}/Glossary.php";
            $json = $this->json($locale)['glossary'];

            foreach ($json as $key => $term) {
                $this->assertArrayHasKey($key, $php, "glossary.{$key} missing in {$locale}/Glossary.php");
                $this->assertSame($php[$key], $term, "glossary.{$key} differs between PHP and JSON ({$locale})");
            }
        }
    }

    public function testEveryPhpKeyUsedExistsAndEveryKeyDefinedIsUsed(): void
    {
        $defined = [];
        foreach (self::FILES as $file) {
            foreach (array_keys($this->flatten(require APPPATH . "Language/fr/{$file}.php")) as $key) {
                $defined["{$file}.{$key}"] = true;
            }
        }

        $used = [];
        $pattern = "/['\"]((?:" . implode('|', self::FILES) . ')\.[A-Za-z0-9_.]+)[\'"]/';
        foreach ($this->sources(APPPATH, 'php') as $path) {
            if (str_contains($path, DIRECTORY_SEPARATOR . 'Language' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            preg_match_all($pattern, (string) file_get_contents($path), $m);
            foreach ($m[1] as $key) {
                $used[$key] = true;
            }
        }

        foreach (array_keys($used) as $key) {
            if (str_ends_with($key, '.')) {
                continue; // computed key: prefix only
            }
            $this->assertArrayHasKey($key, $defined, "lang('{$key}') is used but not defined");
        }

        foreach (array_keys($defined) as $key) {
            if ($this->startsWithAny($key, self::DYNAMIC_PHP)) {
                continue;
            }
            $this->assertArrayHasKey($key, $used, "{$key} is defined but never used");
        }
    }

    public function testEveryJsKeyUsedExistsAndEveryKeyDefinedIsUsed(): void
    {
        $catalog = $this->json('fr');
        $defined = $this->flatten($catalog, true);
        $groups  = $this->groupPaths($catalog);

        $used = [];
        foreach (self::JS_FILES as $file) {
            $src = (string) file_get_contents(FCPATH . 'assets/js/' . $file);
            preg_match_all("/'((?:common|dashboard|export|progress|datepicker|glossary|meta)\\.[A-Za-z0-9_.]+)'/", $src, $m);
            foreach ($m[1] as $key) {
                $used[$key] = true;
            }
        }

        foreach (array_keys($used) as $key) {
            $this->assertTrue(isset($defined[$key]) || isset($groups[$key]), "t('{$key}') is used but not defined in fr.json");
        }

        foreach (array_keys($defined) as $key) {
            if ($this->startsWithAny($key, self::DYNAMIC_JS)) {
                continue;
            }
            // Plural forms are read by tn('x') as x.one / x.other.
            $base = preg_replace('/\.(one|other)$/', '', $key);
            $this->assertTrue(isset($used[$key]) || isset($used[$base]), "{$key} is defined in fr.json but never used");
        }
    }

    public function testFrenchIsTheDefaultLanguage(): void
    {
        $config = config('App');

        $this->assertSame('fr', $config->defaultLocale);
        $this->assertSame(['fr', 'en'], $config->supportedLocales);
    }

    // ---- helpers -------------------------------------------------------

    /**
     * @return array<string, mixed> dotted key => leaf (lists kept whole when $keepLists)
     */
    private function flatten(array $tree, bool $keepLists = false, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value) && ! ($keepLists && array_is_list($value))) {
                $out += $this->flatten($value, $keepLists, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    /** @return array<string, true> every non-leaf path (t('dashboard.columns') returns the group) */
    private function groupPaths(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $path       = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
                $out[$path] = true;
                $out += $this->groupPaths($value, $path);
            }
        }

        return $out;
    }

    private function json(string $locale): array
    {
        return json_decode((string) file_get_contents(FCPATH . "assets/i18n/{$locale}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function sources(string $dir, string $ext): array
    {
        $files = [];
        $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === $ext) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @param list<string> $prefixes */
    private function startsWithAny(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
