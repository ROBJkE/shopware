import Menu from 'bootstrap/js/dist/menu.js';
import Popover from 'bootstrap/js/dist/popover.js';
import Tooltip from 'bootstrap/js/dist/tooltip.js';
import BootstrapUtil from 'src/utility/bootstrap/bootstrap.util';

jest.mock('bootstrap/js/dist/tooltip.js', () => ({
    __esModule: true,
    default: jest.fn(),
}));

jest.mock('bootstrap/js/dist/popover.js', () => ({
    __esModule: true,
    default: jest.fn(),
}));

jest.mock('bootstrap/js/dist/menu.js', () => {
    const Menu = jest.fn();
    Menu.Default = { offset: [0, 0] };

    return {
        __esModule: true,
        default: Menu,
    };
});

/**
 * @package storefront
 */
describe('BootstrapUtil tests', () => {
    beforeEach(() => {
        Menu.Default.offset = [0, 0];

        document.body.innerHTML = `
            <button class="btn" data-bs-toggle="tooltip" title="Tooltip text">
                Button with Tooltip
            </button>

            <button class="btn" data-bs-toggle="popover" title="Tooltip text">
                Button with Popover
            </button>
        `;
    });

    test('initializes all Bootstrap plugins', () => {
        BootstrapUtil.initBootstrapPlugins();

        expect(Tooltip).toHaveBeenCalledTimes(1);
        expect(Popover).toHaveBeenCalledTimes(1);
    });

    test('initializes Bootstrap tooltip', () => {
        BootstrapUtil.initTooltip();

        expect(Tooltip).toHaveBeenCalledTimes(1);
        expect(Popover).toHaveBeenCalledTimes(0);
    });

    test('initializes Bootstrap popover', () => {
        BootstrapUtil.initPopover();

        expect(Tooltip).toHaveBeenCalledTimes(0);
        expect(Popover).toHaveBeenCalledTimes(1);
    });

    test('sets Bootstrap menu default offset', () => {
        BootstrapUtil.setDropdownDefaultOffset();

        expect(Menu.Default.offset).toEqual([0, 6]);
    });
});
