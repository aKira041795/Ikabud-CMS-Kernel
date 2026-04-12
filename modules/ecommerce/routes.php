<?php

declare(strict_types=1);

return [
    'GET' => [

        // ── Public Storefront ───────────────────────────────────────
        '/ecommerce/shop'                   => 'ecommerce:ecPublicShop',
        '/ecommerce/shop/category/{slug}'   => 'ecommerce:ecPublicCategory',
        '/ecommerce/shop/{slug}'            => 'ecommerce:ecPublicProduct',
        '/ecommerce/compare'                => 'ecommerce:ecPublicCompare',
        '/ecommerce/cart'                   => 'ecommerce:ecPublicCart',
        '/ecommerce/checkout'               => 'ecommerce:ecPublicCheckout',
        '/ecommerce/recover-cart/{token}'   => 'ecommerce:ecPublicRecoverCart',
        '/ecommerce/order/{token}'          => 'ecommerce:ecPublicOrderConfirm',
        '/ecommerce/my-orders'              => 'ecommerce:ecPublicMyOrders',
        '/ecommerce/my-orders/{id}'         => 'ecommerce:ecPublicOrderDetail',
        '/ecommerce/my-wishlist'           => 'ecommerce:ecPublicWishlist',
        '/ecommerce/my-memberships'         => 'ecommerce:ecPublicMemberships',
        '/ecommerce/my-bookings'            => 'ecommerce:ecPublicBookings',
        '/ecommerce/rewards'                => 'ecommerce:ecPublicRewards',
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
        '/ecommerce/admin/returns'                => 'ecommerce:ecAdminReturns',
        '/ecommerce/admin/licenses/{id}/download' => 'ecommerce:ecAdminLicenseDownload',
        '/ecommerce/admin/categories'             => 'ecommerce:ecAdminCategories',
        '/ecommerce/admin/coupons'                => 'ecommerce:ecAdminCoupons',
        '/ecommerce/admin/reports'                => 'ecommerce:ecAdminReports',
        '/ecommerce/admin/email-templates'        => 'ecommerce:ecAdminEmailTemplates',
        '/ecommerce/admin/webhooks'               => 'ecommerce:ecAdminWebhooks',
        '/ecommerce/admin/abandoned-carts'        => 'ecommerce:ecAdminAbandonedCarts',
        '/ecommerce/admin/reviews'                => 'ecommerce:ecAdminReviews',
        '/ecommerce/admin/customers'              => 'ecommerce:ecAdminCustomers',
        '/ecommerce/admin/customers/{id}/edit'    => 'ecommerce:ecAdminCustomerEdit',
        '/ecommerce/admin/memberships'            => 'ecommerce:ecAdminMemberships',
        '/ecommerce/admin/loyalty'                => 'ecommerce:ecAdminLoyalty',
        '/ecommerce/admin/import-export'          => 'ecommerce:ecAdminImportExport',
        '/ecommerce/admin/import-export/{resource}' => 'ecommerce:ecAdminExportCsv',
        '/ecommerce/admin/settings'               => 'ecommerce:ecAdminSettings',
        '/ecommerce/pos'                          => 'ecommerce:ecPosTerminal',

        // ── REST API (GET) ───────────────────────────────────────────
        '/api/v1/ecommerce/products'              => 'ecommerce:ecApiProductsList',
        '/api/v1/ecommerce/products/{id}'         => 'ecommerce:ecApiProductGet',
        '/api/v1/ecommerce/products/{id}/reviews' => 'ecommerce:ecApiProductReviewsList',
        '/api/v1/ecommerce/catalog'               => 'ecommerce:ecApiCatalogSearch',
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
        '/ecommerce/compare'                        => 'ecommerce:ecPublicCompareAction',
        '/ecommerce/wishlist'                       => 'ecommerce:ecPublicWishlistAction',
        '/ecommerce/checkout'                        => 'ecommerce:ecPublicCheckoutProcess',
        '/ecommerce/my-orders/{id}'                  => 'ecommerce:ecPublicOrderDetail',
        '/ecommerce/my-bookings/reschedule'              => 'ecommerce:ecPublicBookingReschedule',
        '/ecommerce/my-bookings/cancel'                  => 'ecommerce:ecPublicBookingCancel',

        // ── Admin Pages (form submissions) ──────────────────────────
        '/ecommerce/admin/products/create'          => 'ecommerce:ecAdminProductCreate',
        '/ecommerce/admin/products/{id}/edit'       => 'ecommerce:ecAdminProductEdit',
        '/ecommerce/admin/orders/{id}'              => 'ecommerce:ecAdminOrderDetail',
        '/ecommerce/admin/categories'               => 'ecommerce:ecAdminCategories',
        '/ecommerce/admin/coupons'                  => 'ecommerce:ecAdminCoupons',
        '/ecommerce/admin/email-templates'          => 'ecommerce:ecAdminEmailTemplates',
        '/ecommerce/admin/abandoned-carts'          => 'ecommerce:ecAdminAbandonedCarts',
        '/ecommerce/admin/webhooks'                 => 'ecommerce:ecAdminWebhooks',
        '/ecommerce/admin/reviews/{id}/{action}'    => 'ecommerce:ecAdminReviewAction',
        '/ecommerce/admin/customers/{id}/edit'      => 'ecommerce:ecAdminCustomerEdit',
        '/ecommerce/admin/memberships'              => 'ecommerce:ecAdminMembershipAction',
        '/ecommerce/admin/loyalty/adjust'           => 'ecommerce:ecAdminLoyaltyAdjust',
        '/ecommerce/admin/import-export'            => 'ecommerce:ecAdminImportExport',
        '/ecommerce/admin/settings'                 => 'ecommerce:ecAdminSettings',

        // ── REST API — Products ──────────────────────────────────────
        '/api/v1/ecommerce/products'                 => 'ecommerce:ecApiProductCreate',
        '/api/v1/ecommerce/products/{id}'            => 'ecommerce:ecApiProductUpdate',
        '/api/v1/ecommerce/products/{id}/delete'     => 'ecommerce:ecApiProductDelete',
        '/api/v1/ecommerce/products/{id}/reviews'    => 'ecommerce:ecApiProductReviewSubmit',

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
        '/api/v1/ecommerce/cart/loyalty'             => 'ecommerce:ecApiCartApplyLoyalty',
        '/api/v1/ecommerce/cart/clear'               => 'ecommerce:ecApiCartClear',
        '/api/v1/ecommerce/abandoned-carts/capture'  => 'ecommerce:ecApiAbandonedCartCapture',

        // ── REST API — Checkout ──────────────────────────────────────
        '/api/v1/ecommerce/checkout'                 => 'ecommerce:ecApiCheckout',

        // ── REST API — Orders ────────────────────────────────────────
        '/api/v1/ecommerce/orders/{id}/status'       => 'ecommerce:ecApiOrderStatus',
        '/api/v1/ecommerce/orders/{id}/note'         => 'ecommerce:ecApiOrderNote',
        '/api/v1/ecommerce/orders/{id}/refund'       => 'ecommerce:ecApiOrderRefund',

        // ── REST API — Coupons ───────────────────────────────────────
        '/api/v1/ecommerce/coupons'                  => 'ecommerce:ecApiCouponCreate',
        '/api/v1/ecommerce/coupons/{id}'             => 'ecommerce:ecApiCouponUpdate',
        '/api/v1/ecommerce/coupons/{id}/delete'      => 'ecommerce:ecApiCouponDelete',

        // ── REST API — POS ───────────────────────────────────────────
        '/api/v1/ecommerce/pos/transaction'          => 'ecommerce:ecApiPosTransaction',

        // ── Payment Gateway Webhooks ──────────────────────────────────
        '/api/v1/ecommerce/webhooks/paymongo'        => 'ecommerce:ecPaymongoWebhook',
        '/api/v1/ecommerce/webhooks/stripe'          => 'ecommerce:ecStripeWebhook',
        '/api/v1/ecommerce/webhooks/paypal'          => 'ecommerce:ecPaypalWebhook',
    ],
];
