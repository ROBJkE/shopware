import PseudoModalUtil from 'src/utility/modal-extension/pseudo-modal.util';

import PseudoModalTemplate from './pseudo-modal.template.html';
import ModalContentTemplate from './modal-content.template.html';

const selector = {
    templateTitle: '.js-pseudo-modal-template-title-element',
    templateRoot: '.js-pseudo-modal-template-root-element',
};

/**
 * @package storefront
 */
describe('pseudo-modal.util tests', () => {
    let pseudoModal = null;

    function initialModal() {
        return new PseudoModalUtil(ModalContentTemplate);
    }

    beforeEach(() => {
        document.body.innerHTML = PseudoModalTemplate;

        // Mock implementation of insertAdjacentElement because it is currently not supported by jsdom
        document.body.insertAdjacentElement = jest.fn().mockImplementation((position, html) => {
            document.body.appendChild(html);
        });

        window.focusHandler = {
            // @todo: Remove when upstream issue https://github.com/twbs/bootstrap/issues/42503 is resolved.
            _addFocusTrapGuard: jest.fn(),
            _removeFocusTrapGuard: jest.fn(),
        };

        jest.useFakeTimers();
        pseudoModal = initialModal();
    });

    afterEach(() => {
        document.body.innerHTML = '';
        jest.useRealTimers();
    });

    test('it should open the dialog', () => {
        const spyModalOpen = jest.spyOn(pseudoModal, '_open');

        pseudoModal.open();
        jest.runAllTimers();

        expect(spyModalOpen).toHaveBeenCalled();

        // Ensure opened dialog is found in DOM
        const openedModal = document.querySelector('dialog.dialog[open]');
        expect(openedModal).toBeTruthy();

        // Ensure content is found inside opened dialog DOM
        expect(openedModal.querySelector('.dialog-body').innerHTML).toContain('<div>Modal content</div>');
    });

    test('it should open the dialog with delay', () => {
        const spyModalOpen = jest.spyOn(pseudoModal, '_open');
        const spyCallback = jest.fn();

        pseudoModal.open(spyCallback, 200);
        jest.advanceTimersByTime(210);

        expect(spyModalOpen).toHaveBeenCalled();
        expect(spyCallback).toHaveBeenCalled();

        // Ensure opened dialog is found in DOM
        const openedModal = document.querySelector('dialog.dialog[open]');
        expect(openedModal).toBeTruthy();

        // Ensure content is found inside opened dialog DOM
        expect(openedModal.querySelector('.dialog-body').innerHTML).toContain('<div>Modal content</div>');
    });

    test('it should close the dialog', () => {
        pseudoModal.open();
        jest.runAllTimers();

        // Ensure opened dialog is found in DOM
        expect(document.querySelector('dialog.dialog[open]')).toBeTruthy();

        // Now we close the dialog again
        pseudoModal.close();
        jest.runAllTimers();

        // Ensure the dialog with "show" class is not present after close
        expect(document.querySelector('dialog.dialog[open]')).toBeFalsy();
    });

    test('it moves the focus into the dialog when another focus-trap pulled it out while opening', () => {
        const offcanvasButton = document.createElement('button');
        document.body.appendChild(offcanvasButton);

        // Simulates the focus-trap of an open drawer
        document.addEventListener('focusin', () => offcanvasButton.focus(), { once: true });

        pseudoModal.open();
        jest.runAllTimers();

        expect(document.activeElement).toBe(pseudoModal.getModal());
    });

    test('it makes a wrapper left over from an earlier dialog interactive again', () => {
        const leftOverWrapper = document.createElement('div');
        leftOverWrapper.classList.add('js-pseudo-modal');
        leftOverWrapper.inert = true;
        leftOverWrapper.setAttribute('aria-hidden', 'true');
        document.body.appendChild(leftOverWrapper);

        pseudoModal.open();
        jest.runAllTimers();

        expect(pseudoModal.getModal().parentElement).toBe(leftOverWrapper);
        expect(leftOverWrapper.inert).toBe(false);
        expect(leftOverWrapper.hasAttribute('aria-hidden')).toBe(false);
    });

    test('it has title template placeholder in dialog header', () => {
        const templateTitle = document.querySelector(selector.templateTitle);

        expect(templateTitle).not.toBeNull();
        expect(templateTitle.textContent).toBe('');
    });

    test('it can move dialog title from dialog body to header', () => {
        const dialog = pseudoModal.getModal();
        const modalTitle = dialog.querySelector(`h5${selector.templateTitle}`);

        expect(modalTitle).not.toBeNull();
        expect(modalTitle.textContent).toBe('Modal title');
    });

    test('it can remove dialog title from body after moving to header', () => {
        const dialog = pseudoModal.getModal();
        const titleElement = dialog.querySelectorAll(selector.templateTitle);

        expect(titleElement).toHaveLength(1);
    });

    test('it closes existing dialog before opening new dialog', () => {
        pseudoModal.open();
        jest.runAllTimers();

        // Ensure first dialog is opened
        const openedModal = document.querySelector('.js-pseudo-modal dialog.dialog[open]');
        expect(openedModal.querySelector('.dialog-body').innerHTML).toContain('<div>Modal content</div>');

        // While the first dialog is still open, some other dialog is opened with new instance of PseudoModalUtil
        const newPseudoModal = new PseudoModalUtil('<div>New dialog content</div>');
        newPseudoModal.open();
        jest.runAllTimers();

        // Ensure new dialog is opened with new content
        const newModal = document.querySelector('.js-pseudo-modal dialog.dialog[open]');
        expect(newModal.querySelector('.dialog-body').innerHTML).toContain('<div>New dialog content</div>');

        // Bootstrap 6 dialogs use the native backdrop, so only one pseudo dialog stays open.
        expect(document.querySelectorAll('.js-pseudo-modal')).toHaveLength(1);
        expect(document.querySelectorAll('dialog.dialog[open]')).toHaveLength(1);
    });

    test('it should place the content below the dialog content', () => {
        const newPseudoModal = new PseudoModalUtil(`<div class="${selector.templateRoot.slice(1)}"><h1>New dialog content</h1></div>`);
        newPseudoModal.open();
        jest.runAllTimers();

        const newModal = document.querySelector('.js-pseudo-modal dialog.dialog[open]');
        expect(newModal.querySelector(selector.templateRoot).innerHTML).toContain('<h1>New dialog content</h1>');
    });

    test('it should handle just a string', () => {
        const newPseudoModal = new PseudoModalUtil('New dialog content string');
        newPseudoModal.open();
        jest.runAllTimers();

        const newModal = document.querySelector('.js-pseudo-modal dialog.dialog[open]');
        expect(newModal.querySelector('.dialog-body').innerHTML).toContain('New dialog content string');
    });
});
