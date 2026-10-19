import ZoomModalPlugin from 'src/plugin/zoom-modal/zoom-modal.plugin';
import GallerySliderPlugin from 'src/plugin/slider/gallery-slider.plugin';

/**
 * @package storefront
 */
describe('ZoomModalPlugin tests', () => {
    let zoomModalPlugin = undefined;
    let gallerySliderPlugin = undefined;
    let parentSliderPlugin = undefined;

    beforeEach(() => {
        document.body.innerHTML = `
            <div class="js-gallery-zoom-modal-container" data-gallery-slider="true">
                <div data-zoom-modal="true">

                    <div id="gallery-slider" class="gallery-slider-container">
                        <img src="#" alt="" class="gallery-slider-image magnifier-image js-magnifier-image" tabindex="0">
                        <img src="#" alt="" class="gallery-slider-image magnifier-image js-magnifier-image" tabindex="0">
                        <img src="#" alt="" class="gallery-slider-image magnifier-image js-magnifier-image" tabindex="0">
                    </div>

                    <dialog class="dialog js-zoom-modal">
                        <div>
                            <div  data-modal-gallery-slider="true">
                                <div class="dialog-body">
                                    <div class="zoom-modal-actions">
                                        <button class="image-zoom-btn js-image-zoom-out">Zoom Out</button>
                                        <button class="image-zoom-btn js-image-zoom-reset">Reset</button>
                                        <button class="image-zoom-btn js-image-zoom-in">Zoom In</button>
                                    </div>
                                    <div id="zoom-modal-gallery-slider" class="gallery-slider">
                                        <div class="gallery-slider-item">
                                            <div class="image-zoom-container">
                                                <img src="#" alt="Test" class="gallery-slider-image" tabindex="0">
                                            </div>
                                        </div>
                                        <div class="gallery-slider-item">
                                            <div class="image-zoom-container">
                                                <img src="#" alt="Test" class="gallery-slider-image" tabindex="0">
                                            </div>
                                        </div>
                                        <div class="gallery-slider-item">
                                            <div class="image-zoom-container">
                                                <img src="#" alt="Test" class="gallery-slider-image" tabindex="0">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </dialog>
                </div>
            </div>
        `;

        window.PluginManager.register = jest.fn();
        window.PluginManager.initializePlugin = jest.fn(() => Promise.resolve());

        window.focusHandler = {
            saveFocusState: jest.fn(),
            resumeFocusState: jest.fn(),
            setFocus: jest.fn(),
        };

        window.breakpoints = {
            lg: 992,
            md: 768,
            sm: 576,
            xl: 1200,
            xs: 0,
        };

        const element = document.querySelector('[data-zoom-modal]');
        const modalGalleryElement = document.querySelector('[data-modal-gallery-slider]');
        const parentSliderElement = document.querySelector('[data-gallery-slider]');

        zoomModalPlugin = new ZoomModalPlugin(element);
        gallerySliderPlugin = new GallerySliderPlugin(modalGalleryElement);
        parentSliderPlugin = new GallerySliderPlugin(parentSliderElement);

        gallerySliderPlugin._slider = {
            goTo: jest.fn(),
            events: {
                off: jest.fn(),
                on: jest.fn,
            },
        };

        parentSliderPlugin.getCurrentSliderIndex = jest.fn();

        // Access the parent slider plugin instance
        window.PluginManager.getPluginInstanceFromElement = (element, name) => {
            if (name === 'GallerySlider' && element === parentSliderElement) {
                return parentSliderPlugin;
            }
        };

        zoomModalPlugin.gallerySliderPlugin = gallerySliderPlugin;
        zoomModalPlugin._parentSliderPlugin = parentSliderPlugin;
    });

    afterEach(() => {
        jest.useRealTimers();
        zoomModalPlugin = undefined;
        gallerySliderPlugin = undefined;
    });

    test('zoom dialog plugin exists', () => {
        expect(typeof zoomModalPlugin).toBe('object');
    });

    test('zoom dialog show dialog has only one listener', () => {
        zoomModalPlugin.$emitter.publish = jest.fn();

        const dialog = document.querySelector('.js-zoom-modal');

        jest.useFakeTimers();
        zoomModalPlugin._showModal(dialog);
        jest.runAllTimers();
        zoomModalPlugin._showModal(dialog);
        jest.runAllTimers();

        expect(zoomModalPlugin.$emitter.publish).toHaveBeenCalledWith('modalShow', { dialog });
        expect(zoomModalPlugin.$emitter.publish).toHaveBeenCalledTimes(2);
    });

    test('zoom dialog opens via click event on img trigger element', () => {
        const gallerySlider = document.getElementById('gallery-slider');
        const zoomModalSlider = document.getElementById('zoom-modal-gallery-slider');

        const activeIndex = 2;

        const triggerImgElement = gallerySlider.querySelectorAll('img').item(activeIndex);
        const activeModalImgElement = zoomModalSlider.querySelectorAll('img').item(activeIndex);
        const dialog = document.querySelector('.js-zoom-modal');

        const getParentSliderIndexSpy = jest.spyOn(zoomModalPlugin, '_getParentSliderIndex');

        // Mock the current index of the parent GallerySlider, so the ZoomModal attempts to slide to the correct image when opening.
        zoomModalPlugin._parentSliderPlugin.getCurrentSliderIndex.mockReturnValue(activeIndex);

        jest.useFakeTimers();
        // Click on image trigger element to open the zoom dialog
        triggerImgElement.dispatchEvent(new Event('click'));
        jest.runAllTimers();

        expect(window.focusHandler.saveFocusState).toHaveBeenCalled();

        // Expect the native dialog to be open. Bootstrap 6 has no backdrop element.
        expect(dialog.open).toBe(true);
        expect(document.documentElement.classList.contains('dialog-open')).toBe(true);

        expect(getParentSliderIndexSpy).toHaveBeenCalled();
        expect(zoomModalPlugin.gallerySliderPlugin._slider.goTo).toHaveBeenCalledWith(activeIndex);
        expect(window.focusHandler.setFocus).toHaveBeenCalledWith(activeModalImgElement);
    });

    test('zoom dialog opens via enter key on img trigger element', () => {
        const gallerySlider = document.getElementById('gallery-slider');
        const zoomModalSlider = document.getElementById('zoom-modal-gallery-slider');

        const activeIndex = 2;

        const triggerImgElement = gallerySlider.querySelectorAll('img').item(activeIndex);
        const activeModalImgElement = zoomModalSlider.querySelectorAll('img').item(activeIndex);
        const dialog = document.querySelector('.js-zoom-modal');

        const getParentSliderIndexSpy = jest.spyOn(zoomModalPlugin, '_getParentSliderIndex');

        // Mock the current index of the parent GallerySlider, so the ZoomModal attempts to slide to the correct image when opening.
        zoomModalPlugin._parentSliderPlugin.getCurrentSliderIndex.mockReturnValue(activeIndex);

        const keydownEvent = new Event('keydown');
        keydownEvent.key = 'Enter';

        jest.useFakeTimers();
        triggerImgElement.dispatchEvent(keydownEvent);
        jest.runAllTimers();

        expect(window.focusHandler.saveFocusState).toHaveBeenCalled();

        // Expect the native dialog to be open. Bootstrap 6 has no backdrop element.
        expect(dialog.open).toBe(true);
        expect(document.documentElement.classList.contains('dialog-open')).toBe(true);

        expect(getParentSliderIndexSpy).toHaveBeenCalled();
        expect(zoomModalPlugin.gallerySliderPlugin._slider.goTo).toHaveBeenCalledWith(activeIndex);
        expect(window.focusHandler.setFocus).toHaveBeenCalledWith(activeModalImgElement);
    });

    test('zoom dialog registers listeners on triggers only once when init runs multiple times', () => {
        const onClickSpy = jest.spyOn(ZoomModalPlugin.prototype, '_onClick').mockImplementation(() => {});
        const onKeyDownSpy = jest.spyOn(ZoomModalPlugin.prototype, '_onKeyDown').mockImplementation(() => {});

        const element = document.querySelector('[data-zoom-modal]');
        const plugin = new ZoomModalPlugin(element);

        // Re-run init multiple times to simulate repeated plugin initialization
        plugin.init();
        plugin.init();
        plugin.init();

        const triggerImgElement = document.getElementById('gallery-slider').querySelector('img');

        triggerImgElement.dispatchEvent(new Event('click'));

        const keydownEvent = new Event('keydown');
        keydownEvent.key = 'Enter';
        triggerImgElement.dispatchEvent(keydownEvent);

        // Both listeners should only fire once even though init was called multiple times
        expect(onClickSpy).toHaveBeenCalledTimes(1);
        expect(onKeyDownSpy).toHaveBeenCalledTimes(1);
    });

    test('zoom dialog re-registers trigger listeners after viewport changes', () => {
        const registerEventsSpy = jest.spyOn(ZoomModalPlugin.prototype, '_registerEvents');
        const onClickSpy = jest.spyOn(ZoomModalPlugin.prototype, '_onClick').mockImplementation(() => {});

        const element = document.querySelector('[data-zoom-modal]');

        const plugin = new ZoomModalPlugin(element);

        expect(registerEventsSpy).toHaveBeenCalledTimes(1);

        document.getElementById('gallery-slider').innerHTML = `
            <img src="#" alt="" class="gallery-slider-image magnifier-image js-magnifier-image" tabindex="0" id="updated-trigger">
        `;

        document.dispatchEvent(new Event('Viewport/hasChanged'));

        expect(registerEventsSpy).toHaveBeenCalledTimes(2);

        document.getElementById('updated-trigger').dispatchEvent(new Event('click'));

        expect(onClickSpy).toHaveBeenCalledTimes(1);

        expect(plugin).toBeInstanceOf(ZoomModalPlugin);
    });

    test('zoom dialog closes via ESC key', () => {
        const triggerImgElement = document.querySelector('img');
        const dialog = document.querySelector('.js-zoom-modal');

        jest.useFakeTimers();
        // Click on image trigger element to open the zoom dialog
        triggerImgElement.dispatchEvent(new Event('click'));
        jest.runAllTimers();

        // Expect the native dialog to be open. Bootstrap 6 has no backdrop element.
        expect(dialog.open).toBe(true);
        expect(document.documentElement.classList.contains('dialog-open')).toBe(true);

        // A modal dialog closes from the native cancel event, which Escape triggers in the browser.
        dialog.dispatchEvent(new Event('cancel', { cancelable: true }));
        jest.runAllTimers();

        // Expect zoom dialog to be closed again
        expect(dialog.open).toBe(false);
        expect(document.documentElement.classList.contains('dialog-open')).toBe(false);
        expect(window.focusHandler.resumeFocusState).toHaveBeenCalled();
    });

    describe('_loadImages', () => {
        let dialog;

        beforeEach(() => {
            dialog = document.querySelector('.js-zoom-modal');
            zoomModalPlugin._showModal = jest.fn();
        });

        test('opens straight away when there is nothing to load', () => {
            zoomModalPlugin._loadImages(dialog);

            expect(zoomModalPlugin._showModal).toHaveBeenCalledWith(dialog);
        });

        test('waits for an image that has a source', () => {
            dialog.insertAdjacentHTML('beforeend', '<img class="js-load-img" data-src="/media/cat.jpg">');

            zoomModalPlugin._loadImages(dialog);

            expect(zoomModalPlugin._showModal).not.toHaveBeenCalled();

            dialog.querySelector('.js-load-img').onload();

            expect(zoomModalPlugin._showModal).toHaveBeenCalledWith(dialog);
        });

        test('does not wait for an image whose source is empty', () => {
            dialog.insertAdjacentHTML('beforeend', '<img class="js-load-img" data-src="">');

            zoomModalPlugin._loadImages(dialog);

            expect(zoomModalPlugin._showModal).toHaveBeenCalledWith(dialog);
        });

        test('opens once the loadable images are done, ignoring the empty ones', () => {
            dialog.insertAdjacentHTML(
                'beforeend',
                '<img class="js-load-img" data-src=""><img class="js-load-img" data-src="/media/cat.jpg">',
            );

            zoomModalPlugin._loadImages(dialog);

            expect(zoomModalPlugin._showModal).not.toHaveBeenCalled();

            dialog.querySelectorAll('.js-load-img')[1].onload();

            expect(zoomModalPlugin._showModal).toHaveBeenCalledWith(dialog);
        });
    });
});
