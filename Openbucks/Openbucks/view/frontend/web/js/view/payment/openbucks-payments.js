/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component,
              rendererList) {
        'use strict';
        rendererList.push(
            {
                type: 'openbucks',
                component: 'Openbucks_Openbucks/js/view/payment/method-renderer/openbucks-method'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);