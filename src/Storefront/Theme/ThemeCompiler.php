<?php declare(strict_types=1);

namespace Shopware\Storefront\Theme;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\Visibility;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatch;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatchInput;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatchInputFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Storefront\Theme\Exception\ThemeException;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfiguration;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfigurationCollection;
use Shopware\Storefront\Theme\Validator\SCSSValidator;
use Symfony\Component\Asset\Package as AssetPackage;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;

#[Package('discovery')]
class ThemeCompiler implements ThemeCompilerInterface
{
    /**
     * @var array<string, AssetPackage>
     */
    private array $packages;

    /**
     * @var array<string, array{
     *     manifest: array<string, array{file?: string, name?: string, src?: string, isEntry?: bool, css?: list<string>}>,
     *     vendorMap: array<string, string>
     * }|null>
     */
    private array $bundleBuildMetaCache = [];

    /**
     * @internal
     *
     * @param iterable<string, AssetPackage> $packages
     * @param array<int, string> $customAllowedRegex
     */
    public function __construct(
        private readonly FilesystemOperator $filesystem,
        private readonly FilesystemOperator $tempFilesystem,
        private readonly FilesystemOperator $assetFilesystem,
        private readonly CopyBatchInputFactory $copyBatchInputFactory,
        /**
         * @phpstan-ignore property.onlyWritten (Style compilation no longer resolves SCSS files. The argument stays for dependency injection.)
         */
        private readonly ThemeFileResolver $themeFileResolver,
        /**
         * @phpstan-ignore property.onlyWritten (SCSS output style is no longer selected here.)
         */
        private readonly bool $debug,
        /**
         * @phpstan-ignore property.onlyWritten (ThemeCompilerEnrichScssVariablesEvent and ThemeCompilerConcatenatedStylesEvent are no longer dispatched.)
         */
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ThemeFilesystemResolver $themeFilesystemResolver,
        iterable $packages,
        private readonly CacheInvalidator $cacheInvalidator,
        private readonly LoggerInterface $logger,
        private readonly AbstractThemePathBuilder $themePathBuilder,
        private readonly AbstractScssCompiler $scssCompiler,
        private readonly array $customAllowedRegex = [],
        private readonly bool $validate = false,
        private readonly string $visibility = Visibility::PUBLIC,
    ) {
        $this->packages = \is_array($packages) ? $packages : iterator_to_array($packages);
    }

    public function compileTheme(
        string $salesChannelId,
        string $themeId,
        StorefrontPluginConfiguration $themeConfig,
        StorefrontPluginConfigurationCollection $configurationCollection,
        bool $withAssets,
        Context $context
    ): void {
        // Fail before the previous theme directory is removed.
        $stylesheetPath = $this->locateWebpackStylesheet($themeConfig, $themeId);
        $this->validateThemeFields($themeConfig, $themeId);

        $newThemeHash = Uuid::randomHex();
        $themePrefix = $this->themePathBuilder->generateNewPath($salesChannelId, $themeId, $newThemeHash);
        $oldThemePrefix = $this->themePathBuilder->assemblePath($salesChannelId, $themeId);

        // If the system does not use seeded theme paths,
        // we have to delete the complete folder before to ensure that old files are deleted
        if ($oldThemePrefix === $themePrefix) {
            $path = 'theme' . \DIRECTORY_SEPARATOR . $themePrefix;

            $this->filesystem->deleteDirectory($path);
        }

        try {
            $styleCopyFiles = $this->getStyleCopyFiles($themePrefix, $stylesheetPath);

            $assetCopyFiles = [];
            $staleAssetFiles = [];
            if ($withAssets) {
                $assetCopyFiles = $this->getAssetCopyFiles($themeConfig, $configurationCollection, $themeId);
                $staleAssetFiles = $this->getStaleAssetFiles($themeId, $assetCopyFiles);
            }
        } catch (\Throwable $e) {
            throw ThemeException::themeCompileException(
                $themeConfig->getName() ?? '',
                'Error while trying to write compiled files: ' . $e->getMessage(),
                $e
            );
        }

        $scriptFiles = $this->getScriptCopyFiles($configurationCollection, $themePrefix);

        CopyBatch::copy(
            $this->filesystem,
            ...$styleCopyFiles,
            ...$assetCopyFiles,
            ...$scriptFiles,
        );

        // Never delete keys that are being overwritten: storage may apply deletes after the upload.
        foreach ($staleAssetFiles as $staleAssetFile) {
            $this->filesystem->delete($staleAssetFile);
        }

        $this->themePathBuilder->saveSeed($salesChannelId, $themeId, $newThemeHash);

        $this->cacheInvalidator->invalidate([
            ThemeConfigCacheInvalidator::buildCacheTag($themeId),
        ]);
    }

