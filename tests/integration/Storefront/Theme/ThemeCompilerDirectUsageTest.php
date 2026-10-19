<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Storefront\Theme;

use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatchInputFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Storefront\Theme\AbstractScssCompiler;
use Shopware\Storefront\Theme\CompilerConfiguration;
use Shopware\Storefront\Theme\Exception\ThemeCompileException;
use Shopware\Storefront\Theme\MD5ThemePathBuilder;
use Shopware\Storefront\Theme\ScssPhpCompiler;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfiguration;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfigurationCollection;
use Shopware\Storefront\Theme\ThemeCompiler;
use Shopware\Storefront\Theme\ThemeFileResolver;
use Shopware\Storefront\Theme\ThemeFilesystemResolver;
use Symfony\Component\Asset\UrlPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
#[Package('discovery')]
class ThemeCompilerDirectUsageTest extends TestCase
{
    use KernelTestBehaviour;

    private ThemeCompiler $themeCompiler;

    private Filesystem $filesystem;

    private Filesystem $tempFilesystem;

    private Filesystem $assetFilesystem;

    private EventDispatcherInterface $eventDispatcher;

    private string $mockSalesChannelId;

    private ?string $createdStylesheet = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $this->tempFilesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $this->assetFilesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $this->mockSalesChannelId = '98432def39fc4624b33213a56b8c944d';
        $this->eventDispatcher = static::getContainer()->get('event_dispatcher');

