import Menu from 'bootstrap/js/dist/menu.js';
import Popover from 'bootstrap/js/dist/popover.js';
import Tooltip from 'bootstrap/js/dist/tooltip.js';

const TOOLTIP_SELECTOR = '[data-bs-toggle="tooltip"]';
const POPOVER_SELECTOR = '[data-bs-toggle="popover"]';

/**
 * @sw-package framework
 */
export default class BootstrapUtil {

    /**
     * Initialize Tooltip plugin everywhere
     * @see https://getbootstrap.com/docs/5.2/components/tooltips/#enable-tooltips
     */
    static initTooltip() {
        return new Tooltip(document.body, {
            selector: TOOLTIP_SELECTOR,
        });
    }

    /**
     * Initialize Popover plugin everywhere
     * @see https://getbootstrap.com/docs/5.2/components/popovers/#enable-popovers
     */
    static initPopover() {
        new Popover(document.querySelector('html'), {
            selector: POPOVER_SELECTOR,
            trigger: 'focus',
        });
    }

    /**
     * Using increased default offset to avoid focus outline being cut off by the dropdown menu.
     * @see https://getbootstrap.com/docs/5.3/components/dropdowns/#options
     */
    static setDropdownDefaultOffset() {
        Menu.Default.offset = [0, 6];
    }

    static initBootstrapPlugins() {
        this.initTooltip();
        this.initPopover();
        this.setDropdownDefaultOffset();
    }
}
