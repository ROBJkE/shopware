/**
 * @sw-package framework
 */
const chalk = require('chalk');

const { merge } = require('webpack-merge');
const path = require('path');
const webpack = require('webpack');
const fs = require('fs');
const TerserPlugin = require('terser-webpack-plugin');
const WebpackBar = require('webpackbar');
const CopyWebpackPlugin = require('copy-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const FilenameToChunkNamePlugin = require('./build/webpack/FilenameToChunkNamePlugin');

if (process.env.IPV4FIRST) {
    require('dns').setDefaultResultOrder('ipv4first');
}

const isProdMode = process.env.NODE_ENV === 'production';
const isHotMode = process.env.MODE === 'hot';
const isDevMode = process.env.NODE_ENV !== 'production' && process.env.MODE !== 'hot';
const isDebugMode = process.env.DEBUG === 'true';

const projectRootPath = process.env.PROJECT_ROOT
    ? path.resolve(process.env.PROJECT_ROOT)
    : path.resolve('../../../../..');

let themeFiles;
let features = {};

if (isHotMode) {
    const themeFilesConfigPath = path.resolve(projectRootPath, 'var/theme-files.json');
    themeFiles = require(themeFilesConfigPath);
}
const featureConfigPath = path.resolve(projectRootPath, 'var/config_js_features.json');

if (fs.existsSync(featureConfigPath)) {
    features = require(featureConfigPath);
} else {
    console.error(chalk.red('\n \u{26A0}️  The feature dump file "config_js_features.json" cannot be found. All features will be deactivated. Please execute bin/console feature:dump.  \u{26A0}️\n'));
}

const scssFeatureConfig = (() => {
    if (!features || Object.keys(features).length === 0) {
        return '$sw-features: ();';
    }

    const featuresScss = Object.entries(features).map(([key, val]) => {
        return `'${key}': ${val}`;
    }).join(',');

    return `$sw-features: (${featuresScss});`;
})();

const scssLoaders = [
    isHotMode ? { loader: 'style-loader' } : MiniCssExtractPlugin.loader,
    {
        loader: 'css-loader',
        options: {
            sourceMap: !isProdMode,
            // Skip auto resolving of url(), see: https://github.com/webpack-contrib/css-loader/blob/master/CHANGELOG.md#400-2020-07-25
            url: false,
        },
    },
    {
        loader: 'postcss-loader',
        options: {
            sourceMap: !isProdMode,
            postcssOptions: {
                config: false,
                plugins: [
                    require('postcss-prefix-custom-properties')({
                        prefix: 'bs-',
                        ignore: [/^--bs-/, /^--bd-/, /^--sw-/],
                    }),
                ],
            },
        },
    },
    {
        loader: 'sass-loader',
        options: {
            sourceMap: !isProdMode,
            additionalData: (content) => {
                if (content.includes('// WEBPACK_FEATURE_FLAGS')) {
                    return content.replace('// WEBPACK_FEATURE_FLAGS', scssFeatureConfig);
                }

                return content;
            },
            sassOptions: {
                loadPaths: [
                    path.resolve(__dirname, 'src/scss'),
                    path.resolve(__dirname, 'node_modules'),
                ],
                ...(!isDebugMode ? {
                    silenceDeprecations: [
                        'import',
                        'global-builtin',
                        'color-functions',
                        'slash-div',
                    ],
                } : {}),
            },
        },
    },
];

let hostName;
const proxyPort = parseInt(process.env.STOREFRONT_PROXY_PORT || 9998);

try {
    const { protocol, hostname } = new URL(process.env.PROXY_URL || process.env.APP_URL);
    hostName = `${protocol}//${hostname}`;
    if (proxyPort !== 80 && proxyPort !== 443) {
        hostName += `:${proxyPort}`;
    }
} catch (e) {
    hostName = undefined;
}

const useExtensionTwigWatch = process.env.SHOPWARE_STOREFRONT_SKIP_EXTENSION_TWIG_WATCH !== '1';
let watchFilePaths = isHotMode ? [`${themeFiles.basePath}/**/*.twig`] : [];

const pluginEntries = (() => {
    const pluginFile = path.resolve(process.env.PROJECT_ROOT, 'var/plugins.json');

    if (!fs.existsSync(pluginFile)) {
        throw new Error(`The file ${pluginFile} could not be found. Try bin/console bundle:dump to create this file.`);
    }

    const pluginDefinition = JSON.parse(fs.readFileSync(pluginFile, 'utf8'));

    return Object.entries(pluginDefinition)
        .filter(([, definition]) => definition.technicalName !== 'storefront' && !!definition.storefront && !!definition.storefront.entryFilePath && !process.env.hasOwnProperty('SKIP_' + definition.technicalName.toUpperCase().replace(/-/g, '_')))
        .map(([name, definition]) => {
            console.log(chalk.bgGreenBright.black(`# Plugin "${name}": Injected successfully`));

            const technicalName = definition.technicalName || name.replace(/([a-z])([A-Z])/g, '$1-$2').toLowerCase();
            const htmlFilePath = path.resolve(process.env.PROJECT_ROOT, definition.basePath, definition.storefront.path, '..', 'index.html');
            const hasHtmlFile = fs.existsSync(htmlFilePath);

            if (isHotMode && useExtensionTwigWatch && definition.views?.length > 0) {
                watchFilePaths = watchFilePaths.concat(definition.views.map((view) => {
                    return `${path.resolve(projectRootPath, definition.basePath, view)}/**/*.twig`;
                }));
            }

            return {
                name,
                technicalName: technicalName,
                technicalFolderName: technicalName.replace(/(-)/g, '').toLowerCase(),
                basePath: path.resolve(process.env.PROJECT_ROOT, definition.basePath),
                path: path.resolve(process.env.PROJECT_ROOT, definition.basePath, definition.storefront.path),
                filePath: path.resolve(process.env.PROJECT_ROOT, definition.basePath, definition.storefront.entryFilePath),
                isTheme: definition.isTheme,
                hasHtmlFile,
                webpackConfig: definition.storefront.webpack ? path.resolve(process.env.PROJECT_ROOT, definition.basePath, definition.storefront.webpack) : null,
            };
        });
})();

const coreConfig = {
    cache: true,
    experiments: {
        topLevelAwait: true,
    },
    devtool: (() => {
        if (isDevMode || isHotMode) {
            return 'eval-cheap-module-source-map';
        }

        if (isProdMode) {
            return process.env.GENERATE_SOURCEMAPS === 'true' ? 'source-map' : false;
        }

        return 'inline-cheap-source-map';
    })(),
    context: path.resolve(__dirname, 'src'),
    mode: isProdMode ? 'production' : 'development',
    entry: {},
    module: {
        rules: [
            {
                test: /\.m?(t|j)s$/,
                exclude: /(node_modules|bower_components|vendors)\/(?!(are-you-es5|fs-extra|query-string|split-on-first)\/).*/,
                use: [
                    {
                        loader: 'swc-loader',
                        options: {
                            env: {
                                mode: 'entry',
                                coreJs: '3.34.0',
                                // .browserlistrc is not found by swc-loader, so we load it manually: https://github.com/swc-project/swc/issues/3365
                                targets: require('browserslist').loadConfig({
                                    path: './',
                                }),
                            },
                            jsc: {
                                parser: {
                                    syntax: 'typescript',
                                },
                                transform: {
                                    // NEXT-30535 - Restore babel option to not use defineProperty for class fields.
                                    // Previously (in v6.5.x) this was done by `@babel/preset-typescript` automatically.
                                    useDefineForClassFields: false,
                                },
                            },
                        },
                    },
                ],
            },
            {
                test: /\.(woff(2)?|ttf|eot|svg)(\?v=\d+\.\d+\.\d+)?$/,
                use: [
                    {
                        loader: 'file-loader',
                        options: {
                            name: '[name].[ext]',
                            outputPath: 'assets/font',
                            publicPath: '../assets/font',
                        },
                    },
                ],
            },
            {
                test: /\.(jp(e)g|png|gif|svg)(\?v=\d+\.\d+\.\d+)?$/,
                use: [
                    {
                        loader: 'file-loader',
                        options: {
                            name: '[name].[ext]',
                            outputPath: 'assets/img',
                            publicPath: '../assets/img',
                        },
                    },
                ],
            },
            {
                // three.js/DRACO ships a .wasm decoder that dive imports as a URL (`?url`).
                // Emit it as an asset so its public URL can be fetched by the DRACOLoader at runtime.
                test: /\.wasm$/,
                type: 'asset/resource',
                generator: {
                    filename: 'assets/wasm/[name].[contenthash:8][ext]',
                },
            },
            {
                test: /\.scss$/,
                use: scssLoaders,
            },
            {
                test: /\.(woff(2)?|ttf|eot|svg|otf)$/,
                use: [
                    {
                        loader: 'file-loader',
                        options: {
                            name: '[name].[ext]',
                            outputPath: 'fonts/',
                        },
                    },
                ],
            },
        ],
    },
    name: 'shopware-6-storefront',
    optimization: {
        moduleIds: 'deterministic',
        chunkIds: false, // chunk name is set by FilenameToChunkNamePlugin
        ...(() => {
            if (isProdMode) {
                return {
                    minimizer: [
                        new TerserPlugin({
                            minify: TerserPlugin.swcMinify,
                            terserOptions: {
                                compress: true,
                            },
                            parallel: true,
                        }),
                    ],
                };
            }

            return {};
        })(),
    },
    output: {
        path: path.resolve(__dirname, 'dist'),
        filename: './storefront/[name].js',
        chunkFilename: isHotMode ? './storefront/[name].js' : './storefront/[name].[chunkhash:6].js',
        clean: (isHotMode ? false : {
            keep: /assets\//,
        }),
    },
    performance: {
        hints: false,
    },
    plugins: [
        new webpack.NoEmitOnErrorsPlugin(),
        new MiniCssExtractPlugin({
            filename: './css/[name].css',
            chunkFilename: './css/[name].css',
        }),
        new webpack.ids.DeterministicChunkIdsPlugin({
            maxLength: 5,
        }),
        new FilenameToChunkNamePlugin(),
        ...(() => {
            if (isHotMode) {
                return [
                    new webpack.HotModuleReplacementPlugin(),
                ];
            }
            return [];
        })(),
        ...(() => {
            if (fs.existsSync(path.resolve(__dirname, 'static'))) {
                // copy custom static assets
                return [
                    new CopyWebpackPlugin({
                        patterns: [
                            {
                                from: path.resolve(__dirname, 'static'),
                                to: path.resolve(__dirname, '../../../Resources/public/assets'),
                                globOptions: {
                                    ignore: ['.*'],
                                },
                            },
                        ],
                    }),
                ];
            }

            return [];
        })(),
    ],
    resolve: {
        extensions: [ '.ts', '.tsx', '.js', '.jsx', '.json', '.less', '.sass', '.scss', '.twig' ],
        modules: [
            // statically add the storefront node_modules folder, so sw plugins can resolve it
            path.resolve(__dirname, 'node_modules'),
            path.resolve(__dirname, 'node_modules/@shopware-ag/dive/node_modules'),
        ],
        alias: {
            src: path.resolve(__dirname, 'src'),
            assets: path.resolve(__dirname, 'assets'),
            scss: path.resolve(__dirname, 'src/scss'),
            vendor: path.resolve(__dirname, 'vendor'),
        },
    },
    stats: 'minimal',
    infrastructureLogging: {
        level: 'warn',
    },
    target: 'web',
};

// Create all plugin configs
const pluginConfigs = pluginEntries.map((plugin) => {

    // add custom config optionally when it exists
    let customPluginConfig = {};

    if (plugin.webpackConfig) {
        // eslint-disable-next-line no-console
        console.log(chalk.green(`# Plugin "${plugin.name}": Extends the webpack config successfully`));

        const pluginWebpackConfigFn = require(path.resolve(plugin.webpackConfig));

        customPluginConfig = pluginWebpackConfigFn({
            basePath: plugin.basePath,
            env: process.env.NODE_ENV,
            config: coreConfig,
            name: plugin.name,
            technicalName: plugin.technicalName,
            technicalFolderName: plugin.technicalFolderName,
            plugin,
        });
    }

    if (isHotMode) {
        const scriptAssetNames = themeFiles.script.map(script => script.assetName);
        const pluginNameDashes = plugin.name
            .replace(/[A-Z]/g, m => '-' + m.toLowerCase())
            .replace(/^-/, '');

        if (plugin.isTheme && scriptAssetNames.includes(pluginNameDashes)) {
            console.log(chalk.bgYellowBright.black(`# Compiling Theme "${plugin.name}" in HotMode`));
        }
        if (plugin.isTheme && !scriptAssetNames.includes(pluginNameDashes)) {
            console.log(chalk.bgHex('#fbbc39').black(`# Skipping "${plugin.name}" Theme in HotMode`));
            return merge([
                coreConfig,
                {},
                customPluginConfig,
            ]);
        }
    }

    return merge([
        coreConfig,
        {
            name: plugin.technicalName,
            entry: {
                [plugin.technicalName]: plugin.filePath,
            },
            output: {
                // Without an explicit unique name every build shares the default `webpackChunk` chunk
                // loading global, which lets one build's runtime process another build's chunks and
                // resolve a dynamic import to the wrong module. The core build keeps the default on
                // purpose: renaming its global would change the runtime every shop already ships.
                uniqueName: plugin.technicalName,
                // In dev mode use same path as the core storefront to be able to access all files in multi-compiler-mode
                path: isHotMode ? path.resolve(__dirname, 'dist') : path.resolve(plugin.path, '../dist/storefront'),
                filename: isHotMode ? `./${plugin.technicalName}/[name].js` : `./js/${plugin.technicalName}/[name].js`,
                chunkFilename:
                    isHotMode ? `./${plugin.technicalName}/[name].js` :
                        isDevMode ? `./js/${plugin.technicalName}/[name].js` :
                            `./js/${plugin.technicalName}/[name].[chunkhash:6].js`,
                clean: !isHotMode,
            },
            resolve: {
                modules: ['node_modules'],
            },
            plugins: [
                new WebpackBar({
                    name: plugin.name,
                    color: 'green',
                }),
            ],
            optimization: {
                splitChunks: false,
                runtimeChunk: false,
            },
        },
        customPluginConfig,
    ]);
});

const mergedCoreConfig = merge([
    coreConfig,
    {
        devServer: (() => {
            if (isHotMode) {
                return {
                    static: {
                        directory: path.resolve(__dirname, 'dist'),
                    },
                    open: false,
                    devMiddleware: {
                        publicPath: `${hostName}/`,
                        stats: 'none',
                    },
                    hot: false,
                    compress: false,
                    allowedHosts: 'all',
                    port: parseInt(process.env.STOREFRONT_ASSETS_PORT || 9999, 10),
                    host: '127.0.0.1',
                    client: {
                        webSocketURL: {
                            hostname: '0.0.0.0',
                            protocol: 'ws',
                            port: parseInt(process.env.STOREFRONT_ASSETS_PORT || 9999, 10),
                        },
                        logging: 'warn',
                        overlay: {
                            warnings: false,
                            errors: true,
                            runtimeErrors: false,
                        },
                    },
                    headers: {
                        'Access-Control-Allow-Origin': '*',
                    },
                    watchFiles: {
                        paths: watchFilePaths,
                        options: {
                            persistent: true,
                            cwd: projectRootPath,
                            ignorePermissionErrors: true,
                        },
                    },
                };
            }
            return {};
        })(),
        entry: {
            storefront: `${path.resolve('src')}/main.js`,
        },
        plugins: [
            new WebpackBar({
                name: 'Shopware 6 Storefront',
                color: '#118cff',
            }),
        ],
    },
]);

// Use multi-compiler
module.exports = [mergedCoreConfig, ...pluginConfigs];
// Default is infinity @see https://github.com/webpack/webpack/blob/c109f97b1bf5eceb2e0e498d399f46321f40b07f/lib/MultiCompiler.js#L83
module.exports.parallelism = process.env.SHOPWARE_BUILD_PARALLELISM ? parseInt(process.env.SHOPWARE_BUILD_PARALLELISM, 10) : Infinity;
