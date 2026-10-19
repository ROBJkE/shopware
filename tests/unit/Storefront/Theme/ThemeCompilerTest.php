<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Storefront\Theme;

use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatchInput;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatchInputFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\KernelPluginLoader;
use Shopware\Core\Framework\Test\TestCaseBase\EnvTestBehaviour;
use Shopware\Core\Framework\Util\Filesystem as ThemeFilesystem;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\Stub\App\StaticSourceResolver;
use Shopware\Core\Test\Stub\Framework\Util\StaticFilesystem;
use Shopware\Core\Test\TestDefaults;
use Shopware\Storefront\Event\ThemeCompilerConcatenatedStylesEvent;
use Shopware\Storefront\Theme\Event\ThemeCompilerEnrichScssVariablesEvent;
use Shopware\Storefront\Theme\Exception\ThemeCompileException;
use Shopware\Storefront\Theme\MD5ThemePathBuilder;
use Shopware\Storefront\Theme\ScssPhpCompiler;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\FileCollection;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfiguration;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfigurationCollection;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfigurationFactory;
use Shopware\Storefront\Theme\ThemeCompiler;
use Shopware\Storefront\Theme\ThemeFileResolver;
use Shopware\Storefront\Theme\ThemeFilesystemResolver;
use Shopware\Tests\Integration\Storefront\Theme\fixtures\MockThemeCompilerConcatenatedSubscriber;
use Shopware\Tests\Integration\Storefront\Theme\fixtures\MockThemeVariablesSubscriber;
use Shopware\Tests\Unit\Storefront\Theme\fixtures\ThemeAndPlugin\AsyncPlugin\AsyncPlugin;
use Shopware\Tests\Unit\Storefront\Theme\fixtures\ThemeAndPlugin\NotFoundPlugin\NotFoundPlugin;
use Shopware\Tests\Unit\Storefront\Theme\fixtures\ThemeAndPlugin\TestTheme\TestTheme;
use Symfony\Component\Asset\UrlPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * @internal
 */
#[Package('discovery')]
#[CoversClass(ThemeCompiler::class)]
class ThemeCompilerTest extends TestCase
{
    use EnvTestBehaviour;

    private string $mockSalesChannelId;

    /**
     * @var ThemeFileResolver&Stub
     */
    private ThemeFileResolver $themeFileResolver;

    private Filesystem $filesystem;

    private Filesystem $tempFilesystem;

    /**
     * @var EventDispatcher&Stub
     */
    private EventDispatcher $eventDispatcher;

    /**
     * @var CacheInvalidator&Stub
     */
    private CacheInvalidator $cacheInvalidator;

    /**
     * @var LoggerInterface&Stub
     */
    private LoggerInterface $logger;

    /**
     * @var ScssPhpCompiler&Stub
     */
    private ScssPhpCompiler $scssPhpCompiler;

    private MD5ThemePathBuilder $pathBuilder;

    private ThemeFilesystemResolver&Stub $themeFilesystemResolver;

    private string $storefrontRoot;

    private ThemeFilesystem $storefrontFilesystem;

    /**
     * @var CopyBatchInputFactory&Stub
     */
    private CopyBatchInputFactory $copyBatchInputFactory;

    protected function setUp(): void
    {
        $this->themeFileResolver = static::createStub(ThemeFileResolver::class);
        $this->eventDispatcher = static::createStub(EventDispatcher::class);
        $this->cacheInvalidator = static::createStub(CacheInvalidator::class);
        $this->logger = static::createStub(LoggerInterface::class);
        $this->scssPhpCompiler = static::createStub(ScssPhpCompiler::class);
        $this->pathBuilder = new MD5ThemePathBuilder();
        $this->copyBatchInputFactory = static::createStub(CopyBatchInputFactory::class);
        $this->themeFilesystemResolver = static::createStub(ThemeFilesystemResolver::class);

        $this->filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $this->tempFilesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $this->mockSalesChannelId = '98432def39fc4624b33213a56b8c944d';

        $this->storefrontRoot = sys_get_temp_dir() . '/sw-storefront-' . bin2hex(random_bytes(4));
        $cssDirectory = $this->storefrontRoot . '/Resources/app/storefront/dist/css';
        $io = new SymfonyFilesystem();
        $io->mkdir($cssDirectory);
        $io->dumpFile($cssDirectory . '/storefront.css', "body{color:#0042a0}\n");
        $this->storefrontFilesystem = new ThemeFilesystem($this->storefrontRoot);

        $this->themeFilesystemResolver->method('getFilesystemForStorefrontConfig')
            ->willReturnCallback(function (StorefrontPluginConfiguration $configuration): ThemeFilesystem {
                if ($configuration->getTechnicalName() === 'Storefront') {
                    return $this->storefrontFilesystem;
                }

                return new StaticFilesystem();
            });
    }