    /**
     * @param array<string, string> $resolveMappings
     */
    public function getResolveImportPathsCallback(array $resolveMappings): \Closure
    {
        return function (string $originalPath) use ($resolveMappings): ?string {
            foreach ($resolveMappings as $resolve => $resolvePath) {
                $resolve = '~' . $resolve;
                if (mb_strpos($originalPath, $resolve) === 0) {
                    $dirname = $resolvePath . \dirname(mb_substr($originalPath, mb_strlen($resolve)));

                    $filename = basename($originalPath);
                    $extension = $this->getImportFileExtension(pathinfo($filename, \PATHINFO_EXTENSION));
                    $path = $dirname . \DIRECTORY_SEPARATOR . $filename . $extension;
                    if (\is_file($path)) {
                        return $path;
                    }

                    $path = $dirname . \DIRECTORY_SEPARATOR . '_' . $filename . $extension;
                    if (\is_file($path)) {
                        return $path;
                    }
                }
            }

            return null;
        };
    }

    /**
     * @return array{imports: array<string, string>, scopes?: array<string, array<string, string>>, styles?: list<string>}|null
     */
    public function buildComponentImportMap(
        ?StorefrontPluginConfigurationCollection $configurationCollection = null,
    ): ?array {
        // Keep this cache scoped to a single import-map build.
        $this->bundleBuildMetaCache = [];

        $imports = [];
        $styles = [];

        $bundleNames = $this->resolveBundleNames($configurationCollection);

        // Core vendor chunks → top-level specifier imports from bundle asset URLs.
        $coreVendorMap = $this->readBundleBuildMeta('Storefront')['vendorMap'] ?? [];
        foreach ($coreVendorMap as $specifier => $chunkPath) {
            $imports[$specifier] = '/bundles/' . $this->toAssetDirectory('Storefront') . '/storefront/components/' . $chunkPath;
        }

        // The shopware singleton is published as a normal bundle asset.
        $imports['shopware'] = '/bundles/' . $this->toAssetDirectory('Storefront') . '/storefront/shopware/shopware.js';

        // Component entries (with content-hashed filenames) come from per-bundle
        // build metadata in `public/bundles/<bundle>/storefront/components/.vite/build-meta.json`.
        $componentManifest = $this->collectComponentManifestEntries($bundleNames);
        foreach ($componentManifest as $tag => $entry) {
            if (isset($entry['js']) && $entry['js'] !== '') {
                $imports[$tag] = $entry['js'];
            }
            if (isset($entry['css']) && $entry['css'] !== []) {
                foreach ($entry['css'] as $cssPath) {
                    $styles[] = $cssPath;
                }
            }
        }

        // Extension vendor maps → scoped specifier imports so that vendor chunks
        // are only resolved when inside that extension's component scope.
        $scopes = $this->buildExtensionVendorScopes($bundleNames);

        $result = ['imports' => $imports];

        if ($scopes !== []) {
            $result['scopes'] = $scopes;
        }

        if ($styles !== []) {
            $result['styles'] = $styles;
        }

        return $result;
    }