        $this->themeCompiler = $this->createThemeCompiler(static::getContainer()->get(ScssPhpCompiler::class));
        $this->ensureWebpackStylesheet();
    }

    protected function tearDown(): void
    {
        if ($this->createdStylesheet !== null) {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($this->createdStylesheet);
        }
    }

    // ===================================
    // Real SCSS Compilation Tests
    // ===================================

    public function testCompilesScssToValidCss(): void
    {
        $scssInput = '$primary-color: #ff0000; .test { color: $primary-color; }';

        $result = static::getContainer()->get(ScssPhpCompiler::class)->compileString(
            new CompilerConfiguration([]),
            $scssInput
        );

        static::assertStringContainsString('.test', $result);
        static::assertStringContainsString('#ff0000', $result);
        static::assertStringContainsString('color', $result);
    }

    public function testCompileCopiesWebpackStylesheet(): void
    {
        $config = new StorefrontPluginConfiguration('TestTheme');
        $config->setThemeConfig([
            'fields' => [
                'sw-color-brand-primary' => [
                    'name' => 'sw-color-brand-primary',
                    'type' => 'color',
                    'value' => '#008490',
                ],
            ],
        ]);

        $this->themeCompiler->compileTheme(
            $this->mockSalesChannelId,
            'test-theme-id',
            $config,
            new StorefrontPluginConfigurationCollection(),
            false,
            Context::createDefaultContext()
        );

        $themePrefix = (new MD5ThemePathBuilder())->assemblePath($this->mockSalesChannelId, 'test-theme-id');
        $cssPath = 'theme/' . $themePrefix . '/css/all.css';
        static::assertTrue($this->filesystem->fileExists($cssPath));
        static::assertSame($this->webpackStylesheet(), $this->filesystem->read($cssPath));
        static::assertFalse($this->tempFilesystem->fileExists('theme-variables.scss'));
    }

    public function testCompiledCssContainsThemeVariables(): void
    {
        $testScss = '.button { background: $sw-test-color; }';

        // Create a simple SCSS file for testing
        $this->tempFilesystem->write('test.scss', $testScss);

        $config = new StorefrontPluginConfiguration('TestTheme');
        $config->setThemeConfig([
            'fields' => [
                'sw-test-color' => [
                    'name' => 'sw-test-color',
                    'type' => 'color',
                    'value' => '#123456',
                ],
            ],
        ]);

        // Compile with variables
        $variables = '$sw-test-color: #123456;';
        $fullScss = $variables . "\n" . $testScss;

        $result = static::getContainer()->get(ScssPhpCompiler::class)->compileString(
            new CompilerConfiguration([]),
            $fullScss
        );

        static::assertStringContainsString('.button', $result);
        static::assertStringContainsString('#123456', $result);
    }

    // ===================================
    // Feature Flag Behavior Tests
    // ===================================

    public function testFeatureFlagFunctionWorksInScss(): void
    {
        if (EnvironmentHelper::getVariable('FEATURE_ALL')) {
            static::markTestSkipped('Skipped because FEATURE_ALL should be false for this test.');
        }

        Feature::registerFeatures([
            'FEATURE_NEXT_TEST_1' => ['default' => true],
            'FEATURE_NEXT_TEST_2' => ['default' => false],
        ]);

        $featureMixin = '@function feature($feature-flag) { @return map-get($sw-features, $feature-flag); }';

        // Inject $sw-features variable (normally done by ThemeCompiler)
        $allFeatures = Feature::getAll();
        $featuresScss = implode(',', array_map(
            fn ($value, $key) => \sprintf('"%s": %s', $key, json_encode($value, \JSON_THROW_ON_ERROR)),
            $allFeatures,
            array_keys($allFeatures)
        ));
        $featureVariables = \sprintf('$sw-features: (%s);', $featuresScss);

        $testScss = <<<'SCSS'
.test-selector {
    @if feature('FEATURE_NEXT_TEST_1') {
        background: green;
    } @else {
        background: red;
    }
}

@if feature('FEATURE_NEXT_TEST_2') {
    .should-not-exist {
        display: none;
    }
}
SCSS;

        $result = static::getContainer()->get(ScssPhpCompiler::class)->compileString(
            new CompilerConfiguration([]),
            $featureVariables . "\n" . $featureMixin . "\n" . $testScss
        );

        // FEATURE_NEXT_TEST_1 is active, so we should see green background
        static::assertStringContainsString('background:green', str_replace(' ', '', $result));
        static::assertStringNotContainsString('background:red', str_replace(' ', '', $result));

        // FEATURE_NEXT_TEST_2 is inactive, so .should-not-exist should not appear
        static::assertStringNotContainsString('.should-not-exist', $result);
    }

    public function testResolvesVendorImportPaths(): void
    {
        $testScss = <<<'SCSS'
@import '~vendor/library.min';
@import '~vendor/another-library';
SCSS;

        $vendorPath = __DIR__ . '/fixtures/ThemeWithScssVendorImports/Storefront/Resources/app/storefront/vendor';

        $result = static::getContainer()->get(ScssPhpCompiler::class)->compileString(
            new CompilerConfiguration([
                'importPaths' => [
                    function (string $path) use ($vendorPath) {
                        if (str_starts_with($path, '~vendor/')) {
                            $relativePath = substr($path, 8); // Remove '~vendor/'
                            $fullPath = $vendorPath . '/' . $relativePath;

                            // Try with .css extension for .min files
                            if (str_ends_with($relativePath, '.min')) {
                                $cssPath = $fullPath . '.css';
                                if (is_file($cssPath)) {
                                    return $cssPath;
                                }
                            }

                            // Try with .scss extension
                            if (is_file($fullPath . '.scss')) {
                                return $fullPath . '.scss';
                            }
                        }

                        return null;
                    },
                ],
            ]),
            $testScss
        );

        // Should contain content from both imported files
        static::assertStringContainsString('.plain-css-from-library', $result);
        static::assertStringContainsString('.another-lib', $result);
    }

    // ===================================
    // Variable Type Handling Tests
    // ===================================

    public function testMissingWebpackStylesheetFailsBeforeWritingThemeFiles(): void
    {
        $stylesheet = $this->webpackStylesheetPath();
        $io = new \Symfony\Component\Filesystem\Filesystem();
        $backup = $stylesheet . '.bak';
        $existed = is_file($stylesheet);
        if ($existed) {
            $io->rename($stylesheet, $backup);
        }

        try {
            $this->expectException(ThemeCompileException::class);
            $this->themeCompiler->compileTheme(
                $this->mockSalesChannelId,
                'test-theme-id',
                new StorefrontPluginConfiguration('TestTheme'),
                new StorefrontPluginConfigurationCollection(),
                false,
                Context::createDefaultContext()
            );
        } finally {
            if ($existed) {
                $io->rename($backup, $stylesheet);
            }
        }
    }

    private function webpackStylesheetPath(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/src/Storefront/Resources/app/storefront/dist/css/storefront.css';
    }

    private function ensureWebpackStylesheet(): void
    {
        $stylesheet = $this->webpackStylesheetPath();
        if (is_file($stylesheet)) {
            return;
        }

        $io = new \Symfony\Component\Filesystem\Filesystem();
        $io->mkdir(\dirname($stylesheet));
        $io->dumpFile($stylesheet, "body{color:#0042a0}\n");
        $this->createdStylesheet = $stylesheet;
    }

    private function webpackStylesheet(): string
    {
        return (string) file_get_contents($this->webpackStylesheetPath());
    }

    private function createThemeCompiler(AbstractScssCompiler $scssCompiler): ThemeCompiler
    {
        return new ThemeCompiler(
            $this->filesystem,
            $this->tempFilesystem,
            $this->assetFilesystem,
            new CopyBatchInputFactory(),
            static::getContainer()->get(ThemeFileResolver::class),
            true,
            $this->eventDispatcher,
            static::getContainer()->get(ThemeFilesystemResolver::class),
            ['theme' => new UrlPackage(['http://localhost'], new EmptyVersionStrategy())],
            static::getContainer()->get(CacheInvalidator::class),
            static::createStub(LoggerInterface::class),
            new MD5ThemePathBuilder(),
            $scssCompiler,
            [],
            false
        );
    }
}