    protected function tearDown(): void
    {
        (new SymfonyFilesystem())->remove($this->storefrontRoot);
    }

    public function testThemeCompileExceptionIsThrownWhenWebpackStylesheetIsMissing(): void
    {
        $emptyRoot = sys_get_temp_dir() . '/sw-storefront-missing-' . bin2hex(random_bytes(4));
        (new SymfonyFilesystem())->mkdir($emptyRoot);
        $resolver = static::createStub(ThemeFilesystemResolver::class);
        $resolver->method('getFilesystemForStorefrontConfig')
            ->willReturn(new ThemeFilesystem($emptyRoot));
        $compiler = $this->getThemeCompiler(themeFilesystemResolver: $resolver);

        $config = new StorefrontPluginConfiguration('Storefront');
        $config->setName('Storefront');

        $this->filesystem->write('theme/kept/all.js', 'keep');

        try {
            $compiler->compileTheme(
                TestDefaults::SALES_CHANNEL,
                'theme-id',
                $config,
                new StorefrontPluginConfigurationCollection(),
                true,
                Context::createDefaultContext()
            );
            static::fail('A missing Webpack stylesheet must fail the compile.');
        } catch (ThemeCompileException $exception) {
            static::assertSame(
                'Unable to compile the theme "Storefront - Theme-ID: theme-id". The storefront stylesheet "dist/css/storefront.css" is missing. Run the storefront build (`bin/build-storefront.sh` or `composer build:js:storefront`).',
                $exception->getMessage()
            );
            static::assertSame('keep', $this->filesystem->read('theme/kept/all.js'));
        } finally {
            (new SymfonyFilesystem())->remove($emptyRoot);
        }
    }

    public function testCompileCopiesWebpackStylesheetWithoutCompilingScss(): void
    {
        $scssPhpCompiler = $this->createMock(ScssPhpCompiler::class);
        $scssPhpCompiler->expects($this->never())->method('compileString');
        $compiler = $this->getThemeCompiler(scssPhpCompiler: $scssPhpCompiler);

        $config = new StorefrontPluginConfiguration('test');

        $compiler->compileTheme(
            TestDefaults::SALES_CHANNEL,
            'test',
            $config,
            new StorefrontPluginConfigurationCollection(),
            false,
            Context::createDefaultContext()
        );

        $cssPath = 'theme/9a11a759d278b4a55cb5e2c3414733c1/css/all.css';
        static::assertTrue($this->filesystem->fileExists($cssPath));
        static::assertSame("body{color:#0042a0}\n", $this->filesystem->read($cssPath));
        static::assertFalse($this->tempFilesystem->fileExists('theme-variables.scss'));
    }

    public function testThemeCompileExceptionIsThrownWhenCollectCompiledFilesFails(): void
    {
        $this->themeFileResolver->method('resolveFiles')->willReturn(
            [ThemeFileResolver::STYLE_FILES => FileCollection::createFromArray(['foo'])]
        );

        $this->copyBatchInputFactory->method('fromDirectory')->willThrowException(new \Exception());

        $compiler = $this->getThemeCompiler();

        $config = new StorefrontPluginConfiguration('test');
        $config->setName('faultyTheme');
        $config->setAssetPaths(['bla']);

        $this->expectExceptionObject(new ThemeCompileException('faultyTheme'));
        $compiler->compileTheme(
            TestDefaults::SALES_CHANNEL,
            'test',
            $config,
            new StorefrontPluginConfigurationCollection(),
            true,
            Context::createDefaultContext()
        );
    }