    /**
     * @deprecated tag:v6.8.0 - Build-meta loading no longer fetches public URLs.
     * Keep as protected no-op for backwards compatibility.
     */
    protected function fetchPublicFile(string $url): string|false
    {
        Feature::triggerDeprecationOrThrow(
            'v6.8.0.0',
            Feature::deprecatedMethodMessage(self::class, __METHOD__, 'v6.8.0.0')
        );

        return false;
    }

    private function readBuildMetaFile(string $relativeMetaPath): ?string
    {
        $filesystemPath = ltrim($relativeMetaPath, '/');

        try {
            $raw = $this->assetFilesystem->read($filesystemPath);

            return $raw !== '' ? $raw : null;
        } catch (FilesystemException) {
            return null;
        }
    }

    /**
     * @return list<CopyBatchInput>
     */
    private function copyScriptFilesToTheme(
        StorefrontPluginConfigurationCollection $configurationCollection,
        string $themePrefix
    ): array {
        // The "getScriptDistFolders" method can remove script files from the scriptFiles property in the configurationCollection.
        // This can result in plugin script files being missing from later methods. Cloning the collection prevents this.
        // As structs are overriding the object cloning with the "CloneTrait" and implement a deep copy mechanism,
        // cloning the collection will prevent the mutation of the configurations and file collections inside as well.
        $scriptsDist = $this->getScriptDistFolders(clone $configurationCollection);
        $themePath = 'theme/' . $themePrefix;
        $distRelativePath = 'Resources/app/storefront/dist/storefront';

        $copyFiles = [];

        foreach ($scriptsDist as $folderName => $pluginConfig) {
            // For themes, we get basePath with Resources and for Plugins without, so we always remove and add it again
            $pathToJsFiles = $distRelativePath;
            if ($folderName !== 'storefront') {
                $pathToJsFiles .= '/js/' . $folderName;
            }

            $fs = $this->themeFilesystemResolver->getFilesystemForStorefrontConfig($pluginConfig);

            if ($fs->has($pathToJsFiles)) {
                $pathToJsFiles = $fs->realpath($pathToJsFiles);
            }

            $files = $this->getScriptDistFiles($pathToJsFiles);

            if ($files === null) {
                continue;
            }

            $targetPath = $themePath . '/js/' . $folderName;
            foreach ($files as $file) {
                $filePath = $file->getRealPath();
                if ($filePath) {
                    $copyFiles[] = new CopyBatchInput($filePath, [$targetPath . '/' . $file->getFilename()], $this->visibility);
                }
            }
        }

        return $copyFiles;
    }

    /**
     * Collects component import-map entries from all active bundle manifests.
     *
     * @param list<string> $bundleNames
     *
     * @return array<string, array{bundle: string, js?: string, css?: list<string>}>
     */
    private function collectComponentManifestEntries(
        array $bundleNames,
    ): array {
        $manifest = [];
        foreach ($bundleNames as $bundleName) {
            $bundleManifest = $this->readBundleComponentManifest($bundleName);
            if ($bundleManifest === null) {
                continue;
            }

            foreach ($bundleManifest as $tag => $entry) {
                $manifest[$tag] = $entry;
            }
        }

        return $manifest;
    }

