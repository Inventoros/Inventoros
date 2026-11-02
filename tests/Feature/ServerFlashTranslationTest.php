<?php

namespace Tests\Feature;

use Tests\TestCase;

class ServerFlashTranslationTest extends TestCase
{
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
