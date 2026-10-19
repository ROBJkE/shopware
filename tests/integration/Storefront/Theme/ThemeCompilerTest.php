<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Storefront\Theme;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\Exception\InvalidPlatformVersion;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatchInputFactory;
use Shopware\Core\Framework\App\ActiveAppsLoader;
use Shopware\Core\Framework\App\Source\SourceResolver;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\EnvTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Kernel;
use Shopware\Core\System\SystemConfig\Service\AppConfigReader;
use Shopware\Core\System\SystemConfig\Service\ConfigurationService;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\SystemConfig\Util\ConfigReader;
use Shopware\Core\Test\AppSystemTestBehaviour;
use Shopware\Core\Test\TestDefaults;
use Shopware\Storefront\Event\ThemeCompilerConcatenatedStylesEvent;
use Shopware\Storefront\Theme\Event\ThemeCompilerEnrichScssVariablesEvent;
use Shopware\Storefront\Theme\MD5ThemePathBuilder;
use Shopware\Storefront\Theme\ScssPhpCompiler;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\FileCollection;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfiguration;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfigurationCollection;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfigurationFactory;
use Shopware\Storefront\Theme\StorefrontPluginRegistry;
use Shopware\Storefront\Theme\Subscriber\ThemeCompilerEnrichScssVarSubscriber;
use Shopware\Storefront\Theme\ThemeCompiler;
use Shopware\Storefront\Theme\ThemeFileResolver;
use Shopware\Storefront\Theme\ThemeFilesystemResolver;
use Shopware\Tests\Integration\Storefront\Theme\fixtures\MockThemeCompilerConcatenatedSubscriber;
use Shopware\Tests\Integration\Storefront\Theme\fixtures\MockThemeVariablesSubscriber;
use Shopware\Tests\Integration\Storefront\Theme\fixtures\SimplePlugin\SimplePlugin;
use Symfony\Component\Asset\UrlPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
#[Package('discovery')]
class ThemeCompilerTest extends TestCase
{
    use AppSystemTestBehaviour;
    use DatabaseTransactionBehaviour;
    use EnvTestBehaviour;
    use KernelTestBehaviour;

    private ThemeCompiler $themeCompiler;

    private string $mockSalesChannelId;

    private EventDispatcherInterface $eventDispatcher;

    private Filesystem $themeFilesystem;

    private MD5ThemePathBuilder $themePathBuilder;

    protected function setUp(): void
    {
        $themeFileResolver = static::getContainer()->get(ThemeFileResolver::class);
        $this->eventDispatcher = static::getContainer()->get('event_dispatcher');

        // Avoid real filesystem operations
        $this->themeFilesystem = new Filesystem(new InMemoryFilesystemAdapter());

        $this->mockSalesChannelId = '98432def39fc4624b33213a56b8c944d';
        $this->themePathBuilder = new MD5ThemePathBuilder();

        $this->themeCompiler = new ThemeCompiler(
            $this->themeFilesystem,
            new Filesystem(new InMemoryFilesystemAdapter()),
            new Filesystem(new InMemoryFilesystemAdapter()),
            new CopyBatchInputFactory(),
            $themeFileResolver,
            true,
            $this->eventDispatcher,
            static::getContainer()->get(ThemeFilesystemResolver::class),
            ['theme' => new UrlPackage(['http://localhost'], new EmptyVersionStrategy())],
            static::getContainer()->get(CacheInvalidator::class),
            static::createStub(LoggerInterface::class),
            $this->themePathBuilder,
            static::getContainer()->get(ScssPhpCompiler::class),
        );
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(SourceResolver::class)->reset();
        static::getContainer()->get(ActiveAppsLoader::class)->reset();
    }