    public function testScssVariablesEventAddsNewVariablesToArray(): void
    {
        $subscriber = new MockThemeVariablesSubscriber(static::createStub(SystemConfigService::class));

        $variables = [
            'sw-color-brand-primary' => '#008490',
            'sw-color-brand-secondary' => '#526e7f',
            'sw-border-color' => '#bcc1c7',
        ];

        $event = new ThemeCompilerEnrichScssVariablesEvent($variables, $this->mockSalesChannelId, Context::createDefaultContext());
        $subscriber->onAddVariables($event);

        $actual = $event->getVariables();

        $expected = [
            'sw-color-brand-primary' => '#008490',
            'sw-color-brand-secondary' => '#526e7f',
            'sw-border-color' => '#bcc1c7',
            'mock-variable-black' => '#000000',
            'mock-variable-special' => '\'Special value with quotes\'',
        ];

        static::assertSame($expected, $actual);
    }

    public function testConcatenatedStylesEventPassThru(): void
    {
        $subscriber = new MockThemeCompilerConcatenatedSubscriber();

        $styles = 'body {}';

        $event = new ThemeCompilerConcatenatedStylesEvent($styles, $this->mockSalesChannelId);
        $subscriber->onGetConcatenatedStyles($event);
        $actual = $event->getConcatenatedStyles();

        $expected = $styles . MockThemeCompilerConcatenatedSubscriber::STYLES_CONCAT;

        static::assertSame($expected, $actual);
    }

    public function testCompileWithoutAssets(): void
    {
        $this->filesystem->write('theme/test/logo.png', 'existing logo');
        $this->themeFileResolver->method('resolveFiles')->willReturn([
            ThemeFileResolver::SCRIPT_FILES => new FileCollection(),
            ThemeFileResolver::STYLE_FILES => new FileCollection(),
        ]);

        $compiler = $this->getThemeCompiler();

        $config = new StorefrontPluginConfiguration('test');
        $config->setAssetPaths(['bla']);

        $pathBuilder = new MD5ThemePathBuilder();
        static::assertSame('9a11a759d278b4a55cb5e2c3414733c1', $pathBuilder->assemblePath(TestDefaults::SALES_CHANNEL, 'test'));

        try {
            $pathBuilder->getDecorated();
        } catch (\Throwable $e) {
            static::assertInstanceOf(DecorationPatternException::class, $e);
        }

        $compiler->compileTheme(
            TestDefaults::SALES_CHANNEL,
            'test',
            $config,
            new StorefrontPluginConfigurationCollection(),
            false,
            Context::createDefaultContext()
        );

        static::assertTrue($this->filesystem->has('theme/9a11a759d278b4a55cb5e2c3414733c1'));
        static::assertSame('existing logo', $this->filesystem->read('theme/test/logo.png'));
    }

