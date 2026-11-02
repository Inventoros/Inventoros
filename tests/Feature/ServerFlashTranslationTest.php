<?php

namespace Tests\Feature;

use Tests\TestCase;

class ServerFlashTranslationTest extends TestCase
{
    public function test_custom_controller_validation_feedback_is_localized(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Http/Controllers')));
        $untranslated = [];
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') continue;
            preg_match_all('/(?:withErrors|withMessages)\(\[([\s\S]*?)\]\)/', file_get_contents($file->getPathname()), $blocks);
            foreach ($blocks[1] as $block) {
                preg_match_all("/'[^']+'\\s*=>\\s*'([^']* [^']*)'/", $block, $messages);
                foreach ($messages[1] as $text) $untranslated[] = $file->getFilename().': '.$text;
            }
        }
        $this->assertSame([], $untranslated);
    }

    public function test_controller_flash_literals_are_localized_except_protocol_status_codes(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Http/Controllers')));
        $untranslated = [];
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') continue;
            preg_match_all("/->with\\(\\s*'(?:success|error|warning|status)'\\s*,\\s*'([^']+)'/", file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $text) {
                if ($text !== 'verification-link-sent') $untranslated[] = $file->getFilename().': '.$text;
            }
        }
        $this->assertSame([], $untranslated);
    }

    public function test_localized_server_messages_keep_keys_and_placeholders(): void
    {
        $source = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['ar', 'de', 'es', 'fr', 'it', 'ja', 'ko', 'nl', 'pl', 'pt-BR', 'ru', 'tr', 'zh-CN'] as $locale) {
            $messages = json_decode(file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            foreach ($source as $key => $english) {
                $this->assertArrayHasKey($key, $messages, $locale);
                $this->assertNotSame('', trim($messages[$key]), $locale.': '.$key);
                $this->assertDoesNotMatchRegularExpression('/\?\?|\x{FFFD}/u', $messages[$key], $locale.': '.$key);
                preg_match_all('/:[a-zA-Z_]+/', $english, $expected);
                preg_match_all('/:[a-zA-Z_]+/', $messages[$key], $actual);
                // Native plural branches may repeat a placeholder (Arabic
                // validation summaries do); compare the variables, not uses.
                $expectedNames = array_values(array_unique($expected[0]));
                $actualNames = array_values(array_unique($actual[0]));
                sort($expectedNames);
                sort($actualNames);
                $this->assertSame($expectedNames, $actualNames, $locale.': '.$key);
            }
        }
    }

    public function test_flash_text_uses_the_active_server_locale(): void
    {
        app()->setLocale('fr');
        $message = __('Product created successfully.');
        $this->assertNotSame('Product created successfully.', $message);
        $this->assertStringContainsString('Produit', $message);
        app()->setLocale('en');
        $this->assertSame('Product created successfully.', __('Product created successfully.'));
    }
}
