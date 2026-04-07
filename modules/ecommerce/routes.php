<?php

declare(strict_types=1);

return [
    'GET' => [

        // ── Public Storefront ───────────────────────────────────────
        '/ecommerce/shop'                   => 'ecommerce:ecPublicShop',
        '/ecommerce/shop/category/{slug}'   => 'ecommerce:ecPublicCategory',
        '/ecommerce/shop/{slug}'            => 'ecommerce:ecPublicProduct',
        '/ecommerce/cart'                   => 'ecommerce:ecPublicCart',
        '/ecommerce/checkout'               => 'ecommerce:ecPublicCheckout',
        '/ecommerce/order/{token}'          => 'ecommerce:ecPublicOrderConfirm',
        '/ecommerce/my-orders'              => 'ecommerce:ecPublicMyOrders',
        '/ecommerce/my-orders/{id}'         => 'ecommerce:ecPublicOrderDetail',
        '/ecommerce/download/{token}'       => 'ecommerce:ecPublicDownloadLicense',

        // ── Payment Gateway Return ────────────────────────────────────
        '/ecommerce/payment/return'         => 'ecommerce:ecPaymentReturn',

        // ── Admin Pages (CMS admin chrome via kernel.nav_items hook) ─
        '/ecommerce/admin'                        => 'ecommerce:ecAdminDashboard',
        '/ecommerce/admin/products'               => 'ecommerce:ecAdminProducts',
        '/ecommerce/admin/products/create'        => 'ecommerce:ecAdminProductCreate',
        '/ecommerce/admin/products/{id}/edit'     => 'ecommerce:ecAdminProductEdit',
        '/ecommerce/admin/orders'                 => 'ecommerce:ecAdminOrders',
        '/ecommerce/admin/orders/{id}'            => 'ecommerce:ecAdminOrderDetail',
        '/ecommerce/admin/licenses/{id}/download' => 'ecommerce:ecAdminLicenseDownload',
        '/ecommerce/admin/categories'             => 'ecommerce:ecAdminCategories',
        '/ecommerce/admin/coupons'                => 'ecommerce:ecAdminCoupons',
        '/ecommerce/admin/reports'                => 'ecommerce:ecAdminReports',
        '/ecommerce/admin/email-templates'        => 'ecommerce:ecAdminEmailTemplates',
        '/ecommerce/admin/settings'               => 'ecommerce:ecAdminSettings',
        '/ecommerce/pos'                          => 'ecommerce:ecPosTerminal',

        // ── REST API (GET) ───────────────────────────────────────────
        '/api/v1/ecommerce/products'              => 'ecommerce:ecApiProductsList',
        '/api/v1/ecommerce/products/{id}'         => 'ecommerce:ecApiProductGet',
        '/api/v1/ecommerce/categories'            => 'ecommerce:ecApiCategoryList',
        '/api/v1/cms/cart/add'                    => 'ecommerce:ecApiCartAdd',
        '/api/v1/ecommerce/cart'                  => 'ecommerce:ecApiCartGet',
        '/api/v1/ecommerce/orders'                => 'ecommerce:ecApiOrdersList',
        '/api/v1/ecommerce/orders/my'             => 'ecommerce:ecApiMyOrders',
        '/api/v1/ecommerce/orders/{id}'           => 'ecommerce:ecApiOrderGet',
        '/api/v1/ecommerce/reports/sales'         => 'ecommerce:ecApiReportSales',
        '/api/v1/ecommerce/reports/inventory'     => 'ecommerce:ecApiReportInventory',
        '/api/v1/ecommerce/pos/products'          => 'ecommerce:ecApiPosProducts',
        '/api/v1/ecommerce/shipping/rates'        => 'ecommerce:ecApiShippingRates',
    ],

    'POST' => [

        // ── Public Checkout ──────────────────────────────────────────
        '/ecommerce/cart/add'                       => 'ecommerce:ecPublicCartAdd',
        '/ecommerce/checkout'                        => 'ecommerce:ecPublicCheckoutProcess',

        // ── Admin Pages (form submissions) ──────────────────────────
        '/ecommerce/admin/products/create'          => 'ecommerce:ecAdminProductCreate',
        '/ecommerce/admin/products/{id}/edit'       => 'ecommerce:ecAdminProductEdit',
        '/ecommerce/admin/orders/{id}'              => 'ecommerce:ecAdminOrderDetail',
        '/ecommerce/admin/categories'               => 'ecommerce:ecAdminCategories',
        '/ecommerce/admin/coupons'                  => 'ecommerce:ecAdminCoupons',
        '/ecommerce/admin/email-templates'          => 'ecommerce:ecAdminEmailTemplates',
        '/ecommerce/admin/settings'                 => 'ecommerce:ecAdminSettings',

        // ── REST API — Products ──────────────────────────────────────
        '/api/v1/ecommerce/products'                 => 'ecommerce:ecApiProductCreate',
        '/api/v1/ecommerce/products/{id}'            => 'ecommerce:ecApiProductUpdate',
        '/api/v1/ecommerce/products/{id}/delete'     => 'ecommerce:ecApiProductDelete',

        // ── REST API — Categories ────────────────────────────────────
        '/api/v1/ecommerce/categories'               => 'ecommerce:ecApiCategoryCreate',
        '/api/v1/ecommerce/categories/{id}'          => 'ecommerce:ecApiCategoryUpdate',
        '/api/v1/ecommerce/categories/{id}/delete'   => 'ecommerce:ecApiCategoryDelete',

        // ── REST API — Cart ──────────────────────────────────────────
        '/api/v1/cms/cart/add'                       => 'ecommerce:ecApiCartAdd',
        '/api/v1/ecommerce/cart/add'                 => 'ecommerce:ecApiCartAdd',
        '/api/v1/ecommerce/cart/update'              => 'ecommerce:ecApiCartUpdate',
        '/api/v1/ecommerce/cart/remove'              => 'ecommerce:ecApiCartRemove',
        '/api/v1/ecommerce/cart/coupon'              => 'ecommerce:ecApiCartApplyCoupon',
        '/api/v1/ecommerce/cart/clear'               => 'ecommerce:ecApiCartClear',

        // ── REST API — Checkout ──────────────────────────────────────
        '/api/v1/ecommerce/checkout'                 => 'ecommerce:ecApiCheckout',

        // ── REST API — Orders ────────────────────────────────────────
        '/api/v1/ecommerce/orders/{id}/status'       => 'ecommerce:ecApiOrderStatus',
        '/api/v1/ecommerce/orders/{id}/note'         => 'ecommerce:ecApiOrderNote',

        // ── REST API — Coupons ───────────────────────────────────────
        '/api/v1/ecommerce/coupons'                  => 'ecommerce:ecApiCouponCreate',
        '/api/v1/ecommerce/coupons/{id}'             => 'ecommerce:ecApiCouponUpdate',
        '/api/v1/ecommerce/coupons/{id}/delete'      => 'ecommerce:ecApiCouponDelete',

        // ── REST API — POS ───────────────────────────────────────────
        '/api/v1/ecommerce/pos/transaction'          => 'ecommerce:ecApiPosTransaction',

        // ── Payment Gateway Webhooks ──────────────────────────────────
        '/api/v1/ecommerce/webhooks/paymongo'        => 'ecommerce:ecPaymongoWebhook',
    ],
];
