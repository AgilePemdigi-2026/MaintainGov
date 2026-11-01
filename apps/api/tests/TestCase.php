<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Locale deterministik utk seluruh suite: pesan validasi wajib Bahasa
        // Indonesia terlepas dari .env / .env.example (APP_LOCALE=en) yang
        // DotEnv muat SETELAH env var PHPUnit sehingga phpunit.xml tidak
        // cukup. `setLocale` berjalan SETELAH dotenv => selalu menang.
        $this->app->setLocale('id');
        config(['app.fallback_locale' => 'id']);
    }
}
