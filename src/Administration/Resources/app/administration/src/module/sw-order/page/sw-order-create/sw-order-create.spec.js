import { mount } from '@vue/test-utils';

/**
 * @package checkout
 */

const remindPaymentMock = jest.fn(() => {
    return Promise.resolve();
});

const contextState = {
    namespaced: true,
    state: {
        api: {
            languageId: '2fbb5fe2e29a4d70aa5854ce7ce3e20b',
            systemLanguageId: '2fbb5fe2e29a4d70aa5854ce7ce3e20b',
        },
    },
    mutations: {
        setLanguageId: jest.fn(),
        resetLanguageToDefault: jest.fn(),
    },
};

describe('src/module/sw-order/page/sw-order-create', () => {
    let wrapper;
    let stubs;

    async function createWrapper() {
        return mount(await wrapTestComponent('sw-order-create', { sync: true }), {
            global: {
                stubs,
                provide: {
                    repositoryFactory: {
                        create: () => ({
                            get: () =>
                                Promise.resolve({
                                    translated: {
                                        distinguishableName: 'Cash on Delivery',
                                    },
                                }),
                        }),
                    },
                    shortcutService: {
                        startEventListener: () => {},
                        stopEventListener: () => {},
                    },
                },
                mocks: {
                    $route: {
                        meta: {
                            $module: {
                                routes: {
                                    detail: {
                                        children: {
                                            base: {},
                                            other: {},
                                        },
                                    },
                                },
                            },
                        },
                    },
                },
            },
        });
    }

    beforeAll(async () => {
        stubs = {
            'router-view': true,
            'sw-icon': true,
            'sw-loader': true,
            'sw-app-actions': true,
            'sw-notification-center': true,
            'sw-help-center': true,
            'sw-search-bar': true,
            'sw-language-switch': true,
            'sw-card-view': await wrapTestComponent('sw-card-view', {
                sync: true,
            }),
            'sw-tabs': await wrapTestComponent('sw-tabs', { sync: true }),
            'sw-tabs-item': true,
            'sw-page': await wrapTestComponent('sw-page', { sync: true }),
            'sw-button': await wrapTestComponent('sw-button', { sync: true }),
            'sw-button-deprecated': await wrapTestComponent('sw-button-deprecated', { sync: true }),
            'sw-button-process': await wrapTestComponent('sw-button-process', {
                sync: true,
            }),
            'sw-modal': {
                template: `
                    <div class="sw-modal">
                        <slot></slot>
                        <footer class="sw-modal__footer">
                            <slot name="modal-footer"></slot>
                        </footer>
                    </div>
                `,
            },
            'sw-order-create-invalid-promotion-modal': true,
            'sw-app-topbar-button': true,
            'sw-help-center-v2': true,
            'router-link': true,
            'sw-error-summary': true,
            'sw-tabs-deprecated': true,
        };
    });

    beforeEach(async () => {
        wrapper = await createWrapper();

        Shopware.State.unregisterModule('swOrder');
        Shopware.State.registerModule('swOrder', {
            namespaced: true,
            state() {
                return {
                    defaultSalesChannel: null,
                    cart: {
                        token: 'CART-TOKEN',
                        lineItems: [{}],
                    },
                    customer: {},
                    promotionCodes: [],
                };
            },
            getters: {
                invalidPromotionCodes() {
                    return [];
                },
            },
            actions: {
                saveOrder() {
                    return {
                        data: {
                            id: Shopware.Utils.createId(),
                            transactions: [
                                {
                                    paymentMethodId: Shopware.Utils.createId(),
                                },
                            ],
                        },
                    };
                },
                createCart() {
                    return {
                        token: null,
                        lineItems: [],
                    };
                },
                remindPayment: remindPaymentMock,
            },
        });

        if (Shopware.State.get('context')) {
            Shopware.State.unregisterModule('context');
        }

        Shopware.State.registerModule('context', contextState);
    });

    it('should be a Vue.js component', async () => {
        expect(wrapper.vm).toBeTruthy();
    });

    it('should open remind payment modal on save order', async () => {
        await wrapper.find('.sw-button-process').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.showRemindPaymentModal).toBeTruthy();
        const modal = wrapper.find('.sw-modal');
        expect(modal.isVisible).toBeTruthy();
    });

    it('should be able to close remind payment modal', async () => {
        await wrapper.find('.sw-button-process').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.showRemindPaymentModal).toBeTruthy();

        const modal = wrapper.find('.sw-modal');
        expect(modal.isVisible).toBeTruthy();

        await modal.find('.sw-modal__footer .sw-button').trigger('click');

        expect(wrapper.vm.isSaveSuccessful).toBeTruthy();
        expect(wrapper.vm.showRemindPaymentModal).not.toBeTruthy();
    });

    it('should remind payment on primary modal action', async () => {
        await wrapper.find('.sw-button-process').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.showRemindPaymentModal).toBeTruthy();

        const modal = wrapper.find('.sw-modal');
        expect(modal.isVisible).toBeTruthy();

        await modal.find('.sw-modal__footer .sw-button--primary').trigger('click');

        expect(remindPaymentMock).toHaveBeenCalledTimes(1);

        await wrapper.vm.$nextTick();
        expect(wrapper.vm.isSaveSuccessful).toBeTruthy();
        expect(wrapper.vm.showRemindPaymentModal).not.toBeTruthy();
    });

    it('should be set context language after the process is successful', async () => {
        const buttonProcess = wrapper.find('.sw-button-process');
        await buttonProcess.trigger('click');

        await wrapper.getComponent('.sw-button-process').vm.$emit('update:processSuccess');
        await flushPromises();

        expect(contextState.mutations.setLanguageId).toHaveBeenCalledWith(
            expect.anything(),
            '2fbb5fe2e29a4d70aa5854ce7ce3e20b',
        );
    });
});