    public function testScssVariablesEventAddsNewVariablesToArray(): void
    {
        $subscriber = new MockThemeVariablesSubscriber(static::getContainer()->get(SystemConfigService::class));

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

    public function testConcanatedStylesEventPassThru(): void
    {
        $subscriber = new MockThemeCompilerConcatenatedSubscriber();

        $styles = 'body {}';

        $event = new ThemeCompilerConcatenatedStylesEvent($styles, $this->mockSalesChannelId);
        $subscriber->onGetConcatenatedStyles($event);
        $actual = $event->getConcatenatedStyles();

        $expected = $styles . MockThemeCompilerConcatenatedSubscriber::STYLES_CONCAT;

        static::assertSame($expected, $actual);
    }

    public function testDBException(): void
    {
        $configurationService = $this->getConfigurationServiceDbException(
            [
                new SimplePlugin(true, __DIR__ . '/fixtures/SimplePlugin'),
            ]
        );

        $storefrontPluginRegistry = $this->getStorefrontPluginRegistry(
            [
                new SimplePlugin(true, __DIR__ . '/fixtures/SimplePlugin'),
            ]
        );

        $event = new ThemeCompilerEnrichScssVariablesEvent([], TestDefaults::SALES_CHANNEL, Context::createDefaultContext());

        $subscriber = new ThemeCompilerEnrichScssVarSubscriber($configurationService, $storefrontPluginRegistry);
        $exception = null;
        try {
            $subscriber->enrichExtensionVars($event);
        } catch (\Throwable $throwable) {
            $exception = $throwable->getMessage();
        }
        // No variables should be added when a DB exception occurs
        static::assertNull($exception, 'No exception should be thrown, found: ' . $exception);
        static::assertCount(0, $event->getVariables());
    }

    /**
     * Theme compilation should be able to run without a database connection.
     */
    public function testCompileWithoutDB(): void
    {
        $this->stopTransactionAfter();
        $this->setEnvVars(['DATABASE_URL' => 'mysql://user:no@mysql:3306/test_db']);
        KernelLifecycleManager::bootKernel(false, 'noDB');
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $testFolder = $projectDir . '/bla';

        if (!\is_dir($testFolder)) {
            mkdir($testFolder);
        }

        $resolver = static::createStub(ThemeFileResolver::class);
        $resolver->method('resolveFiles')->willReturn([ThemeFileResolver::SCRIPT_FILES => new FileCollection(), ThemeFileResolver::STYLE_FILES => new FileCollection()]);

        $config = new StorefrontPluginConfiguration('test');
        $config->setAssetPaths(['bla']);

        $storefrontRoot = $testFolder . '/storefront';
        $io = new \Symfony\Component\Filesystem\Filesystem();
        $io->mkdir($storefrontRoot . '/Resources/app/storefront/dist/css');
        $io->dumpFile($storefrontRoot . '/Resources/app/storefront/dist/css/storefront.css', "body{color:#0042a0}\n");
        $themeFilesystemResolver = static::createStub(ThemeFilesystemResolver::class);
        $themeFilesystemResolver->method('getFilesystemForStorefrontConfig')
            ->willReturn(new \Shopware\Core\Framework\Util\Filesystem($storefrontRoot));

        $fs = new Filesystem(new InMemoryFilesystemAdapter());
        $tmpFs = new Filesystem(new InMemoryFilesystemAdapter());
        $assetFs = new Filesystem(new InMemoryFilesystemAdapter());
        $compiler = new ThemeCompiler(
            $fs,
            $tmpFs,
            $assetFs,
            new CopyBatchInputFactory(),
            $resolver,
            true,
            static::getContainer()->get('event_dispatcher'),
            $themeFilesystemResolver,
            [],
            static::createStub(CacheInvalidator::class),
            static::createStub(LoggerInterface::class),
            $this->themePathBuilder,
            static::getContainer()->get(ScssPhpCompiler::class),
        );

        $exception = null;
        try {
            $compiler->compileTheme(
                TestDefaults::SALES_CHANNEL,
                'test',
                $config,
                new StorefrontPluginConfigurationCollection(),
                true,
                Context::createDefaultContext()
            );
        } catch (\Throwable $throwable) {
            $exception = $throwable->getMessage();
        }

        // Clean up, no matter what
        $this->resetEnvVars();
        KernelLifecycleManager::bootKernel();
        $this->startTransactionBefore();
        $io->remove($testFolder);

        static::assertNull($exception, 'ThemeCompiler->compile() should be executable without a database connection. But following Exception was thrown: ' . $exception);
    }

    public function testCompileCopiesWebpackStylesheet(): void
    {
        $stylesheet = static::getContainer()->getParameter('kernel.project_dir') . '/src/Storefront/Resources/app/storefront/dist/css/storefront.css';
        $created = false;
        $io = new \Symfony\Component\Filesystem\Filesystem();
        if (!is_file($stylesheet)) {
            $io->mkdir(\dirname($stylesheet));
            $io->dumpFile($stylesheet, "body{color:#0042a0}\n");
            $created = true;
        }

        try {
            $config = new StorefrontPluginConfiguration('test');
            $this->themeCompiler->compileTheme(
                $this->mockSalesChannelId,
                'themeId',
                $config,
                new StorefrontPluginConfigurationCollection(),
                false,
                Context::createDefaultContext()
            );

            $themePrefix = $this->themePathBuilder->assemblePath($this->mockSalesChannelId, 'themeId');
            static::assertSame((string) file_get_contents($stylesheet), $this->themeFilesystem->read('theme/' . $themePrefix . '/css/all.css'));
        } finally {
            if ($created) {
                $io->remove($stylesheet);
            }
        }
    }

    /**
     * @param array<int, Plugin> $plugins
     */
    private function getConfigurationService(array $plugins): ConfigurationService
    {
        return new ConfigurationService(
            $plugins,
            new ConfigReader(),
            static::getContainer()->get(AppConfigReader::class),
            static::getContainer()->get('app.repository'),
            static::getContainer()->get(SystemConfigService::class),
            static::getContainer()->get(LoggerInterface::class)
        );
    }

    /**
     * @param array<int, Plugin> $plugins
     */
    private function getConfigurationServiceDbException(array $plugins): ConfigurationService
    {
        return new ConfigurationServiceException(
            $plugins,
            new ConfigReader(),
            static::getContainer()->get(AppConfigReader::class),
            static::getContainer()->get('app.repository'),
            static::getContainer()->get(SystemConfigService::class),
            static::getContainer()->get(LoggerInterface::class)
        );
    }

    /**
     * @param array<int, Plugin> $plugins
     */
    private function getStorefrontPluginRegistry(array $plugins): StorefrontPluginRegistry
    {
        $kernel = static::createStub(Kernel::class);
        $kernel
            ->method('getBundles')
            ->willReturn($plugins);

        return new StorefrontPluginRegistry(
            $kernel,
            static::getContainer()->get(StorefrontPluginConfigurationFactory::class),
            static::getContainer()->get(ActiveAppsLoader::class)
        );
    }
}

/**
 * @internal
 */
class ConfigurationServiceException extends ConfigurationService
{
    /**
     * @throws Exception
     */
    public function checkConfiguration(string $domain, Context $context): bool
    {
        throw new InvalidPlatformVersion('any');
    }
}