    /**
     * @return array<string, array{bundle: string, js?: string, css?: list<string>}>|null
     */
    private function readBundleComponentManifest(
        string $bundleName,
    ): ?array {
        $buildMeta = $this->readBundleBuildMeta($bundleName);
        if ($buildMeta === null || $buildMeta['manifest'] === []) {
            return null;
        }

        $viteManifest = $buildMeta['manifest'];
        $jsToCssFiles = $this->collectJsToCssFiles($viteManifest);

        $result = [];
        $publicBase = '/bundles/' . $this->toAssetDirectory($bundleName) . '/storefront/components/';

        foreach ($viteManifest as $entry) {
            if (($entry['isEntry'] ?? false) !== true || !isset($entry['name']) || $entry['name'] === '' || !isset($entry['file'])) {
                continue;
            }

            $entryName = $this->normalizeComponentEntryName($entry['name']);
            $outputFile = $entry['file'];
            $tag = str_replace('/', ':', $entryName);

            if (str_ends_with($outputFile, '.css')) {
                $result[$tag]['bundle'] = $bundleName;
                $result[$tag]['css'][] = $publicBase . $outputFile;
            } elseif (str_ends_with($outputFile, '.js')) {
                $result[$tag]['bundle'] = $bundleName;
                $result[$tag]['js'] = $publicBase . $outputFile;

                if (isset($jsToCssFiles[$entryName])) {
                    foreach ($jsToCssFiles[$entryName] as $cssFile) {
                        $result[$tag]['css'][] = $publicBase . $cssFile;
                    }
                }
            }
        }

        foreach ($result as $tag => $entry) {
            if (!isset($entry['css'])) {
                continue;
            }
            $result[$tag]['css'] = array_values(array_unique($entry['css']));
        }

        return $result;
    }

    /**
     * @param array<string, array{file?: string, name?: string, src?: string, isEntry?: bool, css?: list<string>}> $viteManifest
     *
     * @return array<string, list<string>>
     */
    private function collectJsToCssFiles(array $viteManifest): array
    {
        $jsToCssFiles = [];
        foreach ($viteManifest as $entry) {
            if (($entry['isEntry'] ?? false) !== true || !isset($entry['name']) || $entry['name'] === '') {
                continue;
            }
            if (isset($entry['css']) && $entry['css'] !== []) {
                $jsToCssFiles[$this->normalizeComponentEntryName($entry['name'])] = $entry['css'];
            }
        }

        return $jsToCssFiles;
    }

    private function normalizeComponentEntryName(string $entryName): string
    {
        $entryName = preg_replace('/\.(scss|css)$/D', '', $entryName) ?? $entryName;

        return preg_replace('~/index$~D', '', $entryName) ?? $entryName;
    }

    /**
     * @return array{
     *     manifest: array<string, array{file?: string, name?: string, src?: string, isEntry?: bool, css?: list<string>}>,
     *     vendorMap: array<string, string>
     * }|null
     */
    private function readBundleBuildMeta(
        string $bundleName,
    ): ?array {
        if (\array_key_exists($bundleName, $this->bundleBuildMetaCache)) {
            return $this->bundleBuildMetaCache[$bundleName];
        }

        $relativeMetaPath = $this->getPublishedComponentsRoot($bundleName) . '/.vite/build-meta.json';
        $raw = $this->readBuildMetaFile($relativeMetaPath);
        if ($raw === null || $raw === '') {
            return $this->bundleBuildMetaCache[$bundleName] = null;
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->bundleBuildMetaCache[$bundleName] = null;
        }

        return $this->bundleBuildMetaCache[$bundleName] = $this->normalizeBundleBuildMeta($decoded);
    }

    /**
     * @return array{
     *     manifest: array<string, array{file?: string, name?: string, src?: string, isEntry?: bool, css?: list<string>}>,
     *     vendorMap: array<string, string>
     * }
     */
    private function normalizeBundleBuildMeta(mixed $decoded): array
    {
        if (!\is_array($decoded)) {
            return [
                'manifest' => [],
                'vendorMap' => [],
            ];
        }

        $manifest = [];
        if (isset($decoded['manifest']) && \is_array($decoded['manifest'])) {
            $manifest = $decoded['manifest'];
        }

        $vendorMap = [];
        if (isset($decoded['vendorMap']) && \is_array($decoded['vendorMap'])) {
            $vendorMap = $decoded['vendorMap'];
        }

        return [
            'manifest' => $manifest,
            'vendorMap' => $vendorMap,
        ];
    }