    public function testFailedAssetCopyPreservesPreviousAssets(): void
    {
        $adapter = new class extends InMemoryFilesystemAdapter {
            public function writeStream(string $path, $contents, Config $config): void
            {
                throw UnableToWriteFile::atLocation($path);
            }
        };
        $this->filesystem = new Filesystem($adapter);
        $this->filesystem->write('theme/test/logo.png', 'old logo');
        $this->filesystem->write('theme/test/obsolete.png', 'old asset');
        $this->tempFilesystem->write('logo.png', 'new logo');
        $this->themeFileResolver->method('resolveFiles')->willReturn([
            ThemeFileResolver::SCRIPT_FILES => new FileCollection(),
            ThemeFileResolver::STYLE_FILES => new FileCollection(),
        ]);
        $stream = $this->tempFilesystem->readStream('logo.png');
        $this->copyBatchInputFactory->method('fromDirectory')->willReturn([
            new CopyBatchInput($stream, ['theme/test/logo.png']),
        ]);
        $config = new StorefrontPluginConfiguration('test');
        $config->setAssetPaths(['assets']);

        try {
            $this->getThemeCompiler()->compileTheme(
                TestDefaults::SALES_CHANNEL,
                'test',
                $config,
                new StorefrontPluginConfigurationCollection(),
                true,
                Context::createDefaultContext()
            );
            static::fail('The failed upload must not report a successful compile.');
        } catch (UnableToWriteFile) {
            static::assertSame('old logo', $this->filesystem->read('theme/test/logo.png'));
            static::assertSame('old asset', $this->filesystem->read('theme/test/obsolete.png'));
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testThemeAssetsSurviveDelayedDeletes(): void
    {
        $adapter = new DelayedThemeAssetDeleteAdapter();
        $this->filesystem = new Filesystem($adapter);
        $this->filesystem->write('theme/test/images/logo.png', 'old logo');
        $this->filesystem->write('theme/test/fonts/obsolete.woff', 'obsolete font');
        $this->filesystem->write('theme/other/images/logo.png', 'other theme');
        $this->tempFilesystem->write('logo.png', 'new logo');

        $config = new StorefrontPluginConfiguration('test');
        $config->setAssetPaths(['assets']);
        $this->themeFileResolver->method('resolveFiles')->willReturn([
            ThemeFileResolver::SCRIPT_FILES => new FileCollection(),
            ThemeFileResolver::STYLE_FILES => new FileCollection(),
        ]);
        $this->copyBatchInputFactory->method('fromDirectory')->willReturn([
            new CopyBatchInput($this->tempFilesystem->readStream('logo.png'), ['theme/test/images/logo.png']),
        ]);

        $this->getThemeCompiler()->compileTheme(
            TestDefaults::SALES_CHANNEL,
            'test',
            $config,
            new StorefrontPluginConfigurationCollection(),
            true,
            Context::createDefaultContext()
        );
        $adapter->completeDeletes();

        static::assertTrue($this->filesystem->fileExists('theme/test/images/logo.png'));
        static::assertSame('new logo', $this->filesystem->read('theme/test/images/logo.png'));
        static::assertFalse($this->filesystem->fileExists('theme/test/fonts/obsolete.woff'));
        static::assertSame('other theme', $this->filesystem->read('theme/other/images/logo.png'));
    }

    public function testAssetPathWillBeAbsoluteConverted(): void
    {
        $config = new StorefrontPluginConfiguration('test');
        $config->setAssetPaths(['assets']);

        $fs = new StaticFilesystem(['Resources/assets' => 'directory']);

        $themeFilesystemResolver = $this->createMock(ThemeFilesystemResolver::class);
        $themeFilesystemResolver->expects($this->exactly(2))
            ->method('getFilesystemForStorefrontConfig')
            ->willReturnCallback(function (StorefrontPluginConfiguration $configuration) use ($fs): ThemeFilesystem {
                if ($configuration->getTechnicalName() === 'Storefront') {
                    return $this->storefrontFilesystem;
                }

                static::assertSame('test', $configuration->getTechnicalName());

                return $fs;
            });

        $this->themeFileResolver->method('resolveFiles')->willReturn([
            ThemeFileResolver::SCRIPT_FILES => new FileCollection(),
            ThemeFileResolver::STYLE_FILES => new FileCollection(),
        ]);

        $this->filesystem->createDirectory('temp');
        $this->filesystem->write('temp/test.png', '');
        $png = $this->filesystem->readStream('temp/test.png');

        $this->copyBatchInputFactory->method('fromDirectory')->willReturn(
            [
                new CopyBatchInput($png, ['theme/9a11a759d278b4a55cb5e2c3414733c1/assets/test.png']),
            ]
        );

        $compiler = $this->getThemeCompiler(themeFilesystemResolver: $themeFilesystemResolver);

        $pathBuilder = new MD5ThemePathBuilder();
        static::assertSame('9a11a759d278b4a55cb5e2c3414733c1', $pathBuilder->assemblePath(TestDefaults::SALES_CHANNEL, 'test'));

        try {
            $pathBuilder->getDecorated();
        } catch (\Throwable $e) {
            static::assertInstanceOf(DecorationPatternException::class, $e);
        }

        $compiler->compileTheme(
            TestDefaults::SALES_CHANNEL,
            'test',
            $config,
            new StorefrontPluginConfigurationCollection(),
            true,
            Context::createDefaultContext()
        );

        static::assertTrue($this->filesystem->fileExists('theme/9a11a759d278b4a55cb5e2c3414733c1/assets/test.png'));
    }

    public function testExistingFilesAreNotDeletedOnCompileError(): void
    {
        $this->themeFileResolver->method('resolveFiles')->willReturn(
            [
                ThemeFileResolver::SCRIPT_FILES => new FileCollection(),
                ThemeFileResolver::STYLE_FILES => new FileCollection()]
        );

        $this->filesystem->createDirectory('theme/9a11a759d278b4a55cb5e2c3414733c1');
        $this->filesystem->write('theme/9a11a759d278b4a55cb5e2c3414733c1/all.js', '');

        $emptyRoot = sys_get_temp_dir() . '/sw-storefront-missing-' . bin2hex(random_bytes(4));
        (new SymfonyFilesystem())->mkdir($emptyRoot);
        $resolver = static::createStub(ThemeFilesystemResolver::class);
        $resolver->method('getFilesystemForStorefrontConfig')->willReturn(new ThemeFilesystem($emptyRoot));

        $scssPhpCompiler = $this->createMock(ScssPhpCompiler::class);
        $scssPhpCompiler->expects($this->never())->method('compileString');

        $compiler = $this->getThemeCompiler(themeFilesystemResolver: $resolver, scssPhpCompiler: $scssPhpCompiler);

        $config = new StorefrontPluginConfiguration('test');
        $config->setAssetPaths(['assets']);

        $pathBuilder = new MD5ThemePathBuilder();
        static::assertSame('9a11a759d278b4a55cb5e2c3414733c1', $pathBuilder->assemblePath(TestDefaults::SALES_CHANNEL, 'test'));

        $wasThrown = false;

        try {
            $compiler->compileTheme(
                TestDefaults::SALES_CHANNEL,
                'test',
                $config,
                new StorefrontPluginConfigurationCollection(),
                true,
                Context::createDefaultContext()
            );
        } catch (ThemeCompileException) {
            $wasThrown = true;
        }

        static::assertTrue($wasThrown);
        static::assertTrue($this->filesystem->fileExists('theme/9a11a759d278b4a55cb5e2c3414733c1/all.js'));
        (new SymfonyFilesystem())->remove($emptyRoot);
    }

    public function testNewFilesAreDeletedOnCompileError(): void
    {
        $this->themeFileResolver->method('resolveFiles')->willReturn(
            [
                ThemeFileResolver::SCRIPT_FILES => new FileCollection(),
                ThemeFileResolver::STYLE_FILES => new FileCollection()]
        );

        $this->filesystem->createDirectory('theme/current');
        $this->filesystem->write('theme/current/all.js', '');

        $copyBatchInputFactory = $this->createMock(CopyBatchInputFactory::class);
        $copyBatchInputFactory->expects($this->never())
            ->method('fromDirectory');

        $emptyRoot = sys_get_temp_dir() . '/sw-storefront-missing-' . bin2hex(random_bytes(4));
        (new SymfonyFilesystem())->mkdir($emptyRoot);
        $resolver = static::createStub(ThemeFilesystemResolver::class);
        $resolver->method('getFilesystemForStorefrontConfig')->willReturn(new ThemeFilesystem($emptyRoot));

        $scssPhpCompiler = $this->createMock(ScssPhpCompiler::class);
        $scssPhpCompiler->expects($this->never())->method('compileString');

        $this->pathBuilder = $this->createMock(MD5ThemePathBuilder::class);
        $this->pathBuilder->method('assemblePath')->willReturn('current');
        $this->pathBuilder->method('generateNewPath')->willReturn('new');
        $this->pathBuilder->expects($this->never())->method('saveSeed');

        $compiler = $this->getThemeCompiler(
            copyBatchInputFactory: $copyBatchInputFactory,
            themeFilesystemResolver: $resolver,
            scssPhpCompiler: $scssPhpCompiler,
        );

        $config = new StorefrontPluginConfiguration('test');
        $config->setAssetPaths(['assets']);

        $wasThrown = false;

        try {
            $compiler->compileTheme(
                TestDefaults::SALES_CHANNEL,
                'test',
                $config,
                new StorefrontPluginConfigurationCollection(),
                true,
                Context::createDefaultContext()
            );
        } catch (ThemeCompileException) {
            $wasThrown = true;
        }

        static::assertTrue($wasThrown);
        static::assertTrue($this->filesystem->fileExists('theme/current/all.js'));
        static::assertFalse($this->filesystem->fileExists('theme/new/all.js'));
        (new SymfonyFilesystem())->remove($emptyRoot);
    }

    public function testOldThemeFilesAreDeletedDelayedOnThemeCompileSuccess(): void
    {
        $this->themeFileResolver->method('resolveFiles')->willReturn(
            [
                ThemeFileResolver::SCRIPT_FILES => new FileCollection(),
                ThemeFileResolver::STYLE_FILES => new FileCollection()]
        );

        $this->filesystem->createDirectory('theme/current');
        $this->filesystem->write('theme/current/all.js', '');

        $this->pathBuilder = $this->createMock(MD5ThemePathBuilder::class);
        $this->pathBuilder->method('assemblePath')->willReturn('current');
        $this->pathBuilder->expects($this->once())
            ->method('generateNewPath')
            ->with(
                TestDefaults::SALES_CHANNEL,
                'test'
            )
            ->willReturn('new');
        $this->pathBuilder->expects($this->once())
            ->method('saveSeed')
            ->with(TestDefaults::SALES_CHANNEL, 'test');

        $compiler = $this->getThemeCompiler();

        $config = new StorefrontPluginConfiguration('test');
        $config->setAssetPaths(['assets']);

        $compiler->compileTheme(
            TestDefaults::SALES_CHANNEL,
            'test',
            $config,
            new StorefrontPluginConfigurationCollection(),
            true,
            Context::createDefaultContext()
        );

        static::assertTrue($this->filesystem->fileExists('theme/current/all.js'));
        static::assertSame("body{color:#0042a0}\n", $this->filesystem->read('theme/new/css/all.css'));
    }

    public function testCopyScriptFilesToTheme(): void
    {
        $this->themeFileResolver->method('resolveFiles')->willReturn(
            [
                ThemeFileResolver::SCRIPT_FILES => new FileCollection(),
                ThemeFileResolver::STYLE_FILES => new FileCollection()]
        );

        $distLocation = __DIR__ . '/fixtures/ThemeAndPlugin/TestTheme/Resources/app/storefront/dist/storefront/js/test-theme';
        $this->filesystem->createDirectory($distLocation);
        $this->filesystem->write($distLocation . '/test-theme.js', '');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->setEnvVars([
            'V6_6_0_0' => 1,
        ]);

        $projectDir = __DIR__ . '/fixtures';
        $themeFilesystemResolver = $this->createMock(ThemeFilesystemResolver::class);
        $compiler = $this->getThemeCompiler(
            themeFilesystemResolver: $themeFilesystemResolver,
            logger: $logger,
        );

        $filesystems = [
            'AsyncPlugin' => new ThemeFilesystem(__DIR__ . '/fixtures/ThemeAndPlugin/AsyncPlugin'),
            'TestTheme' => new ThemeFilesystem(__DIR__ . '/fixtures/ThemeAndPlugin/TestTheme'),
            'NotFoundPlugin' => new ThemeFilesystem(__DIR__ . '/fixtures/ThemeAndPlugin/NotFoundPlugin'),
            'Storefront' => $this->storefrontFilesystem,
        ];

        $sourceResolver = new StaticSourceResolver($filesystems);

        $themeFilesystemResolver->expects($this->exactly(\count($filesystems)))
            ->method('getFilesystemForStorefrontConfig')
            ->willReturnCallback(static fn (StorefrontPluginConfiguration $config) => $filesystems[$config->getTechnicalName()]);

        $configurationFactory = new StorefrontPluginConfigurationFactory(
            static::createStub(KernelPluginLoader::class),
            $sourceResolver,
            new SymfonyFilesystem(),
        );

        $themePluginBundle = new TestTheme();
        $asyncPluginBundle = new AsyncPlugin(true, $projectDir . 'fixtures/ThemeAndPlugin/AsyncPlugin');
        $notFoundPluginBundle = new NotFoundPlugin(
            true,
            $projectDir . 'fixtures/ThemeAndPlugin/NotFoundPlugin'
        );
        $testTheme = $configurationFactory->createFromBundle($themePluginBundle);
        $asyncPlugin = $configurationFactory->createFromBundle($asyncPluginBundle);
        $appWithoutJs = $configurationFactory->createFromApp('ThemeAppWithoutJs', 'ThemeAppWithoutJs');

        $notFoundPlugin = $configurationFactory->createFromBundle($notFoundPluginBundle);
        $scripts = new FileCollection();
        $scripts = $scripts::createFromArray([
            'Resources/app/storefront/src/plugins/lorem-ipsum/plugin.js',
        ]);
        $notFoundPlugin->setScriptFiles($scripts);

        $configCollection = new StorefrontPluginConfigurationCollection();
        $configCollection->add($testTheme);
        $configCollection->add($asyncPlugin);
        $configCollection->add($notFoundPlugin);
        $configCollection->add($appWithoutJs);

        $compiler->compileTheme(
            TestDefaults::SALES_CHANNEL,
            'TestTheme',
            $testTheme,
            $configCollection,
            true,
            Context::createDefaultContext()
        );

        $themeBasePath = '/theme/2fb1d60e66e241fe65bcedc271cc2174';
        $asyncMainJsInTheme = $themeBasePath . '/js/async-plugin/async-plugin.js';
        $asyncAnotherJsFileInTheme = $themeBasePath . '/js/async-plugin/custom_plugins_AsyncPlugin_src_Resources_app_storefront_src_plugins_lorem-ipsum_plugin_js.js';
        $themeMainJsInTheme = $themeBasePath . '/js/test-theme/test-theme.js';

        static::assertTrue($this->filesystem->directoryExists($distLocation));
        static::assertTrue($this->filesystem->fileExists($distLocation . '/test-theme.js'));
        static::assertTrue($this->filesystem->fileExists($asyncMainJsInTheme));
        static::assertTrue($this->filesystem->fileExists($asyncAnotherJsFileInTheme));
        static::assertTrue($this->filesystem->fileExists($themeMainJsInTheme));
    }

    public function testKeepConfigurationCollectionWithGetScriptDistFolders(): void
    {
        $compiler = $this->getThemeCompiler();

        $configurationFactory = new StorefrontPluginConfigurationFactory(
            static::createStub(KernelPluginLoader::class),
            new StaticSourceResolver([]),
            new SymfonyFilesystem(),
        );

        $themePluginBundle = new TestTheme();
        $testTheme = $configurationFactory->createFromBundle($themePluginBundle);

        $configCollection = new StorefrontPluginConfigurationCollection();
        $configCollection->add($testTheme);

        $testTheme->setScriptFiles(
            FileCollection::createFromArray([
                'Resources/app/storefront/src/plugins/lorem-ipsum/plugin.js',
                '@Storefront',
            ])
        );

        $currentConfigCollection = clone $configCollection;

        $compiler->compileTheme(
            TestDefaults::SALES_CHANNEL,
            'TestTheme',
            $testTheme,
            $configCollection,
            true,
            Context::createDefaultContext()
        );

        // There should be no side effects on the configuration collection
        static::assertEquals($currentConfigCollection, $configCollection);
    }

    /**
     * @param array<string> $mappings
     */
    #[DataProvider('importPathsProvider')]
    public function testGetResolveImportPathsCallbackReturnsNull(array $mappings, string $originPath): void
    {
        $compiler = $this->getThemeCompiler();
        $closure = $compiler->getResolveImportPathsCallback($mappings);

        static::assertNull($closure($originPath));
    }

    public static function importPathsProvider(): \Generator
    {
        yield 'no mapping' => [
            [],
            'fake_path',
        ];
        yield 'wrong path without extension' => [
            ['fake_path' => 'fake_path'],
            '~fake_path',
        ];
        yield 'wrong path with min extension' => [
            ['fake_path' => 'fake_path'],
            '~fake_path.min',
        ];
        yield 'wrong path with zip extension' => [
            ['fake_path' => 'fake_path'],
            '~fake_path.zip',
        ];
    }

    protected function getThemeCompiler(
        ?CopyBatchInputFactory $copyBatchInputFactory = null,
        ?ThemeFilesystemResolver $themeFilesystemResolver = null,
        ?LoggerInterface $logger = null,
        ?ScssPhpCompiler $scssPhpCompiler = null,
    ): ThemeCompiler {
        return new ThemeCompiler(
            $this->filesystem,
            $this->tempFilesystem,
            static::createStub(FilesystemOperator::class),
            $copyBatchInputFactory ?? $this->copyBatchInputFactory,
            $this->themeFileResolver,
            true,
            $this->eventDispatcher,
            $themeFilesystemResolver ?? $this->themeFilesystemResolver,
            ['theme' => new UrlPackage(['http://localhost'], new EmptyVersionStrategy())],
            $this->cacheInvalidator,
            $logger ?? $this->logger,
            $this->pathBuilder,
            $scssPhpCompiler ?? $this->scssPhpCompiler,
            [],
            false
        );
    }
}

/**
 * @internal
 */
class DelayedThemeAssetDeleteAdapter extends InMemoryFilesystemAdapter
{
    /**
     * @var list<string>
     */
    private array $pendingDeletes = [];

    public function deleteDirectory(string $path): void
    {
        foreach ($this->listContents($path, true) as $item) {
            if ($item->isFile()) {
                $this->delete($item->path());
            }
        }
    }

    public function delete(string $path): void
    {
        $this->pendingDeletes[] = $path;
    }

    public function completeDeletes(): void
    {
        foreach ($this->pendingDeletes as $path) {
            parent::delete($path);
        }
        $this->pendingDeletes = [];
    }
}
