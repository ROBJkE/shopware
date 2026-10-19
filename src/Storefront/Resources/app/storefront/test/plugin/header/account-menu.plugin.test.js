import Menu from 'bootstrap/js/dist/menu.js';
import OffCanvasAccountMenu from 'src/plugin/header/account-menu.plugin';

jest.mock('bootstrap/js/dist/menu.js', () => {
    const Menu = jest.fn();
    Menu.getInstance = jest.fn();
    Menu.Default = { offset: [0, 0] };

    return {
        __esModule: true,
        default: Menu,
    };
});

describe('OffCanvasAccountMenuPlugin tests', () => {
    let plugin;

    beforeEach(() => {
        document.body.innerHTML = `
        <div class="account-menu">
            <div class="dropdown">
                <button class="btn account-menu-btn header-actions-btn show" type="button" id="accountWidget" data-offcanvas-account-menu="true" data-bs-toggle="menu" aria-haspopup="true" aria-expanded="true" aria-label="Your account" title="Your account">
                    <span class="icon icon-avatar"></span>
                </button>

                <div class="menu account-menu-dropdown js-account-menu-dropdown" aria-labelledby="accountWidget">
                    <div class="drawer-header p-0">
                        <button class="btn btn-solid theme-light offcanvas-close js-offcanvas-close">
                            <span class="icon icon-x icon-sm"></span>
                            Close menu
                        </button>
                    </div>

                    <div class="drawer-body">
                        <div class="account-menu">
                            <div class="menu-header account-menu-header">Your account</div>

                            <div class="account-menu-login">
                                <a href="/account/login" title="Log in" class="btn btn-solid theme-primary account-menu-login-button">Log in</a>
                                <div class="account-menu-register">or <a href="/account/login" title="Sign up">sign up</a></div>
                            </div>

                            <div class="account-menu-links">
                                <div class="header-account-menu">
                                    <div class="card account-menu-inner">
                                        <div class="list-group list-group-flush account-aside-list-group">
                                            <a href="/account" title="Overview" class="list-group-item list-group-item-action account-aside-item">Overview</a>
                                            <a href="/account/profile" title="Your profile" class="list-group-item list-group-item-action account-aside-item">Your profile</a>
                                            <a href="/account/address" title="Addresses" class="list-group-item list-group-item-action account-aside-item">Addresses</a>
                                            <a href="/account/payment" title="Payment methods" class="list-group-item list-group-item-action account-aside-item">Payment methods</a>
                                            <a href="/account/order" title="Orders" class="list-group-item list-group-item-action account-aside-item">Orders</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        `;

        const el = document.querySelector('[data-offcanvas-account-menu]');

        window.focusHandler = {
            saveFocusState: jest.fn(),
            resumeFocusState: jest.fn(),
            // @todo: Remove when upstream issue https://github.com/twbs/bootstrap/issues/42503 is resolved.
            _addFocusTrapGuard: jest.fn(),
            _removeFocusTrapGuard: jest.fn(),
        };

        plugin = new OffCanvasAccountMenu(el);

        jest.useFakeTimers();
    });

    afterEach(() => {
        jest.useRealTimers();
    });

    test('Creates plugin instance', () => {
        expect(typeof plugin).toBe('object');
    });

    test('Opens an OffCanvas with the account menu HTML on mobile viewports', () => {
        // Simulate mobile viewport for viewport detection helper
        window.getComputedStyle = jest.fn(() => {
            return {
                content: 'xs',
                getPropertyValue: jest.fn(() => 'xs'),
            };
        });

        const event = new Event('click', { bubbles: true });

        // We need to mock composedPath because it's not available in JSDOM and used internally by Bootstrap
        event.composedPath = () => [document.body];

        // Click on trigger element
        plugin.el.dispatchEvent(event);

        jest.runAllTimers();

        // Ensure OffCanvas exists with account menu HTML
        expect(document.querySelector('.drawer.account-menu-offcanvas .account-menu-inner')).toBeTruthy();
    });

    test('Does not open OffCanvas on desktop viewports', () => {
        // Simulate desktop viewport for viewport detection helper
        window.getComputedStyle = jest.fn(() => {
            return {
                content: 'xl',
                getPropertyValue: jest.fn(() => 'xl'),
            };
        });

        const event = new Event('click', { bubbles: true });

        // We need to mock composedPath because it's not available in JSDOM and used internally by Bootstrap
        event.composedPath = () => [document.body];

        // Click on trigger element
        plugin.el.dispatchEvent(event);

        jest.runAllTimers();

        // Ensure no OffCanvas exists on desktop viewport
        expect(document.querySelector('.drawer.account-menu-offcanvas')).toBeFalsy();
    });

    test('Closes the dropdown when the viewport changes to allowed viewports', () => {
        // Initially, simulate desktop viewport for viewport detection helper
        window.getComputedStyle = jest.fn(() => {
            return {
                content: 'xl',
                getPropertyValue: jest.fn(() => 'xl'),
            };
        });

        // Mock the bootstrap dropdown instance
        const mockDropdown = {
            show: jest.fn(),
            hide: jest.fn(),
        };
        Menu.getInstance.mockReturnValue(mockDropdown);

        const event = new Event('click', { bubbles: true });

        // We need to mock composedPath because it's not available in JSDOM and used internally by Bootstrap
        event.composedPath = () => [document.body];

        // Click on trigger element
        document.querySelector('.account-menu-btn').dispatchEvent(event);

        jest.runAllTimers();

        // Simulate a change to mobile viewport (xl to xs)
        window.getComputedStyle = jest.fn(() => {
            return {
                content: 'xs',
                getPropertyValue: jest.fn(() => 'xs'),
            };
        });
        document.dispatchEvent(new CustomEvent('Viewport/hasChanged'));

        // Should close dropdown on mobile viewport after the viewport change
        expect(mockDropdown.hide).toHaveBeenCalled();
    });
});
