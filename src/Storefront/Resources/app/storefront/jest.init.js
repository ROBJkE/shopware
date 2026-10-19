/**
 * @sw-package framework
 */

// log rejections so that they are not printed to stderr as a fallback
process.on('unhandledRejection', (reason) => {
    console.log('REJECTION', reason);
});

global.router = {};

/**
 * JSDOM does not implement the HTML dialog methods Bootstrap 6 Drawer and Dialog call.
 */
const dialogPrototype = global.HTMLDialogElement?.prototype ?? global.HTMLElement.prototype;

const openDialog = function openDialog() {
    this.open = true;
    this.setAttribute('open', '');

    if (typeof this.focus === 'function') {
        this.focus();
    }
};

if (typeof dialogPrototype.showModal !== 'function') {
    dialogPrototype.showModal = openDialog;
}

if (typeof dialogPrototype.show !== 'function') {
    dialogPrototype.show = openDialog;
}

if (typeof dialogPrototype.close !== 'function') {
    dialogPrototype.close = function close() {
        this.open = false;
        this.removeAttribute('open');
    };
}

/**
 * JSDOM's selector engine rejects the :modal pseudo-class Bootstrap uses
 * to detect an open modal dialog.
 */
const patchSelector = (prototype, methodName) => {
    const original = prototype[methodName];

    if (typeof original !== 'function') {
        return;
    }

    prototype[methodName] = function modalSafeSelector(selector, ...args) {
        if (typeof selector === 'string' && selector.includes(':modal')) {
            return original.call(this, selector.replaceAll(':modal', ''), ...args);
        }

        return original.call(this, selector, ...args);
    };
};

patchSelector(Document.prototype, 'querySelector');
patchSelector(Document.prototype, 'querySelectorAll');
patchSelector(Element.prototype, 'querySelector');
patchSelector(Element.prototype, 'querySelectorAll');

/**
 * Global mocks for "PluginManager" to avoid errors when testing JS Plugins.
 * The "Plugin" base class (plugin-system/plugin.class.js) uses "window.PluginManager" methods internally.
 * This leads to errors when instantiating "Plugin" classes in jest because "window.PluginManager" is undefined.
 */
global.PluginManager = {
    getPluginInstancesFromElement: () => {
        return new Map();
    },
    getPluginInstanceFromElement: () => {
        return {};
    },
    getPlugin: () => {
        return {
            get: () => [],
        };
    },
    getPluginInstances: () => {
        return new Map();
    },
    initializePlugins: jest.fn(() => Promise.resolve()),
    initializePluginsInParentElement: jest.fn(() => Promise.resolve()),
};
