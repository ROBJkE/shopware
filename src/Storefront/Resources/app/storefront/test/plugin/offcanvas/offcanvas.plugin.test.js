import Drawer from 'bootstrap/js/dist/drawer.js';
import OffCanvas from 'src/plugin/offcanvas/offcanvas.plugin';

/**
 * @package storefront
 */
describe('OffCanvas tests', () => {

    beforeEach(() => {
        window.focusHandler = {
            saveFocusState: jest.fn(),
            resumeFocusState: jest.fn(),
            // @todo: Remove when upstream issue https://github.com/twbs/bootstrap/issues/42503 is resolved.
            _addFocusTrapGuard: jest.fn(),
            _removeFocusTrapGuard: jest.fn(),
        };
    });

    afterEach(() => {
        jest.useRealTimers();
        document.body.innerHTML = '';
    });

    it('should create and open with content', () => {
        jest.useFakeTimers();

        OffCanvas.open('Interesting content');
        jest.runAllTimers();

        // Ensure offCanvas was injected to the DOM by selecting it
        const offCanvasElement = document.querySelector('.drawer');

        // Ensure exists check works
        expect(OffCanvas.exists()).toBe(true);

        // Bootstrap 6 drawers are open <dialog> elements. The browser supplies the dialog role.
        expect(offCanvasElement.tagName).toBe('DIALOG');
        expect(offCanvasElement.open).toBe(true);

        // Ensure the OffCanvas content is set
        expect(offCanvasElement.innerHTML).toBe('Interesting content');
    });

    it('should close', () => {
        jest.useFakeTimers();

        // Open the OffCanvas
        OffCanvas.open('Interesting content');
        jest.runAllTimers();

        // Ensue OffCanvas was opened
        expect(OffCanvas.exists()).toBe(true);
        expect(document.querySelector('.drawer').open).toBe(true);

        // Close the OffCanvas
        OffCanvas.close();
        jest.runAllTimers();

        // Ensure OffCanvas is no longer existing in the DOM
        expect(document.querySelector('.drawer')).toBeFalsy();
        expect(OffCanvas.exists()).toBe(false);
    });

    it('restores the previous accessibility state of the background after closing', () => {
        document.body.innerHTML = `
            <header id="header"></header>
            <main id="main" aria-hidden="false"></main>
            <footer id="footer" inert></footer>
        `;

        const header = document.getElementById('header');
        const main = document.getElementById('main');
        const footer = document.getElementById('footer');
        footer.inert = true;

        jest.useFakeTimers();
        OffCanvas.open('Interesting content');
        jest.runAllTimers();

        [header, main, footer].forEach(element => {
            expect(element.inert).toBe(true);
            expect(element.getAttribute('aria-hidden')).toBe('true');
        });

        OffCanvas.close();
        jest.runAllTimers();

        expect(header.inert).not.toBe(true);
        expect(header.hasAttribute('aria-hidden')).toBe(false);
        expect(main.inert).not.toBe(true);
        expect(main.getAttribute('aria-hidden')).toBe('false');
        expect(footer.inert).toBe(true);
        expect(footer.hasAttribute('aria-hidden')).toBe(false);
    });

    it('saves and restores the focused trigger before making the background inaccessible', () => {
        document.body.innerHTML = `
            <main id="main">
                <button id="trigger">Open drawer</button>
            </main>
        `;

        const main = document.getElementById('main');
        const trigger = document.getElementById('trigger');
        let savedFocus = null;

        window.focusHandler.saveFocusState.mockImplementation(() => {
            expect(main.inert).not.toBe(true);
            expect(main.hasAttribute('aria-hidden')).toBe(false);
            savedFocus = document.activeElement;
        });
        window.focusHandler.resumeFocusState.mockImplementation(() => savedFocus?.focus());

        trigger.focus();
        jest.useFakeTimers();
        OffCanvas.open('Interesting content');
        jest.runAllTimers();

        expect(window.focusHandler.saveFocusState).toHaveBeenCalledWith('drawer');

        OffCanvas.close();
        jest.runAllTimers();

        expect(document.activeElement).toBe(trigger);
    });

    it('should close via click on backdrop', () => {
        jest.useFakeTimers();

        // Open the OffCanvas
        OffCanvas.open('Interesting content');
        jest.runAllTimers();

        // Ensue OffCanvas was opened
        expect(OffCanvas.exists()).toBe(true);

        const offCanvasElement = document.querySelector('.drawer');
        // A click on the dialog itself is the native backdrop click.
        offCanvasElement.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        jest.runAllTimers();

        // Ensure OffCanvas is no longer existing in the DOM
        expect(OffCanvas.exists()).toBe(false);
        expect(document.querySelector('.drawer')).toBeFalsy();
    });

    it('should not close via click on backdrop when configured', () => {
        jest.useFakeTimers();

        // Open the OffCanvas
        OffCanvas.open(
            'Interesting content',
            null,
            'right',
            false // Don't allow close on backdrop
        );
        jest.runAllTimers();

        // Ensue OffCanvas was opened
        expect(OffCanvas.exists()).toBe(true);

        const offCanvasElement = document.querySelector('.drawer');
        // A static backdrop ignores a click on the dialog itself.
        offCanvasElement.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        jest.runAllTimers();

        // Ensure OffCanvas is still in the DOM
        expect(OffCanvas.exists()).toBe(true);
        expect(document.querySelector('.drawer')).toBeTruthy();
    });

    it('should be able to set additional CSS classes', () => {
        jest.useFakeTimers();

        OffCanvas.open(
            'Interesting content',
            () => {},
            'right',
            true,
            100,
            true,
            'custom-class'
        );
        jest.runAllTimers();

        const offCanvasElement = document.querySelector('.drawer');

        // Only initially configured class should be given
        expect(offCanvasElement.classList.contains('fancy-class')).toBe(false);
        expect(offCanvasElement.classList.contains('custom-class')).toBe(true);

        // Set additional class
        OffCanvas.setAdditionalClassName('fancy-class');

        // Ensure additional class was set on OffCanvas element
        expect(offCanvasElement.classList.contains('fancy-class')).toBe(true);
    });

    it('should be able to update the content for already existing OffCanvas', () => {
        jest.useFakeTimers();

        OffCanvas.open(
            'Interesting content',
            () => {},
            'right',
            true,
            100,
            true,
            'custom-class'
        );
        jest.runAllTimers();

        const offCanvasElement = document.querySelector('.drawer');

        // Should have initial content in the beginning
        expect(offCanvasElement.innerHTML).toBe('Interesting content');

        // Set other content
        OffCanvas.setContent('Even more interesting content');

        // Ensure other content was set
        expect(offCanvasElement.innerHTML).toBe('Even more interesting content');
    });

    it('should close when clicking on close-trigger element', () => {
        jest.useFakeTimers();

        // Set content containing a close trigger button with class "js-offcanvas-close"
        OffCanvas.open(
            '<button class="js-offcanvas-close">Close</button><div class="drawer-body">Lorem ipsum</div>',
        );

        jest.runAllTimers();

        const closeBtn = document.querySelector('.js-offcanvas-close');

        // Click on the close button
        closeBtn.dispatchEvent(new Event('click', { bubbles: true }));

        jest.runAllTimers();

        // OffCanvas should not exist after closing it via the close button
        expect(OffCanvas.exists()).toBe(false);
    });

    it('should be able to pass initial CSS classes as an array', () => {
        jest.useFakeTimers();

        OffCanvas.open(
            'Interesting content',
            () => {},
            'right',
            true,
            350,
            true,
            ['super-sticky', 'extra-wide'] // Pass CSS as array
        );
        jest.runAllTimers();

        const offCanvasElement = document.querySelector('.drawer');

        // Should have CSS classes from array on OffCanvas element
        expect(offCanvasElement.classList.contains('super-sticky')).toBe(true);
        expect(offCanvasElement.classList.contains('extra-wide')).toBe(true);
    });

    it('should throw an error when no string or array is passed for CSS classes', () => {
        expect(() => {
            OffCanvas.open(
                'Interesting content',
                () => {},
                'right',
                true,
                100,
                true,
                { foo: 'Not allowed' } // Cause some trouble
            )
        }).toThrow('The type "object" is not supported. Please pass an array or a string.');
    });

    it('should add aria-labelledby attribute to the OffCanvas', () => {
        jest.useFakeTimers();

        OffCanvas.open(
            '<div class="drawer-body">Lorem ipsum<div data-id="off-canvas-headline">Nice headline</div></div>',
            () => {},
            'right',
            true,
            100,
            true,
            'custom-class',
        );
        jest.runAllTimers();

        const offCanvasElement = document.querySelector('.drawer');

        // Should have aria-labelledby attribute on OffCanvas element
        expect(offCanvasElement.getAttribute('aria-labelledby')).toBe('off-canvas-headline');
    });

    const offCanvasPositions = [
        {  passedPosition: 'start', expectedPositionClass: 'drawer-start' },
        {  passedPosition: 'end', expectedPositionClass: 'drawer-end' },
        {  passedPosition: 'top', expectedPositionClass: 'drawer-top' },
        {  passedPosition: 'bottom', expectedPositionClass: 'drawer-bottom' },

        // Ensure backwards compatible positions "left" and "right"
        {  passedPosition: 'left', expectedPositionClass: 'drawer-start' },
        {  passedPosition: 'right', expectedPositionClass: 'drawer-end' },
    ];

    offCanvasPositions.forEach((position) => {
        it(`should open with position "${position.passedPosition}"`, () => {
            jest.useFakeTimers();

            OffCanvas.open(
                'Interesting content',
                () => {},
                position.passedPosition,
            );
            jest.runAllTimers();

            // Should have the correct position class
            expect(document.querySelector('.drawer').classList.contains(position.expectedPositionClass)).toBe(true);
        });
    });

    it('removes only drawer from the DOM created by OffCanvasSingleton and ignores other Bootstrap drawer', () => {
        // Add a hard-coded Bootstrap drawer to the HTML which must not be removed by OffCanvasSingleton
        document.body.innerHTML = `
            <button class="btn btn-solid theme-primary" type="button" data-bs-toggle="drawer" data-bs-target="#offcanvasExample" aria-controls="offcanvasExample">
                Open Bootstrap drawer
            </button>

            <dialog class="drawer drawer-start" tabindex="-1" id="offcanvasExample" aria-labelledby="offcanvasExampleLabel">
                <div class="drawer-header">
                    <h5 class="drawer-title" id="offcanvasExampleLabel">Offcanvas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="drawer" aria-label="Close"></button>
                </div>
                <div class="drawer-body">
                    Bootstrap drawer
                </div>
            </dialog>
        `;

        jest.useFakeTimers();

        // Open the shopware OffCanvas via OffCanvasSingleton
        OffCanvas.open('Interesting content');
        jest.runAllTimers();

        // Ensue shopware OffCanvas was opened
        expect(OffCanvas.exists()).toBe(true);
        expect(document.querySelector('.js-offcanvas-singleton').open).toBe(true);

        // Ensure hard-coded Bootstrap drawer is also present in the DOM. It must not be removed by "_removeExistingOffCanvas".
        expect(document.getElementById('offcanvasExample')).toBeTruthy();

        // Close the shopware OffCanvas
        OffCanvas.close();
        jest.runAllTimers();

        // Ensure shopware OffCanvas is no longer existing in the DOM
        expect(document.querySelector('.js-offcanvas-singleton')).toBeFalsy();
        expect(OffCanvas.exists()).toBe(false);

        // Ensure the hard-coded Bootstrap drawer is still in the DOM
        expect(document.getElementById('offcanvasExample')).toBeTruthy();
    });

    it('should properly dispose Bootstrap instances when removing existing drawer', () => {
        jest.useFakeTimers();

        // Open first drawer
        OffCanvas.open('First drawer');
        jest.runAllTimers();

        const firstOffcanvas = document.querySelector('.js-offcanvas-singleton');
        expect(firstOffcanvas).toBeTruthy();

        // Mock bootstrap.Offcanvas.getInstance and dispose
        const mockDispose = jest.fn();
        const mockInstance = { dispose: mockDispose };
        const getInstanceSpy = jest.spyOn(Drawer, 'getInstance').mockReturnValue(mockInstance);

        // Open second drawer (should remove first one)
        OffCanvas.open('Second drawer');
        jest.runAllTimers();

        // Verify dispose was called on the first drawer instance
        expect(getInstanceSpy).toHaveBeenCalledWith(firstOffcanvas);
        expect(mockDispose).toHaveBeenCalled();

        // Verify only one drawer exists
        const allOffcanvas = document.querySelectorAll('.js-offcanvas-singleton');
        expect(allOffcanvas.length).toBe(1);
        expect(allOffcanvas[0].innerHTML).toBe('Second drawer');

        // Cleanup
        getInstanceSpy.mockRestore();
    });
});