    /**
     * @param list<string> $bundleNames
     *
     * @return array<string, array<string, string>>
     */
    private function buildExtensionVendorScopes(
        array $bundleNames,
    ): array {
        $scopes = [];

        foreach ($bundleNames as $bundleName) {
            if ($bundleName === 'Storefront') {
                continue;
            }

            $vendorMap = $this->readBundleBuildMeta($bundleName)['vendorMap'] ?? [];
            if ($vendorMap === []) {
                continue;
            }

            $bundleComponentsBase = '/bundles/' . $this->toAssetDirectory($bundleName) . '/storefront/components/';
            $scopeKey = $bundleComponentsBase . $bundleName . '/';

            foreach ($vendorMap as $specifier => $chunkPath) {
                $scopes[$scopeKey][$specifier] = $bundleComponentsBase . $chunkPath;
            }
        }

        return $scopes;
    }

    /**
     * @return list<string>
     */
    private function resolveBundleNames(?StorefrontPluginConfigurationCollection $configurationCollection): array
    {
        if ($configurationCollection === null) {
            return ['Storefront'];
        }

        $bundleNames = ['Storefront'];

        foreach ($configurationCollection as $configuration) {
            $bundleNames[] = $configuration->getTechnicalName();
        }

        return array_values(array_unique($bundleNames));
    }

    private function getPublishedComponentsRoot(string $bundleName): string
    {
        return 'bundles/' . $this->toAssetDirectory($bundleName) . '/storefront/components';
    }

    private function toAssetDirectory(string $bundleName): string
    {
        return preg_replace('/bundle$/D', '', strtolower($bundleName)) ?? strtolower($bundleName);
    }

    /**
     * @return array<string, StorefrontPluginConfiguration>
     */
    private function getScriptDistFolders(StorefrontPluginConfigurationCollection $configurationCollection): array
    {
        $scriptsDistFolders = [];
        foreach ($configurationCollection as $configuration) {
            $scripts = $configuration->getScriptFiles();
            foreach ($scripts as $key => $script) {
                if ($script->getFilepath() === '@Storefront') {
                    $scripts->remove($key);
                }
            }
            if ($scripts->count() === 0) {
                continue;
            }

            $scriptsDistFolders[$configuration->getAssetName()] = $configuration;
        }

        return $scriptsDistFolders;
    }

    private function getScriptDistFiles(string $path): ?Finder
    {
        try {
            $finder = (new Finder())->files()->followLinks()->in($path)->exclude('js');
        } catch (DirectoryNotFoundException $e) {
            $this->logger->error($e->getMessage());
        }

        return $finder ?? null;
    }

    /**
     * @return list<CopyBatchInput>
     */
    private function getAssets(
        StorefrontPluginConfiguration $configuration,
        StorefrontPluginConfigurationCollection $configurationCollection,
        string $outputPath
    ): array {
        $collected = [];

        if (!$configuration->getAssetPaths()) {
            return [];
        }

        $fs = $this->themeFilesystemResolver->getFilesystemForStorefrontConfig($configuration);

        foreach ($configuration->getAssetPaths() as $asset) {
            if (str_starts_with($asset, '@')) {
                $name = mb_substr($asset, 1);
                $config = $configurationCollection->getByTechnicalName($name);
                if (!$config) {
                    throw ThemeException::couldNotFindThemeByName($name);
                }

                $collected = [...$collected, ...$this->getAssets($config, $configurationCollection, $outputPath)];

                continue;
            }

            if ($asset[0] !== '/' && $fs->has('Resources', $asset)) {
                $asset = $fs->path('Resources', $asset);
            }

            $collected = [...$collected, ...$this->copyBatchInputFactory->fromDirectory($asset, $outputPath, $this->visibility)];
        }

        return array_values($collected);
    }

    private function getImportFileExtension(string $extension): string
    {
        // If the import has no extension, it must be a SCSS module.
        if ($extension === '') {
            return '.scss';
        }

        // If the import has a .min extension, we assume it must be a compiled CSS file.
        if ($extension === 'min') {
            return '.css';
        }

        // If it has any other extension, we don't assume a specific extension.
        return '';
    }

