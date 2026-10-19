import Dialog from 'bootstrap/js/dist/dialog.js';
import { REMOVE_BACKDROP_DELAY } from 'src/utility/backdrop/backdrop.util';

const PSEUDO_MODAL_CLASS = 'js-pseudo-modal';
const PSEUDO_MODAL_TEMPLATE_CLASS = 'js-pseudo-modal-template';
const PSEUDO_MODAL_TEMPLATE_CONTENT_CLASS = 'js-pseudo-modal-template-content-element';
const PSEUDO_MODAL_TEMPLATE_TITLE_CLASS = 'js-pseudo-modal-template-title-element';
const PSEUDO_MODAL_TEMPLATE_ROOT_CLASS = 'js-pseudo-modal-template-root-element';

/**
 * @sw-package framework
 */
export default class PseudoModalUtil {
    constructor(
        content,
        useBackdrop = true,
        templateSelector = `.${PSEUDO_MODAL_TEMPLATE_CLASS}`,
        templateContentSelector = `.${PSEUDO_MODAL_TEMPLATE_CONTENT_CLASS}`,
        templateTitleSelector = `.${PSEUDO_MODAL_TEMPLATE_TITLE_CLASS}`,
    ) {
        this._content = content;
        this._useBackdrop = useBackdrop;
        this._templateSelector = templateSelector;
        this._templateContentSelector = templateContentSelector;
        this._templateTitleSelector = templateTitleSelector;
    }

    /**
     * opens the dialog
     *
     * @param {function} cb
     * @param {Number} delay
     */
    open(cb, delay = REMOVE_BACKDROP_DELAY) {
        this._hideExistingModal();
        this._create();
        setTimeout(this._open.bind(this, cb), delay);
    }

    /**
     * closes the dialog
     */
    close() {
        const dialog = this.getModal();

        this._modalInstance = Dialog.getInstance(dialog);
        this._modalInstance.hide();
    }

    /**
     * returns the dialog element
     *
     * @returns {HTMLElement}
     */
    getModal() {
        if (!this._modal) this._create();

        return this._modal;
    }

    /**
     * updates the dialog position
     */
    updatePosition() {
        this._modalInstance.handleUpdate();
    }

    /**
     * This method can be used to update a dialog's content.
     * A callback may be provided, for example to re-initialise all plugins once
     * the markup is changed.
     *
     * @param {string} content
     * @param {function} callback
     */
    updateContent(content, callback) {
        this._content = content;
        this._setModalContent(content);
        this.updatePosition();

        if (typeof callback === 'function') {
            callback.bind(this)();
        }
    }

    /**
     * Before opening a new pseudo dialog, check if there is any existing pseudo dialog already.
     * Hide an existing pseudo dialog first to avoid multiple modals or backdrops.
     *
     * @private
     */
    _hideExistingModal() {
        try {
            const existingModalEl = document.querySelector(`.${PSEUDO_MODAL_CLASS} .dialog`);
            if (!existingModalEl) {
                return;
            }

            const existingModalInstance = Dialog.getInstance(existingModalEl);
            if (!existingModalInstance) {
                return;
            }

            existingModalInstance.hide();
            // @todo: Remove when upstream issue https://github.com/twbs/bootstrap/issues/42503 is resolved.
            window.focusHandler._removeFocusTrapGuard();
        } catch (err) {
            console.warn(`[PseudoModalUtil] Unable to hide existing pseudo dialog before opening pseudo modal: ${err.message}`);
        }
    }

    /**
     * @param {function} cb
     * @private
     */
    _open(cb) {
        this.getModal();

        this._modal.addEventListener('hidden.bs.dialog', this._modalWrapper.remove);
        this._modal.addEventListener('shown.bs.dialog', cb);

        // An open drawer focus-trap pulls the focus back out before the dialog's focus-trap is active.
        this._modal.addEventListener('shown.bs.dialog', () => {
            if (!this._modal.contains(document.activeElement)) {
                this._modal.focus();
            }
        });

        // Keep the Bootstrap focus-trap working when the dialog is the last element before `</body>`.
        // @todo: Remove when upstream issue https://github.com/twbs/bootstrap/issues/42503 is resolved.
        this._modal.addEventListener('hidden.bs.dialog', () => window.focusHandler._removeFocusTrapGuard());

        /**
         * Fix bootstrap dialog accessibility errors.
         *
         * The bootstrap dialog adds the `aria-hidden` attribute on the dialog element when closed.
         * This leads to console errors in some browsers, if an element within the dialog still has focus.
         */
        this._modal.addEventListener('hide.bs.dialog', () => {
            if (document.activeElement instanceof HTMLElement) {
                document.activeElement.blur();
            }
        });

        this._modalInstance.show();

        // @todo: Remove when upstream issue https://github.com/twbs/bootstrap/issues/42503 is resolved.
        window.focusHandler._addFocusTrapGuard(this._modalWrapper);
    }

    /**
     * insert a temporarily needed wrapper div
     * with the response's html content
     *
     * @returns {HTMLElement}
     *
     * @private
     */
    _create() {
        this._modalMarkupEl = document.querySelector(this._templateSelector);
        this._createModalWrapper();
        this._modalWrapper.innerHTML = this._content;
        this._modal = this._createModalMarkup();

        this._modalInstance = new Dialog(this._modal, {
            backdrop: this._useBackdrop,
        });

        document.body.insertAdjacentElement('beforeend', this._modalWrapper);
    }

    /**
     * creates the dialog wrapper
     *
     * @private
     */
    _createModalWrapper() {
        this._modalWrapper = document.querySelector(`.${PSEUDO_MODAL_CLASS}`);

        if (!this._modalWrapper) {
            this._modalWrapper = document.createElement('div');
            this._modalWrapper.classList.add(PSEUDO_MODAL_CLASS);
        }

        // An open drawer makes the rest of the page inert, including a wrapper left over from an earlier dialog.
        this._modalWrapper.inert = false;
        this._modalWrapper.removeAttribute('aria-hidden');
    }

    /**
     * creates the dialog markup if
     * it's not existing already
     *
     * @returns {HTMLElement}
     *
     * @private
     */
    _createModalMarkup() {
        const dialog = this._modalWrapper.querySelector('.dialog');

        if (dialog) {
            return dialog;
        }

        const content = this._modalWrapper.innerHTML;
        this._modalWrapper.innerHTML = this._modalMarkupEl.innerHTML;

        this._setModalContent(content);

        return this._modalWrapper.querySelector('.dialog');
    }

    /**
     * This method is used to set the dialog element's title.
     *
     * @param {string} title
     * @private
     */
    _setModalTitle(title = '') {
        try {
            const titleElement = this._modalWrapper.querySelector(this._templateTitleSelector);
            titleElement.innerHTML = title;
        } catch (err) {
            // do nothing
        }
    }

    /**
     * This method is used to set the dialog element's content.
     *
     * @private
     */
    _setModalContent(content) {
        const contentElement = this._modalWrapper.querySelector(this._templateContentSelector);
        contentElement.innerHTML = content;

        const rootElement = contentElement.querySelector(`.${PSEUDO_MODAL_TEMPLATE_ROOT_CLASS}`);

        if (rootElement) {
            this._modalWrapper.querySelector(`.${PSEUDO_MODAL_TEMPLATE_ROOT_CLASS}`).replaceChildren(rootElement);
            return;
        }

        try {
            const titleElement = contentElement.querySelector(this._templateTitleSelector);
            if (titleElement) {
                this._setModalTitle(titleElement.innerHTML);
                titleElement.parentNode.removeChild(titleElement);
            }
        } catch (err) {
            // do nothing
        }
    }
}