    /**
     * Webpack emits one Storefront stylesheet. Theme compilation copies that file.
     */
    private function locateWebpackStylesheet(StorefrontPluginConfiguration $themeConfig, string $themeId): string
    {
        $filesystem = $this->themeFilesystemResolver->getFilesystemForStorefrontConfig(new StorefrontPluginConfiguration('Storefront'));
        $relativePath = 'Resources/app/storefront/dist/css/storefront.css';

        if (!$filesystem->hasFile($relativePath)) {
            throw ThemeException::themeCompileException(
                $themeConfig->getTechnicalName() . ' - Theme-ID: ' . $themeId,
                'The storefront stylesheet "dist/css/storefront.css" is missing. Run the storefront build (`bin/build-storefront.sh` or `composer build:js:storefront`).',
            );
        }

        return $filesystem->path($relativePath);
    }

    private function validateThemeFields(StorefrontPluginConfiguration $configuration, string $themeId): void
    {
        if (!$this->validate) {
            return;
        }

        foreach (($configuration->getThemeConfig() ?? [])['fields'] ?? [] as $data) {
            if (
                !\is_array($data)
                || (\array_key_exists('scss', $data) && $data['scss'] === false)
                || !isset($data['type'])
            ) {
                continue;
            }

            try {
                SCSSValidator::validate($this->scssCompiler, $data, $this->customAllowedRegex, true);
            } catch (\Throwable $exception) {
                throw ThemeException::themeCompileException(
                    $configuration->getTechnicalName() . ' - Theme-ID: ' . $themeId,
                    $exception->getMessage(),
                    $exception,
                );
            }
        }
    }

    /**
     * @return list<CopyBatchInput>
     */
    private function getStyleCopyFiles(string $themePrefix, string $stylesheetPath): array
    {
        $compileLocation = 'theme' . \DIRECTORY_SEPARATOR . $themePrefix;

        return [
            new CopyBatchInput(
                $stylesheetPath,
                [
                    $compileLocation . \DIRECTORY_SEPARATOR . 'css' . \DIRECTORY_SEPARATOR . 'all.css',
                ],
                $this->visibility
            ),
        ];
    }

    /**
     * @return list<CopyBatchInput>
     */
    private function getAssetCopyFiles(
        StorefrontPluginConfiguration $themeConfig,
        StorefrontPluginConfigurationCollection $configurationCollection,
        string $themeId
    ): array {
        return $this->getAssets($themeConfig, $configurationCollection, 'theme' . \DIRECTORY_SEPARATOR . $themeId);
    }

    /**
     * @param list<CopyBatchInput> $assetCopyFiles
     *
     * @return list<string>
     */
    private function getStaleAssetFiles(string $themeId, array $assetCopyFiles): array
    {
        $assetPath = 'theme' . \DIRECTORY_SEPARATOR . $themeId;
        if (!$this->filesystem->directoryExists($assetPath)) {
            return [];
        }

        $targetFiles = [];
        foreach ($assetCopyFiles as $assetCopyFile) {
            foreach ($assetCopyFile->getTargetFiles() as $targetFile) {
                $targetFiles[ltrim($targetFile, '/')] = true;
            }
        }

        $staleAssetFiles = [];
        foreach ($this->filesystem->listContents($assetPath, FilesystemReader::LIST_DEEP) as $item) {
            if ($item->isFile() && str_starts_with($item->path(), $assetPath . '/') && !isset($targetFiles[$item->path()])) {
                $staleAssetFiles[] = $item->path();
            }
        }

        return $staleAssetFiles;
    }

    /**
     * @return list<CopyBatchInput>
     */
    private function getScriptCopyFiles(
        StorefrontPluginConfigurationCollection $configurationCollection,
        string $themePrefix
    ): array {
        return $this->copyScriptFilesToTheme($configurationCollection, $themePrefix);
    }
}
